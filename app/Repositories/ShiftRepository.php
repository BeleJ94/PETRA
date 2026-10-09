<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Postes ouverts, tranches et relevés d'index (ajout seul). */
final class ShiftRepository extends Repository
{
    /**
     * Postes ouverts d'une station avec leur tranche en cours et, par pistolet, le relevé d'ouverture de
     * cette tranche. Avec $productId, seuls les postes qui servent ce produit sont retenus.
     *
     * @return list<array{shift_id: string, attendant: string, segment_id: string, sequence: int,
     *                    nozzles: list<array{nozzle_id: string, code: string, name: string, product_id: string, product: string, opening_index: string, price_id: string}>}>
     */
    public function openSegments(string $stationId, ?string $productId = null): array
    {
        $this->stations->assertAllows($stationId);
        $rows = $this->db->fetchAll(
            "SELECT sh.id AS shift_id, CONCAT(u.first_name, ' ', u.last_name) AS attendant, sg.id AS segment_id, sg.sequence,
                    n.id AS nozzle_id, n.code, n.name, p.id AS product_id, p.name AS product, mr.index_value AS opening_index, mr.price_id
               FROM shifts sh
               JOIN users u ON u.id = sh.attendant_id
               JOIN shift_segments sg ON sg.shift_id = sh.id
                    AND sg.sequence = (SELECT MAX(s2.sequence) FROM shift_segments s2 WHERE s2.shift_id = sh.id)
               JOIN meter_readings mr ON mr.segment_id = sg.id AND mr.reading_type = 'opening'
                    AND NOT EXISTS (SELECT 1 FROM meter_readings c WHERE c.corrects_id = mr.id)
               JOIN nozzles n ON n.id = mr.nozzle_id
               JOIN tanks t ON t.id = n.tank_id
               JOIN products p ON p.id = t.product_id
              WHERE sh.station_id = :s AND sh.status = 'open'
              ORDER BY sh.opened_at, sh.id, n.code",
            ['s' => $stationId]
        );

        $shifts = [];
        foreach ($rows as $row) {
            $shifts[$row['shift_id']] ??= [
                'shift_id' => $row['shift_id'], 'attendant' => $row['attendant'], 'segment_id' => $row['segment_id'],
                'sequence' => (int) $row['sequence'], 'nozzles' => [],
            ];
            $shifts[$row['shift_id']]['nozzles'][] = [
                'nozzle_id' => $row['nozzle_id'], 'code' => $row['code'], 'name' => $row['name'], 'product_id' => $row['product_id'],
                'product' => $row['product'], 'opening_index' => (string) $row['opening_index'], 'price_id' => $row['price_id'],
            ];
        }
        if ($productId !== null) {
            $shifts = array_filter($shifts, static fn (array $s): bool => in_array($productId, array_column($s['nozzles'], 'product_id'), true));
        }

        return array_values($shifts);
    }

    public const SORTABLE = ['opened_at' => 'sh.opened_at', 'business_date' => 'sh.business_date', 'attendant' => 'u.last_name', 'status' => 'sh.status', 'expected_amount' => 'sh.expected_amount'];

    /**
     * @param string|null $attendantId limite la liste aux postes de ce pompiste
     */
    public function datatable(DataTableRequest $dt, ?string $attendantId = null, ?string $status = null): array
    {
        [$scope, $params] = $this->stations->clause('sh.station_id');
        $base = [$scope];
        if ($attendantId !== null) {
            $base[] = 'sh.attendant_id = :attendant';
            $params['attendant'] = $attendantId;
        }
        $filters = [];
        $filterParams = [];
        if ($status !== null) {
            $filters[] = 'sh.status = :status';
            $filterParams['status'] = $status;
        }

        return $this->paginate(
            $dt,
            "sh.id, sh.business_date, sh.label, sh.status, sh.opened_at, sh.closed_at, sh.expected_amount, sh.currency,
             s.name AS station, CONCAT(u.first_name, ' ', u.last_name) AS attendant",
            'shifts sh JOIN stations s ON s.id = sh.station_id JOIN users u ON u.id = sh.attendant_id',
            $base,
            $params,
            $filters,
            $filterParams,
            ['u.last_name', 'u.first_name', 'sh.label', 's.name'],
        );
    }

