<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Petra\Core\App;
use Petra\Core\Database;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Security\Auth;
use Petra\Security\PasswordHasher;
use Petra\Services\CashControl;
use Petra\Services\CatalogService;
use Petra\Services\PriceResolver;
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Petra\Services\StockControl;
use Petra\Sync\HttpTransport;
use Petra\Sync\SyncClient;
use Petra\Sync\SyncConfig;
use Petra\Sync\SyncReceiver;
use Petra\Sync\SyncStatus;
use Petra\Sync\TransportException;
use Tests\DatabaseTestCase;

/**
 * Synchronisation entre une station (base petra_test) et le central (base petra_central_test).
 * Le « réseau » est un transport factice qui remet les requêtes à l'application centrale en mémoire :
 * il peut être coupé, ou perdre la réponse après que le central a traité le lot.
 */
final class SyncTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';
    private const TOKEN = 'jeton-de-la-station-a-0123456789abcdef0123456789abcdef';

    private Database $central;
    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $attendant;
    private object $network;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = central_test_database();
        $this->central->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->central->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = (string) array_values($row)[0];
            if (!in_array($table, ['schema_migrations', 'roles', 'permissions', 'role_permissions'], true)) {
                $this->central->pdo()->exec("TRUNCATE TABLE `$table`");
            }
        }
        $this->central->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Le central connaît l'organisation, la station (avec l'empreinte de son jeton) et les comptes.
        $this->org = Uuid::v7();
        $this->station = Uuid::v7();
        $this->central->execute('INSERT INTO organizations (id, name) VALUES (:id, :n)', ['id' => $this->org, 'n' => 'Pétrole du Katanga']);
        $this->central->execute(
            "INSERT INTO stations (id, organization_id, code, name, sync_token_hash) VALUES (:id, :o, 'A', 'Station A', :h)",
            ['id' => $this->station, 'o' => $this->org, 'h' => hash('sha256', self::TOKEN)]
        );
        $this->owner = $this->centralUser('owner', 'proprio', true);
        $this->manager = $this->centralUser('manager', 'gerant');
        $this->attendant = $this->centralUser('attendant', 'pompiste');

        // Cette installation est la station A, reliée au central.
        putenv('STATION_ID=' . $this->station);
        putenv('SYNC_URL=https://central.test');
        putenv('SYNC_TOKEN=' . self::TOKEN);
        $this->db->pdo()->exec("SET @petra_station_id = _ascii'{$this->station}' COLLATE ascii_bin");
        $this->network = $this->network();
    }

    protected function tearDown(): void
    {
        putenv('STATION_ID');
        putenv('SYNC_URL');
        putenv('SYNC_TOKEN');
        $this->db->pdo()->exec('SET @petra_station_id = NULL');
        $this->db->queueing(true);
    }

    // --- Outils -----------------------------------------------------------------------------

    private function centralUser(string $role, string $username, bool $allStations = false): string
    {
        $id = Uuid::v7();
        $this->central->execute(
            'INSERT INTO users (id, organization_id, role_id, username, password_hash, first_name, last_name, all_stations)
             SELECT :id, :o, r.id, :u, :h, :f, :l, :a FROM roles r WHERE r.code = :r',
            ['id' => $id, 'o' => $this->org, 'u' => $username, 'h' => self::passwordHash(), 'f' => ucfirst($role), 'l' => 'Central', 'a' => $allStations ? 1 : 0, 'r' => $role]
        );
        if (!$allStations) {
            $this->central->execute('INSERT INTO user_stations (id, user_id, station_id) VALUES (:id, :u, :s)', ['id' => Uuid::v7(), 'u' => $id, 's' => $this->station]);
        }

        return $id;
    }

    private function centralConfig(): array
    {
        return ['mode' => 'central', 'station_id' => '', 'sync' => ['url' => '', 'token' => '']] + self::config();
    }

    /** Requête traitée par l'application centrale, au nom d'un de ses utilisateurs (ou sans session). */
    private function centralHttp(?string $userId, string $method, string $path, array $body = [], array $headers = []): Response
    {
        $data = ['_csrf_token' => self::CSRF];
        if ($userId !== null) {
            $hash = (string) $this->central->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
            $data += [Auth::SESSION_USER => $userId, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash)];
            $body += ['_csrf' => self::CSRF];
        }
        $request = new Request($method, $path, [], $body, array_change_key_case($headers), ['REMOTE_ADDR' => '10.0.0.5']);

        return App::create($this->centralConfig(), Session::memory($data), $this->central, $request)->handle($request);
    }

    /** @template T of object  @param class-string<T> $class  @return T */
    private function centralService(string $userId, string $class): object
    {
        $hash = (string) $this->central->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        $session = Session::memory([Auth::SESSION_USER => $userId, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash)]);

        return App::create($this->centralConfig(), $session, $this->central, new Request('GET', '/'))->container()->get($class);
    }

    /** Réseau factice : remet chaque requête à l'application centrale. */
    private function network(): object
    {
        return new class (fn (Request $r): Response => App::create($this->centralConfig(), Session::memory(), $this->central, $r)->handle($r)) implements HttpTransport {
            public bool $online = true;
            /** Nombre de réponses à perdre : le central traite la requête, la station ne reçoit rien. */
            public int $dropResponses = 0;
            /** @var list<array{path: string, items: int}> */
            public array $requests = [];

            public function __construct(private readonly \Closure $central)
            {
            }

            public function post(string $url, array $headers, string $body): array
            {
                if (!$this->online) {
                    throw new TransportException('Could not resolve host: central.test');
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                $payload = json_decode($body, true);
                $this->requests[] = ['path' => $path, 'items' => count($payload['batch'] ?? [])];
                $response = ($this->central)(new Request('POST', $path, [], $payload, array_change_key_case($headers) + ['content-type' => 'application/json']));
                if ($this->dropResponses > 0) {
                    $this->dropResponses--;
                    throw new TransportException('Connection reset by peer');
                }

                return ['status' => $response->status, 'body' => $response->body];
            }
        };
    }

    private function client(int $batchSize = 200, ?string $token = null, ?string $stationId = null): SyncClient
    {
        return new SyncClient($this->db, new SyncConfig('station', $stationId ?? $this->station, 'https://central.test', $token ?? self::TOKEN), $this->network, $batchSize);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    /** @return array<string, int> entrées de la file par statut */
    private function queue(): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT status, COUNT(*) n FROM sync_queue GROUP BY status'), 'n', 'status'));
    }

    private function rowCount(Database $db, string $table): int
    {
        return (int) $db->fetchValue("SELECT COUNT(*) FROM `$table`");
    }

    /**
     * Activité d'une station : installation, prix et taux locaux, un poste complet avec caisse, un jaugeage.
     *
     * @return array{product: string, tank: string, nozzle: string, shift: string}
     */
    private function activity(): array
    {
        $catalog = $this->service($this->owner, CatalogService::class);
        $product = $catalog->createProduct($this->station, ['code' => 'ESS', 'name' => 'Essence']);
        $tank = $catalog->createTank($this->station, ['product_id' => $product, 'code' => 'C-ESS', 'name' => 'Cuve', 'capacity_l' => '30000']);
        $nozzle = $catalog->createNozzle($this->station, ['pump_id' => $this->pumpFor($this->station), 'tank_id' => $tank, 'code' => 'P-ESS', 'name' => 'Pistolet']);
        $csv = tempnam(sys_get_temp_dir(), 'petra-csv-');
        file_put_contents($csv, "0;0\n150;15000\n300;30000\n");
        $catalog->importCalibrations($tank, ['tmp_name' => $csv, 'error' => UPLOAD_ERR_OK]);
        $pricing = $this->service($this->owner, PricingService::class);
        $pricing->publishRate($this->station, ['rate' => '2300'], self::at('05:00:00'));
        $pricing->publishPrice($product, ['unit_price' => '5000', 'currency' => 'CDF'], self::at('05:00:00'));
        $this->service($this->manager, StockControl::class)->recordDip($tank, ['depth_cm' => '100', 'dip_type' => 'opening'], self::at('05:30:00'));

        $shift = $this->service($this->manager, ShiftService::class)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$nozzle => '0']], self::at('06:00:00'));
        $this->service($this->attendant, ShiftService::class)->addOutput($shift, ['nozzle_id' => $nozzle, 'output_type' => 'test', 'volume_l' => '5', 'reason' => 'Essai'], self::at('07:00:00'));
        $this->service($this->attendant, ShiftService::class)->close($shift, ['final' => [$nozzle => '105']], self::at('12:00:00'));
        $this->service($this->manager, CashControl::class)->addHandover($shift, ['amount' => '485000', 'currency' => 'CDF'], self::at('12:10:00'));
        $this->service($this->manager, ShiftService::class)->validate($shift, [], self::at('12:30:00'));

        return ['product' => $product, 'tank' => $tank, 'nozzle' => $nozzle, 'shift' => $shift];
    }

    /** La station et le central ont exactement les mêmes lignes dans cette table. */
    private function assertSameRows(string $table): void
    {
        $sql = "SELECT * FROM `$table` WHERE station_id = :s ORDER BY id";
        self::assertSame($this->db->fetchAll($sql, ['s' => $this->station]), $this->central->fetchAll($sql, ['s' => $this->station]), $table);
    }

    // --- File d'attente ------------------------------------------------------------------------

    public function testEveryBusinessTableQueuesItsWritesByTrigger(): void
    {
        $triggers = array_column($this->db->fetchAll(
            "SELECT CONCAT(EVENT_OBJECT_TABLE, ':', EVENT_MANIPULATION) k FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE '%\\_sync\\_%'"
        ), 'k');
        foreach (SyncConfig::UP as $table => $appendOnly) {
            self::assertContains("$table:INSERT", $triggers, $table);
            if (!$appendOnly) {
                self::assertContains("$table:UPDATE", $triggers, "$table : table modifiable");
            }
        }
        // Toute table portant un station_id est soit poussée vers le central, soit décidée par lui, soit locale.
        $withStation = array_column($this->db->fetchAll("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'station_id'"), 't');
        self::assertSame([], array_values(array_diff($withStation, array_keys(SyncConfig::UP), ['user_stations', 'sync_queue', 'sync_log'])), 'Table métier oubliée par la synchronisation');
    }

    public function testQueueEntryIsWrittenInTheSameTransactionAsTheBusinessWrite(): void
    {
        $this->client()->pull();
        self::assertSame([], $this->queue(), 'Ce qui vient du central ne repart pas dans la file');

        $product = $this->service($this->owner, CatalogService::class)->createProduct($this->station, ['code' => 'ESS', 'name' => 'Essence']);
        $entries = $this->db->fetchAll("SELECT entity_type, entity_id, operation, status, station_id FROM sync_queue ORDER BY seq");
        self::assertContains(['entity_type' => 'products', 'entity_id' => $product, 'operation' => 'insert', 'status' => 'pending', 'station_id' => $this->station], $entries);
        self::assertContains('audit_log', array_column($entries, 'entity_type'), 'Le journal d\'audit est synchronisé lui aussi');
        $before = $this->rowCount($this->db, 'sync_queue');

        // Écriture annulée : son entrée de file disparaît avec elle.
        try {
            $this->db->transaction(function (): void {
                $this->db->execute("INSERT INTO products (id, station_id, code, name) VALUES (:id, :s, 'MAZ', 'Mazout')", ['id' => Uuid::v7(), 's' => $this->station]);
                self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sync_queue q JOIN products p ON p.id = q.entity_id WHERE p.code = 'MAZ'"));
                throw new \RuntimeException('annulation');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $this->rowCount($this->db, 'sync_queue'));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM products WHERE code = 'MAZ'"));

        // Le central, lui, ne met rien en file.
        $this->central->execute("INSERT INTO products (id, station_id, code, name) VALUES (:id, :s, 'GPL', 'Gaz')", ['id' => Uuid::v7(), 's' => $this->station]);
        self::assertSame(0, $this->rowCount($this->central, 'sync_queue'));
    }

    // --- Descente : comptes, droits ------------------------------------------------------------------

    public function testFirstPullInstallsOrganizationStationAndAccounts(): void
    {
        self::assertSame(0, $this->rowCount($this->db, 'users'));

        $pull = $this->client()->pull();

        self::assertSame(['success', 0], [$pull['status'], $pull['conflicts']]);
        self::assertSame(['Station A', null], array_values((array) $this->db->fetchOne('SELECT name, sync_token_hash FROM stations WHERE id = :id', ['id' => $this->station])), 'Le jeton ne descend pas');
        self::assertSame(['gerant', 'pompiste', 'proprio'], array_column($this->db->fetchAll('SELECT username FROM users ORDER BY username'), 'username'));
        self::assertSame(2, $this->rowCount($this->db, 'user_stations'));
        self::assertSame($this->rowCount($this->central, 'role_permissions'), $this->rowCount($this->db, 'role_permissions'));

        // Le gérant se connecte à la station avec le mot de passe défini au central, même sans Internet.
        $this->network->online = false;
        $session = Session::memory();
        $this->http($session, 'GET', '/login');
        self::assertSame(302, $this->http($session, 'POST', '/login', ['username' => 'gerant', 'password' => self::PASSWORD])->status);
        self::assertStringContainsString('Station A', $this->http($session, 'GET', '/')->body);

        // Un compte désactivé ou retiré de la station au central l'est ici à la passe suivante ; les compteurs locaux sont gardés.
        $this->network->online = true;
        $this->db->execute('UPDATE users SET failed_logins = 3 WHERE id = :id', ['id' => $this->attendant]);
        $this->central->execute('UPDATE users SET is_active = 0, first_name = :f WHERE id = :id', ['f' => 'Renommé', 'id' => $this->attendant]);
        $this->central->execute('DELETE FROM user_stations WHERE user_id = :u', ['u' => $this->manager]);
        $this->client()->pull();
        self::assertSame(['Renommé', 0, 3], array_values((array) $this->db->fetchOne('SELECT first_name, is_active, failed_logins FROM users WHERE id = :id', ['id' => $this->attendant])));
        self::assertSame([$this->attendant], array_column($this->db->fetchAll('SELECT user_id FROM user_stations'), 'user_id'));
    }

    // --- Montée -----------------------------------------------------------------------------------------

    public function testPushSendsTheQueueInBatchesAndTheCentralHoldsTheSameRows(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $pending = $this->queue()['pending'];
        self::assertGreaterThan(30, $pending);

        $push = $this->client(7)->push();

        self::assertSame(['success', $pending, $pending, 0, 0], [$push['status'], $push['sent'], $push['accepted'], $push['rejected'], $push['conflicts']]);
        self::assertSame(['sent' => $pending], $this->queue());
        $requests = array_filter($this->network->requests, static fn (array $r): bool => $r['path'] === '/api/sync/push');
        self::assertCount((int) ceil($pending / 7), $requests, 'Envoi par lots');
        self::assertLessThanOrEqual(7, max(array_column($requests, 'items')));

        foreach (array_keys(SyncConfig::UP) as $table) {
            $this->assertSameRows($table);
        }
        self::assertSame(['validated', 500000, 485000, -15000], array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : $v,
            array_values((array) $this->central->fetchOne('SELECT status, expected_amount, received_amount, variance_amount FROM shifts WHERE id = :id', ['id' => $ids['shift']]))
        ), 'Le propriétaire voit au central l\'écart du poste');
        self::assertSame(3, $this->rowCount($this->central, 'tank_calibrations'));
        self::assertNotNull($this->central->fetchValue('SELECT last_sync_at FROM stations WHERE id = :id', ['id' => $this->station]));

        $log = $this->db->fetchOne("SELECT * FROM sync_log WHERE direction = 'push' ORDER BY started_at DESC, id DESC LIMIT 1");
        self::assertSame(['success', $pending, $pending, 0], [$log['status'], (int) $log['items_sent'], (int) $log['items_accepted'], (int) $log['items_conflicted']]);
        self::assertSame((int) ceil($pending / 7), (int) $this->central->fetchValue("SELECT COUNT(*) FROM sync_log WHERE direction = 'push'"), 'Le central consigne chaque lot reçu');
    }

    public function testReplacedCalibrationChartIsReplacedAtTheCentralToo(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $this->client()->push();
        self::assertSame(3, $this->rowCount($this->central, 'tank_calibrations'));

        $csv = tempnam(sys_get_temp_dir(), 'petra-csv-');
        file_put_contents($csv, "0;0\n100;9000\n200;19000\n300;30000\n");
        $this->service($this->owner, CatalogService::class)->importCalibrations($ids['tank'], ['tmp_name' => $csv, 'error' => UPLOAD_ERR_OK]);
        $this->client()->push();

        $this->assertSameRows('tank_calibrations');
        self::assertSame(['0.00', '9000.00', '19000.00', '30000.00'], array_column($this->central->fetchAll('SELECT volume_l FROM tank_calibrations ORDER BY depth_cm'), 'volume_l'));
    }

    // --- Coupure et reprise ---------------------------------------------------------------------------------

    public function testOutageKeepsEverythingQueuedAndResumeSendsItOnce(): void
    {
        $this->client()->pull();
        $this->activity();
        $pending = $this->queue()['pending'];
        $status = new SyncStatus($this->db, new SyncConfig('station', $this->station, 'https://central.test', self::TOKEN));
        self::assertSame(['pending', $pending], [$status->summary()['state'], $status->summary()['pending']]);

        // Coupure : le central est injoignable.
        $this->network->online = false;
        $run = $this->client()->run();

        self::assertSame('offline', $run['push']['status']);
        self::assertNull($run['pull'], 'Hors ligne : la réception n\'est pas tentée');
        self::assertSame(['pending' => $pending], $this->queue(), 'Rien n\'est perdu ni marqué envoyé');
        self::assertSame(0, $this->rowCount($this->central, 'shifts'));
        $log = $this->db->fetchOne("SELECT status, error_message, items_sent FROM sync_log WHERE direction = 'push' ORDER BY started_at DESC, id DESC LIMIT 1");
        self::assertSame(['offline', 'Could not resolve host: central.test', 0], [$log['status'], $log['error_message'], (int) $log['items_sent']]);
        self::assertSame('offline', $status->summary()['state']);

        // La station continue de travailler hors ligne : la file grossit.
        $this->service($this->owner, CatalogService::class)->createProduct($this->station, ['code' => 'MAZ', 'name' => 'Mazout']);
        $more = $this->queue()['pending'];
        self::assertGreaterThan($pending, $more);

        // Coupure au milieu de l'envoi : le premier lot passe, le réseau tombe ensuite.
        $this->network->online = true;
        $network = $this->network;
        $cut = new class ($network) implements HttpTransport {
            private int $calls = 0;

            public function __construct(private readonly HttpTransport $inner)
            {
            }

            public function post(string $url, array $headers, string $body): array
            {
                if (++$this->calls > 1) {
                    throw new TransportException('Connection timed out');
                }

                return $this->inner->post($url, $headers, $body);
            }
        };
        $partial = (new SyncClient($this->db, new SyncConfig('station', $this->station, 'https://central.test', self::TOKEN), $cut, 10))->push();
        self::assertSame(['offline', 10, 10], [$partial['status'], $partial['sent'], $partial['accepted']]);
        self::assertSame(['pending' => $more - 10, 'sent' => 10], $this->queue());

        // Reprise : le reste part, rien n'est renvoyé deux fois.
        $resume = $this->client(10)->run();
        self::assertSame(['success', $more - 10, $more - 10], [$resume['push']['status'], $resume['push']['sent'], $resume['push']['accepted']]);
        self::assertSame('success', $resume['pull']['status']);
        self::assertSame(['sent' => $more], $this->queue());
        foreach (['products', 'shifts', 'meter_readings', 'cash_handovers', 'tank_dips', 'audit_log'] as $table) {
            $this->assertSameRows($table);
        }
        self::assertSame(['synced', 0], [$status->summary()['state'], $status->summary()['pending']]);
        self::assertNotNull($status->summary()['last_at']);
    }

    // --- Double envoi -------------------------------------------------------------------------------------------

    public function testALostResponseLeadsToAResendWithoutAnyDuplicate(): void
    {
        $this->client()->pull();
        $this->activity();
        $pending = $this->queue()['pending'];

        // Le central reçoit et applique le lot, mais la réponse se perd : pour la station, rien n'est acquitté.
        $this->network->dropResponses = 1;
        $first = $this->client()->push();
        self::assertSame(['offline', 0], [$first['status'], $first['accepted']]);
        self::assertSame(['pending' => $pending], $this->queue());
        $counts = [];
        foreach (array_keys(SyncConfig::UP) as $table) {
            $counts[$table] = $this->rowCount($this->central, $table);
        }
        self::assertSame(1, $counts['shifts'], 'Le central avait bien tout reçu');

        // La station renvoie tout : le central reconnaît chaque ligne et n'en double aucune.
        $second = $this->client()->push();
        self::assertSame(['success', $pending, $pending, 0, 0], [$second['status'], $second['sent'], $second['accepted'], $second['rejected'], $second['conflicts']]);
        foreach ($counts as $table => $count) {
            self::assertSame($count, $this->rowCount($this->central, $table), "$table : aucun doublon");
            $this->assertSameRows($table);
        }
        self::assertSame(['sent' => $pending], $this->queue());

        // Une passe de plus n'a plus rien à envoyer.
        self::assertSame(0, $this->client()->push()['sent']);
    }

    public function testReceiverIsIdempotentForAnIdenticalBatch(): void
    {
        $this->client()->pull();
        $this->activity();
        $batch = [];
        foreach ($this->db->fetchAll('SELECT seq, entity_type, entity_id, operation FROM sync_queue ORDER BY seq') as $entry) {
            $row = $this->db->fetchOne("SELECT * FROM `{$entry['entity_type']}` WHERE id = :id", ['id' => $entry['entity_id']]);
            unset($row['open_station_id'], $row['open_key']);
            $batch[] = ['seq' => (int) $entry['seq'], 'table' => $entry['entity_type'], 'id' => $entry['entity_id'], 'op' => $row === null ? 'delete' : $entry['operation'], 'row' => $row];
        }
        $receiver = new SyncReceiver($this->central);

        $first = $receiver->push($this->station, $batch);
        $total = $this->rowCount($this->central, 'audit_log') + $this->rowCount($this->central, 'meter_readings');
        $again = $receiver->push($this->station, $batch);
        $thrice = $receiver->push($this->station, array_reverse($batch));

        self::assertSame([0, 0], [$first['conflicts'], $first['rejected']]);
        self::assertGreaterThan(20, $first['applied']);
        self::assertSame(count($batch), $first['applied'] + $first['duplicates'], 'Une ligne mise en file deux fois (création puis modification) n\'est appliquée qu\'une fois');
        self::assertSame([0, count($batch), 0, 0], [$again['applied'], $again['duplicates'], $again['conflicts'], $again['rejected']], 'UUID déjà reçu = ignoré');
        self::assertSame([0, count($batch)], [$thrice['applied'], $thrice['duplicates']], 'Même dans le désordre');
        self::assertSame($total, $this->rowCount($this->central, 'audit_log') + $this->rowCount($this->central, 'meter_readings'));
    }

    // --- Jeton et station ----------------------------------------------------------------------------------------

    public function testCentralChecksThatTheStationMatchesTheToken(): void
    {
        $this->client()->pull();
        $this->service($this->owner, CatalogService::class)->createProduct($this->station, ['code' => 'ESS', 'name' => 'Essence']);

        // Mauvais jeton : refus, rien n'est reçu, la file reste en attente.
        $bad = $this->client(200, 'mauvais-jeton')->push();
        self::assertSame('failed', $bad['status']);
        self::assertStringContainsString('401', (string) $bad['error']);
        self::assertSame(0, $this->rowCount($this->central, 'products'));
        self::assertArrayNotHasKey('sent', $this->queue());

        // Autre station : son propre jeton ne lui permet pas d'écrire dans la station A.
        $other = Uuid::v7();
        $this->central->execute("INSERT INTO stations (id, organization_id, code, name, sync_token_hash) VALUES (:id, :o, 'B', 'Station B', :h)", ['id' => $other, 'o' => $this->org, 'h' => hash('sha256', 'jeton-b')]);
        $row = ['id' => Uuid::v7(), 'station_id' => $this->station, 'code' => 'PIRATE', 'name' => 'Injecté', 'is_active' => 1];
        $headers = ['authorization' => 'Bearer jeton-b', 'x-station-id' => $other];
        $response = $this->centralHttp(null, 'POST', '/api/sync/push', ['station_id' => $other, 'batch' => [['seq' => 1, 'table' => 'products', 'id' => $row['id'], 'op' => 'insert', 'row' => $row]]], $headers);
        self::assertSame(200, $response->status);
        self::assertSame('rejected', json_decode($response->body, true)['results'][0]['status']);
        self::assertSame(0, $this->rowCount($this->central, 'products'));

        // Jeton de B présenté pour la station A, station_id du corps différent de l'en-tête, jeton absent : 401.
        foreach ([
            [['authorization' => 'Bearer jeton-b', 'x-station-id' => $this->station], ['station_id' => $this->station]],
            [['authorization' => 'Bearer jeton-b', 'x-station-id' => $other], ['station_id' => $this->station]],
            [['x-station-id' => $this->station], ['station_id' => $this->station]],
            [['authorization' => 'Bearer ' . self::TOKEN, 'x-station-id' => 'pas-un-uuid'], []],
        ] as [$h, $body]) {
            self::assertSame(401, $this->centralHttp(null, 'POST', '/api/sync/push', $body + ['batch' => []], $h)->status);
            self::assertSame(401, $this->centralHttp(null, 'POST', '/api/sync/pull', $body, $h)->status);
        }
        // La descente ne livre que les comptes de la station du jeton.
        $pull = json_decode($this->centralHttp(null, 'POST', '/api/sync/pull', ['station_id' => $other], $headers)->body, true);
        self::assertSame([$other, ['proprio']], [$pull['station']['id'], array_column($pull['users'], 'username')], 'Seul le propriétaire a accès à la station B');

        // Station désactivée au central : son jeton ne vaut plus rien.
        $this->central->execute('UPDATE stations SET is_active = 0 WHERE id = :id', ['id' => $this->station]);
        self::assertSame('failed', $this->client()->push()['status']);
        self::assertSame(0, $this->rowCount($this->central, 'products'));

        // Sur un serveur de station, cette API n'existe pas.
        $request = new Request('POST', '/api/sync/push', [], ['batch' => []], ['authorization' => 'Bearer ' . self::TOKEN, 'x-station-id' => $this->station]);
        self::assertSame(404, App::create(self::config(), Session::memory(), $this->db, $request)->handle($request)->status);
    }

    // --- Conflits ---------------------------------------------------------------------------------------------------

    public function testConflictsAreLoggedSignalledToTheManagerAndNeverOverwritten(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $this->client()->run();

        // Une ligne en ajout seul existe déjà au central sous le même identifiant, avec un autre contenu.
        $dip = $this->service($this->manager, StockControl::class)->recordDip($ids['tank'], ['depth_cm' => '90', 'dip_type' => 'control'], self::at('13:00:00'));
        $stationRow = $this->db->fetchOne('SELECT * FROM tank_dips WHERE id = :id', ['id' => $dip['id']]);
        $this->central->execute(
            'INSERT INTO tank_dips (id, station_id, tank_id, dip_type, depth_cm, volume_l, measured_at, created_by) VALUES (:id, :s, :t, "control", "95.0", "9500.00", :at, :u)',
            ['id' => $dip['id'], 's' => $this->station, 't' => $ids['tank'], 'at' => self::DAY . ' 13:00:00', 'u' => $this->manager]
        );
        // Une alerte déjà traitée au central ne peut pas redevenir « vue ».
        $alert = (string) $this->db->fetchValue("SELECT id FROM alerts WHERE alert_type = 'cash_shortage'");
        $this->central->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u, resolution_note = 'Soldée au siège' WHERE id = :id", ['u' => $this->owner, 'id' => $alert]);
        $this->db->execute("UPDATE alerts SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = :u WHERE id = :id", ['u' => $this->manager, 'id' => $alert]);

        $push = $this->client()->push();

        self::assertSame(['success', 2], [$push['status'], $push['conflicts']]);
        self::assertSame('9500.00', $this->central->fetchValue('SELECT volume_l FROM tank_dips WHERE id = :id', ['id' => $dip['id']]), 'La ligne du central n\'est pas écrasée');
        self::assertSame($stationRow, $this->db->fetchOne('SELECT * FROM tank_dips WHERE id = :id', ['id' => $dip['id']]), 'Ni celle de la station');
        self::assertSame(['resolved', 'Soldée au siège'], array_values((array) $this->central->fetchOne('SELECT status, resolution_note FROM alerts WHERE id = :id', ['id' => $alert])));
        self::assertSame(2, $this->queue()['conflict']);

        // Consigné dans sync_log des deux côtés.
        $log = $this->db->fetchOne("SELECT * FROM sync_log WHERE direction = 'push' ORDER BY started_at DESC, id DESC LIMIT 1");
        self::assertSame(2, (int) $log['items_conflicted']);
        $details = json_decode((string) $log['details'], true);
        self::assertCount(2, $details);
        self::assertStringContainsString('ajout seul', implode(' ', array_column($details, 'message')));
        self::assertStringContainsString('resolved', implode(' ', array_column($details, 'message')));
        self::assertSame(2, (int) $this->central->fetchValue("SELECT items_conflicted FROM sync_log WHERE direction = 'push' ORDER BY started_at DESC, id DESC LIMIT 1"));

        // Signalé au gérant dans le centre d'alertes de la station.
        $signal = $this->db->fetchOne("SELECT * FROM alerts WHERE alert_type = 'sync_conflict'");
        self::assertSame([$this->station, 'warning', 'open', $log['id']], [$signal['station_id'], $signal['severity'], $signal['status'], $signal['entity_id']]);
        self::assertStringContainsString('2 ligne(s) en conflit', $signal['message']);
        self::assertStringContainsString('2 ligne(s) en conflit', $this->http($this->sessionFor($this->manager), 'GET', '/alerts')->body);
        self::assertStringContainsString('tank_dips', $this->http($this->sessionFor($this->owner), 'GET', '/sync')->body);

        // Un conflit n'est pas renvoyé en boucle.
        $next = $this->client()->push();
        self::assertSame(0, $next['conflicts']);
        self::assertSame(2, $this->queue()['conflict']);
    }

    // --- Prix et taux décidés au central -----------------------------------------------------------------------------

    public function testPricesAndRatesDecidedAtTheCentralComeDownToTheStation(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $this->client()->run();

        // Le propriétaire publie un prix et un taux au central : ni poste à découper ni alerte sur place.
        $pricing = $this->centralService($this->owner, PricingService::class);
        $rate = $pricing->publishRate($this->station, ['rate' => '2320'], self::at('14:00:00'));
        $price = $pricing->publishPrice($ids['product'], ['unit_price' => '5200', 'currency' => 'CDF'], self::at('14:00:00'));
        self::assertSame(0, (int) $this->central->fetchValue("SELECT COUNT(*) FROM alerts WHERE alert_type IN ('price_change', 'rate_change') AND created_at > NOW() - INTERVAL 5 SECOND AND entity_id IN (:a, :b)", ['a' => $rate, 'b' => $price]));
        self::assertNull($this->db->fetchOne('SELECT id FROM prices WHERE id = :id', ['id' => $price]));
        $queued = $this->rowCount($this->db, 'sync_queue');

        $pull = $this->client()->pull();

        self::assertSame(['success', 0], [$pull['status'], $pull['conflicts']]);
        self::assertSame(5200, $this->service($this->manager, PriceResolver::class)->at($ids['product'], self::at('15:00:00'))['unit_price']);
        self::assertSame(5000, $this->service($this->manager, PriceResolver::class)->at($ids['product'], self::at('13:00:00'))['unit_price'], 'Les opérations d\'avant gardent leur prix');
        self::assertSame($this->central->fetchOne('SELECT * FROM exchange_rates WHERE id = :id', ['id' => $rate]), $this->db->fetchOne('SELECT * FROM exchange_rates WHERE id = :id', ['id' => $rate]));
        // Le gérant est prévenu ; les versions reçues ne repartent pas vers le central.
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM alerts WHERE alert_type IN ('price_change', 'rate_change') AND entity_id IN (:a, :b)", ['a' => $rate, 'b' => $price]));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sync_queue WHERE entity_type IN ('prices', 'exchange_rates') AND entity_id IN (:a, :b)", ['a' => $rate, 'b' => $price]));
        self::assertSame($queued + 2, $this->rowCount($this->db, 'sync_queue'), 'Seules les deux alertes sont à remonter');
        self::assertSame('success', $this->client()->pull()['status'], 'Une seconde réception ne change rien');
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM prices WHERE product_id = :p', ['p' => $ids['product']]));
    }

    public function testAPriceFromTheCentralIsNotAppliedBehindAnOpenShift(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $this->client()->run();
        $shift = $this->service($this->manager, ShiftService::class)->open($this->station, ['attendant_id' => $this->attendant], self::at('13:00:00'));
        $price = $this->centralService($this->owner, PricingService::class)->publishPrice($ids['product'], ['unit_price' => '5200', 'currency' => 'CDF'], self::at('14:00:00'));

        $pull = $this->client()->pull();

        // Principe 5 : pas de changement de prix pendant un poste sans relevé intermédiaire.
        self::assertSame(['success', 1], [$pull['status'], $pull['conflicts']]);
        self::assertNull($this->db->fetchOne('SELECT id FROM prices WHERE id = :id', ['id' => $price]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $shift]));
        $log = $this->db->fetchOne("SELECT * FROM sync_log WHERE direction = 'pull' ORDER BY started_at DESC, id DESC LIMIT 1");
        self::assertSame(1, (int) $log['items_conflicted']);
        self::assertStringContainsString('poste est ouvert', (string) $log['details']);
        $alert = $this->db->fetchOne("SELECT * FROM alerts WHERE alert_type = 'sync_conflict' AND entity_id = :id", ['id' => $price]);
        self::assertStringContainsString('relevé intermédiaire', $alert['message']);

        // Passes suivantes, poste toujours ouvert : toujours pas appliqué, et une seule alerte.
        $this->client()->pull();
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM alerts WHERE alert_type = 'sync_conflict' AND entity_id = :id", ['id' => $price]));

        // Poste clôturé : le prix du central s'applique à la passe suivante.
        $this->service($this->attendant, ShiftService::class)->close($shift, ['final' => [$ids['nozzle'] => '200']], self::at('18:00:00'));
        self::assertSame(0, $this->client()->pull()['conflicts']);
        self::assertNotNull($this->db->fetchOne('SELECT id FROM prices WHERE id = :id', ['id' => $price]));
    }

    // --- Qui décide de quoi -----------------------------------------------------------------------------------------

    public function testCentralIsReadOnlyForStationDataAndASyncedStationLeavesAccountsToTheCentral(): void
    {
        $this->client()->pull();
        $ids = $this->activity();
        $this->client()->run();
        $alert = (string) $this->central->fetchValue("SELECT id FROM alerts WHERE alert_type = 'cash_shortage'");

        // Au central : consultation des postes et des alertes, sans écriture sur les données de la station.
        self::assertSame(200, $this->centralHttp($this->owner, 'GET', '/shifts/' . $ids['shift'])->status);
        self::assertStringContainsString("-15\u{202F}000 FC", $this->centralHttp($this->owner, 'GET', '/shifts/' . $ids['shift'] . '/cash')->body);
        foreach (["/alerts/$alert/resolve", "/stations/{$this->station}/products", "/stock/tanks/{$ids['tank']}/dips", "/tanks/{$ids['tank']}/calibrations"] as $path) {
            self::assertSame(409, $this->centralHttp($this->owner, 'POST', $path, ['code' => 'X', 'name' => 'X', 'depth_cm' => '10', 'volume_l' => '10', 'dip_type' => 'control'])->status, $path);
        }
        self::assertSame('open', $this->central->fetchValue('SELECT status FROM alerts WHERE id = :id', ['id' => $alert]));
        self::assertSame(1, $this->rowCount($this->central, 'products'));
        self::assertStringNotContainsString('sync-state', $this->centralHttp($this->owner, 'GET', '/')->body, 'Pas d\'indicateur de file au central');
        self::assertStringContainsString('Reçu de la station', $this->centralHttp($this->owner, 'GET', '/sync')->body);

        // À la station reliée : comptes, droits et fiche de station se gèrent au central.
        $owner = $this->sessionFor($this->owner);
        $role = (string) $this->db->fetchValue("SELECT id FROM roles WHERE code = 'manager'");
        foreach ([
            "/users/{$this->attendant}/password" => ['password' => 'Nouveau-Secret-2026', 'password_confirmation' => 'Nouveau-Secret-2026'],
            "/roles/$role/permissions" => ['permissions' => []],
            "/stations/{$this->station}" => ['code' => 'A', 'name' => 'Renommée', 'timezone' => 'UTC'],
            "/stations/{$this->station}/deactivate" => [],
        ] as $path => $body) {
            self::assertSame(409, $this->http($owner, 'POST', $path, $body)->status, $path);
        }
        self::assertSame('Station A', $this->db->fetchValue('SELECT name FROM stations WHERE id = :id', ['id' => $this->station]));
        self::assertSame(16, (int) $this->db->fetchValue("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.code = 'manager'"));
    }

    // --- Indicateur de l'en-tête --------------------------------------------------------------------------------------

    public function testHeaderShowsOfflinePendingOrSynced(): void
    {
        $this->client()->pull();
        $session = $this->sessionFor($this->manager);
        $state = function () use ($session): string {
            preg_match('#<span class="sync-state sync-state--(\w+)"[^>]*>\s*<i[^>]*></i>\s*<span>([^<]+)</span>#', $this->http($session, 'GET', '/')->body, $m);

            return ($m[1] ?? '?') . ' | ' . ($m[2] ?? '?');
        };
        $this->activity();
        $pending = $this->queue()['pending'];

        self::assertSame("pending | En attente ($pending)", $state());

        $this->network->online = false;
        $this->client()->run();
        self::assertSame('offline | Hors ligne', $state());

        $this->network->online = true;
        $this->client()->run();
        self::assertSame('synced | Synchronisé', $state());

        // Une nouvelle écriture repasse l'indicateur en attente.
        $this->service($this->owner, CatalogService::class)->createProduct($this->station, ['code' => 'MAZ', 'name' => 'Mazout']);
        self::assertMatchesRegularExpression('/^pending \| En attente \(\d+\)$/', $state());

        // Station autonome (pas de central configuré) : pas d'indicateur.
        putenv('SYNC_URL');
        self::assertStringNotContainsString('sync-state', $this->http($session, 'GET', '/')->body);
        putenv('SYNC_URL=https://central.test');
    }

    public function testClientRefusesAnInsecureOrIncompleteConfiguration(): void
    {
        foreach ([
            new SyncConfig('station', $this->station, 'http://central.example.com', self::TOKEN),
            new SyncConfig('station', $this->station, '', self::TOKEN),
            new SyncConfig('station', $this->station, 'https://central.test', ''),
            new SyncConfig('station', '', 'https://central.test', self::TOKEN),
            new SyncConfig('central', $this->station, 'https://central.test', self::TOKEN),
        ] as $config) {
            try {
                (new SyncClient($this->db, $config, $this->network))->run();
                self::fail('Configuration acceptée : ' . $config->url);
            } catch (\RuntimeException $e) {
                self::assertMatchesRegularExpression('/HTTPS|non configurée/', $e->getMessage());
            }
        }
        self::assertSame([], $this->network->requests, 'Aucune donnée n\'est partie');
        self::assertTrue((new SyncConfig('station', $this->station, 'http://localhost/PetraCentral', self::TOKEN))->urlIsAcceptable(), 'HTTP toléré vers la machine locale, pour les essais');
        self::assertTrue((new SyncConfig('station', $this->station, 'http://127.0.0.1:8082', self::TOKEN))->urlIsAcceptable());
    }
}
