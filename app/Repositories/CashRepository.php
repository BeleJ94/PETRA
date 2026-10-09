<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

/**
 * Caisse d'un poste : remises, ventes à crédit, dépenses et justifications d'écart.
 * Ajout seul : la base refuse UPDATE et DELETE sur ces tables ; les lectures ignorent les lignes corrigées.
 */
final class CashRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function handovers(string $stationId, string $shiftId): array
    {
        return $this->rows(
            "SELECT h.id, h.segment_id, h.amount, h.currency, h.amount_cdf, h.handed_at, h.note, sg.sequence, x.rate,
                    CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
               FROM cash_handovers h
               JOIN shift_segments sg ON sg.id = h.segment_id
               LEFT JOIN exchange_rates x ON x.id = h.exchange_rate_id
               JOIN users u ON u.id = h.received_by
              WHERE h.shift_id = :shift AND h.station_id = :s AND NOT EXISTS (SELECT 1 FROM cash_handovers c WHERE c.corrects_id = h.id)
              ORDER BY h.handed_at, h.id",
            $stationId,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> */
    public function creditSales(string $stationId, string $shiftId): array
    {
        return $this->rows(
            "SELECT cs.id, cs.segment_id, cs.customer_name, cs.voucher_no, cs.volume_l, cs.amount, cs.currency, cs.sold_at, cs.note, sg.sequence,
                    n.code AS nozzle, p.name AS product, CONCAT(u.first_name, ' ', u.last_name) AS author
               FROM credit_sales cs
               JOIN shift_segments sg ON sg.id = cs.segment_id
               JOIN nozzles n ON n.id = cs.nozzle_id JOIN tanks t ON t.id = n.tank_id JOIN products p ON p.id = t.product_id
               JOIN users u ON u.id = cs.created_by
              WHERE cs.shift_id = :shift AND cs.station_id = :s AND NOT EXISTS (SELECT 1 FROM credit_sales c WHERE c.corrects_id = cs.id)
              ORDER BY cs.sold_at, cs.id",
            $stationId,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> */
    public function expenses(string $stationId, string $shiftId): array
    {
        return $this->rows(
            "SELECT e.id, e.segment_id, e.category, e.description, e.amount, e.currency, e.amount_cdf, e.spent_at, sg.sequence,
                    CONCAT(u.first_name, ' ', u.last_name) AS author
               FROM cash_expenses e
               LEFT JOIN shift_segments sg ON sg.id = e.segment_id
               JOIN users u ON u.id = e.created_by
              WHERE e.shift_id = :shift AND e.station_id = :s AND NOT EXISTS (SELECT 1 FROM cash_expenses c WHERE c.corrects_id = e.id)
              ORDER BY e.spent_at, e.id",
            $stationId,
            $shiftId
        );
    }

    /** @return list<array<string, mixed>> */
    public function justifications(string $stationId, string $shiftId): array
    {
        return $this->rows(
            "SELECT j.id, j.amount, j.currency, j.reason, j.created_at, j.alert_id, CONCAT(u.first_name, ' ', u.last_name) AS author
               FROM variance_justifications j JOIN users u ON u.id = j.created_by
              WHERE j.shift_id = :shift AND j.station_id = :s AND NOT EXISTS (SELECT 1 FROM variance_justifications c WHERE c.corrects_id = j.id)
              ORDER BY j.created_at, j.id",
            $stationId,
            $shiftId
        );
    }

    /** Litres déjà vendus à crédit sur ce pistolet dans cette tranche (centilitres exprimés en DECIMAL). */
    public function creditVolume(string $segmentId, string $nozzleId): string
    {
        return (string) $this->db->fetchValue(
            'SELECT COALESCE(SUM(cs.volume_l), 0) FROM credit_sales cs
              WHERE cs.segment_id = :segment AND cs.nozzle_id = :nozzle AND NOT EXISTS (SELECT 1 FROM credit_sales c WHERE c.corrects_id = cs.id)',
            ['segment' => $segmentId, 'nozzle' => $nozzleId]
        );
    }

    public function addHandover(string $stationId, string $shiftId, string $segmentId, int $amount, string $currency, ?string $rateId, int $amountCdf, string $receivedBy, string $at, ?string $note): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO cash_handovers (id, station_id, shift_id, segment_id, amount, currency, exchange_rate_id, amount_cdf, received_by, handed_at, note, created_by)
             VALUES (:id, :s, :shift, :segment, :amount, :currency, :rate, :cdf, :received_by, :at, :note, :created_by)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'segment' => $segmentId, 'amount' => $amount, 'currency' => $currency,
                'rate' => $rateId, 'cdf' => $amountCdf, 'received_by' => $receivedBy, 'at' => $at, 'note' => $note, 'created_by' => $receivedBy,
            ]
        );

        return $id;
    }

    public function addCreditSale(string $stationId, string $shiftId, string $segmentId, string $nozzleId, string $customer, ?string $voucher, string $volume, string $priceId, int $amount, string $currency, string $at, ?string $note, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO credit_sales (id, station_id, shift_id, segment_id, nozzle_id, customer_name, voucher_no, volume_l, price_id, amount, currency, sold_at, note, created_by)
             VALUES (:id, :s, :shift, :segment, :nozzle, :customer, :voucher, :volume, :price, :amount, :currency, :at, :note, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'segment' => $segmentId, 'nozzle' => $nozzleId, 'customer' => $customer,
                'voucher' => $voucher, 'volume' => $volume, 'price' => $priceId, 'amount' => $amount, 'currency' => $currency, 'at' => $at, 'note' => $note, 'user' => $userId,
            ]
        );

        return $id;
    }

    public function addExpense(string $stationId, string $shiftId, string $segmentId, string $category, string $description, int $amount, string $currency, ?string $rateId, int $amountCdf, string $at, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO cash_expenses (id, station_id, shift_id, segment_id, category, description, amount, currency, exchange_rate_id, amount_cdf, spent_at, created_by)
             VALUES (:id, :s, :shift, :segment, :category, :description, :amount, :currency, :rate, :cdf, :at, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'segment' => $segmentId, 'category' => $category, 'description' => $description,
                'amount' => $amount, 'currency' => $currency, 'rate' => $rateId, 'cdf' => $amountCdf, 'at' => $at, 'user' => $userId,
            ]
        );

        return $id;
    }

    public function addJustification(string $stationId, string $shiftId, ?string $alertId, int $amount, string $reason, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO variance_justifications (id, station_id, shift_id, alert_id, amount, reason, created_by) VALUES (:id, :s, :shift, :alert, :amount, :reason, :user)',
            ['id' => $id, 's' => $this->stations->writable($stationId), 'shift' => $shiftId, 'alert' => $alertId, 'amount' => $amount, 'reason' => $reason, 'user' => $userId]
        );

        return $id;
    }

    /** Taux de chaque tranche du poste : identifiant de tranche => [identifiant du taux, taux]. */
    public function segmentRates(string $stationId, string $shiftId): array
    {
        $this->stations->assertAllows($stationId);
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT sg.id, sg.sequence, x.id AS rate_id, x.rate FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id
              WHERE sg.shift_id = :shift AND sg.station_id = :s ORDER BY sg.sequence',
            ['shift' => $shiftId, 's' => $stationId]
        ) as $row) {
            $out[$row['id']] = ['segment_id' => $row['id'], 'sequence' => (int) $row['sequence'], 'rate_id' => $row['rate_id'], 'rate' => (int) $row['rate']];
        }

        return $out;
    }

    /** Alerte d'écart de caisse levée pour ce poste, s'il y en a une. */
    public function cashAlertId(string $stationId, string $shiftId): ?string
    {
        $this->stations->assertAllows($stationId);
        $id = $this->db->fetchValue(
            "SELECT id FROM alerts WHERE station_id = :s AND shift_id = :shift AND alert_type IN ('cash_shortage', 'cash_surplus') ORDER BY created_at DESC LIMIT 1",
            ['s' => $stationId, 'shift' => $shiftId]
        );

        return $id === null ? null : (string) $id;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, string $stationId, string $shiftId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll($sql, ['shift' => $shiftId, 's' => $stationId]);
    }
}
