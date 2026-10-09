<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

/**
 * Agrégations des tableaux de bord, faites en SQL (arithmétique DECIMAL exacte, jamais de flottant).
 *
 * Toutes les méthodes prennent les stations visées par scope() : la liste est toujours contrôlée par
 * StationContext, jamais reprise telle quelle de la requête. Les lignes corrigées (ajout seul : une
 * correction est une nouvelle ligne liée) sont exclues partout.
 *
 * Ventes : mêmes règles que ShiftCalculator, par pistolet et par tranche de poste —
 *   litres vendus = index final − index initial (+ passage à zéro) − sorties non vendues ;
 *   montant = litres × prix de la tranche, arrondi à l'unité ; en dollars, converti au taux de la tranche.
 * Seules les tranches relevées (index final saisi) comptent. Une vente est rattachée à la journée
 * d'exploitation de son poste.
 */
class DashboardRepository extends Repository
{
    protected const NOT_CORRECTED = 'NOT EXISTS (SELECT 1 FROM %1$s cx WHERE cx.corrects_id = %2$s.id)';

    protected const SALE_LINES = "
        SELECT sh.station_id, sh.id AS shift_id, sh.business_date, sh.attendant_id, p.name AS product, pr.currency, x.rate,
               sg.sequence, n.code AS nozzle, o.index_value AS opening, c.index_value AS closing, c.is_rollover,
               CASE WHEN c.is_rollover = 1 THEN n.rollover_index - o.index_value + c.index_value ELSE c.index_value - o.index_value END AS delivered_l,
               ROUND((
                   CASE WHEN c.is_rollover = 1 THEN n.rollover_index - o.index_value + c.index_value ELSE c.index_value - o.index_value END
                   - COALESCE((SELECT SUM(ns.volume_l) FROM non_sale_outputs ns
                                WHERE ns.segment_id = o.segment_id AND ns.nozzle_id = o.nozzle_id
                                  AND NOT EXISTS (SELECT 1 FROM non_sale_outputs nc WHERE nc.corrects_id = ns.id)), 0)
               ), 2) AS sold_l,
               pr.unit_price
          FROM meter_readings o
          JOIN meter_readings c ON c.segment_id = o.segment_id AND c.nozzle_id = o.nozzle_id AND c.reading_type = 'closing'
                               AND NOT EXISTS (SELECT 1 FROM meter_readings cc WHERE cc.corrects_id = c.id)
          JOIN shifts sh ON sh.id = o.shift_id
          JOIN shift_segments sg ON sg.id = o.segment_id
          JOIN exchange_rates x ON x.id = sg.exchange_rate_id
          JOIN prices pr ON pr.id = o.price_id
          JOIN nozzles n ON n.id = o.nozzle_id
          JOIN tanks t ON t.id = n.tank_id
          JOIN products p ON p.id = t.product_id
         WHERE o.reading_type = 'opening'
           AND NOT EXISTS (SELECT 1 FROM meter_readings oc WHERE oc.corrects_id = o.id)
           AND %s AND sh.business_date BETWEEN :from AND :to";

    /** Montant en CDF d'une ligne de vente : arrondi au plus proche, comme ShiftCalculator et Money::usdCentsToCdf. */
    protected const AMOUNT_CDF = "CASE WHEN l.currency = 'USD' THEN ROUND(ROUND(l.sold_l * l.unit_price) * l.rate / 100) ELSE ROUND(l.sold_l * l.unit_price) END";

    /**
     * Stations visées : celle demandée si l'utilisateur y a droit (403 sinon), « all » pour toutes ses
     * stations, sinon la station active de l'en-tête (ou toutes s'il n'y en a pas).
     *
     * @return list<string>
     */
    public function scope(mixed $requested): array
    {
        if ($requested === 'all') {
            return $this->stations->stationIds();
        }
        if (is_string($requested) && $requested !== '') {
            $this->stations->assertAllows($requested);

            return [$requested];
        }

        return $this->stations->readIds();
    }

    /** @return array<string, array{sold_l: string, amount_cdf: int}> ventes par station */
    public function salesByStation(array $ids, string $from, string $to): array
    {
        $out = [];
        foreach ($this->sales($ids, $from, $to, 'l.station_id') as $row) {
            $out[$row['k']] = ['sold_l' => (string) $row['sold_l'], 'amount_cdf' => (int) $row['amount_cdf']];
        }

        return $out;
    }

