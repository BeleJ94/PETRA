<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Alertes : écarts chiffrés et accès refusés, par station. Statuts : ouverte → vue → traitée. */
final class AlertRepository extends Repository
{
    public const STATUSES = ['open', 'acknowledged', 'resolved'];
    public const SEVERITIES = ['info', 'warning', 'critical'];
    public const SORTABLE = ['created_at' => 'a.created_at', 'severity' => "FIELD(a.severity, 'info', 'warning', 'critical')", 'alert_type' => 'a.alert_type', 'status' => 'a.status', 'amount' => 'a.amount'];

    private const SELECT = "a.*, s.code AS station_code, s.name AS station_name, CONCAT(u.first_name, ' ', u.last_name) AS user_name";
    private const FROM = 'alerts a JOIN stations s ON s.id = a.station_id LEFT JOIN users u ON u.id = a.user_id';

    /**
     * Alertes des stations visées, les plus récentes d'abord.
     *
     * @param string|list<string>|null $status un statut, plusieurs, ou tous
     * @return list<array<string, mixed>>
     */
    public function listRecent(string|array|null $status = null, int $limit = 200): array
    {
        [$scope, $params] = $this->stations->clause('a.station_id');
        $where = [$scope];
        foreach (array_values(array_intersect(self::STATUSES, (array) $status)) as $i => $value) {
            $in[] = ":status_$i";
            $params["status_$i"] = $value;
        }
        if (isset($in)) {
            $where[] = 'a.status IN (' . implode(', ', $in) . ')';
        }

        return $this->db->fetchAll(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where) . "
              ORDER BY a.status = 'open' DESC, a.created_at DESC, a.id DESC
              LIMIT " . max(1, min(500, $limit)),
            $params
        );
    }

    /** @param array{status?: ?string, severity?: ?string, alert_type?: ?string} $filters */
    public function datatable(DataTableRequest $dt, array $filters = []): array
    {
        [$scope, $params] = $this->stations->clause('a.station_id');
        $where = [];
        $filterParams = [];
        foreach (['status' => self::STATUSES, 'severity' => self::SEVERITIES] as $key => $allowed) {
            if (in_array($filters[$key] ?? null, $allowed, true)) {
                $where[] = "a.$key = :$key";
                $filterParams[$key] = $filters[$key];
            }
        }
        if (($filters['alert_type'] ?? null) !== null) {
            $where[] = 'a.alert_type = :alert_type';
            $filterParams['alert_type'] = $filters['alert_type'];
        }

        return $this->paginate(
            $dt,
            "a.id, a.created_at, a.alert_type, a.severity, a.status, a.message, a.amount, a.currency, a.volume_l,
             s.name AS station, CONCAT(u.first_name, ' ', u.last_name) AS user_name",
            self::FROM,
            [$scope],
            $params,
            $where,
            $filterParams,
            ['a.message', 's.name', 'u.last_name', 'u.first_name'],
        );
    }

    /** Alertes ouvertes (pas encore vues) des stations visées : c'est le badge de l'en-tête. */
    public function countOpen(): int
    {
        [$scope, $params] = $this->stations->clause('station_id');

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM alerts WHERE status = 'open' AND $scope", $params);
    }

    /**
     * Vue consolidée : alertes ouvertes et vues par station attribuée, les stations sans alerte comprises.
     *
     * @return list<array{id: string, name: string, open: int, acknowledged: int, critical: int}>
     */
    public function countsByStation(): array
    {
        $counts = [];
        $ids = $this->stations->stationIds();
        if ($ids !== []) {
            $params = [];
            foreach ($ids as $i => $id) {
                $params["id_$i"] = $id;
            }
            foreach ($this->db->fetchAll(
                "SELECT station_id, SUM(status = 'open') AS open, SUM(status = 'acknowledged') AS acknowledged,
                        SUM(status <> 'resolved' AND severity = 'critical') AS critical
                   FROM alerts WHERE station_id IN (:" . implode(', :', array_keys($params)) . ') GROUP BY station_id',
                $params
            ) as $row) {
                $counts[$row['station_id']] = $row;
            }
        }

        return array_map(static fn (array $station): array => [
            'id' => $station['id'], 'name' => $station['name'],
            'open' => (int) ($counts[$station['id']]['open'] ?? 0),
            'acknowledged' => (int) ($counts[$station['id']]['acknowledged'] ?? 0),
            'critical' => (int) ($counts[$station['id']]['critical'] ?? 0),
        ], $this->stations->stations());
    }

    public function find(string $id): ?array
    {
        $row = $this->scopedRow('alerts', $id);
        if ($row === null) {
            return null;
        }

        return $row + ($this->db->fetchOne(
            "SELECT s.name AS station_name, CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                    CONCAT(k.first_name, ' ', k.last_name) AS acknowledged_by_name, CONCAT(r.first_name, ' ', r.last_name) AS resolved_by_name
               FROM alerts a JOIN stations s ON s.id = a.station_id LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN users k ON k.id = a.acknowledged_by LEFT JOIN users r ON r.id = a.resolved_by
              WHERE a.id = :id",
            ['id' => $id]
        ) ?? []);
    }

    /** Ouverte → vue. Sans effet sur une alerte déjà vue ou traitée. */
    public function acknowledge(string $id, string $userId): bool
    {
        $alert = $this->find($id);
        if ($alert === null || $alert['status'] !== 'open') {
            return false;
        }
        $this->db->execute(
            "UPDATE alerts SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = :user WHERE id = :id AND station_id = :station AND status = 'open'",
            ['user' => $userId, 'id' => $id, 'station' => $this->stations->writable((string) $alert['station_id'], true)]
        );

        return true;
    }

    /** Ouverte ou vue → traitée. */
    public function resolve(string $id, string $userId, ?string $note): bool
    {
        $alert = $this->find($id);
        if ($alert === null || $alert['status'] === 'resolved') {
            return false;
        }
        $this->db->execute(
            "UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :user, resolution_note = :note
              WHERE id = :id AND station_id = :station",
            ['user' => $userId, 'note' => $note, 'id' => $id, 'station' => $this->stations->writable((string) $alert['station_id'], true)]
        );

        return true;
    }

    /**
     * Lève une alerte sur une station attribuée à l'utilisateur courant.
     *
     * @param array{user_id?: ?string, shift_id?: ?string, entity_type?: ?string, entity_id?: ?string,
     *              amount?: ?int, currency?: ?string, volume_l?: ?string, threshold_value?: ?string} $context
     */
    public function raise(string $stationId, string $type, string $severity, string $message, array $context = [], ?string $createdBy = null): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO alerts (id, station_id, shift_id, user_id, alert_type, severity, entity_type, entity_id, message,
                                 amount, currency, volume_l, threshold_value, created_by)
             VALUES (:id, :station, :shift, :user, :type, :severity, :entity_type, :entity_id, :message,
                     :amount, :currency, :volume, :threshold, :created_by)',
            [
                'id' => $id,
                'station' => $this->stations->writable($stationId),
                'shift' => $context['shift_id'] ?? null,
                'user' => $context['user_id'] ?? null,
                'type' => $type,
                'severity' => $severity,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'message' => mb_substr($message, 0, 255),
                'amount' => $context['amount'] ?? null,
                'currency' => $context['currency'] ?? null,
                'volume' => $context['volume_l'] ?? null,
                'threshold' => $context['threshold_value'] ?? null,
                'created_by' => $createdBy,
            ]
        );

        return $id;
    }

    /** Une alerte ouverte du même type existe-t-elle déjà pour cet utilisateur depuis $minutes minutes ? */
    public function hasRecentOpen(string $stationId, string $type, string $userId, int $minutes): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM alerts
              WHERE station_id = :station AND alert_type = :type AND user_id = :user AND status = 'open'
                AND created_at >= NOW() - INTERVAL :minutes MINUTE",
            ['station' => $this->stations->writable($stationId), 'type' => $type, 'user' => $userId, 'minutes' => $minutes]
        ) > 0;
    }
}
