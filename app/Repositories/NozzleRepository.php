<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

final class NozzleRepository extends Repository
{
    public const SORTABLE = ['code' => 'n.code', 'name' => 'n.name', 'pump' => 'pu.code', 'tank' => 't.code', 'product' => 'p.name', 'is_active' => 'n.is_active'];

    public function datatable(string $stationId, DataTableRequest $dt): array
    {
        $this->stations->assertAllows($stationId);

        return $this->paginate(
            $dt,
            'n.id, n.code, n.name, pu.code AS pump, n.is_active, t.code AS tank, p.name AS product',
            'nozzles n JOIN tanks t ON t.id = n.tank_id JOIN products p ON p.id = t.product_id LEFT JOIN pumps pu ON pu.id = n.pump_id',
            ['n.station_id = :station'],
            ['station' => $stationId],
            [],
            [],
            ['n.code', 'n.name', 'pu.code', 't.code', 'p.name'],
        );
    }

    public function find(string $id): ?array
    {
        return $this->scopedRow('nozzles', $id);
    }

    public function codeExists(string $stationId, string $code, ?string $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM nozzles WHERE station_id = :s AND code = :c AND id <> :id',
            ['s' => $stationId, 'c' => $code, 'id' => $exceptId ?? '']
        );
    }

    /** Vrai dès qu'un index a été relevé sur le pistolet : son distributeur et sa cuve (donc son produit) sont alors figés. */
    public function hasReadings(string $id): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM meter_readings WHERE nozzle_id = :id LIMIT 1', ['id' => $id]);
    }

    /** @param array{tank_id: string, pump_id: ?string, code: string, name: string} $data */
    public function create(string $stationId, array $data, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO nozzles (id, station_id, tank_id, pump_id, code, name, created_by) VALUES (:id, :s, :tank, :pump, :code, :name, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId, true), 'tank' => $data['tank_id'],
                'code' => $data['code'], 'name' => $data['name'], 'pump' => $data['pump_id'] ?? null, 'user' => $userId,
            ]
        );

        return $id;
    }

    /** @param array{tank_id: string, pump_id: ?string, code: string, name: string, rollover_index: string, is_active: bool} $data */
    public function update(string $id, string $stationId, array $data): void
    {
        $this->db->execute(
            'UPDATE nozzles SET tank_id = :tank, code = :code, name = :name, pump_id = :pump, rollover_index = :rollover, is_active = :active WHERE id = :id AND station_id = :s',
            [
                'tank' => $data['tank_id'], 'code' => $data['code'], 'name' => $data['name'], 'pump' => $data['pump_id'] ?? null,
                'rollover' => $data['rollover_index'], 'active' => $data['is_active'] ? 1 : 0, 'id' => $id, 's' => $this->stations->writable($stationId, true),
            ]
        );
    }
}