    /** @return list<array{product: string, sold_l: string, amount_cdf: int}> ventes par produit, du plus vendu au moins vendu */
    public function salesByProduct(array $ids, string $from, string $to): array
    {
        $rows = array_map(
            static fn (array $r): array => ['product' => (string) $r['k'], 'sold_l' => (string) $r['sold_l'], 'amount_cdf' => (int) $r['amount_cdf']],
            $this->sales($ids, $from, $to, 'l.product')
        );
        usort($rows, static fn (array $a, array $b): int => [$b['amount_cdf'], $a['product']] <=> [$a['amount_cdf'], $b['product']]);

        return $rows;
    }

    /** @return array<string, array{sold_l: string, amount_cdf: int}> ventes par journée d'exploitation */
    public function salesByDay(array $ids, string $from, string $to): array
    {
        $out = [];
        foreach ($this->sales($ids, $from, $to, 'l.business_date') as $row) {
            $out[$row['k']] = ['sold_l' => (string) $row['sold_l'], 'amount_cdf' => (int) $row['amount_cdf']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function sales(array $ids, string $from, string $to, string $groupBy): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT $groupBy AS k, SUM(l.sold_l) AS sold_l, SUM(" . self::AMOUNT_CDF . ') AS amount_cdf
               FROM (' . sprintf(self::SALE_LINES, $scope) . ") l GROUP BY $groupBy ORDER BY $groupBy",
            $params + ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Recettes remises en caisse, par station et par devise.
     *
     * @return array<string, array{CDF: int, USD: int, USD_cdf: int}> USD en cents, USD_cdf : contre-valeur au taux de la tranche
     */
    public function receipts(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT sh.station_id, h.currency, SUM(h.amount) AS amount, SUM(h.amount_cdf) AS amount_cdf
               FROM cash_handovers h JOIN shifts sh ON sh.id = h.shift_id
              WHERE $scope AND sh.business_date BETWEEN :from AND :to AND " . sprintf(self::NOT_CORRECTED, 'cash_handovers', 'h') . '
              GROUP BY sh.station_id, h.currency',
            $params + ['from' => $from, 'to' => $to]
        ) as $row) {
            $out[$row['station_id']] ??= ['CDF' => 0, 'USD' => 0, 'USD_cdf' => 0];
            if ($row['currency'] === 'USD') {
                $out[$row['station_id']]['USD'] = (int) $row['amount'];
                $out[$row['station_id']]['USD_cdf'] = (int) $row['amount_cdf'];
            } else {
                $out[$row['station_id']]['CDF'] = (int) $row['amount'];
            }
        }

        return $out;
    }

    /**
     * Écarts de caisse des postes validés, par station et par pompiste, du plus gros manquant au plus gros excédent.
     *
     * @return list<array{station_id: string, attendant_id: string, attendant: string, shifts: int, expected: int, variance: int, shortage: int, surplus: int}>
     */
    public function cashVariances(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'station_id' => (string) $r['station_id'], 'attendant_id' => (string) $r['attendant_id'], 'attendant' => trim($r['first_name'] . ' ' . $r['last_name']),
            'shifts' => (int) $r['shifts'], 'expected' => (int) $r['expected'], 'variance' => (int) $r['variance'], 'shortage' => (int) $r['shortage'], 'surplus' => (int) $r['surplus'],
        ], $this->db->fetchAll(
            "SELECT sh.station_id, sh.attendant_id, u.first_name, u.last_name, COUNT(*) AS shifts,
                    SUM(sh.expected_amount) AS expected, SUM(sh.variance_amount) AS variance,
                    SUM(LEAST(sh.variance_amount, 0)) AS shortage, SUM(GREATEST(sh.variance_amount, 0)) AS surplus
               FROM shifts sh JOIN users u ON u.id = sh.attendant_id
              WHERE $scope AND sh.business_date BETWEEN :from AND :to AND sh.status = 'validated'
              GROUP BY sh.station_id, sh.attendant_id, u.first_name, u.last_name
              ORDER BY variance, u.last_name, u.first_name",
            $params + ['from' => $from, 'to' => $to]
        ));
    }

    /** @return array<string, array{open: int, closed: int, validated: int}> postes de la période par statut */
    public function shiftCounts(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT station_id, SUM(status = 'open') AS open, SUM(status = 'closed') AS closed, SUM(status = 'validated') AS validated
               FROM shifts WHERE $scope AND business_date BETWEEN :from AND :to GROUP BY station_id",
            $params + ['from' => $from, 'to' => $to]
        ) as $row) {
            $out[$row['station_id']] = ['open' => (int) $row['open'], 'closed' => (int) $row['closed'], 'validated' => (int) $row['validated']];
        }

