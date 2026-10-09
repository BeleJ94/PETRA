<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/**
 * Journal d'audit : ajout et lecture, rien d'autre. Il n'existe aucune méthode de modification ou de
 * suppression ici, et la base refuse UPDATE et DELETE sur audit_log (déclencheurs de la migration 0011).
 */
final class AuditLogRepository extends Repository
{
    public const SORTABLE = ['created_at' => 'a.created_at', 'user_name' => 'a.user_name', 'action' => 'a.action', 'entity_type' => 'a.entity_type'];

    /**
     * @param array{station_id: ?string, user_id: ?string, user_name: ?string, entity_type: string, entity_id: ?string,
     *              action: string, summary: ?string, before_data: ?string, after_data: ?string, ip: ?string, user_agent: ?string} $entry
     */
    public function insert(array $entry): string
    {
        // Une station non attribuée n'est jamais écrite : l'entrée est alors gardée sans station.
        $stationId = $this->stations->allows($entry['station_id']) ? $entry['station_id'] : null;
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO audit_log (id, station_id, user_name, entity_type, entity_id, action, summary,
                                    before_data, after_data, ip_address, user_agent, created_by)
             VALUES (:id, :station, :user_name, :entity_type, :entity_id, :action, :summary,
                     :before_data, :after_data, :ip, :user_agent, :created_by)',
            [
                'id' => $id,
                'station' => $stationId,
                'user_name' => $entry['user_name'],
                'entity_type' => $entry['entity_type'],
                'entity_id' => $entry['entity_id'],
                'action' => $entry['action'],
                'summary' => $entry['summary'] === null ? null : mb_substr($entry['summary'], 0, 255),
                'before_data' => $entry['before_data'],
                'after_data' => $entry['after_data'],
                'ip' => $entry['ip'],
                'user_agent' => $entry['user_agent'],
                'created_by' => $entry['user_id'],
            ]
        );

        return $id;
    }

    /**
     * Journal des stations visées. Sans station active, les entrées sans station (connexions, droits,
     * comptes) de l'organisation sont incluses.
     *
     * @param array{action?: ?string, entity_type?: ?string, from?: ?string, to?: ?string} $filters
     */
    public function datatable(DataTableRequest $dt, array $filters = []): array
    {
        [$scope, $params] = $this->stations->clause('a.station_id');
        if ($this->stations->activeId() === null && $this->stations->organizationId() !== null) {
            $scope = "($scope OR (a.station_id IS NULL AND u.organization_id = :org))";
            $params['org'] = $this->stations->organizationId();
        }
        $where = [];
        $filterParams = [];
        foreach (['action' => 'a.action = :action', 'entity_type' => 'a.entity_type = :entity_type', 'from' => 'a.created_at >= :from', 'to' => 'a.created_at < :to + INTERVAL 1 DAY'] as $key => $sql) {
            if (($filters[$key] ?? null) !== null) {
                $where[] = $sql;
                $filterParams[$key] = $filters[$key];
            }
        }

        return $this->paginate(
            $dt,
            "a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.summary, a.before_data, a.after_data, a.ip_address,
             COALESCE(CONCAT(u.first_name, ' ', u.last_name), a.user_name) AS user_name, s.name AS station",
            'audit_log a LEFT JOIN users u ON u.id = a.created_by LEFT JOIN stations s ON s.id = a.station_id',
            [$scope],
            $params,
            $where,
            $filterParams,
            ['a.summary', 'a.user_name', 'u.last_name', 'u.first_name', 'a.ip_address'],
        );
    }

    /** @return list<array<string, mixed>> historique d'une entité, du plus ancien au plus récent */
    public function forEntity(string $entityType, string $entityId, string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll(
            "SELECT a.created_at, a.action, a.summary, a.before_data, a.after_data, COALESCE(CONCAT(u.first_name, ' ', u.last_name), a.user_name) AS user_name
               FROM audit_log a LEFT JOIN users u ON u.id = a.created_by
              WHERE a.entity_type = :type AND a.entity_id = :id AND a.station_id = :s ORDER BY a.created_at, a.id",
            ['type' => $entityType, 'id' => $entityId, 's' => $stationId]
        );
    }
}
