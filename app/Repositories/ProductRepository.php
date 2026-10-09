<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

final class ProductRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function listForStation(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM tanks t WHERE t.product_id = p.id) AS tank_count
               FROM products p WHERE p.station_id = :s ORDER BY p.name',
            ['s' => $stationId]
        );
    }

    public function find(string $id): ?array
    {
        return $this->scopedRow('products', $id);
    }

    public function codeExists(string $stationId, string $code): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM products WHERE station_id = :s AND code = :c', ['s' => $stationId, 'c' => $code]);
    }

    /** @param array{code: string, name: string} $data */
    public function create(string $stationId, array $data, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO products (id, station_id, code, name, created_by) VALUES (:id, :s, :code, :name, :user)',
            ['id' => $id, 's' => $this->stations->writable($stationId, true), 'code' => $data['code'], 'name' => $data['name'], 'user' => $userId]
        );

        return $id;
    }

    public function setActive(string $id, string $stationId, bool $active): void
    {
        $this->db->execute(
            'UPDATE products SET is_active = :a WHERE id = :id AND station_id = :s',
            ['a' => $active ? 1 : 0, 'id' => $id, 's' => $this->stations->writable($stationId, true)]
        );
    }
}
