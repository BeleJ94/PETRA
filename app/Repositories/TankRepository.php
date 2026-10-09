<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

final class TankRepository extends Repository
{
    public const SORTABLE = ['code' => 't.code', 'name' => 't.name', 'product' => 'p.name', 'capacity_l' => 't.capacity_l', 'is_active' => 't.is_active'];

    public function datatable(string $stationId, DataTableRequest $dt): array
    {
        $this->stations->assertAllows($stationId);

        return $this->paginate(
            $dt,
            't.id, t.code, t.name, t.capacity_l, t.is_active, p.name AS product,
             (SELECT COUNT(*) FROM tank_calibrations c WHERE c.tank_id = t.id) AS calibration_count,
             (SELECT COUNT(*) FROM nozzles n WHERE n.tank_id = t.id AND n.is_active = 1) AS nozzle_count',
            'tanks t JOIN products p ON p.id = t.product_id',
            ['t.station_id = :station'],
            ['station' => $stationId],
            [],
            [],
            ['t.code', 't.name', 'p.name'],
        );
    }

    /** @return list<array<string, mixed>> cuves actives, pour rattacher un pistolet */
    public function listActive(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll(
            'SELECT t.id, t.code, t.name, p.name AS product FROM tanks t JOIN products p ON p.id = t.product_id
              WHERE t.station_id = :s AND t.is_active = 1 ORDER BY t.code',
            ['s' => $stationId]
        );
    }

    public function find(string $id): ?array
    {
        return $this->scopedRow('tanks', $id);
    }

    public function codeExists(string $stationId, string $code, ?string $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM tanks WHERE station_id = :s AND code = :c AND id <> :id',
            ['s' => $stationId, 'c' => $code, 'id' => $exceptId ?? '']
        );
    }

    /** @param array{product_id: string, code: string, name: string, capacity_l: string} $data */
    public function create(string $stationId, array $data, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO tanks (id, station_id, product_id, code, name, capacity_l, created_by)
             VALUES (:id, :s, :product, :code, :name, :capacity, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId, true), 'product' => $data['product_id'],
                'code' => $data['code'], 'name' => $data['name'], 'capacity' => $data['capacity_l'], 'user' => $userId,
            ]
        );

        return $id;
    }

    /** @param array{code: string, name: string, capacity_l: string, is_active: bool} $data */
    public function update(string $id, string $stationId, array $data): void
    {
        $this->db->execute(
            'UPDATE tanks SET code = :code, name = :name, capacity_l = :capacity, is_active = :active WHERE id = :id AND station_id = :s',
            [
                'code' => $data['code'], 'name' => $data['name'], 'capacity' => $data['capacity_l'], 'active' => $data['is_active'] ? 1 : 0,
                'id' => $id, 's' => $this->stations->writable($stationId, true),
            ]
        );
    }

    public function activeNozzleCount(string $id): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM nozzles WHERE tank_id = :id AND is_active = 1', ['id' => $id]);
    }
}
