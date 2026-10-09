<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Petra\Core\Database;
use Petra\Core\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Le schéma issu des migrations respecte les principes métier non négociables. */
final class SchemaTest extends TestCase
{
    private const TABLES = [
        'organizations', 'stations', 'products', 'tanks', 'tank_calibrations', 'nozzles', 'users', 'roles', 'permissions',
        'role_permissions', 'user_stations', 'prices', 'exchange_rates', 'shifts', 'shift_segments', 'meter_readings',
        'cash_handovers', 'non_sale_outputs', 'credit_sales', 'cash_expenses', 'deliveries', 'tank_dips', 'alerts',
        'audit_log', 'station_settings', 'sync_queue', 'sync_log', 'variance_justifications', 'suppliers', 'pumps',
    ];
    /** Tables sans station : l'organisation, la station elle-même et le référentiel des droits. */
    private const WITHOUT_STATION = ['organizations', 'stations', 'users', 'roles', 'permissions', 'role_permissions'];
    private const OPERATIONS = ['meter_readings', 'cash_handovers', 'non_sale_outputs', 'credit_sales', 'cash_expenses', 'deliveries', 'tank_dips', 'variance_justifications'];
    private const APPEND_ONLY = ['prices', 'exchange_rates', 'shift_segments', 'audit_log', ...self::OPERATIONS];

    private Database $db;

    protected function setUp(): void
    {
        $this->db = test_database();
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            if (!in_array($table, ['roles', 'permissions', 'role_permissions'], true)) {
                $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
            }
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string, array<string, array<string, mixed>>> table => colonne => définition */
    private function columns(): array
    {
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE type, COLUMN_TYPE full_type, IS_NULLABLE nullable, COLUMN_KEY k
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        );
        foreach ($rows as $row) {
            $out[$row['t']][$row['c']] = $row;
        }
        unset($out['schema_migrations']);

        return $out;
    }

    public function testAllExpectedTablesExist(): void
    {
        $tables = array_keys($this->columns());
        sort($tables);
        $expected = self::TABLES;
        sort($expected);
        self::assertSame($expected, $tables);
    }

    public function testEveryTableHasUuidPrimaryKeyAndAuthorship(): void
    {
        foreach ($this->columns() as $table => $columns) {
            self::assertSame('char(36)', $columns['id']['full_type'] ?? null, "$table.id");
            self::assertSame('PRI', $columns['id']['k'], "$table.id doit être la clé primaire");
            self::assertSame('datetime', $columns['created_at']['type'] ?? null, "$table.created_at");
            self::assertSame('char(36)', $columns['created_by']['full_type'] ?? null, "$table.created_by");
        }
    }

    public function testBusinessTablesCarryAnIndexedStationId(): void
    {
        $leading = array_column($this->db->fetchAll(
            "SELECT DISTINCT TABLE_NAME t FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'station_id' AND SEQ_IN_INDEX = 1"
        ), 't');
        foreach ($this->columns() as $table => $columns) {
            if (in_array($table, self::WITHOUT_STATION, true)) {
                continue;
            }
            self::assertArrayHasKey('station_id', $columns, "$table.station_id");
            self::assertContains($table, $leading, "$table : station_id doit ouvrir un index");
        }
    }

    public function testOperationTablesCarryCorrectionColumns(): void
    {
        $columns = $this->columns();
        foreach (self::OPERATIONS as $table) {
            foreach (['corrects_id', 'approved_by'] as $column) {
                self::assertSame('char(36)', $columns[$table][$column]['full_type'] ?? null, "$table.$column");
                self::assertSame('YES', $columns[$table][$column]['nullable'], "$table.$column");
            }
            self::assertSame('NO', $columns[$table]['created_by']['nullable'], "$table.created_by obligatoire");
        }
    }

    public function testNoFloatAndTypedAmountsAndVolumes(): void
    {
        foreach ($this->columns() as $table => $columns) {
            foreach ($columns as $name => $column) {
                self::assertNotContains($column['type'], ['float', 'double', 'real'], "$table.$name : float interdit");
                if ($name === 'rate') {
                    self::assertSame('bigint(20)', $column['full_type'], "$table.rate : taux entier");
                    self::assertArrayHasKey('from_currency', $columns);
                } elseif (!str_ends_with($name, '_id') && preg_match('/(^|_)(amount|price|cost)(_|$)/', $name)) {
                    self::assertSame('bigint(20)', $column['full_type'], "$table.$name : montant en BIGINT");
                    self::assertArrayHasKey('currency', $columns, "$table : colonne currency attendue");
                }
                if (str_ends_with($name, '_l') || $name === 'index_value') {
                    self::assertSame('decimal(12,2)', $column['full_type'], "$table.$name : volume en DECIMAL(12,2)");
                }
            }
        }
    }

    // --- Comportement : ajout seul et corrections --------------------------------

