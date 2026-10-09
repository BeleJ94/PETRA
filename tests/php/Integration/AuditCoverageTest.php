<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDOException;
use Petra\Core\I18n;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Repositories\AuditLogRepository;
use Petra\Repositories\Repository;
use Petra\Security\Auth;
use Petra\Services\AuditLogger;
use Petra\Services\CashControl;
use Petra\Services\CatalogService;
use Petra\Services\DeliveryService;
use Petra\Services\PricingService;
use Petra\Services\RoleService;
use Petra\Services\ShiftService;
use Petra\Services\StationService;
use Petra\Services\StockControl;
use Petra\Services\UserService;
use Tests\DatabaseTestCase;

/** Journal d'audit : inaltérable, complet, et alimenté par toutes les opérations sensibles. */
final class AuditCoverageTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $attendant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner', [], 'proprio');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station], 'gerant');
        $this->attendant = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste');
    }

    protected function tearDown(): void
    {
        $this->restoreReferenceData();
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    /** @return list<string> couples « entité:action » présents au journal */
    private function pairs(): array
    {
        $pairs = array_column($this->db->fetchAll("SELECT DISTINCT CONCAT(entity_type, ':', action) AS p FROM audit_log"), 'p');
        sort($pairs);

        return $pairs;
    }

    // --- Inaltérable ----------------------------------------------------------------------

    public function testDatabaseRefusesAnyUpdateOrDeleteOnTheAuditLog(): void
    {
        $this->service($this->owner, AuditLogger::class)->log('update', 'station', $this->station, $this->station, ['name' => 'A'], ['name' => 'B'], 'Essai');
        $before = $this->row('SELECT * FROM audit_log');

        foreach ([
            "UPDATE audit_log SET summary = 'réécrit'", 'UPDATE audit_log SET created_by = NULL', "UPDATE audit_log SET after_data = '{}'",
            'DELETE FROM audit_log', "DELETE FROM audit_log WHERE action = 'update'",
        ] as $sql) {
            try {
                $this->db->execute($sql);
                self::fail("Accepté : $sql");
            } catch (PDOException $e) {
                self::assertStringContainsString('audit_log: ajout seul', $e->getMessage());
            }
        }
        self::assertSame($before, $this->row('SELECT * FROM audit_log'));
        self::assertSame(
            ['audit_log:DELETE', 'audit_log:UPDATE'],
            array_column($this->db->fetchAll("SELECT CONCAT(EVENT_OBJECT_TABLE, ':', EVENT_MANIPULATION) k FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log' AND ACTION_TIMING = 'BEFORE' ORDER BY k"), 'k')
        );
    }

    public function testNoPhpCodeCanModifyOrDeleteAuditEntries(): void
    {
        // Le repository n'offre que l'ajout et la lecture.
        $own = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(AuditLogRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => !method_exists(Repository::class, $name) || $name === 'insert'
        ));
        sort($own);
        self::assertSame(['datatable', 'forEntity', 'insert'], $own);
        self::assertSame(['log', 'diff'], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), array_values(array_filter(
            (new \ReflectionClass(AuditLogger::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => !$m->isConstructor()
        ))));

        // Nulle part dans l'application une requête ne modifie ni ne vide audit_log.
        $root = dirname(__DIR__, 3);
        foreach (new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app')), '/\.php$/') as $file) {
            $source = (string) file_get_contents((string) $file);
            self::assertDoesNotMatchRegularExpression('/(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?|REPLACE\s+INTO|DROP\s+TABLE)\s+`?audit_log/i', $source, basename((string) $file));
        }
        foreach (glob($root . '/bin/*.php') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/audit_log/i', (string) file_get_contents($file), basename($file));
        }
    }

    // --- Contenu d'une entrée -----------------------------------------------------------------

    public function testAnEntryRecordsUserStationActionEntityOldAndNewValuesIpAndDate(): void
    {
        $session = $this->sessionFor($this->owner);
        $this->http($session, 'POST', "/stations/{$this->station}", ['code' => 'A', 'name' => 'Station Centre', 'city' => 'Likasi', 'timezone' => 'Africa/Lubumbashi'], ['user-agent' => 'Tablette station']);

        $row = $this->row("SELECT * FROM audit_log WHERE entity_type = 'station'");
        self::assertTrue(Uuid::isValid($row['id']));
        self::assertSame($this->owner, $row['created_by'], 'Utilisateur');
        self::assertSame('Owner Test', $row['user_name'], 'Nom conservé même si le compte change');
        self::assertSame($this->station, $row['station_id'], 'Station');
        self::assertSame(['update', 'station', $this->station], [$row['action'], $row['entity_type'], $row['entity_id']]);
        self::assertSame(['name' => 'Station A', 'city' => null], json_decode((string) $row['before_data'], true), 'Ancienne valeur, en JSON');
        self::assertSame(['name' => 'Station Centre', 'city' => 'Likasi'], json_decode((string) $row['after_data'], true), 'Nouvelle valeur, en JSON');
        self::assertSame('127.0.0.1', $row['ip_address']);
        self::assertSame('Tablette station', $row['user_agent']);
        self::assertEqualsWithDelta(time(), strtotime((string) $row['created_at']), 5, 'Date');
    }

    public function testSecretsNeverReachTheLog(): void
    {
        $this->service($this->owner, UserService::class)->resetPassword($this->attendant, ['password' => 'Nouveau-Secret-2026', 'password_confirmation' => 'Nouveau-Secret-2026']);
        $this->service($this->owner, AuditLogger::class)->log('update', 'user', $this->attendant, null, ['password_hash' => 'ancien'], ['password_hash' => 'nouveau', 'password' => 'clair', '_csrf' => 'jeton']);

        $dump = json_encode($this->db->fetchAll('SELECT * FROM audit_log'), JSON_UNESCAPED_UNICODE);
        foreach (['Nouveau-Secret-2026', 'argon2id', 'ancien', 'nouveau', 'clair', 'jeton'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $dump);
        }
        self::assertStringContainsString('***', (string) $dump);
    }

    // --- Toutes les opérations sensibles -----------------------------------------------------------

    /**
     * Joue une fois chaque opération sensible des modules existants, puis vérifie qu'aucune ne manque au journal.
     */
    public function testEverySensitiveOperationWritesToTheAuditLog(): void
    {
        // Authentification et droits.
        $session = Session::memory();
        $this->http($session, 'GET', '/login');
        $this->http($session, 'POST', '/login', ['username' => 'gerant', 'password' => 'faux']);
        $this->http($session, 'POST', '/login', ['username' => 'gerant', 'password' => self::PASSWORD]);
        $this->http($session, 'GET', '/users');                         // accès refusé
        $this->http($session, 'POST', '/logout');
        for ($i = 0; $i < 5; $i++) {
            $anonymous = Session::memory();
            $this->http($anonymous, 'GET', '/login');
            $this->http($anonymous, 'POST', '/login', ['username' => 'pompiste', 'password' => 'faux']);
        }
        $this->service($this->owner, UserService::class)->resetPassword($this->attendant, ['password' => self::PASSWORD . '!', 'password_confirmation' => self::PASSWORD . '!']);
        $roleId = (string) $this->value("SELECT id FROM roles WHERE code = 'attendant'");
        $this->service($this->owner, RoleService::class)->setPermissions($roleId, ['shifts.view', 'shifts.record']);

        // Stations et installation.
        $stations = $this->service($this->owner, StationService::class);
        $second = $stations->create(['code' => 'B', 'name' => 'Station B', 'timezone' => 'Africa/Lubumbashi']);
        // Requête suivante : la nouvelle station fait partie des stations du propriétaire.
        $stations = $this->service($this->owner, StationService::class);
        $stations->update($second, ['code' => 'B', 'name' => 'Station Bis', 'timezone' => 'Africa/Lubumbashi']);
        $stations->setActive($second, false);
        $stations->setActive($second, true);
        $stations->saveSchedule($this->station, ['timezone' => 'Africa/Kinshasa', 'slots' => [['label' => 'Jour', 'start' => '06:00', 'end' => '18:00']]]);
        $stations->saveThresholds($this->station, ['stock_tolerance_pct' => '1', 'stock_tolerance_l' => '60', 'low_stock_l' => '2000', 'cash_shortage_tolerance_cdf' => '1000', 'cash_surplus_tolerance_cdf' => '1000', 'delivery_variance_l' => '50']);

        $catalog = $this->service($this->owner, CatalogService::class);
        $spare = $catalog->createProduct($this->station, ['code' => 'PET', 'name' => 'Pétrole']);
        $catalog->setProductActive($spare, false);
        $catalog->setProductActive($spare, true);
        $product = $catalog->createProduct($this->station, ['code' => 'ESS', 'name' => 'Essence']);
        $tank = $catalog->createTank($this->station, ['product_id' => $product, 'code' => 'C-ESS', 'name' => 'Cuve', 'capacity_l' => '30000']);
        $catalog->updateTank($tank, ['code' => 'C-ESS', 'name' => 'Cuve Essence', 'capacity_l' => '30000', 'is_active' => '1']);
        $nozzle = $catalog->createNozzle($this->station, ['pump_id' => $this->pumpFor($this->station), 'tank_id' => $tank, 'code' => 'P-ESS', 'name' => 'Pistolet']);
        $catalog->updatePump($catalog->createPump($this->station, ['code' => 'D9', 'name' => 'Distributeur 9']), ['code' => 'D9', 'name' => 'Distributeur neuf', 'is_active' => '1']);
        $catalog->updateNozzle($nozzle, ['tank_id' => $tank, 'code' => 'P-ESS', 'name' => 'Pistolet 1', 'is_active' => '1']);
        $catalog->saveCalibrationPoint($tank, ['depth_cm' => '0', 'volume_l' => '0']);
        $catalog->saveCalibrationPoint($tank, ['depth_cm' => '0', 'volume_l' => '5']);
        $csv = tempnam(sys_get_temp_dir(), 'petra-csv-');
        file_put_contents($csv, "0;0\n300;30000\n");
        $catalog->importCalibrations($tank, ['tmp_name' => $csv, 'error' => UPLOAD_ERR_OK]);

        // Prix et taux.
        $pricing = $this->service($this->owner, PricingService::class);
        $pricing->publishRate($this->station, ['rate' => '2300'], self::at('05:00:00'));
        $pricing->publishPrice($product, ['unit_price' => '5000', 'currency' => 'CDF'], self::at('05:00:00'));

        // Stock, livraison, poste, caisse.
        $this->service($this->manager, StockControl::class)->recordDip($tank, ['depth_cm' => '100', 'dip_type' => 'control'], self::at('05:30:00'));
        $this->service($this->manager, DeliveryService::class)->receive($this->station, [
            'tank_id' => $tank, 'supplier_id' => $this->createSupplier($this->station), 'invoiced_volume_l' => '5000', 'received_volume_l' => '5000',
            'before_cm' => '100', 'after_cm' => '150', 'received_by' => $this->manager,
        ], null, self::at('05:45:00'));

        $shift = $this->service($this->manager, ShiftService::class)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$nozzle => '0']], self::at('06:00:00'));
        $this->service($this->attendant, ShiftService::class)->addOutput($shift, ['nozzle_id' => $nozzle, 'output_type' => 'test', 'volume_l' => '5', 'reason' => 'Essai'], self::at('07:00:00'));
        $this->service($this->attendant, ShiftService::class)->close($shift, ['final' => [$nozzle => '105']], self::at('12:00:00'));
        $cash = $this->service($this->manager, CashControl::class);
        $cash->addCreditSale($shift, ['nozzle_id' => $nozzle, 'customer_name' => 'Mining Co', 'volume_l' => '10'], self::at('12:05:00'));
        $cash->addExpense($shift, ['category' => 'transport', 'description' => 'Course banque', 'amount' => '5000', 'currency' => 'CDF'], self::at('12:06:00'));
        $cash->addHandover($shift, ['amount' => '430000', 'currency' => 'CDF'], self::at('12:10:00'));
        $this->service($this->manager, ShiftService::class)->validate($shift, [], self::at('12:30:00'));
        $cash->justify($shift, ['amount' => '15000', 'reason' => 'Billets refusés par la banque']);

        // Alertes.
        $alert = (string) $this->value("SELECT id FROM alerts WHERE alert_type = 'cash_shortage'");
        $managerSession = $this->sessionFor($this->manager);
        $this->http($managerSession, 'POST', "/alerts/$alert/acknowledge");
        $this->http($managerSession, 'POST', "/alerts/$alert/resolve", ['resolution_note' => 'Justifié']);

        $expected = [
            'alert:acknowledge', 'alert:resolve',
            'cash_expense:create', 'cash_handover:create', 'credit_sale:create',
            'delivery:create',
            'exchange_rate:publish',
            'non_sale_output:create',
            'nozzle:create', 'nozzle:update',
            'price:publish',
            'product:activate', 'product:create', 'product:deactivate',
            'pump:create', 'pump:update',
            'role:permissions',
            'route:access_denied',
            'shift:close', 'shift:open', 'shift:validate',
            'station:activate', 'station:create', 'station:deactivate', 'station:update',
            'station_schedule:update', 'station_settings:update',
            'tank:create', 'tank:update',
            'tank_calibration:create', 'tank_calibration:import', 'tank_calibration:update',
            'tank_dip:create',
            'user:login', 'user:login_failed', 'user:login_locked', 'user:logout', 'user:password_reset',
            'variance_justification:create',
        ];
        sort($expected);
        self::assertSame($expected, $this->pairs());

        // Aucune écriture métier sans auteur : seules les tentatives de connexion peuvent précéder l'identification.
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE created_by IS NULL"));
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE station_id IS NULL AND entity_type NOT IN ('user', 'role', 'station')"));
    }

    /** Toute action et toute entité écrites par le code figurent dans les listes de référence, avec un libellé. */
    public function testEveryLoggedActionAndEntityIsListedAndLabelled(): void
    {
        $root = dirname(__DIR__, 3);
        $i18n = new I18n($root . '/lang');
        $found = [];
        foreach (new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app')), '/\.php$/') as $file) {
            preg_match_all("/logger->log\\(\\s*(?:[^,']*\\?\\s*)?'([a-z_]+)'(?:\\s*:\\s*'([a-z_]+)')?,\\s*'([a-z_]+)'/", (string) file_get_contents((string) $file), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $found[] = [$match[1], $match[3]];
                if ($match[2] !== '') {
                    $found[] = [$match[2], $match[3]];
                }
            }
        }
        self::assertGreaterThan(30, count($found), 'Les appels au journal sont bien repérés');
        foreach ($found as [$action, $entity]) {
            self::assertContains($action, AuditLogger::ACTIONS, "Action non listée : $action");
            self::assertContains($entity, AuditLogger::ENTITIES, "Entité non listée : $entity");
        }
        foreach (AuditLogger::ACTIONS as $action) {
            self::assertTrue($i18n->has('action.' . $action), 'action.' . $action);
        }
        foreach (AuditLogger::ENTITIES as $entity) {
            self::assertTrue($i18n->has('entity.' . $entity), 'entity.' . $entity);
        }
    }

    // --- Consultation ---------------------------------------------------------------------------------

    public function testAuditScreenIsReadOnlyFilteredAndScopedToTheOrganization(): void
    {
        $other = $this->createStation($this->org, 'B');
        $foreignOrg = $this->createOrganization('Concurrent');
        $foreignStation = $this->createStation($foreignOrg, 'X');
        $foreignOwner = $this->createUser($foreignOrg, 'owner');
        $this->service($this->owner, StationService::class)->saveThresholds($this->station, ['stock_tolerance_pct' => '1', 'stock_tolerance_l' => '60', 'low_stock_l' => '2000', 'cash_shortage_tolerance_cdf' => '1000', 'cash_surplus_tolerance_cdf' => '1000', 'delivery_variance_l' => '50']);
        $this->service($this->owner, StationService::class)->update($other, ['code' => 'B', 'name' => 'Station Bis', 'timezone' => 'Africa/Lubumbashi']);
        $this->service($foreignOwner, StationService::class)->update($foreignStation, ['code' => 'X', 'name' => 'Station secrète', 'timezone' => 'Africa/Lubumbashi']);
        $login = Session::memory();
        $this->http($login, 'GET', '/login');
        $this->http($login, 'POST', '/login', ['username' => 'gerant', 'password' => self::PASSWORD]);

        $session = $this->sessionFor($this->owner);
        $page = $this->http($session, 'GET', '/audit');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-table="audit"', $page->body);
        self::assertStringNotContainsString('<form method="post"', str_replace(['action="/logout"', 'action="/context/station"'], '', preg_replace('#<form method="post" action="/(logout|context/station)".*?</form>#s', '', $page->body)), 'Aucune action d\'écriture sur cet écran');

        $all = json_decode($this->http($session, 'GET', '/audit/data?draw=5&length=50', [], self::AJAX)->body, true);
        self::assertSame(5, $all['draw']);
        self::assertSame(3, $all['recordsTotal'], 'Deux stations de l\'organisation + la connexion, sans l\'autre organisation');
        self::assertNotContains('Station secrète', array_column($all['data'], 'summary'));
        $row = array_values(array_filter($all['data'], static fn (array $r): bool => $r['entity_type'] === 'station_settings'))[0];
        self::assertSame(['Owner Test', 'Station A', 'update'], [$row['user_name'], $row['station'], $row['action']]);
        self::assertSame('60.00', json_decode((string) $row['after_data'], true)['stock_tolerance_l']);
        self::assertSame('50.00', json_decode((string) $row['before_data'], true)['stock_tolerance_l']);

        self::assertSame(['login'], array_column(json_decode($this->http($session, 'GET', '/audit/data?entity_type=user', [], self::AJAX)->body, true)['data'], 'action'));
        self::assertSame(2, json_decode($this->http($session, 'GET', '/audit/data?action=update', [], self::AJAX)->body, true)['recordsFiltered']);
        self::assertSame(3, json_decode($this->http($session, 'GET', '/audit/data?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'), [], self::AJAX)->body, true)['recordsFiltered']);
        self::assertSame(0, json_decode($this->http($session, 'GET', '/audit/data?to=' . date('Y-m-d', time() - 86400), [], self::AJAX)->body, true)['recordsFiltered']);
        self::assertSame(1, json_decode($this->http($session, 'GET', '/audit/data?search[value]=Bis', [], self::AJAX)->body, true)['recordsFiltered']);

        // Station active : son journal seulement.
        $session->set(Auth::SESSION_STATION, $other);
        self::assertSame(['Station Bis'], array_column(json_decode($this->http($session, 'GET', '/audit/data', [], self::AJAX)->body, true)['data'], 'station'));

        // Gérant et pompiste n'ont pas accès au journal ; aucune route n'écrit dedans.
        foreach ([$this->manager, $this->attendant] as $user) {
            self::assertSame(403, $this->http($this->sessionFor($user), 'GET', '/audit')->status);
            self::assertSame(403, $this->http($this->sessionFor($user), 'GET', '/audit/data', [], self::AJAX)->status);
        }
        self::assertSame(405, $this->http($this->sessionFor($this->owner), 'POST', '/audit')->status);
    }
}
