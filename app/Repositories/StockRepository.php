<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Stock des cuves : jaugeages (ajout seul), livraisons et volumes débités entre deux jaugeages. */
final class StockRepository extends Repository
{
    public const SORTABLE = ['measured_at' => 'd.measured_at', 'tank' => 't.code', 'volume_l' => 'd.volume_l', 'variance_l' => 'd.variance_l', 'variance_amount' => 'd.variance_amount'];

    /** @return list<array<string, mixed>> cuves en service des stations visées, avec leur produit */
    public function tanks(): array
    {
        [$scope, $params] = $this->stations->clause('t.station_id');

        return $this->db->fetchAll(
            "SELECT t.id, t.station_id, t.code, t.name, t.capacity_l, p.id AS product_id, p.name AS product, s.name AS station,
                    (SELECT COUNT(*) FROM tank_calibrations c WHERE c.tank_id = t.id) AS calibration_count
               FROM tanks t JOIN products p ON p.id = t.product_id JOIN stations s ON s.id = t.station_id
              WHERE t.is_active = 1 AND $scope ORDER BY s.name, t.code",
            $params
        );
    }

    public function findTank(string $id): ?array
    {
        $tank = $this->scopedRow('tanks', $id);
        if ($tank === null) {
            return null;
        }

        return $tank + ($this->db->fetchOne('SELECT p.name AS product FROM products p WHERE p.id = :id', ['id' => $tank['product_id']]) ?? []);
    }

    /** @return list<array{depth_cm: string, volume_l: string}> barème de la cuve, par hauteur croissante */
    public function calibration(string $tankId): array
    {
        $this->guard($tankId);
        return $this->db->fetchAll('SELECT depth_cm, volume_l FROM tank_calibrations WHERE tank_id = :t ORDER BY depth_cm', ['t' => $tankId]);
    }

    /** Dernier jaugeage en vigueur de la cuve (hors lignes corrigées). */
    public function lastDip(string $tankId): ?array
    {
        $this->guard($tankId);
        return $this->db->fetchOne(
            'SELECT d.* FROM tank_dips d WHERE d.tank_id = :t AND NOT EXISTS (SELECT 1 FROM tank_dips c WHERE c.corrects_id = d.id)
              ORDER BY d.measured_at DESC, d.created_at DESC, d.id DESC LIMIT 1',
            ['t' => $tankId]
        );
    }

    /**
     * Litres débités par cuve (index relevés après $from et jusqu'à $to), sur les stations affichées.
     * Sert à estimer l'autonomie : au rythme des derniers jours, combien de jours de stock reste-t-il.
     *
     * @return array<string, string> litres par identifiant de cuve
     */
    public function throughputByTank(string $from, string $to): array
    {
        [$scope, $params] = $this->stations->clause('t.station_id');

        return array_column($this->db->fetchAll(
            "SELECT n.tank_id, SUM(CASE WHEN c.is_rollover = 1 THEN n.rollover_index - o.index_value + c.index_value ELSE c.index_value - o.index_value END) AS litres
               FROM meter_readings c
               JOIN nozzles n ON n.id = c.nozzle_id
               JOIN tanks t ON t.id = n.tank_id
               JOIN meter_readings o ON o.segment_id = c.segment_id AND o.nozzle_id = c.nozzle_id AND o.reading_type = 'opening'
                    AND NOT EXISTS (SELECT 1 FROM meter_readings z WHERE z.corrects_id = o.id)
              WHERE c.reading_type = 'closing' AND c.read_at > :from AND c.read_at <= :to
                AND NOT EXISTS (SELECT 1 FROM meter_readings z WHERE z.corrects_id = c.id) AND $scope
              GROUP BY n.tank_id",
            $params + ['from' => $from, 'to' => $to]
        ), 'litres', 'tank_id');
    }

    /** @return list<array<string, mixed>> derniers jaugeages d'une cuve, les plus récents d'abord */
    public function tankHistory(string $tankId, int $limit = 30): array
    {
        $this->guard($tankId);

        return $this->db->fetchAll(
            "SELECT d.id, d.measured_at, d.dip_type, d.depth_cm, d.volume_l, d.theoretical_l, d.variance_l, d.variance_amount, d.note,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author
               FROM tank_dips d JOIN users u ON u.id = d.created_by
              WHERE d.tank_id = :t AND NOT EXISTS (SELECT 1 FROM tank_dips c WHERE c.corrects_id = d.id)
              ORDER BY d.measured_at DESC, d.id DESC LIMIT " . max(1, min(120, $limit)),
            ['t' => $tankId]
        );
    }

    /** Litres reçus en livraison dans la cuve, après $from et jusqu'à $to compris. */
    public function deliveredVolume(string $tankId, string $from, string $to): string
    {
        $this->guard($tankId);
        return (string) $this->db->fetchValue(
            'SELECT COALESCE(SUM(d.received_volume_l), 0) FROM deliveries d
              WHERE d.tank_id = :t AND d.delivered_at > :from AND d.delivered_at <= :to
                AND NOT EXISTS (SELECT 1 FROM deliveries c WHERE c.corrects_id = d.id)',
            ['t' => $tankId, 'from' => $from, 'to' => $to]
        );
    }