    /** @return array{station: string, owner: string, manager: string, product: string, price: string} */
    private function fixture(): array
    {
        $ids = array_map(static fn () => Uuid::v7(), array_flip(['org', 'station', 'owner', 'manager', 'product', 'price']));
        $role = $this->db->fetchValue("SELECT id FROM roles WHERE code = 'owner'");
        $this->db->execute('INSERT INTO organizations (id, name) VALUES (:id, :n)', ['id' => $ids['org'], 'n' => 'Org']);
        foreach (['owner', 'manager'] as $user) {
            $this->db->execute(
                "INSERT INTO users (id, organization_id, role_id, username, password_hash, first_name, last_name) VALUES (:id, :o, :r, :u, 'x', 'A', 'B')",
                ['id' => $ids[$user], 'o' => $ids['org'], 'r' => $role, 'u' => $user]
            );
        }
        $this->db->execute("INSERT INTO stations (id, organization_id, code, name) VALUES (:id, :o, 'S1', 'Station')", ['id' => $ids['station'], 'o' => $ids['org']]);
        $this->db->execute("INSERT INTO products (id, station_id, code, name) VALUES (:id, :s, 'ESS', 'Essence')", ['id' => $ids['product'], 's' => $ids['station']]);
        $this->db->execute(
            "INSERT INTO prices (id, station_id, product_id, unit_price, effective_at, created_by) VALUES (:id, :s, :p, 3450, '2026-10-01 06:00:00', :u)",
            ['id' => $ids['price'], 's' => $ids['station'], 'p' => $ids['product'], 'u' => $ids['owner']]
        );

        return $ids;
    }

