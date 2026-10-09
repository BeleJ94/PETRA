<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\Uuid;
use Throwable;

/**
 * Côté central : reçoit les lots d'une station et lui sert les données décidées au central.
 * - La station est identifiée par son jeton ; toute ligne reçue doit porter son station_id.
 * - Réception idempotente : une ligne déjà reçue à l'identique est ignorée.
 * - Une ligne d'une table en ajout seul reçue avec un contenu différent, ou un statut qui reculerait,
 *   est un conflit : consigné, signalé, jamais écrasé.
 */
final class SyncReceiver
{
    public const MAX_BATCH = 500;

    /** @var array<string, list<string>> colonnes inscriptibles par table */
    private array $columns = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{id: string, organization_id: string, code: string, name: string}
     * @throws HttpException 401 si le jeton ne correspond pas à cette station
     */
    public function authenticate(string $stationId, string $token): array
    {
        if (!Uuid::isValid($stationId) || $token === '') {
            throw new HttpException(401, 'errors.sync_unauthorized');
        }
        $station = $this->db->fetchOne('SELECT id, organization_id, code, name, sync_token_hash, is_active FROM stations WHERE id = :id', ['id' => $stationId]);
        // Comparaison à temps constant, même pour une station inconnue.
        $expected = (string) ($station['sync_token_hash'] ?? str_repeat('0', 64));
        if (!hash_equals($expected, hash('sha256', $token)) || $station === null || $station['sync_token_hash'] === null || !(bool) $station['is_active']) {
            throw new HttpException(401, 'errors.sync_unauthorized');
        }

        return $station;
    }

