<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Taux USD → CDF versionné par station : une ligne par version, jamais modifiée. */
final class ExchangeRateRepository extends Repository
{
    public const SORTABLE = ['effective_at' => 'x.effective_at', 'rate' => 'x.rate', 'author' => 'u.last_name'];

    /** Version en vigueur à l'instant $at : la plus récente dont effective_at ≤ $at. */
    public function versionAt(string $stationId, string $at): ?array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchOne(
            "SELECT * FROM exchange_rates WHERE station_id = :s AND from_currency = 'USD' AND to_currency = 'CDF' AND effective_at <= :at
              ORDER BY effective_at DESC LIMIT 1",
            ['s' => $stationId, 'at' => $at]
        );
    }

    public function latest(string $stationId): ?array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchOne('SELECT * FROM exchange_rates WHERE station_id = :s ORDER BY effective_at DESC LIMIT 1', ['s' => $stationId]);
    }

    public function create(string $stationId, int $rate, string $effectiveAt, ?string $note, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO exchange_rates (id, station_id, rate, effective_at, note, created_by) VALUES (:id, :s, :rate, :at, :note, :user)',
            ['id' => $id, 's' => $this->stations->administrable($stationId, false), 'rate' => $rate, 'at' => $effectiveAt, 'note' => $note, 'user' => $userId]
        );

        return $id;
    }

    public function datatable(DataTableRequest $dt): array
    {
        [$scope, $params] = $this->stations->clause('x.station_id');

        return $this->paginate(
            $dt,
            "x.id, x.effective_at, x.rate, x.note, s.name AS station, CONCAT(u.first_name, ' ', u.last_name) AS author",
            'exchange_rates x JOIN stations s ON s.id = x.station_id JOIN users u ON u.id = x.created_by',
            [$scope],
            $params,
            [],
            [],
            ['u.last_name', 'u.first_name', 'x.note'],
        );
    }
}
