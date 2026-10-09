<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Petra\Core\App;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Domain\StationSettings;
use Petra\Maintenance\Backup;
use Petra\Maintenance\BackupTransfer;
use Petra\Maintenance\DailyChecks;
use Petra\Services\ShiftService;
use Petra\Services\StationService;
use Petra\Sync\HttpTransport;
use Petra\Sync\SyncConfig;
use Petra\Sync\TransportException;

/**
 * Scripts de maintenance : sauvegarde et restauration réelles (mysqldump / mysql), copie vers le central,
 * contrôles quotidiens. Jeu d'essai : voir TwoStationsTestCase.
 */
final class MaintenanceTest extends TwoStationsTestCase
{
    private const TOKEN = 'jeton-de-sauvegarde-0123456789abcdef';
    private const RESTORE_DB = 'petra_restore_test';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'petra-backup-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv('BACKUP_DIR');
        $this->db->pdo()->exec('DROP DATABASE IF EXISTS `' . self::RESTORE_DB . '`');
        $remove = static function (string $path) use (&$remove): void {
            foreach (is_dir($path) ? array_diff(scandir($path), ['.', '..']) : [] as $entry) {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
            is_dir($path) ? @rmdir($path) : @unlink($path);
        };
        $remove($this->dir);
    }

    private function backup(int $retention = 30, ?string $dir = null): Backup
    {
        $config = self::config();

        return new Backup($config['db'], $dir ?? $this->dir, $retention, $config['backup']['mysql_bin']);
    }

    // --- Sauvegarde et restauration -------------------------------------------------------------------

    public function testBackupThenRestoreGivesBackTheSameDatabase(): void
    {
        $backup = $this->backup();

        $path = $backup->create(new DateTimeImmutable('2026-10-06 23:30:00'));

        self::assertSame('petra-petra_test-20261006-233000.sql.gz', basename($path));
        self::assertTrue($backup->verify($path));
        self::assertSame("\x1f\x8b", substr((string) file_get_contents($path), 0, 2), 'Fichier compressé (gzip)');
        self::assertSame([], glob($this->dir . '/*.tmp'), 'Pas de fichier temporaire laissé');
        self::assertSame([basename($path)], array_column($backup->list(), 'name'));

        // Procédure de restauration, dans une base d'essai : mêmes tables, mêmes déclencheurs, mêmes lignes.
        $backup->restore($path, self::RESTORE_DB);

        $count = fn (string $what, string $schema): int => (int) $this->value(
            $what === 'tables' ? 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :s' : 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :s',
            ['s' => $schema]
        );
        self::assertSame($count('tables', 'petra_test'), $count('tables', self::RESTORE_DB));
        self::assertSame($count('triggers', 'petra_test'), $count('triggers', self::RESTORE_DB));
        self::assertGreaterThan(40, $count('triggers', self::RESTORE_DB), 'Ajout seul et file de synchronisation restaurés avec la base');
        foreach (['shifts', 'meter_readings', 'cash_handovers', 'tank_dips', 'prices', 'exchange_rates', 'alerts', 'audit_log', 'users', 'sync_queue', 'schema_migrations'] as $table) {
            self::assertSame(
                $this->db->fetchAll("SELECT * FROM `petra_test`.`$table` ORDER BY 1"),
                $this->db->fetchAll('SELECT * FROM `' . self::RESTORE_DB . "`.`$table` ORDER BY 1"),
                $table
            );
        }
        self::assertGreaterThan(0, (int) $this->value('SELECT COUNT(*) FROM `' . self::RESTORE_DB . '`.shifts'));
        // La base restaurée garde ses protections : une opération validée ne s'y modifie toujours pas.
        try {
            $this->db->execute('UPDATE `' . self::RESTORE_DB . '`.cash_handovers SET amount = 1');
            self::fail('Ajout seul perdu à la restauration');
        } catch (\PDOException $e) {
            self::assertStringContainsString('45000', (string) $e->getCode() . $e->getMessage());
        }

        // Restaurer par-dessus un état plus récent le remplace : la base revient à la sauvegarde.
        $this->db->execute('INSERT INTO `' . self::RESTORE_DB . "`.products (id, station_id, code, name) VALUES (:id, :s, 'NEW', 'Après la sauvegarde')", ['id' => Uuid::v7(), 's' => $this->a]);
        $backup->restore($path, self::RESTORE_DB);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM `' . self::RESTORE_DB . "`.products WHERE code = 'NEW'"));
    }