    /**
     * Tranches relevées (index final lu après $from et jusqu'à $to) des pistolets de la cuve, avec leurs
     * sorties non vendues et la part reversée en cuve.
     *
     * @return list<array{opening: string, closing: string, is_rollover: bool, rollover_index: string, non_sale: string, tank_return: string}>
     */
    public function closedSegments(string $tankId, string $from, string $to): array
    {
        $this->guard($tankId);
        $rows = $this->db->fetchAll(
            "SELECT o.index_value AS opening, c.index_value AS closing, c.is_rollover, n.rollover_index,
                    (SELECT COALESCE(SUM(x.volume_l), 0) FROM non_sale_outputs x
                      WHERE x.segment_id = c.segment_id AND x.nozzle_id = c.nozzle_id
                        AND NOT EXISTS (SELECT 1 FROM non_sale_outputs y WHERE y.corrects_id = x.id)) AS non_sale,
                    (SELECT COALESCE(SUM(x.volume_l), 0) FROM non_sale_outputs x
                      WHERE x.segment_id = c.segment_id AND x.nozzle_id = c.nozzle_id AND x.output_type = 'tank_return'
                        AND NOT EXISTS (SELECT 1 FROM non_sale_outputs y WHERE y.corrects_id = x.id)) AS tank_return
               FROM meter_readings c
               JOIN nozzles n ON n.id = c.nozzle_id
               JOIN meter_readings o ON o.segment_id = c.segment_id AND o.nozzle_id = c.nozzle_id AND o.reading_type = 'opening'
                    AND NOT EXISTS (SELECT 1 FROM meter_readings z WHERE z.corrects_id = o.id)
              WHERE n.tank_id = :t AND c.reading_type = 'closing' AND c.read_at > :from AND c.read_at <= :to
                AND NOT EXISTS (SELECT 1 FROM meter_readings z WHERE z.corrects_id = c.id)",
            ['t' => $tankId, 'from' => $from, 'to' => $to]
        );

        return array_map(static fn (array $r): array => [
            'opening' => (string) $r['opening'], 'closing' => (string) $r['closing'], 'is_rollover' => (bool) $r['is_rollover'],
            'rollover_index' => (string) $r['rollover_index'], 'non_sale' => (string) $r['non_sale'], 'tank_return' => (string) $r['tank_return'],
        ], $rows);
    }

    /** Un poste ouvert débite-t-il en ce moment dans cette cuve (ventes pas encore relevées) ? */
    public function hasUnreadSales(string $tankId): bool
    {
        $this->guard($tankId);
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM shifts sh
               JOIN meter_readings mr ON mr.shift_id = sh.id AND mr.reading_type = 'opening'
               JOIN nozzles n ON n.id = mr.nozzle_id
              WHERE sh.status = 'open' AND n.tank_id = :t LIMIT 1",
            ['t' => $tankId]
        );
    }

    /**
     * @param array{dip_type: string, depth_cm: string, volume_l: string, water_cm: ?string, theoretical_l: ?string, variance_l: ?string,
     *              variance_amount: ?int, note: ?string} $data
     */
    public function addDip(string $stationId, string $tankId, ?string $shiftId, array $data, string $measuredAt, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO tank_dips (id, station_id, tank_id, shift_id, dip_type, depth_cm, volume_l, water_cm, theoretical_l, variance_l, variance_amount, currency, measured_at, note, created_by)
             VALUES (:id, :s, :tank, :shift, :type, :depth, :volume, :water, :theoretical, :variance, :amount, :currency, :at, :note, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'tank' => $tankId, 'shift' => $shiftId, 'type' => $data['dip_type'],
                'depth' => $data['depth_cm'], 'volume' => $data['volume_l'], 'water' => $data['water_cm'], 'theoretical' => $data['theoretical_l'],
                'variance' => $data['variance_l'], 'amount' => $data['variance_amount'], 'currency' => $data['variance_amount'] === null ? null : 'CDF',
                'at' => $measuredAt, 'note' => $data['note'], 'user' => $userId,
            ]
        );

        return $id;
    }

    public function datatable(DataTableRequest $dt, ?string $tankId = null): array
    {
        [$scope, $params] = $this->stations->clause('d.station_id');
        $filters = ['NOT EXISTS (SELECT 1 FROM tank_dips c WHERE c.corrects_id = d.id)'];
        $filterParams = [];
        if ($tankId !== null) {
            $filters[] = 'd.tank_id = :tank';
            $filterParams['tank'] = $tankId;
        }

        return $this->paginate(
            $dt,
            "d.id, d.measured_at, d.dip_type, d.depth_cm, d.volume_l, d.water_cm, d.theoretical_l, d.variance_l, d.variance_amount, d.note,
             t.code AS tank, p.name AS product, s.name AS station, CONCAT(u.first_name, ' ', u.last_name) AS author",
            'tank_dips d JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id JOIN stations s ON s.id = d.station_id JOIN users u ON u.id = d.created_by',
            [$scope],
            $params,
            $filters,
            $filterParams,
            ['t.code', 'p.name', 'u.last_name', 'd.note'],
        );
    }

    /** Toute lecture par cuve passe par ici : une cuve d'une station non attribuée lève un 403. */
    private function guard(string $tankId): void
    {
        $this->scopedRow('tanks', $tankId) ?? throw \Petra\Core\HttpException::notFound();
    }

    /** Une alerte ouverte de ce type existe-t-elle déjà pour cette cuve ? */
    public function hasOpenAlert(string $stationId, string $type, string $tankId): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM alerts WHERE station_id = :s AND alert_type = :type AND entity_type = 'tank' AND entity_id = :tank AND status = 'open' LIMIT 1",
            ['s' => $stationId, 'type' => $type, 'tank' => $tankId]
        );
    }
}
