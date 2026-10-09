<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Prix versionnés : une ligne par version, jamais modifiée (la base refuse UPDATE et DELETE). */
final class PriceRepository extends Repository
{
    public const SORTABLE = ['effective_at' => 'pr.effective_at', 'product' => 'p.name', 'unit_price' => 'pr.unit_price', 'author' => 'u.last_name'];

    /** Version en vigueur à l'instant $at : la plus récente dont effective_at ≤ $at. */
    public function versionAt(string $productId, string $at): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM prices WHERE product_id = :p AND effective_at <= :at ORDER BY effective_at DESC LIMIT 1',
            ['p' => $productId, 'at' => $at]
        );
        if ($row !== null) {
            $this->stations->assertAllows((string) $row['station_id']);
        }

        return $row;
    }

    /** Dernière version publiée, quelle que soit sa date d'effet. */
    public function latest(string $productId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM prices WHERE product_id = :p ORDER BY effective_at DESC LIMIT 1', ['p' => $productId]);
        if ($row !== null) {
            $this->stations->assertAllows((string) $row['station_id']);
        }

        return $row;
    }

    public function create(string $stationId, string $productId, int $unitPrice, string $currency, string $effectiveAt, ?string $note, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO prices (id, station_id, product_id, unit_price, currency, effective_at, note, created_by)
             VALUES (:id, :s, :p, :price, :currency, :at, :note, :user)',
            ['id' => $id, 's' => $this->stations->administrable($stationId, false), 'p' => $productId, 'price' => $unitPrice, 'currency' => $currency, 'at' => $effectiveAt, 'note' => $note, 'user' => $userId]
        );

        return $id;
    }

    public function datatable(DataTableRequest $dt, ?string $productId = null): array
    {
        [$scope, $params] = $this->stations->clause('pr.station_id');
        $filters = [];
        $filterParams = [];
        if ($productId !== null) {
            $filters[] = 'pr.product_id = :product';
            $filterParams['product'] = $productId;
        }

        return $this->paginate(
            $dt,
            "pr.id, pr.effective_at, pr.unit_price, pr.currency, pr.note, p.name AS product, s.name AS station,
             CONCAT(u.first_name, ' ', u.last_name) AS author",
            'prices pr JOIN products p ON p.id = pr.product_id JOIN stations s ON s.id = pr.station_id JOIN users u ON u.id = pr.created_by',
            [$scope],
            $params,
            $filters,
            $filterParams,
            ['p.name', 'u.last_name', 'u.first_name', 'pr.note'],
        );
    }
}
