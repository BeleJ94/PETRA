<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests;

use Petra\Core\App;
use Petra\Core\Database;
use Petra\Core\I18n;
use Petra\Core\Migrator;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Security\Auth;
use Petra\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration et fonctionnels : base de test vidée avant chaque test,
 * fabriques de données et exécution de l'application en mémoire (sans serveur HTTP).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const PASSWORD = 'Secret-Password-42';
    protected const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Tables conservées entre les tests (référentiel issu des migrations). */
    private const KEEP = ['schema_migrations', 'roles', 'permissions', 'role_permissions'];

    private static ?string $passwordHash = null;

    protected Database $db;

    protected function setUp(): void
    {
        $this->db = test_database();
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = (string) array_values($row)[0];
            if (!in_array($table, self::KEEP, true)) {
                $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
            }
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        I18n::setCurrent(new I18n(dirname(__DIR__, 2) . '/lang'));
    }

    protected static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/app.php';
    }

    /** Hachage argon2id du mot de passe de test, calculé une seule fois (coûteux par construction). */
    protected static function passwordHash(): string
    {
        return self::$passwordHash ??= PasswordHasher::hash(self::PASSWORD);
    }

    // --- Fabriques ------------------------------------------------------------

    protected function createOrganization(string $name = 'Organisation'): string
    {
        $id = Uuid::v7();
        $this->db->execute('INSERT INTO organizations (id, name) VALUES (:id, :n)', ['id' => $id, 'n' => $name]);

        return $id;
    }

    protected function createStation(string $organizationId, string $code): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO stations (id, organization_id, code, name) VALUES (:id, :o, :c, :n)',
            ['id' => $id, 'o' => $organizationId, 'c' => $code, 'n' => "Station $code"]
        );

        return $id;
    }

    /** @param list<string> $stationIds */
    protected function createUser(string $organizationId, string $role, array $stationIds = [], ?string $username = null): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO users (id, organization_id, role_id, username, password_hash, first_name, last_name, all_stations)
             SELECT :id, :o, r.id, :u, :h, :f, :l, :a FROM roles r WHERE r.code = :r',
            [
                'id' => $id, 'o' => $organizationId, 'u' => $username ?? $role . '.' . bin2hex(random_bytes(3)),
                'h' => self::passwordHash(), 'f' => ucfirst($role), 'l' => 'Test', 'a' => $role === 'owner' ? 1 : 0, 'r' => $role,
            ]
        );
        foreach ($stationIds as $stationId) {
            $this->db->execute(
                'INSERT INTO user_stations (id, user_id, station_id) VALUES (:id, :u, :s)',
                ['id' => Uuid::v7(), 'u' => $id, 's' => $stationId]
            );
        }

        return $id;
    }

    protected function createSupplier(string $stationId, string $name = 'SEP Congo', bool $active = true): string
    {
        $id = Uuid::v7();
        $this->db->execute('INSERT INTO suppliers (id, station_id, name, is_active) VALUES (:id, :s, :n, :a)', ['id' => $id, 's' => $stationId, 'n' => $name, 'a' => $active ? 1 : 0]);

        return $id;
    }

    protected function createProduct(string $stationId, string $code = 'ESS', string $name = 'Essence'): string
    {
        $id = Uuid::v7();
        $this->db->execute('INSERT INTO products (id, station_id, code, name) VALUES (:id, :s, :c, :n)', ['id' => $id, 's' => $stationId, 'c' => $code, 'n' => $name]);

        return $id;
    }

    protected function createTank(string $stationId, string $productId, string $code = 'C-ESS', string $capacity = '30000.00'): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO tanks (id, station_id, product_id, code, name, capacity_l) VALUES (:id, :s, :p, :c, :n, :cap)',
            ['id' => $id, 's' => $stationId, 'p' => $productId, 'c' => $code, 'n' => "Cuve $code", 'cap' => $capacity]
        );

        return $id;
    }

    /** Distributeur d'une station : crée « D1 » au premier appel, le rend ensuite (ou crée celui du code demandé). */
    protected function pumpFor(string $stationId, string $code = 'D1'): string
    {
        $id = $this->db->fetchValue('SELECT id FROM pumps WHERE station_id = :s AND code = :c', ['s' => $stationId, 'c' => $code]);
        if ($id === null) {
            $id = Uuid::v7();
            $this->db->execute('INSERT INTO pumps (id, station_id, code, name) VALUES (:id, :s, :c, :n)', ['id' => $id, 's' => $stationId, 'c' => $code, 'n' => "Distributeur $code"]);
        }

        return (string) $id;
    }

    protected function createNozzle(string $stationId, string $tankId, string $code = 'P-1'): string
    {
        $id = Uuid::v7();
        $this->db->execute('INSERT INTO nozzles (id, station_id, tank_id, code, name) VALUES (:id, :s, :t, :c, :n)', ['id' => $id, 's' => $stationId, 't' => $tankId, 'c' => $code, 'n' => "Pistolet $code"]);

        return $id;
    }

    /** Version de prix en vigueur à partir de $at (« AAAA-MM-JJ HH:MM:SS »). */
    protected function createPrice(string $stationId, string $productId, int $unitPrice, string $at, string $userId, string $currency = 'CDF'): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO prices (id, station_id, product_id, unit_price, currency, effective_at, created_by) VALUES (:id, :s, :p, :price, :c, :at, :u)',
            ['id' => $id, 's' => $stationId, 'p' => $productId, 'price' => $unitPrice, 'c' => $currency, 'at' => $at, 'u' => $userId]
        );

        return $id;
    }

    /** Version du taux USD/CDF en vigueur à partir de $at. */
    protected function createRate(string $stationId, int $rate, string $at, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO exchange_rates (id, station_id, rate, effective_at, created_by) VALUES (:id, :s, :r, :at, :u)',
            ['id' => $id, 's' => $stationId, 'r' => $rate, 'at' => $at, 'u' => $userId]
        );

        return $id;
    }

    /**
     * Poste ouvert avec sa première tranche et un relevé d'ouverture par pistolet.
     *
     * @param array<string, array{0: string, 1: string}> $nozzles identifiant de pistolet => [index d'ouverture, identifiant du prix]
     * @return array{shift: string, segment: string}
     */
    protected function openShift(string $stationId, string $attendantId, string $rateId, array $nozzles, string $at, string $openedBy): array
    {
        $shift = Uuid::v7();
        $segment = Uuid::v7();
        $this->db->execute(
            'INSERT INTO shifts (id, station_id, attendant_id, business_date, opened_at, opened_by, created_by) VALUES (:id, :s, :a, :d, :at, :o, :c)',
            ['id' => $shift, 's' => $stationId, 'a' => $attendantId, 'd' => substr($at, 0, 10), 'at' => $at, 'o' => $openedBy, 'c' => $openedBy]
        );
        $this->db->execute(
            "INSERT INTO shift_segments (id, station_id, shift_id, sequence, reason, started_at, exchange_rate_id, created_by) VALUES (:id, :s, :sh, 1, 'shift_open', :at, :r, :u)",
            ['id' => $segment, 's' => $stationId, 'sh' => $shift, 'at' => $at, 'r' => $rateId, 'u' => $openedBy]
        );
        foreach ($nozzles as $nozzleId => [$index, $priceId]) {
            $this->db->execute(
                "INSERT INTO meter_readings (id, station_id, shift_id, segment_id, nozzle_id, reading_type, index_value, price_id, read_at, created_by)
                 VALUES (:id, :s, :sh, :seg, :n, 'opening', :i, :p, :at, :u)",
                ['id' => Uuid::v7(), 's' => $stationId, 'sh' => $shift, 'seg' => $segment, 'n' => $nozzleId, 'i' => $index, 'p' => $priceId, 'at' => $at, 'u' => $openedBy]
            );
        }

        return ['shift' => $shift, 'segment' => $segment];
    }

    /** Accorde des permissions supplémentaires à un rôle (à faire suivre de restoreReferenceData() en tearDown). */
    protected function grant(string $role, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->db->execute(
                'INSERT INTO role_permissions (id, role_id, permission_id) SELECT :id, r.id, p.id FROM roles r JOIN permissions p WHERE r.code = :r AND p.code = :p',
                ['id' => Uuid::v7(), 'r' => $role, 'p' => $permission]
            );
        }
    }

    /** Remet rôles et permissions dans l'état de la migration 0010. */
    protected function restoreReferenceData(): void
    {
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['role_permissions', 'permissions', 'roles'] as $table) {
            $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        // Le référentiel d'origine, puis les permissions ajoutées par les migrations suivantes.
        $files = array_merge([dirname(__DIR__, 2) . '/database/migrations/0010_seed_roles_permissions.sql'], glob(dirname(__DIR__, 2) . '/database/migrations/*_permission_*.sql') ?: []);
        foreach ($files as $file) {
            foreach (Migrator::splitStatements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
        }
    }

    protected function createAlert(string $stationId, string $message, string $type = 'cash_variance'): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            "INSERT INTO alerts (id, station_id, alert_type, severity, message, amount, currency) VALUES (:id, :s, :t, 'warning', :m, -12500, 'CDF')",
            ['id' => $id, 's' => $stationId, 't' => $type, 'm' => $message]
        );

        return $id;
    }

    // --- Application en mémoire -------------------------------------------------

    /** Session ouverte au nom de $userId (null = anonyme), avec un jeton CSRF connu. */
    protected function sessionFor(?string $userId): Session
    {
        $data = ['_csrf_token' => self::CSRF];
        if ($userId !== null) {
            $hash = (string) $this->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
            $data += [Auth::SESSION_USER => $userId, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash)];
        }

        return Session::memory($data);
    }

    /**
     * Service résolu dans le conteneur d'une application ouverte au nom de $userId.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function service(?string $userId, string $class, ?Session $session = null): object
    {
        return App::create(self::config(), $session ?? $this->sessionFor($userId), $this->db, new Request('GET', '/'))->container()->get($class);
    }

    /** Exécute une requête HTTP simulée ; la session est partagée entre les appels. Le jeton CSRF est joint aux POST. */
    protected function http(Session $session, string $method, string $path, array $body = [], array $headers = [], bool $csrf = true, array $files = []): Response
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $queryParams);
        if ($method !== 'GET' && $csrf) {
            $body += ['_csrf' => $session->get('_csrf_token')];
        }
        $request = new Request($method, $path, $queryParams, $body, array_change_key_case($headers), ['REMOTE_ADDR' => '127.0.0.1'], $files);

        return App::create(self::config(), $session, $this->db, $request)->handle($request);
    }

    protected function row(string $sql, array $params = []): array
    {
        return $this->db->fetchOne($sql, $params) ?? [];
    }

    protected function value(string $sql, array $params = []): mixed
    {
        return $this->db->fetchValue($sql, $params);
    }
}
