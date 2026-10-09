<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Barème de jaugeage d'une cuve : hauteur (cm) → volume (litres). */
final class CalibrationRepository extends Repository
{
    public const SORTABLE = ['depth_cm' => 'c.depth_cm', 'volume_l' => 'c.volume_l'];

    public function datatable(string $stationId, string $tankId, DataTableRequest $dt): array
    {
        $this->stations->assertAllows($stationId);

        return $this->paginate(
            $dt,
            'c.id, c.depth_cm, c.volume_l',
            'tank_calibrations c',
            ['c.station_id = :station', 'c.tank_id = :tank'],
            ['station' => $stationId, 'tank' => $tankId],
            [],
            [],
            ['c.depth_cm', 'c.volume_l'],
        );
    }

    /** @return array{points: int, max_depth_cm: ?string, max_volume_l: ?string} */
    public function summary(string $stationId, string $tankId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchOne(
            'SELECT COUNT(*) AS points, MAX(depth_cm) AS max_depth_cm, MAX(volume_l) AS max_volume_l
               FROM tank_calibrations WHERE station_id = :s AND tank_id = :t',
            ['s' => $stationId, 't' => $tankId]
        ) ?? ['points' => 0, 'max_depth_cm' => null, 'max_volume_l' => null];
    }

    /**
     * Le point (hauteur, volume) garde-t-il le barème croissant ? Un point plus bas ne peut pas
     * contenir plus, un point plus haut ne peut pas contenir moins.
     */
    public function keepsIncreasing(string $tankId, string $depth, string $volume): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM tank_calibrations
              WHERE tank_id = :t AND ((depth_cm < :d1 AND volume_l > :v1) OR (depth_cm > :d2 AND volume_l < :v2))',
            ['t' => $tankId, 'd1' => $depth, 'v1' => $volume, 'd2' => $depth, 'v2' => $volume]
        ) === 0;
    }

    public function volumeAt(string $tankId, string $depth): ?string
    {
        $value = $this->db->fetchValue('SELECT volume_l FROM tank_calibrations WHERE tank_id = :t AND depth_cm = :d', ['t' => $tankId, 'd' => $depth]);

        return $value === null ? null : (string) $value;
    }

    /** Ajoute un point ou remplace le volume d'une hauteur déjà présente. */
    public function upsert(string $stationId, string $tankId, string $depth, string $volume, string $userId): void
    {
        $this->db->execute(
            'INSERT INTO tank_calibrations (id, station_id, tank_id, depth_cm, volume_l, created_by) VALUES (:id, :s, :t, :d, :v, :u)
             ON DUPLICATE KEY UPDATE volume_l = VALUES(volume_l)',
            ['id' => Uuid::v7(), 's' => $this->stations->writable($stationId, true), 't' => $tankId, 'd' => $depth, 'v' => $volume, 'u' => $userId]
        );
    }

    /**
     * Remplace tout le barème de la cuve par les lignes fournies (import CSV).
     *
     * @param list<array{depth_cm: string, volume_l: string}> $rows
     */
    public function replaceAll(string $stationId, string $tankId, array $rows, string $userId): void
    {
        $stationId = $this->stations->writable($stationId, true);
        $this->db->transaction(function () use ($stationId, $tankId, $rows, $userId): void {
            $this->db->execute('DELETE FROM tank_calibrations WHERE station_id = :s AND tank_id = :t', ['s' => $stationId, 't' => $tankId]);
            foreach ($rows as $row) {
                $this->db->execute(
                    'INSERT INTO tank_calibrations (id, station_id, tank_id, depth_cm, volume_l, created_by) VALUES (:id, :s, :t, :d, :v, :u)',
                    ['id' => Uuid::v7(), 's' => $stationId, 't' => $tankId, 'd' => $row['depth_cm'], 'v' => $row['volume_l'], 'u' => $userId]
                );
            }
        });
    }
}
