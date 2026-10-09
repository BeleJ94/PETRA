<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

use Petra\Core\Database;
use Petra\Core\I18n;
use Petra\Core\Uuid;
use RuntimeException;
use Throwable;

/**
 * Côté station : envoie la file au central par lots, puis récupère ce que le central décide
 * (comptes, droits, fiche de station, prix, taux). Lancé par bin/sync.php (cron).
 * Une coupure laisse la file intacte : elle repart au passage suivant, sans doublon (réception idempotente).
 */
final class SyncClient
{
    public const MAX_ATTEMPTS = 10;

    /** @var array<string, array{0: string, 1: string}> passes en cours : sens et heure de début */
    private array $logs = [];

    public function __construct(
        private readonly Database $db,
        private readonly SyncConfig $config,
        private readonly HttpTransport $transport,
        private readonly int $batchSize = 200,
    ) {
    }

    /**
     * Une passe complète : envoi puis réception. La réception est tentée même si l'envoi a des conflits,
     * pas si le central est injoignable.
     *
     * @return array{push: array<string, mixed>, pull: ?array<string, mixed>}
     */
    public function run(): array
    {
        $this->assertConfigured();
        $push = $this->push();

        return ['push' => $push, 'pull' => $push['status'] === 'offline' ? null : $this->pull()];
    }

