<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

/** Distributeurs (pompes) d'une station : chacun regroupe des pistolets, d'un ou de plusieurs produits. */
final class PumpRepository extends Repository
{
    /**
     * Distributeurs de la station avec leurs pistolets en service et les produits qu'ils distribuent.
     *
     * @return list<array<string, mixed>> nozzle_count, nozzle_codes et products (noms séparés par « , »)
     */
    public function listForStation(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return array_map(static fn (array $row): array => ['nozzle_count' => (int) $row['nozzle_count'], 'product_count' => (int) $row['product_count']] + $row, $this->db->fetchAll(
            "SELECT pu.*, COUNT(n.id) AS nozzle_count, COUNT(DISTINCT p.id) AS product_count,
                    GROUP_CONCAT(DISTINCT n.code ORDER BY n.code SEPARATOR ', ') AS nozzle_codes,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS products
               FROM pumps pu
               LEFT JOIN nozzles n ON n.pump_id = pu.id AND n.is_active = 1
               LEFT JOIN tanks t ON t.id = n.tank_id LEFT JOIN products p ON p.id = t.product_id
              WHERE pu.station_id = :s
              GROUP BY pu.id ORDER BY pu.is_active DESC, pu.code",
            ['s' => $stationId]
        ));
    }

    public function find(string $id): ?array
    {
        return $this->scopedRow('pumps', $id);
    }

    public function codeExists(string $stationId, string $code, ?string $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM pumps WHERE station_id = :s AND code = :c AND id <> :id',
            ['s' => $stationId, 'c' => $code, 'id' => $exceptId ?? '']
        );
    }

    /** Vrai si un pistolet du distributeur est tenu par un poste ouvert. */
    public function inOpenShift(string $pumpId): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM nozzles n JOIN meter_readings mr ON mr.nozzle_id = n.id JOIN shifts sh ON sh.id = mr.shift_id
              WHERE n.pump_id = :p AND sh.status = 'open' LIMIT 1",
            ['p' => $pumpId]
        );
    }

    /** @param array{code: string, name: string} $data */
    public function create(string $stationId, array $data, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO pumps (id, station_id, code, name, created_by) VALUES (:id, :s, :code, :name, :user)',
            ['id' => $id, 's' => $this->stations->writable($stationId, true), 'code' => $data['code'], 'name' => $data['name'], 'user' => $userId]
        );

        return $id;
    }

    /** @param array{code: string, name: string, is_active: bool} $data */
    public function update(string $id, string $stationId, array $data): void
    {
        $this->db->execute(
            'UPDATE pumps SET code = :code, name = :name, is_active = :active WHERE id = :id AND station_id = :s',
            ['code' => $data['code'], 'name' => $data['name'], 'active' => $data['is_active'] ? 1 : 0, 'id' => $id, 's' => $this->stations->writable($stationId, true)]
        );
    }
}