    public function find(string $id): ?array
    {
        $row = $this->scopedRow('shifts', $id);
        if ($row === null) {
            return null;
        }
        $names = $this->db->fetchOne(
            "SELECT s.name AS station, CONCAT(a.first_name, ' ', a.last_name) AS attendant,
                    CONCAT(c.first_name, ' ', c.last_name) AS closed_by_name, CONCAT(v.first_name, ' ', v.last_name) AS validated_by_name
               FROM shifts sh JOIN stations s ON s.id = sh.station_id JOIN users a ON a.id = sh.attendant_id
               LEFT JOIN users c ON c.id = sh.closed_by LEFT JOIN users v ON v.id = sh.validated_by
              WHERE sh.id = :id",
            ['id' => $id]
        ) ?? [];

        return $row + $names;
    }

    /**
     * Résumé par journée d'exploitation, pour le sélecteur de semaine : nombre de postes, postes non soldés, écart validé.
     *
     * @param list<string> $stationIds stations déjà contrôlées par StationContext
     * @param string|null $attendantId limite aux postes d'un pompiste
     * @return array<string, array{shifts: int, pending: int, variance: int}> par date
     */
    public function daySummary(array $stationIds, string $from, string $to, ?string $attendantId = null): array
    {
        $params = ['from' => $from, 'to' => $to];
        foreach (array_values($stationIds) as $i => $id) {
            $this->stations->assertAllows($id);
            $params["st_$i"] = $id;
        }
        if (count($params) === 2) {
            return [];
        }
        $where = 'station_id IN (:' . implode(', :', array_slice(array_keys($params), 2)) . ') AND business_date BETWEEN :from AND :to';
        if ($attendantId !== null) {
            $where .= ' AND attendant_id = :a';
            $params['a'] = $attendantId;
        }
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT business_date, COUNT(*) AS shifts, SUM(status <> 'validated') AS pending,
                    COALESCE(SUM(CASE WHEN status = 'validated' THEN variance_amount ELSE 0 END), 0) AS variance
               FROM shifts WHERE $where GROUP BY business_date",
            $params
        ) as $row) {
            $out[(string) $row['business_date']] = ['shifts' => (int) $row['shifts'], 'pending' => (int) $row['pending'], 'variance' => (int) $row['variance']];
        }

        return $out;
    }

    /** Postes clôturés en attente de validation, sur les stations affichées : c'est la pastille du menu. */
    public function countToValidate(): int
    {
        [$scope, $params] = $this->stations->clause('station_id');

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM shifts WHERE status = 'closed' AND $scope", $params);
    }

    /**
     * Derniers postes d'un même créneau (même libellé) sur une station, les plus récents d'abord.
     *
     * @param string|null $attendantId limite aux postes d'un pompiste (qui ne voit que les siens)
     * @return list<array<string, mixed>>
     */
    public function slotHistory(string $stationId, ?string $label, int $limit = 30, ?string $attendantId = null): array
    {
        $this->stations->assertAllows($stationId);
        $where = 'sh.station_id = :s AND ' . ($label === null ? 'sh.label IS NULL' : 'sh.label = :l');
        $params = ['s' => $stationId] + ($label === null ? [] : ['l' => $label]);
        if ($attendantId !== null) {
            $where .= ' AND sh.attendant_id = :a';
            $params['a'] = $attendantId;
        }

        return $this->db->fetchAll(
            "SELECT sh.id, sh.business_date, sh.label, sh.status, sh.opened_at, sh.expected_amount, sh.received_amount, sh.variance_amount,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS attendant
               FROM shifts sh JOIN users u ON u.id = sh.attendant_id
              WHERE $where ORDER BY sh.opened_at DESC, sh.id DESC LIMIT " . max(1, min(120, $limit)),
            $params
        );
    }

    /** Poste ouvert de la station quand il n'y en a qu'un ; null s'il n'y en a aucun ou s'il y en a plusieurs. */
    public function currentOpen(string $stationId): ?array
    {
        $this->stations->assertAllows($stationId);
        $ids = array_column($this->db->fetchAll("SELECT id FROM shifts WHERE station_id = :s AND status = 'open' LIMIT 2", ['s' => $stationId]), 'id');

        return count($ids) === 1 ? $this->find((string) $ids[0]) : null;
    }

    /** Vrai s'il reste un distributeur en service qu'aucun poste ouvert ne tient : un poste peut être ouvert. */
    public function hasFreePump(string $stationId): bool
    {
        foreach ($this->nozzlesToOpen($stationId) as $nozzle) {
            if (!$nozzle['busy']) {
                return true;
            }
        }

        return false;
    }

    /** Vrai si le pompiste tient déjà un poste ouvert sur la station. */
    public function attendantHasOpen(string $stationId, string $attendantId): bool
    {
        return (bool) $this->db->fetchValue("SELECT 1 FROM shifts WHERE station_id = :s AND attendant_id = :a AND status = 'open'", ['s' => $stationId, 'a' => $attendantId]);
    }

    /**
     * Verrouille les pistolets le temps de la transaction et rend ceux qu'un poste ouvert tient déjà :
     * deux ouvertures simultanées ne peuvent pas se partager un distributeur.
     *
     * @param list<string> $nozzleIds
     * @return list<string> codes des pistolets déjà tenus
     */
    public function lockNozzles(array $nozzleIds): array
    {
        if ($nozzleIds === []) {
            return [];
        }
        $params = [];
        foreach (array_values($nozzleIds) as $i => $id) {
            $params["n$i"] = $id;
        }
        $in = ':' . implode(', :', array_keys($params));
        $this->db->fetchAll("SELECT id FROM nozzles WHERE id IN ($in) FOR UPDATE", $params);

        return array_column($this->db->fetchAll(
            "SELECT DISTINCT n.code FROM nozzles n JOIN meter_readings mr ON mr.nozzle_id = n.id JOIN shifts sh ON sh.id = mr.shift_id
              WHERE n.id IN ($in) AND sh.status = 'open' ORDER BY n.code",
            $params
        ), 'code');
    }

    /**
     * Distributeurs tenus par chaque poste, d'après ses relevés d'index.
     *
     * @param list<string> $shiftIds
     * @return array<string, string> identifiant du poste => codes des distributeurs (« D1, D2 »)
     */
    public function pumpsOfShifts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }
        $params = [];
        foreach (array_values($shiftIds) as $i => $id) {
            $params["s$i"] = $id;
        }

        return array_column($this->db->fetchAll(
            "SELECT mr.shift_id, GROUP_CONCAT(DISTINCT pu.code ORDER BY pu.code SEPARATOR ', ') AS pumps
               FROM meter_readings mr JOIN nozzles n ON n.id = mr.nozzle_id JOIN pumps pu ON pu.id = n.pump_id
              WHERE mr.shift_id IN (:" . implode(', :', array_keys($params)) . ') GROUP BY mr.shift_id',
            $params
        ), 'pumps', 'shift_id');
    }

    /**
     * Pistolets en service de la station, avec leur distributeur, leur produit, leur dernier index de fermeture
     * (null : pistolet jamais relevé) et « busy » quand un poste ouvert les tient déjà.
     *
     * @return list<array{nozzle_id: string, code: string, name: string, pump_id: ?string, pump_code: ?string, pump_name: ?string, product_id: string, product: string, last_index: ?string, busy: bool}>
     */
    public function nozzlesToOpen(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return array_map(static fn (array $row): array => ['last_index' => $row['last_index'] === null ? null : (string) $row['last_index'], 'busy' => (bool) $row['busy']] + $row, $this->db->fetchAll(
            "SELECT n.id AS nozzle_id, n.code, n.name, n.pump_id, pu.code AS pump_code, pu.name AS pump_name, p.id AS product_id, p.name AS product,
                    EXISTS (SELECT 1 FROM meter_readings o JOIN shifts so ON so.id = o.shift_id WHERE o.nozzle_id = n.id AND so.status = 'open') AS busy,
                    (SELECT mr.index_value FROM meter_readings mr
                      WHERE mr.nozzle_id = n.id AND mr.reading_type = 'closing'
                        AND NOT EXISTS (SELECT 1 FROM meter_readings c WHERE c.corrects_id = mr.id)
                      ORDER BY mr.read_at DESC, mr.created_at DESC, mr.id DESC LIMIT 1) AS last_index
               FROM nozzles n JOIN tanks t ON t.id = n.tank_id JOIN products p ON p.id = t.product_id
               LEFT JOIN pumps pu ON pu.id = n.pump_id
              WHERE n.station_id = :s AND n.is_active = 1 AND t.is_active = 1 AND p.is_active = 1 AND (pu.id IS NULL OR pu.is_active = 1)
              ORDER BY pu.code, n.code",
            ['s' => $stationId]
        ));
    }

    public function create(string $stationId, string $attendantId, string $businessDate, ?string $label, string $openedAt, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO shifts (id, station_id, attendant_id, business_date, label, opened_at, opened_by, created_by)
             VALUES (:id, :s, :attendant, :date, :label, :at, :opened_by, :created_by)',
            ['id' => $id, 's' => $this->stations->writable($stationId), 'attendant' => $attendantId, 'date' => $businessDate, 'label' => $label, 'at' => $openedAt, 'opened_by' => $userId, 'created_by' => $userId]
        );

        return $id;
    }

    /**
     * Tranches d'un poste avec, par pistolet, relevés en vigueur (hors lignes corrigées), prix et sorties non vendues.
     * Forme attendue par ShiftCalculator::compute().
     *
     * @return list<array<string, mixed>>
     */
    public function segments(string $stationId, string $shiftId): array
    {
        $this->stations->assertAllows($stationId);
        $segments = [];
        foreach ($this->db->fetchAll(
            'SELECT sg.id AS segment_id, sg.sequence, sg.reason, sg.started_at, x.rate
               FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id
              WHERE sg.shift_id = :shift AND sg.station_id = :s ORDER BY sg.sequence',
            ['shift' => $shiftId, 's' => $stationId]
        ) as $row) {
            $segments[$row['segment_id']] = ['sequence' => (int) $row['sequence'], 'rate' => (int) $row['rate'], 'nozzles' => []] + $row;
        }

        $outputs = [];
        foreach ($this->db->fetchAll(
            'SELECT o.segment_id, o.nozzle_id, SUM(o.volume_l) AS volume FROM non_sale_outputs o
              WHERE o.shift_id = :shift AND NOT EXISTS (SELECT 1 FROM non_sale_outputs c WHERE c.corrects_id = o.id)
              GROUP BY o.segment_id, o.nozzle_id',
            ['shift' => $shiftId]
        ) as $row) {
            $outputs[$row['segment_id']][$row['nozzle_id']] = (string) $row['volume'];
        }

        foreach ($this->db->fetchAll(
            "SELECT mr.segment_id, mr.nozzle_id, mr.reading_type, mr.index_value, mr.is_rollover, mr.price_id, n.code, n.name, n.rollover_index,
                    p.id AS product_id, p.name AS product, pr.unit_price, pr.currency
               FROM meter_readings mr
               JOIN nozzles n ON n.id = mr.nozzle_id JOIN tanks t ON t.id = n.tank_id JOIN products p ON p.id = t.product_id
               JOIN prices pr ON pr.id = mr.price_id
              WHERE mr.shift_id = :shift AND NOT EXISTS (SELECT 1 FROM meter_readings c WHERE c.corrects_id = mr.id)
              ORDER BY n.code, mr.reading_type",
            ['shift' => $shiftId]
        ) as $row) {
            if (!isset($segments[$row['segment_id']])) {
                continue;
            }
            $line = &$segments[$row['segment_id']]['nozzles'][$row['nozzle_id']];
            $line ??= [
                'nozzle_id' => $row['nozzle_id'], 'code' => $row['code'], 'name' => $row['name'], 'product_id' => $row['product_id'], 'product' => $row['product'],
                'opening' => '0.00', 'closing' => null, 'is_rollover' => false, 'rollover_index' => (string) $row['rollover_index'],
                'price_id' => $row['price_id'], 'unit_price' => (int) $row['unit_price'], 'currency' => $row['currency'],
                'non_sale' => $outputs[$row['segment_id']][$row['nozzle_id']] ?? '0.00',
            ];
            if ($row['reading_type'] === 'opening') {
                $line['opening'] = (string) $row['index_value'];
            } else {
                $line['closing'] = (string) $row['index_value'];
                $line['is_rollover'] = (bool) $row['is_rollover'];
            }
            unset($line);
        }

        return array_values(array_map(static fn (array $s): array => ['nozzles' => array_values($s['nozzles'])] + $s, $segments));
    }

    /** @return list<array<string, mixed>> sorties non vendues du poste, les plus récentes d'abord */
    public function outputs(string $stationId, string $shiftId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll(
            "SELECT o.id, o.output_type, o.volume_l, o.reason, o.occurred_at, n.code AS nozzle, p.name AS product, sg.sequence,
                    CONCAT(u.first_name, ' ', u.last_name) AS author
               FROM non_sale_outputs o
               JOIN nozzles n ON n.id = o.nozzle_id JOIN tanks t ON t.id = n.tank_id JOIN products p ON p.id = t.product_id
               JOIN shift_segments sg ON sg.id = o.segment_id JOIN users u ON u.id = o.created_by
              WHERE o.shift_id = :shift AND o.station_id = :s AND NOT EXISTS (SELECT 1 FROM non_sale_outputs c WHERE c.corrects_id = o.id)
              ORDER BY o.occurred_at DESC, o.id DESC",
            ['shift' => $shiftId, 's' => $stationId]
        );
    }

    public function addOutput(string $stationId, string $shiftId, string $segmentId, string $nozzleId, string $type, string $volume, string $reason, string $occurredAt, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO non_sale_outputs (id, station_id, shift_id, segment_id, nozzle_id, output_type, volume_l, reason, occurred_at, created_by)
             VALUES (:id, :s, :shift, :segment, :nozzle, :type, :volume, :reason, :at, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'segment' => $segmentId, 'nozzle' => $nozzleId,
                'type' => $type, 'volume' => $volume, 'reason' => $reason, 'at' => $occurredAt, 'user' => $userId,
            ]
        );

        return $id;
    }

    /** Clôture des index : le poste passe à « closed » avec la caisse attendue à cet instant, en CDF. */
    public function close(string $stationId, string $shiftId, string $closedAt, string $userId, int $expectedAmount): void
    {
        $this->db->execute(
            "UPDATE shifts SET status = 'closed', closed_at = :at, closed_by = :user, expected_amount = :expected
              WHERE id = :id AND station_id = :s AND status = 'open'",
            ['at' => $closedAt, 'user' => $userId, 'expected' => $expectedAmount, 'id' => $shiftId, 's' => $this->stations->writable($stationId)]
        );
    }

    /** Caisse attendue du poste clôturé, recalculée tant qu'il n'est pas validé. */
    public function setExpected(string $stationId, string $shiftId, int $expected): void
    {
        $this->db->execute(
            "UPDATE shifts SET expected_amount = :expected WHERE id = :id AND station_id = :s AND status = 'closed'",
            ['expected' => $expected, 'id' => $shiftId, 's' => $this->stations->writable($stationId)]
        );
    }

    /**
     * Contre-validation : caisse attendue, caisse remise et écart sont figés en CDF, puis le poste est
     * verrouillé par la base.
     */
    public function validate(string $stationId, string $shiftId, string $validatedAt, string $userId, ?string $note, int $expected, int $received, int $variance): void
    {
        $this->db->execute(
            "UPDATE shifts SET status = 'validated', validated_at = :at, validated_by = :user, note = COALESCE(:note, note),
                    expected_amount = :expected, received_amount = :received, variance_amount = :variance
              WHERE id = :id AND station_id = :s AND status = 'closed'",
            [
                'at' => $validatedAt, 'user' => $userId, 'note' => $note, 'expected' => $expected, 'received' => $received, 'variance' => $variance,
                'id' => $shiftId, 's' => $this->stations->writable($stationId),
            ]
        );
    }

    public function addSegment(string $stationId, string $shiftId, int $sequence, string $reason, string $startedAt, string $exchangeRateId, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO shift_segments (id, station_id, shift_id, sequence, reason, started_at, exchange_rate_id, created_by)
             VALUES (:id, :s, :shift, :seq, :reason, :at, :rate, :user)',
            ['id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'seq' => $sequence, 'reason' => $reason, 'at' => $startedAt, 'rate' => $exchangeRateId, 'user' => $userId]
        );

        return $id;
    }

    public function addReading(string $stationId, string $shiftId, string $segmentId, string $nozzleId, string $type, string $index, string $priceId, string $readAt, string $userId, bool $rollover = false): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO meter_readings (id, station_id, shift_id, segment_id, nozzle_id, reading_type, index_value, is_rollover, price_id, read_at, created_by)
             VALUES (:id, :s, :shift, :segment, :nozzle, :type, :index, :rollover, :price, :at, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'segment' => $segmentId, 'nozzle' => $nozzleId,
                'type' => $type, 'index' => $index, 'rollover' => $rollover ? 1 : 0, 'price' => $priceId, 'at' => $readAt, 'user' => $userId,
            ]
        );

        return $id;
    }
}