    /** @return array{status: string, sent: int, accepted: int, rejected: int, conflicts: int, error: ?string} */
    public function push(): array
    {
        $this->assertConfigured();
        $logId = $this->startLog('push');
        $totals = ['sent' => 0, 'accepted' => 0, 'rejected' => 0, 'conflicts' => 0];
        $details = [];
        $lastSeq = 0;
        $status = 'success';
        $error = null;

        try {
            while (true) {
                $entries = $this->db->fetchAll(
                    "SELECT seq, entity_type, entity_id, operation FROM sync_queue
                      WHERE station_id = :s AND seq > :seq AND (status = 'pending' OR (status = 'failed' AND attempts < :max))
                      ORDER BY seq LIMIT " . max(1, min(SyncReceiver::MAX_BATCH, $this->batchSize)),
                    ['s' => $this->config->stationId, 'seq' => $lastSeq, 'max' => self::MAX_ATTEMPTS]
                );
                if ($entries === []) {
                    break;
                }
                $lastSeq = (int) $entries[count($entries) - 1]['seq'];
                $bySeq = array_column($entries, null, 'seq');
                $response = $this->request('/api/sync/push', ['batch' => array_map(fn (array $e): array => $this->item($e), $entries)]);
                foreach ($response['results'] ?? [] as $result) {
                    $totals['sent']++;
                    $seq = (int) ($result['seq'] ?? 0);
                    $message = isset($result['message']) ? mb_substr((string) $result['message'], 0, 255) : null;
                    switch ($result['status'] ?? '') {
                        case 'applied':
                        case 'duplicate':
                            $totals['accepted']++;
                            $this->mark($seq, 'sent', null);
                            break;
                        case 'conflict':
                            $totals['conflicts']++;
                            $details[] = ['seq' => $seq, 'table' => $bySeq[$seq]['entity_type'] ?? null, 'id' => $bySeq[$seq]['entity_id'] ?? null, 'status' => 'conflict', 'message' => $message];
                            $this->mark($seq, 'conflict', $message);
                            break;
                        default:
                            $totals['rejected']++;
                            $details[] = ['seq' => $seq, 'table' => $bySeq[$seq]['entity_type'] ?? null, 'id' => $bySeq[$seq]['entity_id'] ?? null, 'status' => 'rejected', 'message' => $message];
                            $this->mark($seq, 'failed', $message);
                    }
                }
            }
        } catch (TransportException $e) {
            // Hors ligne : rien n'est perdu, les entrées non acquittées restent en attente.
            $status = 'offline';
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        $this->finishLog($logId, $status, $totals, $error, $details);
        if ($totals['conflicts'] > 0) {
            $this->alert('sync_log', $logId, 'sync.alert_push_conflicts', ['count' => $totals['conflicts']]);
        }

        return ['status' => $status, 'error' => $error] + $totals;
    }

    /** @return array{status: string, applied: int, conflicts: int, error: ?string} */
    public function pull(): array
    {
        $this->assertConfigured();
        $logId = $this->startLog('pull');
        $applied = 0;
        $conflicts = [];
        $news = [];
        $status = 'success';
        $error = null;

        try {
            $data = $this->request('/api/sync/pull', []);
            if (($data['station']['id'] ?? null) !== $this->config->stationId) {
                throw new RuntimeException('Le central a répondu pour une autre station');
            }
            // Ces données viennent du central : elles ne doivent pas repartir dans la file.
            $this->db->queueing(false);
            try {
                $this->db->transaction(function () use ($data, &$applied, &$conflicts, &$news): void {
                    $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
                    $applied += $this->applyReference($data);
                    $openShift = (bool) $this->db->fetchValue("SELECT 1 FROM shifts WHERE station_id = :s AND status = 'open' LIMIT 1", ['s' => $this->config->stationId]);
                    foreach (['prices' => 'price', 'exchange_rates' => 'exchange_rate'] as $table => $entity) {
                        foreach ($data[$table] ?? [] as $row) {
                            $outcome = $this->applyVersion($table, (array) $row, $openShift);
                            if ($outcome === 'applied') {
                                $applied++;
                                $news[] = [$entity, (array) $row];
                            } elseif ($outcome !== 'duplicate') {
                                $conflicts[] = ['table' => $table, 'id' => $row['id'] ?? null, 'status' => 'conflict', 'message' => $outcome, 'entity' => $entity];
                            }
                        }
                    }
                    $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
                });
            } finally {
                $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
                $this->db->queueing(true);
            }
        } catch (TransportException $e) {
            $status = 'offline';
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        $this->finishLog($logId, $status, ['sent' => 0, 'accepted' => $applied, 'rejected' => 0, 'conflicts' => count($conflicts)], $error, $conflicts);
        foreach ($conflicts as $conflict) {
            $this->alert($conflict['entity'], (string) $conflict['id'], 'sync.alert_pull_conflict', ['reason' => $conflict['message']]);
        }
        foreach ($news as [$entity, $row]) {
            $this->alert($entity, (string) $row['id'], $entity === 'price' ? 'sync.alert_new_price' : 'sync.alert_new_rate', [], 'info', $entity === 'price' ? 'price_change' : 'rate_change');
        }

        return ['status' => $status, 'applied' => $applied, 'conflicts' => count($conflicts), 'error' => $error];
    }

    // --- Envoi ---------------------------------------------------------------------------------

    /** Entrée de file → élément du lot, avec l'état courant de la ligne. */
    private function item(array $entry): array
    {
        $table = (string) $entry['entity_type'];
        $row = array_key_exists($table, SyncConfig::UP)
            ? $this->db->fetchOne("SELECT * FROM `$table` WHERE id = :id", ['id' => $entry['entity_id']])
            : null;
        // Ligne disparue depuis sa mise en file (point de barème remplacé) : on transmet sa suppression.
        $op = $row === null ? 'delete' : (string) $entry['operation'];
        if ($row !== null && $op === 'delete') {
            $op = 'update';
        }
        unset($row['open_station_id'], $row['open_key']);

        return ['seq' => (int) $entry['seq'], 'table' => $table, 'id' => $entry['entity_id'], 'op' => $op, 'row' => $row];
    }

    private function mark(int $seq, string $status, ?string $error): void
    {
        $this->db->execute(
            "UPDATE sync_queue SET status = :status, last_error = :error, attempts = attempts + 1, sent_at = IF(:sent = 'sent', NOW(), sent_at)
              WHERE seq = :seq AND station_id = :s",
            ['status' => $status, 'error' => $error, 'sent' => $status, 'seq' => $seq, 's' => $this->config->stationId]
        );
    }

    // --- Réception -------------------------------------------------------------------------------

    /** Organisation, fiche de station, droits et comptes : le central fait foi. */
    private function applyReference(array $data): int
    {
        $count = 0;
        $upsert = function (string $table, array $row, array $keep = []) use (&$count): void {
            $columns = array_keys($row);
            $updates = array_diff($columns, ['id', 'created_at'], $keep);
            $this->db->execute(
                "INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "`$c` = VALUES(`$c`)", $updates)),
                $row
            );
            $count++;
        };
        if (is_array($data['organization'] ?? null)) {
            $upsert('organizations', $data['organization']);
        }
        // Le fuseau se règle à la station (avec les horaires des postes) : il n'est pas repris du central après la création.
        $upsert('stations', (array) $data['station'], ['timezone']);
        foreach (['roles', 'permissions'] as $table) {
            foreach ($data[$table] ?? [] as $row) {
                $upsert($table, (array) $row);
            }
        }
        $this->db->execute('DELETE FROM role_permissions');
        foreach ($data['role_permissions'] ?? [] as $row) {
            $upsert('role_permissions', (array) $row);
        }
        // Comptes : identité, rôle et mot de passe viennent du central ; les compteurs de connexion restent locaux.
        foreach ($data['users'] ?? [] as $row) {
            $upsert('users', (array) $row);
        }
        $ids = array_column($data['user_stations'] ?? [], 'id');
        $existing = array_column($this->db->fetchAll('SELECT id FROM user_stations WHERE station_id = :s', ['s' => $this->config->stationId]), 'id');
        foreach (array_diff($existing, $ids) as $id) {
            $this->db->execute('DELETE FROM user_stations WHERE id = :id', ['id' => $id]);
        }
        foreach ($data['user_stations'] ?? [] as $row) {
            $upsert('user_stations', (array) $row);
        }

        return $count;
    }

    /**
     * Version de prix ou de taux décidée au central.
     *
     * @return string 'applied', 'duplicate', ou le motif du conflit (la version n'est alors pas appliquée)
     */
    private function applyVersion(string $table, array $row, bool $openShift): string
    {
        if (!Uuid::isValid($row['id'] ?? null) || ($row['station_id'] ?? null) !== $this->config->stationId) {
            return I18n::current()->get('sync.conflict_foreign');
        }
        if ($this->db->fetchValue("SELECT 1 FROM `$table` WHERE id = :id", ['id' => $row['id']])) {
            return 'duplicate';
        }
        // Un changement de prix ou de taux pendant un poste impose un relevé intermédiaire : il ne s'applique pas tout seul.
        if ($openShift) {
            return I18n::current()->get('sync.conflict_open_shift');
        }
        $columns = array_keys($row);
        try {
            $this->db->execute("INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')', $row);
        } catch (Throwable $e) {
            return I18n::current()->get('sync.conflict_duplicate_version');
        }

        return 'applied';
    }

    // --- Commun ------------------------------------------------------------------------------------

    private function assertConfigured(): void
    {
        if (!$this->config->isSyncedStation()) {
            throw new RuntimeException('Synchronisation non configurée : APP_MODE=station, STATION_ID, SYNC_URL et SYNC_TOKEN sont requis.');
        }
        if (!$this->config->urlIsAcceptable()) {
            throw new RuntimeException('SYNC_URL doit être en HTTPS (HTTP n\'est accepté que vers localhost).');
        }
    }

    /** @return array<string, mixed> réponse JSON du central */
    private function request(string $path, array $payload): array
    {
        $response = $this->transport->post(
            $this->config->url . $path,
            ['Authorization' => 'Bearer ' . $this->config->token, 'X-Station-Id' => $this->config->stationId],
            json_encode(['station_id' => $this->config->stationId] + $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data)) {
            throw new RuntimeException('Réponse du central : HTTP ' . $response['status'] . (is_array($data) && isset($data['error']) ? ' — ' . $data['error'] : ''));
        }

        return $data;
    }

    /**
     * Le journal s'écrit en fin de passe, d'un seul tenant : à la toute première réception, la fiche de la
     * station n'existe pas encore ici (elle arrive du central), et sync_log la référence.
     */
    private function startLog(string $direction): string
    {
        $id = Uuid::v7();
        $this->logs[$id] = [$direction, date('Y-m-d H:i:s')];

        return $id;
    }

    /** @param array{sent: int, accepted: int, rejected: int, conflicts: int} $totals */
    private function finishLog(string $id, string $status, array $totals, ?string $error, array $details): void
    {
        [$direction, $startedAt] = $this->logs[$id];
        unset($this->logs[$id]);
        if (!$this->db->fetchValue('SELECT 1 FROM stations WHERE id = :id', ['id' => $this->config->stationId])) {
            return;
        }
        $this->db->execute(
            'INSERT INTO sync_log (id, station_id, direction, status, started_at, finished_at, items_sent, items_accepted, items_rejected,
                                   items_conflicted, error_message, details)
             VALUES (:id, :s, :d, :status, :started, NOW(), :sent, :accepted, :rejected, :conflicts, :error, :details)',
            [
                'id' => $id, 's' => $this->config->stationId, 'd' => $direction, 'started' => $startedAt,
                'status' => $status, 'sent' => $totals['sent'], 'accepted' => $totals['accepted'], 'rejected' => $totals['rejected'], 'conflicts' => $totals['conflicts'],
                'error' => $error === null ? null : mb_substr($error, 0, 255),
                'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }

    /** Signale au gérant, dans le centre d'alertes de la station. Une seule alerte ouverte par objet. */
    private function alert(string $entityType, string $entityId, string $messageKey, array $params, string $severity = 'warning', string $type = 'sync_conflict'): void
    {
        $exists = $this->db->fetchValue(
            "SELECT 1 FROM alerts WHERE station_id = :s AND alert_type = :type AND entity_type = :et AND entity_id = :id AND status <> 'resolved' LIMIT 1",
            ['s' => $this->config->stationId, 'type' => $type, 'et' => $entityType, 'id' => $entityId]
        );
        if ($exists) {
            return;
        }
        $this->db->execute(
            'INSERT INTO alerts (id, station_id, alert_type, severity, entity_type, entity_id, message) VALUES (:id, :s, :type, :severity, :et, :eid, :message)',
            [
                'id' => Uuid::v7(), 's' => $this->config->stationId, 'type' => $type, 'severity' => $severity, 'et' => $entityType, 'eid' => $entityId,
                'message' => mb_substr(I18n::current()->get($messageKey, $params), 0, 255),
            ]
        );
    }
}