    public function testADamagedBackupIsRefusedBeforeAnythingIsTouched(): void
    {
        $backup = $this->backup();
        $path = $backup->create();
        // Fichier tronqué (copie interrompue, disque plein).
        $cut = $this->dir . DIRECTORY_SEPARATOR . 'petra-petra_test-20260101-000000.sql.gz';
        file_put_contents($cut, substr((string) file_get_contents($path), 0, (int) (filesize($path) / 2)));
        // Fichier compressé valide, mais qui n'est pas une sauvegarde complète.
        $partial = $this->dir . DIRECTORY_SEPARATOR . 'petra-petra_test-20260102-000000.sql.gz';
        file_put_contents($partial, gzencode("CREATE TABLE t (id INT);\n"));

        self::assertFalse($backup->verify($cut));
        self::assertFalse($backup->verify($partial));
        self::assertFalse($backup->verify($this->dir . '/absente.sql.gz'));
        foreach ([$cut, $partial] as $file) {
            try {
                $backup->restore($file, self::RESTORE_DB);
                self::fail('Sauvegarde abîmée restaurée');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('illisible ou incomplète', $e->getMessage());
            }
        }
        self::assertNull($this->db->fetchOne("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . self::RESTORE_DB . "'"), 'Aucune base créée ni modifiée');

        // Nom de base dangereux : refusé.
        $this->expectException(\RuntimeException::class);
        $backup->restore($path, 'petra`; DROP DATABASE petra; --');
    }

    public function testAFailedDumpLeavesNoFile(): void
    {
        $config = self::config();
        $broken = new Backup(['name' => 'base_qui_n_existe_pas'] + $config['db'], $this->dir, 30, $config['backup']['mysql_bin']);
        try {
            $broken->create();
            self::fail('Sauvegarde annoncée alors que mysqldump a échoué');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Sauvegarde échouée', $e->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function testRotationKeepsThirtyDays(): void
    {
        foreach (['20261006-230000', '20261005-230000', '20260907-230000', '20260906-225959', '20260801-230000', '20250101-000000'] as $stamp) {
            file_put_contents($this->dir . "/petra-petra-$stamp.sql.gz", 'x');
        }
        file_put_contents($this->dir . '/notes.txt', 'à ne pas toucher');
        file_put_contents($this->dir . '/petra-petra-20200101-000000.sql', 'pas une sauvegarde compressée');

        $deleted = $this->backup()->rotate(new DateTimeImmutable('2026-10-06 23:00:00'));

        sort($deleted);
        self::assertSame(['petra-petra-20250101-000000.sql.gz', 'petra-petra-20260801-230000.sql.gz', 'petra-petra-20260906-225959.sql.gz'], $deleted);
        self::assertSame(['20261006-230000', '20261005-230000', '20260907-230000'], array_column($this->backup()->list(), 'stamp'), 'Trente jours gardés, les plus récentes d\'abord');
        self::assertFileExists($this->dir . '/notes.txt');
        self::assertFileExists($this->dir . '/petra-petra-20200101-000000.sql');

        // Serveur resté éteint longtemps : la dernière sauvegarde n'est jamais supprimée, même ancienne.
        self::assertSame(['petra-petra-20261005-230000.sql.gz', 'petra-petra-20260907-230000.sql.gz'], $this->backup(7)->rotate(new DateTimeImmutable('2027-06-01 00:00:00')));
        self::assertSame(['20261006-230000'], array_column($this->backup()->list(), 'stamp'));
    }

    // --- Copie vers le central ------------------------------------------------------------------------

    /** Réseau factice vers une application centrale en mémoire, dont le dossier de sauvegarde est $centralDir. */
    private function network(string $centralDir): object
    {
        $central = central_test_database();
        $central->execute('DELETE FROM stations WHERE id = :id', ['id' => $this->a]);
        $central->execute('INSERT IGNORE INTO organizations (id, name) VALUES (:id, :n)', ['id' => $this->org, 'n' => 'Organisation ' . $this->org]);
        $central->execute(
            "INSERT INTO stations (id, organization_id, code, name, sync_token_hash) VALUES (:id, :o, :c, 'Station A', :h)",
            ['id' => $this->a, 'o' => $this->org, 'c' => 'A' . substr($this->a, -6), 'h' => hash('sha256', self::TOKEN)]
        );
        putenv('BACKUP_DIR=' . $centralDir);
        $config = ['mode' => 'central', 'station_id' => '', 'sync' => ['url' => '', 'token' => '']] + self::config();

        return new class (static fn (Request $r): Response => App::create($config, Session::memory(), $central, $r)->handle($r)) implements HttpTransport {
            public bool $online = true;
            public int $calls = 0;
            /** Modifie le corps décodé avant remise (pour simuler un morceau abîmé). */
            public ?\Closure $tamper = null;

            public function __construct(private readonly \Closure $central)
            {
            }

            public function post(string $url, array $headers, string $body): array
            {
                if (!$this->online) {
                    throw new TransportException('Could not resolve host');
                }
                $this->calls++;
                $payload = json_decode($body, true);
                if ($this->tamper !== null) {
                    $payload = ($this->tamper)($payload);
                }
                $response = ($this->central)(new Request('POST', (string) parse_url($url, PHP_URL_PATH), [], $payload, array_change_key_case($headers) + ['content-type' => 'application/json']));

                return ['status' => $response->status, 'body' => $response->body];
            }
        };
    }

    public function testBackupIsCopiedToTheCentralInChunksAndVerified(): void
    {
        $centralDir = $this->dir . DIRECTORY_SEPARATOR . 'central';
        $network = $this->network($centralDir);
        $config = new SyncConfig('station', $this->a, 'https://central.test', self::TOKEN);
        $transfer = new BackupTransfer($this->dir);
        // Fichier de plus de deux morceaux, au contenu incompressible.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'petra-petra-20261006-230000.sql.gz';
        file_put_contents($path, random_bytes(BackupTransfer::CHUNK * 2 + 12345));
        $stored = $centralDir . DIRECTORY_SEPARATOR . 'stations' . DIRECTORY_SEPARATOR . $this->a . DIRECTORY_SEPARATOR . basename($path);

        self::assertSame('sent', $transfer->send($path, $config, $network));

        self::assertSame(3, $network->calls);
        self::assertFileExists($stored);
        self::assertSame(hash_file('sha256', $path), hash_file('sha256', $stored), 'Copie identique à l\'original');
        self::assertSame([], glob(dirname($stored) . '/*.part'));

        // Renvoi du même fichier (tâche relancée) : remplacé, pas doublé ni corrompu.
        self::assertSame('sent', $transfer->send($path, $config, $network));
        self::assertSame(hash_file('sha256', $path), hash_file('sha256', $stored));
        self::assertCount(1, Backup::listDir(dirname($stored)));

        // Central injoignable : la sauvegarde reste locale, sans erreur.
        $network->online = false;
        self::assertSame('offline', $transfer->send($path, $config, $network));
        $network->online = true;
        // Station non reliée à un central, ou adresse non chiffrée : rien n'est envoyé.
        $calls = $network->calls;
        self::assertSame('skipped', $transfer->send($path, new SyncConfig('station', '', '', ''), $network));
        self::assertSame('skipped', $transfer->send($path, new SyncConfig('station', $this->a, 'http://central.example.com', self::TOKEN), $network));
        self::assertSame($calls, $network->calls);

        // Rotation côté central aussi : une copie de plus de trente jours disparaît à l'arrivée d'une nouvelle.
        $old = dirname($stored) . DIRECTORY_SEPARATOR . 'petra-petra-20200101-000000.sql.gz';
        file_put_contents($old, 'x');
        $transfer->send($path, $config, $network);
        self::assertFileDoesNotExist($old);
    }

    public function testCentralRefusesABackupItCannotTrust(): void
    {
        $centralDir = $this->dir . DIRECTORY_SEPARATOR . 'central';
        $network = $this->network($centralDir);
        $transfer = new BackupTransfer($this->dir);
        $path = $this->dir . DIRECTORY_SEPARATOR . 'petra-petra-20261006-230000.sql.gz';
        file_put_contents($path, random_bytes(BackupTransfer::CHUNK + 10));
        $send = function (SyncConfig $config) use ($transfer, $path, $network): string {
            try {
                return $transfer->send($path, $config, $network);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
        };
        $good = new SyncConfig('station', $this->a, 'https://central.test', self::TOKEN);

        // Mauvais jeton, ou jeton présenté pour une autre station.
        self::assertStringContainsString('401', $send(new SyncConfig('station', $this->a, 'https://central.test', 'faux')));
        self::assertStringContainsString('401', $send(new SyncConfig('station', $this->b, 'https://central.test', self::TOKEN)));
        // Morceau abîmé en route : l'empreinte ne correspond plus, le fichier partiel est jeté.
        $network->tamper = static fn (array $p): array => ['data' => base64_encode(str_repeat('x', strlen((string) base64_decode($p['data']))))] + $p;
        self::assertStringContainsString('422', $send($good));
        // Nom de fichier qui sortirait du dossier, morceau hors séquence.
        $network->tamper = static fn (array $p): array => ['name' => '../../public/index.php'] + $p;
        self::assertStringContainsString('400', $send($good));
        $network->tamper = static fn (array $p): array => ['index' => $p['index'] + 5] + $p;
        self::assertStringContainsString('409', $send($good));

        $files = [];
        foreach (is_dir($centralDir) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($centralDir, \FilesystemIterator::SKIP_DOTS)) : [] as $file) {
            $files[] = $file->getFilename();
        }
        self::assertSame([], $files, 'Rien n\'est conservé d\'un envoi refusé');
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/public/index.php.part');

        // Sur un serveur de station, cette API n'existe pas.
        $request = new Request('POST', '/api/sync/backup', [], ['name' => basename($path), 'index' => 0, 'data' => '', 'last' => true], ['authorization' => 'Bearer ' . self::TOKEN, 'x-station-id' => $this->a]);
        self::assertSame(404, App::create(self::config(), Session::memory(), $this->db, $request)->handle($request)->status);
    }

    // --- Contrôles quotidiens ---------------------------------------------------------------------------

    private function checks(string $mode = 'station', string $stationId = '', string $url = '', string $token = ''): DailyChecks
    {
        return new DailyChecks($this->db, new SyncConfig($mode, $stationId, $url, $token));
    }

    private function alerts(string $type): array
    {
        return $this->db->fetchAll('SELECT station_id, severity, entity_type, entity_id, message, shift_id, user_id, threshold_value, status FROM alerts WHERE alert_type = :t ORDER BY created_at, id', ['t' => $type]);
    }

    public function testShiftLeftOpenRaisesOneAlert(): void
    {
        $shift = $this->service($this->managerA, ShiftService::class)->open($this->a, ['attendant_id' => $this->attendant1], self::at('06', '14:00'));

        // Dans le délai (16 h par défaut) : rien.
        self::assertSame([], $this->checks()->run(new DateTimeImmutable('2026-10-07 05:59:00')));

        $raised = $this->checks()->run(new DateTimeImmutable('2026-10-07 06:00:00'));

        self::assertSame([['shift_not_closed', 'Station A']], array_map(static fn (array $a): array => [$a['type'], $a['station']], $raised));
        self::assertStringContainsString('06/10/2026 14:00', $raised[0]['message']);
        $alert = $this->alerts('shift_not_closed')[0];
        self::assertSame([$this->a, 'warning', 'shift', $shift, $shift, $this->attendant1, '16 h', 'open'], [$alert['station_id'], $alert['severity'], $alert['entity_type'], $alert['entity_id'], $alert['shift_id'], $alert['user_id'], $alert['threshold_value'], $alert['status']]);
        self::assertStringContainsString('toujours pas clôturé', $this->http($this->sessionFor($this->managerA), 'GET', '/alerts')->body);

        // Le lendemain, alerte vue mais non traitée : pas de doublon. Traitée et poste toujours ouvert : elle revient.
        $this->db->execute("UPDATE alerts SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = :u WHERE alert_type = 'shift_not_closed'", ['u' => $this->managerA]);
        self::assertSame([], $this->checks()->run(new DateTimeImmutable('2026-10-08 06:00:00')));
        $this->db->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u WHERE alert_type = 'shift_not_closed'", ['u' => $this->managerA]);
        self::assertCount(1, $this->checks()->run(new DateTimeImmutable('2026-10-09 06:00:00')));
        self::assertCount(2, $this->alerts('shift_not_closed'));
    }

    public function testDelaysAreSetPerStation(): void
    {
        $this->service($this->managerA, ShiftService::class)->open($this->a, ['attendant_id' => $this->attendant1], self::at('06', '14:00'));
        $thresholds = array_map(static fn (array $d): string => $d[1], StationSettings::THRESHOLDS);

        // Le propriétaire porte le délai de la station A à 48 h : le poste ouvert depuis 18 h n'alerte plus.
        $this->service($this->owner, StationService::class)->saveThresholds($this->a, $thresholds + ['shift_max_open_hours' => '48']);
        self::assertSame('48', $this->value("SELECT setting_value FROM station_settings WHERE station_id = :s AND setting_key = 'shift_max_open_hours'", ['s' => $this->a]));
        self::assertSame([], $this->checks()->run(new DateTimeImmutable('2026-10-07 08:00:00')));
        self::assertCount(1, $this->checks()->run(new DateTimeImmutable('2026-10-08 14:00:00')));

        // Enregistrer les seuils sans les délais garde ceux en place ; une valeur hors bornes est refusée.
        $this->service($this->owner, StationService::class)->saveThresholds($this->a, $thresholds);
        self::assertSame(['shift_max_open_hours' => '48', 'sync_max_delay_hours' => '24'], StationSettings::delays(array_column($this->db->fetchAll('SELECT setting_key, setting_value FROM station_settings WHERE station_id = :s', ['s' => $this->a]), 'setting_value', 'setting_key')));
        $page = $this->http($this->sessionFor($this->owner), 'GET', '/stations/' . $this->a);
        self::assertMatchesRegularExpression('/name="shift_max_open_hours"[^>]*value="48"/', $page->body);
        self::assertMatchesRegularExpression('/name="sync_max_delay_hours"[^>]*value="24"/', $page->body);
        foreach (['0', '169', 'abc', '1.5'] as $bad) {
            try {
                $this->service($this->owner, StationService::class)->saveThresholds($this->a, $thresholds + ['shift_max_open_hours' => $bad]);
                self::fail("Délai accepté : $bad");
            } catch (\Petra\Core\ValidationException) {
            }
        }
        self::assertSame(['shift_max_open_hours' => '16', 'sync_max_delay_hours' => '24'], StationSettings::delays(['shift_max_open_hours' => 'n\'importe quoi']));
    }

    public function testLowTheoreticalStockRaisesAnAlert(): void
    {
        // Stock théorique de la cuve essence de A au 06/10 au soir : 9 750 L (jaugé 9 800 L, puis 50 L débités).
        self::assertSame([], $this->checks()->run(self::at('06', '18:00')), 'Au-dessus du seuil par défaut (3 000 L)');

        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (:id, :s, 'low_stock_l', '9750.00')", ['id' => Uuid::v7(), 's' => $this->a]);
        $raised = $this->checks()->run(self::at('06', '18:00'));

        self::assertSame([['low_stock', 'Station A']], array_map(static fn (array $a): array => [$a['type'], $a['station']], $raised));
        self::assertStringContainsString("C-ESS (Essence) : stock théorique de 9\u{202F}750,00 L, sous le seuil de 9\u{202F}750,00 L", $raised[0]['message']);
        $alert = $this->alerts('low_stock')[0];
        self::assertSame([$this->a, 'warning', 'tank', $this->tankA, '9750.00'], [$alert['station_id'], $alert['severity'], $alert['entity_type'], $alert['entity_id'], $alert['threshold_value']]);
        // Cuves jamais jaugées (gaz de A, gasoil de B) : pas de stock théorique, donc pas d'alerte ; pas de doublon au passage suivant.
        self::assertSame([], $this->checks()->run(self::at('06', '19:00')));
        self::assertCount(1, $this->alerts('low_stock'));
    }

    public function testCentralFlagsStationsNotSyncedForADay(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $this->db->queueing(false);
        // A : reliée, dernière synchronisation il y a 25 h. B : reliée, synchronisée il y a 2 h.
        $this->db->execute("UPDATE stations SET sync_token_hash = :h, last_sync_at = '2026-10-06 11:00:00' WHERE id = :id", ['h' => hash('sha256', 'a'), 'id' => $this->a]);
        $this->db->execute("UPDATE stations SET sync_token_hash = :h, last_sync_at = '2026-10-07 10:00:00' WHERE id = :id", ['h' => hash('sha256', 'b'), 'id' => $this->b]);
        // C : reliée mais jamais synchronisée. D : pas encore reliée (pas de jeton). E : désactivée.
        $c = $this->createStation($this->org, 'C');
        $d = $this->createStation($this->org, 'D');
        $e = $this->createStation($this->org, 'E');
        $this->db->execute('UPDATE stations SET sync_token_hash = :h WHERE id IN (:c, :e)', ['h' => hash('sha256', 'c'), 'c' => $c, 'e' => $e]);
        $this->db->execute('UPDATE stations SET is_active = 0 WHERE id = :e', ['e' => $e]);
        $this->db->queueing(true);
        // Un poste resté ouvert et un stock bas existent aussi : au central, ce n'est pas son contrôle.
        $this->service($this->managerA, ShiftService::class)->open($this->a, ['attendant_id' => $this->attendant1], self::at('06', '14:00'));
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (:id, :s, 'low_stock_l', '20000.00')", ['id' => Uuid::v7(), 's' => $this->a]);

        $raised = $this->checks('central')->run($now);

        self::assertSame(['sync_stale', 'sync_stale'], array_column($raised, 'type'));
        $alerts = array_column($this->alerts('sync_stale'), null, 'station_id');
        self::assertSame([$this->a, $c], array_keys($alerts));
        self::assertSame(['critical', 'station', $this->a, '24 h'], [$alerts[$this->a]['severity'], $alerts[$this->a]['entity_type'], $alerts[$this->a]['entity_id'], $alerts[$this->a]['threshold_value']]);
        self::assertStringContainsString('aucune synchronisation réussie depuis le 06/10/2026 11:00', $alerts[$this->a]['message']);
        self::assertStringContainsString("n'a encore eu lieu", $alerts[$c]['message']);
        self::assertSame([], $this->alerts('shift_not_closed'));
        self::assertSame([], $this->alerts('low_stock'));
        self::assertSame([], $this->checks('central')->run($now->modify('+1 hour')), 'Pas de doublon tant que l\'alerte n\'est pas traitée');

        // Délai porté à 48 h pour la station A : une fois l'alerte traitée, elle ne revient qu'au-delà.
        $this->db->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u WHERE alert_type = 'sync_stale'", ['u' => $this->owner]);
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (:id, :s, 'sync_max_delay_hours', '48')", ['id' => Uuid::v7(), 's' => $this->a]);
        self::assertSame(['Station C'], array_column($this->checks('central')->run($now), 'station'));
    }

    public function testASyncedStationFlagsItsOwnSilence(): void
    {
        $synced = fn (): DailyChecks => $this->checks('station', $this->a, 'https://central.test', self::TOKEN);
        $log = function (string $status, string $at): void {
            $this->db->execute(
                "INSERT INTO sync_log (id, station_id, direction, status, started_at, finished_at) VALUES (:id, :s, 'push', :st, :at, :at2)",
                ['id' => Uuid::v7(), 's' => $this->a, 'st' => $status, 'at' => $at, 'at2' => $at]
            );
        };
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $log('success', '2026-10-06 11:00:00');
        $log('offline', '2026-10-07 11:55:00');   // des tentatives récentes, mais hors ligne

        $raised = $synced()->run($now);

        self::assertSame([['sync_stale', 'Station A']], array_map(static fn (array $a): array => [$a['type'], $a['station']], $raised));
        self::assertSame([$this->a], array_column($this->alerts('sync_stale'), 'station_id'), 'Ce serveur ne surveille que sa station');

        // Synchronisation revenue : plus d'alerte nouvelle. Station autonome (sans central) : contrôle sans objet.
        $this->db->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u WHERE alert_type = 'sync_stale'", ['u' => $this->owner]);
        $log('success', '2026-10-07 11:58:00');
        self::assertSame([], $synced()->run($now));
        $this->db->execute("DELETE FROM sync_log WHERE status = 'success'");
        self::assertSame([], $this->checks()->run($now));
    }
}
