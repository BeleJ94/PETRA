<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

/**
 * Lignes détaillées des rapports (par poste, journalier, mensuel). Mêmes règles que les tableaux de bord :
 * stations contrôlées par StationContext, lignes corrigées exclues, calculs en DECIMAL exact.
 * Chaque ligne porte l'utilisateur responsable de l'opération.
 */
final class ReportRepository extends DashboardRepository
{
    private const USER = "TRIM(CONCAT(%1\$s.first_name, ' ', %1\$s.last_name))";

    /** Poste visible par l'utilisateur (403 s'il est d'une autre station), avec ses acteurs. */
    public function shift(string $id): ?array
    {
        $shift = $this->scopedRow('shifts', $id);

        return $shift === null ? null : ($this->shifts([(string) $shift['station_id']], (string) $shift['business_date'], (string) $shift['business_date'], $id)[0] ?? null);
    }

    /** @return list<array<string, mixed>> postes de la période, dans l'ordre d'ouverture */
    public function shifts(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT sh.id, sh.station_id, s.name AS station, sh.business_date, sh.label, sh.status, sh.opened_at, sh.closed_at, sh.validated_at,
                    sh.expected_amount, sh.received_amount, sh.variance_amount, sh.note, sh.attendant_id,
                    ' . sprintf(self::USER, 'a') . ' AS attendant, ' . sprintf(self::USER, 'ob') . ' AS opened_by,
                    ' . sprintf(self::USER, 'cb') . ' AS closed_by, ' . sprintf(self::USER, 'vb') . " AS validated_by
               FROM shifts sh
               JOIN stations s ON s.id = sh.station_id
               JOIN users a ON a.id = sh.attendant_id
               JOIN users ob ON ob.id = sh.opened_by
               LEFT JOIN users cb ON cb.id = sh.closed_by
               LEFT JOIN users vb ON vb.id = sh.validated_by
              WHERE $scope AND sh.business_date BETWEEN :from AND :to" . ($shiftId === null ? '' : ' AND sh.id = :shift') . '
              ORDER BY sh.opened_at, sh.id',
            $params + ['from' => $from, 'to' => $to] + ($shiftId === null ? [] : ['shift' => $shiftId])
        );
    }

    /**
     * Ventes relevées, une ligne par pistolet et par tranche : index, litres, prix, montant et contre-valeur en CDF.
     *
     * @return list<array<string, mixed>>
     */
    public function saleLines(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT l.*, ROUND(l.sold_l * l.unit_price) AS amount, ' . self::AMOUNT_CDF . ' AS amount_cdf,
                    ROUND(l.delivered_l - l.sold_l, 2) AS non_sale_l
               FROM (' . sprintf(self::SALE_LINES, $scope . ($shiftId === null ? '' : ' AND sh.id = :shift')) . ') l
              ORDER BY l.business_date, l.shift_id, l.sequence, l.nozzle',
            $params + ['from' => $from, 'to' => $to] + ($shiftId === null ? [] : ['shift' => $shiftId])
        );
    }

    /** @return list<array<string, mixed>> taux USD/CDF utilisés par les tranches des postes de la période */
    public function ratesUsed(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT sh.station_id, s.name AS station, x.id, x.rate, x.effective_at, COUNT(*) AS segments, MIN(sg.started_at) AS first_used_at,
                    ' . sprintf(self::USER, 'u') . " AS published_by
               FROM shift_segments sg
               JOIN shifts sh ON sh.id = sg.shift_id
               JOIN stations s ON s.id = sh.station_id
               JOIN exchange_rates x ON x.id = sg.exchange_rate_id
               JOIN users u ON u.id = x.created_by
              WHERE $scope AND sh.business_date BETWEEN :from AND :to" . ($shiftId === null ? '' : ' AND sh.id = :shift') . '
              GROUP BY sh.station_id, s.name, x.id, x.rate, x.effective_at, u.first_name, u.last_name
              ORDER BY s.name, x.effective_at',
            $params + ['from' => $from, 'to' => $to] + ($shiftId === null ? [] : ['shift' => $shiftId])
        );
    }

    /** @return list<array<string, mixed>> remises de caisse des postes de la période */
    public function handovers(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        return $this->shiftRows(
            'h.id, h.shift_id, sh.station_id, sh.business_date, h.amount, h.currency, h.amount_cdf, h.handed_at AS at, h.note, x.rate,
             ' . sprintf(self::USER, 'u') . ' AS user',
            'cash_handovers h JOIN shifts sh ON sh.id = h.shift_id JOIN users u ON u.id = h.received_by LEFT JOIN exchange_rates x ON x.id = h.exchange_rate_id',
            'cash_handovers',
            'h',
            'h.handed_at',
            $ids,
            $from,
            $to,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> sorties non vendues (essai, étalonnage, usage interne, retour cuve) */
    public function outputs(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        return $this->shiftRows(
            'o.id, o.shift_id, sh.station_id, o.output_type, o.volume_l, o.reason, o.occurred_at AS at, n.code AS nozzle, ' . sprintf(self::USER, 'u') . ' AS user',
            'non_sale_outputs o JOIN shifts sh ON sh.id = o.shift_id JOIN nozzles n ON n.id = o.nozzle_id JOIN users u ON u.id = o.created_by',
            'non_sale_outputs',
            'o',
            'o.occurred_at',
            $ids,
            $from,
            $to,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> ventes à crédit */
    public function creditSales(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        return $this->shiftRows(
            'c.id, c.shift_id, sh.station_id, c.customer_name, c.voucher_no, c.volume_l, c.amount, c.currency, c.sold_at AS at, n.code AS nozzle, ' . sprintf(self::USER, 'u') . ' AS user',
            'credit_sales c JOIN shifts sh ON sh.id = c.shift_id JOIN nozzles n ON n.id = c.nozzle_id JOIN users u ON u.id = c.created_by',
            'credit_sales',
            'c',
            'c.sold_at',
            $ids,
            $from,
            $to,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> dépenses de caisse rattachées à un poste */
    public function expenses(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        return $this->shiftRows(
            'e.id, e.shift_id, sh.station_id, e.category, e.description, e.amount, e.currency, e.amount_cdf, e.spent_at AS at, ' . sprintf(self::USER, 'u') . ' AS user',
            'cash_expenses e JOIN shifts sh ON sh.id = e.shift_id JOIN users u ON u.id = e.created_by',
            'cash_expenses',
            'e',
            'e.spent_at',
            $ids,
            $from,
            $to,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> */
    private function shiftRows(string $select, string $from, string $table, string $alias, string $order, array $ids, string $start, string $end, ?string $shiftId): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT $select FROM $from
              WHERE $scope AND sh.business_date BETWEEN :from AND :to" . ($shiftId === null ? '' : ' AND sh.id = :shift') . '
                AND ' . sprintf(self::NOT_CORRECTED, $table, $alias) . "
              ORDER BY $order, $alias.id",
            $params + ['from' => $start, 'to' => $end] + ($shiftId === null ? [] : ['shift' => $shiftId])
        );
    }

    /** @return list<array<string, mixed>> livraisons reçues du $from au $to compris */
    public function deliveries(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('d.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT d.id, d.station_id, s.name AS station, d.delivered_at AS at, d.supplier_name, d.delivery_note_no, d.truck_plate, d.invoiced_volume_l,
                    d.received_volume_l, d.announced_variance_l, d.tank_variance_l, t.code AS tank, p.name AS product, ' . sprintf(self::USER, 'u') . " AS user
               FROM deliveries d
               JOIN stations s ON s.id = d.station_id JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id
               JOIN users u ON u.id = d.received_by
              WHERE $scope AND d.delivered_at >= :from AND d.delivered_at < :to + INTERVAL 1 DAY AND " . sprintf(self::NOT_CORRECTED, 'deliveries', 'd') . '
              ORDER BY d.delivered_at, d.id',
            $params + ['from' => $from, 'to' => $to]
        );
    }

    /** @return list<array<string, mixed>> jaugeages du $from au $to compris */
    public function dips(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('d.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT d.id, d.station_id, s.name AS station, d.measured_at AS at, d.dip_type, d.depth_cm, d.volume_l, d.theoretical_l, d.variance_l, d.variance_amount,
                    t.code AS tank, p.name AS product, ' . sprintf(self::USER, 'u') . " AS user
               FROM tank_dips d
               JOIN stations s ON s.id = d.station_id JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id
               JOIN users u ON u.id = d.created_by
              WHERE $scope AND d.measured_at >= :from AND d.measured_at < :to + INTERVAL 1 DAY AND " . sprintf(self::NOT_CORRECTED, 'tank_dips', 'd') . '
              ORDER BY d.measured_at, d.id',
            $params + ['from' => $from, 'to' => $to]
        );
    }

    /** @return list<array<string, mixed>> alertes levées du $from au $to compris (ou rattachées à un poste) */
    public function alerts(array $ids, string $from, string $to, ?string $shiftId = null): array
    {
        [$scope, $params] = $this->in('a.station_id', $ids);
        if ($ids === []) {
            return [];
        }
        $when = $shiftId === null ? 'a.created_at >= :from AND a.created_at < :to + INTERVAL 1 DAY' : 'a.shift_id = :shift';

        return $this->db->fetchAll(
            'SELECT a.id, a.station_id, s.name AS station, a.created_at AS at, a.alert_type, a.severity, a.message, a.status, a.resolution_note,
                    ' . sprintf(self::USER, 'u') . " AS user
               FROM alerts a JOIN stations s ON s.id = a.station_id LEFT JOIN users u ON u.id = a.user_id
              WHERE $scope AND $when
              ORDER BY a.created_at, a.id",
            $params + ($shiftId === null ? ['from' => $from, 'to' => $to] : ['shift' => $shiftId])
        );
    }

    /** @return list<array<string, mixed>> prix et taux publiés du $from au $to compris */
    public function publications(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('v.station_id', $ids);
        if ($ids === []) {
            return [];
        }
        $window = 'v.effective_at >= :%1$s AND v.effective_at < :%2$s + INTERVAL 1 DAY';

        return $this->db->fetchAll(
            "SELECT 'price' AS kind, v.station_id, v.effective_at AS at, p.name AS product, v.unit_price AS value, v.currency, " . sprintf(self::USER, 'u') . " AS user
               FROM prices v JOIN products p ON p.id = v.product_id JOIN users u ON u.id = v.created_by
              WHERE $scope AND " . sprintf($window, 'from', 'to') . "
             UNION ALL
             SELECT 'rate', v.station_id, v.effective_at, NULL, v.rate, 'CDF', " . sprintf(self::USER, 'u') . '
               FROM exchange_rates v JOIN users u ON u.id = v.created_by
              WHERE ' . str_replace(':st_', ':sx_', $scope) . ' AND ' . sprintf($window, 'from2', 'to2') . '
              ORDER BY at, kind, product',
            $params + array_combine(array_map(static fn (string $k): string => 'sx_' . substr($k, 3), array_keys($params)), $params)
                + ['from' => $from, 'to' => $to, 'from2' => $from, 'to2' => $to]
        );
    }

    /**
     * Stock théorique de chaque cuve à l'instant $at : dernier jaugeage connu à cet instant
     * + livraisons − litres débités + retours en cuve depuis ce jaugeage (formule de StockCalculator).
     *
     * @return list<array<string, mixed>> theoretical_l à null tant que la cuve n'a jamais été jaugée
     */
    public function stockAt(array $ids, string $at): array
    {
        [$scope, $params] = $this->in('t.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT k.*, CASE WHEN k.dip_l IS NULL THEN NULL ELSE ROUND(k.dip_l + k.delivered_l - k.throughput_l + k.returned_l, 2) END AS theoretical_l
               FROM (
                 SELECT t.id, t.station_id, s.name AS station, t.code, t.capacity_l, p.name AS product, d.volume_l AS dip_l, d.measured_at AS dip_at,
                        (SELECT COALESCE(SUM(dl.received_volume_l), 0) FROM deliveries dl
                          WHERE dl.tank_id = t.id AND dl.delivered_at > COALESCE(d.measured_at, '1970-01-01') AND dl.delivered_at <= :at1
                            AND NOT EXISTS (SELECT 1 FROM deliveries dc WHERE dc.corrects_id = dl.id)) AS delivered_l,
                        (SELECT COALESCE(SUM(CASE WHEN c.is_rollover = 1 THEN n.rollover_index - o.index_value + c.index_value ELSE c.index_value - o.index_value END), 0)
                           FROM meter_readings c
                           JOIN nozzles n ON n.id = c.nozzle_id
                           JOIN meter_readings o ON o.segment_id = c.segment_id AND o.nozzle_id = c.nozzle_id AND o.reading_type = 'opening'
                                AND NOT EXISTS (SELECT 1 FROM meter_readings oc WHERE oc.corrects_id = o.id)
                          WHERE n.tank_id = t.id AND c.reading_type = 'closing' AND c.read_at > COALESCE(d.measured_at, '1970-01-01') AND c.read_at <= :at2
                            AND NOT EXISTS (SELECT 1 FROM meter_readings cc WHERE cc.corrects_id = c.id)) AS throughput_l,
                        (SELECT COALESCE(SUM(ns.volume_l), 0) FROM non_sale_outputs ns
                           JOIN nozzles n2 ON n2.id = ns.nozzle_id
                           JOIN meter_readings c2 ON c2.segment_id = ns.segment_id AND c2.nozzle_id = ns.nozzle_id AND c2.reading_type = 'closing'
                                AND NOT EXISTS (SELECT 1 FROM meter_readings c2c WHERE c2c.corrects_id = c2.id)
                          WHERE n2.tank_id = t.id AND ns.output_type = 'tank_return' AND c2.read_at > COALESCE(d.measured_at, '1970-01-01') AND c2.read_at <= :at3
                            AND NOT EXISTS (SELECT 1 FROM non_sale_outputs nc WHERE nc.corrects_id = ns.id)) AS returned_l
                   FROM tanks t
                   JOIN stations s ON s.id = t.station_id
                   JOIN products p ON p.id = t.product_id
                   LEFT JOIN tank_dips d ON d.id = (
                            SELECT d2.id FROM tank_dips d2
                             WHERE d2.tank_id = t.id AND d2.measured_at <= :at4 AND NOT EXISTS (SELECT 1 FROM tank_dips dx WHERE dx.corrects_id = d2.id)
                             ORDER BY d2.measured_at DESC, d2.id DESC LIMIT 1)
                  WHERE t.is_active = 1 AND $scope
               ) k
              ORDER BY k.station, k.code",
            $params + ['at1' => $at, 'at2' => $at, 'at3' => $at, 'at4' => $at]
        );
    }

    /** @return array<string, array{CDF: int, USD: int, USD_cdf: int}> recettes remises par journée d'exploitation */
    public function receiptsByDay(array $ids, string $from, string $to): array
    {
        $out = [];
        foreach ($this->handovers($ids, $from, $to) as $row) {
            $day = &$out[$row['business_date']];
            $day ??= ['CDF' => 0, 'USD' => 0, 'USD_cdf' => 0];
            if ($row['currency'] === 'USD') {
                $day['USD'] += (int) $row['amount'];
                $day['USD_cdf'] += (int) $row['amount_cdf'];
            } else {
                $day['CDF'] += (int) $row['amount'];
            }
            unset($day);
        }

        return $out;
    }
}