        return $out;
    }

    /**
     * Écarts de stock constatés aux jaugeages de la période (mesuré − théorique).
     *
     * @return array<string, array{dips: int, variance_l: string, variance_cdf: int}>
     */
    public function stockVariances(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('d.station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT d.station_id, COUNT(*) AS dips, SUM(d.variance_l) AS variance_l, COALESCE(SUM(d.variance_amount), 0) AS variance_cdf
               FROM tank_dips d
              WHERE $scope AND d.measured_at >= :from AND d.measured_at < :to + INTERVAL 1 DAY AND d.variance_l IS NOT NULL
                AND " . sprintf(self::NOT_CORRECTED, 'tank_dips', 'd') . '
              GROUP BY d.station_id',
            $params + ['from' => $from, 'to' => $to]
        ) as $row) {
            $out[$row['station_id']] = ['dips' => (int) $row['dips'], 'variance_l' => (string) $row['variance_l'], 'variance_cdf' => (int) $row['variance_cdf']];
        }

        return $out;
    }

    /**
     * Livraisons de la période : volume reçu et écarts (annoncé / reçu, reçu / variation de cuve).
     *
     * @return array<string, array{deliveries: int, received_l: string, announced_variance_l: string, tank_variance_l: string}>
     */
    public function deliveryTotals(array $ids, string $from, string $to): array
    {
        [$scope, $params] = $this->in('d.station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT d.station_id, COUNT(*) AS deliveries, SUM(d.received_volume_l) AS received_l,
                    COALESCE(SUM(d.announced_variance_l), 0) AS announced_variance_l, COALESCE(SUM(d.tank_variance_l), 0) AS tank_variance_l
               FROM deliveries d
              WHERE $scope AND d.delivered_at >= :from AND d.delivered_at < :to + INTERVAL 1 DAY AND " . sprintf(self::NOT_CORRECTED, 'deliveries', 'd') . '
              GROUP BY d.station_id',
            $params + ['from' => $from, 'to' => $to]
        ) as $row) {
            $out[$row['station_id']] = [
                'deliveries' => (int) $row['deliveries'], 'received_l' => (string) $row['received_l'],
                'announced_variance_l' => (string) $row['announced_variance_l'], 'tank_variance_l' => (string) $row['tank_variance_l'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> dernières livraisons, les plus récentes d'abord */
    public function recentDeliveries(array $ids, int $limit = 5): array
    {
        [$scope, $params] = $this->in('d.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT d.id, d.station_id, s.name AS station, d.delivered_at, d.supplier_name, d.delivery_note_no, d.invoiced_volume_l, d.received_volume_l,
                    d.announced_variance_l, d.tank_variance_l, t.code AS tank, p.name AS product
               FROM deliveries d JOIN stations s ON s.id = d.station_id JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id
              WHERE $scope AND " . sprintf(self::NOT_CORRECTED, 'deliveries', 'd') . '
              ORDER BY d.delivered_at DESC, d.id DESC LIMIT ' . max(1, min(50, $limit)),
            $params
        );
    }

    /**
     * Cuves en service avec leur dernier jaugeage et le seuil de stock bas de leur station.
     *
     * @return list<array<string, mixed>> volume_l et measured_at à null tant que la cuve n'a pas été jaugée
     */
    public function tankStocks(array $ids, string $defaultLowStock): array
    {
        [$scope, $params] = $this->in('t.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT t.id, t.station_id, s.name AS station, t.code, t.name, t.capacity_l, p.name AS product, d.volume_l, d.measured_at,
                    CAST(COALESCE(st.setting_value, :low) AS DECIMAL(12,2)) AS low_stock_l
               FROM tanks t
               JOIN stations s ON s.id = t.station_id
               JOIN products p ON p.id = t.product_id
               LEFT JOIN station_settings st ON st.station_id = t.station_id AND st.setting_key = 'low_stock_l'
               LEFT JOIN tank_dips d ON d.id = (
                        SELECT d2.id FROM tank_dips d2
                         WHERE d2.tank_id = t.id AND NOT EXISTS (SELECT 1 FROM tank_dips dc WHERE dc.corrects_id = d2.id)
                         ORDER BY d2.measured_at DESC, d2.id DESC LIMIT 1)
              WHERE t.is_active = 1 AND $scope
              ORDER BY s.name, t.code",
            $params + ['low' => $defaultLowStock]
        );
    }

    /** @return array<string, array{rate: int, effective_at: string}> taux USD/CDF en vigueur à $at, par station */
    public function currentRates(array $ids, string $at): array
    {
        [$scope, $params] = $this->in('x.station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT x.station_id, x.rate, x.effective_at FROM exchange_rates x
              WHERE $scope AND x.from_currency = 'USD' AND x.to_currency = 'CDF'
                AND x.effective_at = (SELECT MAX(y.effective_at) FROM exchange_rates y
                                       WHERE y.station_id = x.station_id AND y.from_currency = 'USD' AND y.to_currency = 'CDF' AND y.effective_at <= :at)",
            $params + ['at' => $at]
        ) as $row) {
            $out[$row['station_id']] = ['rate' => (int) $row['rate'], 'effective_at' => (string) $row['effective_at']];
        }

        return $out;
    }

    /** @return array<string, array{open: int, acknowledged: int, critical: int}> alertes non traitées, par station */
    public function alertCounts(array $ids): array
    {
        [$scope, $params] = $this->in('station_id', $ids);
        $out = [];
        foreach ($ids === [] ? [] : $this->db->fetchAll(
            "SELECT station_id, SUM(status = 'open') AS open, SUM(status = 'acknowledged') AS acknowledged, SUM(severity = 'critical') AS critical
               FROM alerts WHERE $scope AND status <> 'resolved' GROUP BY station_id",
            $params
        ) as $row) {
            $out[$row['station_id']] = ['open' => (int) $row['open'], 'acknowledged' => (int) $row['acknowledged'], 'critical' => (int) $row['critical']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> alertes ouvertes, les plus graves puis les plus récentes d'abord */
    public function openAlerts(array $ids, int $limit = 6): array
    {
        [$scope, $params] = $this->in('a.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT a.id, a.station_id, s.name AS station, a.alert_type, a.severity, a.message, a.created_at
               FROM alerts a JOIN stations s ON s.id = a.station_id
              WHERE $scope AND a.status = 'open'
              ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.created_at DESC, a.id DESC LIMIT " . max(1, min(50, $limit)),
            $params
        );
    }

    /** @return list<array<string, mixed>> alertes non traitées (ouvertes ou vues), les plus graves puis les plus récentes d'abord */
    public function pendingAlerts(array $ids, int $limit = 200): array
    {
        [$scope, $params] = $this->in('a.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT a.id, a.station_id, s.name AS station, a.alert_type, a.severity, a.status, a.message, a.created_at,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS concerned
               FROM alerts a JOIN stations s ON s.id = a.station_id LEFT JOIN users u ON u.id = a.user_id
              WHERE $scope AND a.status <> 'resolved'
              ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.created_at DESC, a.id DESC LIMIT " . max(1, min(500, $limit)),
            $params
        );
    }

    /** @return list<array<string, mixed>> postes clôturés pas encore validés, les plus anciens d'abord */
    public function shiftsToValidate(array $ids, int $limit = 20): array
    {
        [$scope, $params] = $this->in('sh.station_id', $ids);
        if ($ids === []) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT sh.id, sh.station_id, s.name AS station, sh.business_date, sh.label, sh.closed_at, sh.expected_amount,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS attendant
               FROM shifts sh JOIN stations s ON s.id = sh.station_id JOIN users u ON u.id = sh.attendant_id
              WHERE $scope AND sh.status = 'closed' ORDER BY sh.closed_at, sh.id LIMIT " . max(1, min(100, $limit)),
            $params
        );
    }

    /** @return array<string, ?string> dernière synchronisation reçue de chaque station (renseignée sur le serveur central) */
    public function lastSyncs(array $ids): array
    {
        [$scope, $params] = $this->in('id', $ids);

        return $ids === [] ? [] : array_column($this->db->fetchAll("SELECT id, last_sync_at FROM stations WHERE $scope", $params), 'last_sync_at', 'id');
    }

    /**
     * @param list<string> $ids stations issues de scope()
     * @return array{0: string, 1: array<string, string>}
     */
    protected function in(string $column, array $ids): array
    {
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $this->stations->assertAllows($id);
            $params["st_$i"] = $id;
        }

        return [$params === [] ? '1=0' : "$column IN (:" . implode(', :', array_keys($params)) . ')', $params];
    }
}