    /**
     * Applique un lot poussé par la station.
     *
     * @param list<array{seq?: mixed, table?: mixed, id?: mixed, op?: mixed, row?: mixed}> $batch
     * @return array{results: list<array{seq: int, status: string, message: ?string}>, applied: int, duplicates: int, conflicts: int, rejected: int}
     */
    public function push(string $stationId, array $batch): array
    {
        if (count($batch) > self::MAX_BATCH) {
            throw new HttpException(413, 'errors.sync_batch_too_large');
        }
        $startedAt = date('Y-m-d H:i:s');
        $results = [];
        $counts = ['applied' => 0, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0];
        $conflicts = [];
        foreach ($batch as $item) {
            [$status, $message] = $this->apply($stationId, is_array($item) ? $item : []);
            $counts[$status]++;
            $results[] = ['seq' => (int) ($item['seq'] ?? 0), 'status' => $status, 'message' => $message];
            if ($status === 'conflict' || $status === 'rejected') {
                $conflicts[] = ['table' => $item['table'] ?? null, 'id' => $item['id'] ?? null, 'status' => $status, 'message' => $message];
            }
        }
        $this->db->execute(
            "INSERT INTO sync_log (id, station_id, direction, status, started_at, finished_at, items_sent, items_accepted, items_rejected, items_conflicted, details)
             VALUES (:id, :s, 'push', 'success', :started, NOW(), :sent, :accepted, :rejected, :conflicted, :details)",
            [
                'id' => Uuid::v7(), 's' => $stationId, 'started' => $startedAt, 'sent' => count($batch), 'accepted' => $counts['applied'] + $counts['duplicate'],
                'rejected' => $counts['rejected'], 'conflicted' => $counts['conflict'],
                'details' => $conflicts === [] ? null : json_encode($conflicts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
        $this->db->execute('UPDATE stations SET last_sync_at = NOW() WHERE id = :id', ['id' => $stationId]);

        return ['results' => $results, 'applied' => $counts['applied'], 'duplicates' => $counts['duplicate'], 'conflicts' => $counts['conflict'], 'rejected' => $counts['rejected']];
    }

    /**
     * Données décidées au central pour cette station : organisation, fiche de station, droits, comptes
     * autorisés sur la station, prix et taux.
     *
     * @return array<string, mixed>
     */
    public function pull(string $stationId): array
    {
        $station = $this->db->fetchOne('SELECT id, organization_id, code, name, city, address, timezone, is_active, created_at, created_by FROM stations WHERE id = :id', ['id' => $stationId])
            ?? throw new HttpException(401, 'errors.sync_unauthorized');
        $org = (string) $station['organization_id'];

        return [
            'server_time' => date('Y-m-d H:i:s'),
            'organization' => $this->db->fetchOne('SELECT id, name, legal_name, is_active, created_at FROM organizations WHERE id = :id', ['id' => $org]),
            'station' => $station,
            'roles' => $this->db->fetchAll('SELECT id, code, name, is_system, created_at FROM roles'),
            'permissions' => $this->db->fetchAll('SELECT id, code, description, created_at FROM permissions'),
            'role_permissions' => $this->db->fetchAll('SELECT id, role_id, permission_id, created_at FROM role_permissions'),
            'users' => $this->db->fetchAll(
                'SELECT u.id, u.organization_id, u.role_id, u.username, u.email, u.phone, u.password_hash, u.first_name, u.last_name, u.locale,
                        u.all_stations, u.is_active, u.created_at, u.created_by
                   FROM users u
                  WHERE u.organization_id = :org AND (u.all_stations = 1 OR EXISTS (SELECT 1 FROM user_stations us WHERE us.user_id = u.id AND us.station_id = :s))',
                ['org' => $org, 's' => $stationId]
            ),
            'user_stations' => $this->db->fetchAll('SELECT id, user_id, station_id, created_at, created_by FROM user_stations WHERE station_id = :s', ['s' => $stationId]),
            'prices' => $this->db->fetchAll('SELECT * FROM prices WHERE station_id = :s ORDER BY effective_at', ['s' => $stationId]),
            'exchange_rates' => $this->db->fetchAll('SELECT * FROM exchange_rates WHERE station_id = :s ORDER BY effective_at', ['s' => $stationId]),
        ];
    }

    /** @return array{0: 'applied'|'duplicate'|'conflict'|'rejected', 1: ?string} */
    private function apply(string $stationId, array $item): array
    {
        $table = $item['table'] ?? null;
        $id = $item['id'] ?? null;
        $op = $item['op'] ?? null;
        if (!is_string($table) || !array_key_exists($table, SyncConfig::UP) || !Uuid::isValid($id) || !in_array($op, ['insert', 'update', 'delete'], true)) {
            return ['rejected', 'entrée invalide'];
        }
        $existing = $this->db->fetchOne("SELECT * FROM `$table` WHERE id = :id", ['id' => $id]);

        if ($op === 'delete') {
            // Seul le barème de jaugeage se supprime, et seulement dans la station du jeton.
            if ($table !== 'tank_calibrations') {
                return ['conflict', "suppression refusée sur $table"];
            }
            if ($existing === null) {
                return ['duplicate', null];
            }
            if ($existing['station_id'] !== $stationId) {
                return ['rejected', 'station différente de celle du jeton'];
            }
            $this->db->execute('DELETE FROM tank_calibrations WHERE id = :id', ['id' => $id]);

            return ['applied', null];
        }

        $row = $item['row'] ?? null;
        if (!is_array($row) || ($row['id'] ?? null) !== $id) {
            return ['rejected', 'ligne absente ou identifiant incohérent'];
        }
        // Le station_id de chaque ligne doit être celui du jeton (seul le journal admet des lignes sans station).
        $rowStation = $row['station_id'] ?? null;
        if ($rowStation !== $stationId && !($rowStation === null && $table === 'audit_log')) {
            return ['rejected', 'station différente de celle du jeton'];
        }
        if ($existing !== null && ($existing['station_id'] ?? null) !== $rowStation) {
            return ['rejected', 'identifiant déjà utilisé par une autre station'];
        }
        $data = array_intersect_key($row, array_flip($this->columns($table)));

        try {
            if ($existing === null) {
                $columns = array_keys($data);
                $this->db->execute("INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')', $data);

                return ['applied', null];
            }
            $changed = array_filter(array_keys($data), static fn (string $c): bool => self::scalar($existing[$c] ?? null) !== self::scalar($data[$c]));
            if ($changed === []) {
                return ['duplicate', null];
            }
            if (SyncConfig::UP[$table]) {
                return ['conflict', 'ligne en ajout seul déjà reçue avec un autre contenu (' . implode(', ', $changed) . ')'];
            }
            $order = SyncConfig::FORWARD_ONLY[$table] ?? null;
            if ($order !== null && array_search($data['status'] ?? null, $order, true) < array_search($existing['status'], $order, true)) {
                return ['conflict', "statut « {$existing['status']} » déjà enregistré : « " . ($data['status'] ?? '') . ' » reçu ensuite'];
            }
            $sets = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", array_diff($changed, ['id'])));
            $this->db->execute("UPDATE `$table` SET $sets WHERE id = :id", array_intersect_key($data, array_flip($changed)) + ['id' => $id]);

            return ['applied', null];
        } catch (Throwable $e) {
            // Poste validé verrouillé, doublon d'une clé unique, référence absente… : rien n'est écrasé.
            $conflict = $existing !== null || str_contains($e->getMessage(), '1062');

            return [$conflict ? 'conflict' : 'rejected', mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** @return list<string> colonnes de la table, hors colonnes calculées */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= array_values(array_column(array_filter(
            $this->db->fetchAll("SHOW COLUMNS FROM `$table`"),
            static fn (array $c): bool => !preg_match('/GENERATED|VIRTUAL|PERSISTENT|STORED/i', (string) $c['Extra'])
        ), 'Field'));
    }

    private static function scalar(mixed $value): ?string
    {
        return $value === null ? null : (is_bool($value) ? (string) (int) $value : (string) $value);
    }
}