    private function assertRejected(string $sql, array $params, string $expected): void
    {
        try {
            $this->db->execute($sql, $params);
            self::fail("Requête acceptée alors qu'elle devait être refusée : $sql");
        } catch (PDOException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testAppendOnlyTablesHaveUpdateAndDeleteTriggers(): void
    {
        $triggers = array_column($this->db->fetchAll(
            'SELECT CONCAT(EVENT_OBJECT_TABLE, ":", EVENT_MANIPULATION) k FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        ), 'k');
        foreach (self::APPEND_ONLY as $table) {
            self::assertContains("$table:UPDATE", $triggers, $table);
            self::assertContains("$table:DELETE", $triggers, $table);
        }
        self::assertContains('shifts:DELETE', $triggers);
    }

    public function testAPriceVersionCannotBeChangedOrDeleted(): void
    {
        $ids = $this->fixture();
        $this->assertRejected('UPDATE prices SET unit_price = 1 WHERE id = :id', ['id' => $ids['price']], 'ajout seul');
        $this->assertRejected('DELETE FROM prices WHERE id = :id', ['id' => $ids['price']], 'ajout seul');
        self::assertSame(3450, (int) $this->db->fetchValue('SELECT unit_price FROM prices WHERE id = :id', ['id' => $ids['price']]));
    }

    public function testACorrectionIsANewRowApprovedBySomeoneElse(): void
    {
        $ids = $this->fixture();
        $tank = Uuid::v7();
        $this->db->execute(
            "INSERT INTO tanks (id, station_id, product_id, code, name, capacity_l) VALUES (:id, :s, :p, 'C1', 'Cuve', '30000.00')",
            ['id' => $tank, 's' => $ids['station'], 'p' => $ids['product']]
        );
        $dip = 'INSERT INTO tank_dips (id, station_id, tank_id, dip_type, depth_cm, volume_l, measured_at, created_by, corrects_id, correction_reason, approved_by, approved_at)
                VALUES (:id, :s, :t, "control", :d, :v, "2026-10-01 07:00:00", :u, :c, :r, :a, :at)';
        $base = ['s' => $ids['station'], 't' => $tank, 'u' => $ids['manager']];
        $original = Uuid::v7();
        $this->db->execute($dip, $base + ['id' => $original, 'd' => '120.0', 'v' => '14400.00', 'c' => null, 'r' => null, 'a' => null, 'at' => null]);

        $correction = $base + ['id' => Uuid::v7(), 'd' => '102.0', 'v' => '12240.00', 'c' => $original, 'r' => 'Erreur de lecture'];
        $this->assertRejected($dip, $correction + ['a' => null, 'at' => null], 'chk_td_correction');
        $this->assertRejected($dip, $correction + ['a' => $ids['manager'], 'at' => '2026-10-01 08:00:00'], 'chk_td_four_eyes');
        $this->db->execute($dip, $correction + ['a' => $ids['owner'], 'at' => '2026-10-01 08:00:00']);

        $this->assertRejected('UPDATE tank_dips SET volume_l = 0 WHERE id = :id', ['id' => $original], 'ajout seul');
        self::assertSame('14400.00', $this->db->fetchValue('SELECT volume_l FROM tank_dips WHERE id = :id', ['id' => $original]), "L'originale reste intacte");
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM tank_dips'));
    }

    public function testOneOpenShiftPerAttendantAndValidatedShiftIsLocked(): void
    {
        $ids = $this->fixture();
        $open = 'INSERT INTO shifts (id, station_id, attendant_id, business_date, opened_at, opened_by, created_by)
                 VALUES (:id, :s, :u, "2026-10-01", "2026-10-01 06:00:00", :u2, :u3)';
        $shift = Uuid::v7();
        $params = ['s' => $ids['station'], 'u' => $ids['manager'], 'u2' => $ids['owner'], 'u3' => $ids['owner']];
        $this->db->execute($open, $params + ['id' => $shift]);
        $this->assertRejected($open, $params + ['id' => Uuid::v7()], 'uq_shf_one_open');

        $this->assertRejected("UPDATE shifts SET status = 'closed' WHERE id = :id", ['id' => $shift], 'chk_shf_closed');
        $this->db->execute(
            "UPDATE shifts SET status = 'validated', closed_at = NOW(), closed_by = :u, expected_amount = 100, received_amount = 90,
             variance_amount = -10, validated_at = NOW(), validated_by = :u2 WHERE id = :id",
            ['id' => $shift, 'u' => $ids['owner'], 'u2' => $ids['owner']]
        );
        $this->db->execute($open, $params + ['id' => Uuid::v7()]);

        $this->assertRejected('UPDATE shifts SET variance_amount = 0 WHERE id = :id', ['id' => $shift], 'poste valide');
        $this->assertRejected('DELETE FROM shifts WHERE id = :id', ['id' => $shift], 'suppression interdite');
    }

    public function testUsdHandoverRequiresTheExchangeRate(): void
    {
        $ids = $this->fixture();
        $rate = Uuid::v7();
        $shift = Uuid::v7();
        $segment = Uuid::v7();
        $this->db->execute("INSERT INTO exchange_rates (id, station_id, rate, effective_at, created_by) VALUES (:id, :s, 2850, '2026-10-01 06:00:00', :u)", ['id' => $rate, 's' => $ids['station'], 'u' => $ids['owner']]);
        $this->db->execute(
            'INSERT INTO shifts (id, station_id, attendant_id, business_date, opened_at, opened_by, created_by) VALUES (:id, :s, :u, "2026-10-01", "2026-10-01 06:00:00", :o, :o2)',
            ['id' => $shift, 's' => $ids['station'], 'u' => $ids['manager'], 'o' => $ids['owner'], 'o2' => $ids['owner']]
        );
        $this->db->execute(
            "INSERT INTO shift_segments (id, station_id, shift_id, sequence, reason, started_at, exchange_rate_id, created_by) VALUES (:id, :s, :sh, 1, 'shift_open', '2026-10-01 06:00:00', :r, :u)",
            ['id' => $segment, 's' => $ids['station'], 'sh' => $shift, 'r' => $rate, 'u' => $ids['owner']]
        );
        $handover = 'INSERT INTO cash_handovers (id, station_id, shift_id, segment_id, amount, currency, exchange_rate_id, amount_cdf, received_by, handed_at, created_by)
                     VALUES (:id, :s, :sh, :seg, :a, :c, :r, :cdf, :u, "2026-10-01 14:00:00", :u2)';
        $base = ['s' => $ids['station'], 'sh' => $shift, 'seg' => $segment, 'u' => $ids['owner'], 'u2' => $ids['owner']];

        $this->assertRejected($handover, $base + ['id' => Uuid::v7(), 'a' => 10000, 'c' => 'USD', 'r' => null, 'cdf' => 285000], 'chk_ch_rate');
        $this->assertRejected($handover, $base + ['id' => Uuid::v7(), 'a' => 5000, 'c' => 'CDF', 'r' => null, 'cdf' => 4000], 'chk_ch_rate');
        $this->assertRejected($handover, $base + ['id' => Uuid::v7(), 'a' => 5000, 'c' => 'EUR', 'r' => null, 'cdf' => 5000], 'chk_ch_currency');
        $this->db->execute($handover, $base + ['id' => Uuid::v7(), 'a' => 10000, 'c' => 'USD', 'r' => $rate, 'cdf' => 285000]);
        $this->db->execute($handover, $base + ['id' => Uuid::v7(), 'a' => 5000, 'c' => 'CDF', 'r' => null, 'cdf' => 5000]);
        self::assertSame(290000, (int) $this->db->fetchValue('SELECT SUM(amount_cdf) FROM cash_handovers'));
    }

    public function testReferenceRolesAndPermissionsAreSeededWithFixedIds(): void
    {
        self::assertSame(
            ['attendant' => 3, 'manager' => 16, 'owner' => 27],
            array_map('intval', array_column($this->db->fetchAll(
                'SELECT r.code, COUNT(*) n FROM role_permissions rp JOIN roles r ON r.id = rp.role_id GROUP BY r.code ORDER BY r.code'
            ), 'n', 'code'))
        );
        foreach (['roles', 'permissions', 'role_permissions'] as $table) {
            foreach ($this->db->fetchAll("SELECT id FROM `$table`") as $row) {
                self::assertTrue(Uuid::isValid($row['id']), "$table.id : {$row['id']}");
            }
        }
        self::assertSame(0, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code <> 'owner' AND p.code IN ('prices.manage', 'rates.manage', 'corrections.approve')"
        ), 'Seul le propriétaire fixe les prix, les taux et approuve les corrections');
    }
}
