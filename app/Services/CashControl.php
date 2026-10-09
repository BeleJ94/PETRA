<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeImmutable;
use Petra\Core\Database;
use Petra\Core\Validator;
use Petra\Domain\CashCalculator;
use Petra\Domain\Decimal;
use Petra\Domain\Money;
use Petra\Domain\ShiftCalculator;
use Petra\Domain\StationSettings;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\CashRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Repositories\StationSettingRepository;

/**
 * Contrôle de caisse d'un poste : CA théorique, ventes à crédit, dépenses, caisse remise, écart.
 * Les opérations de caisse se saisissent tant que le poste n'est pas validé ; l'écart est figé et
 * comparé aux seuils de la station à la contre-validation. Une justification du gérant est une
 * nouvelle ligne liée au poste : rien n'est modifié ni supprimé.
 */
final class CashControl
{
    public const EXPENSE_CATEGORIES = ['generator_fuel', 'transport', 'maintenance', 'supplies', 'security', 'other'];

    public function __construct(
        private readonly Database $db,
        private readonly ShiftAccess $access,
        private readonly ShiftRepository $shifts,
        private readonly CashRepository $cash,
        private readonly StationSettingRepository $settings,
        private readonly AlertRepository $alerts,
        private readonly AuditLogger $logger,
    ) {
    }

    /**
     * Contrôle de caisse calculé du poste.
     *
     * @return array<string, mixed> résultat de CashCalculator::compute()
     */
    public function forShift(array $shift): array
    {
        $stationId = (string) $shift['station_id'];
        $shiftId = (string) $shift['id'];
        $sales = ShiftCalculator::compute($this->shifts->segments($stationId, $shiftId));

        return CashCalculator::compute(
            $sales,
            array_map(static fn (array $r): array => ['segment_id' => $r['segment_id'], 'amount' => (int) $r['amount'], 'currency' => $r['currency']], $this->cash->creditSales($stationId, $shiftId)),
            array_map(static fn (array $r): array => ['segment_id' => $r['segment_id'], 'amount_cdf' => (int) $r['amount_cdf']], $this->cash->expenses($stationId, $shiftId)),
            array_map(static fn (array $r): array => ['segment_id' => $r['segment_id'], 'amount' => (int) $r['amount'], 'currency' => $r['currency'], 'amount_cdf' => (int) $r['amount_cdf']], $this->cash->handovers($stationId, $shiftId)),
            array_map(static fn (array $r): array => ['amount' => (int) $r['amount']], $this->cash->justifications($stationId, $shiftId)),
        );
    }

    /**
     * Remise d'argent reçue du pompiste. Les dollars sont convertis au taux de la tranche indiquée ;
     * sans tranche (USD non ventilé), la règle de la station désigne la tranche dont le taux s'applique.
     *
     * @param array{amount?: mixed, currency?: mixed, segment_id?: mixed, note?: mixed} $input
     */
    public function addHandover(string $shiftId, array $input, ?DateTimeImmutable $at = null): string
    {
        $shift = $this->editableShift($shiftId);
        $data = Validator::validate($input, [
            'amount' => 'required|string|max:20',
            'currency' => 'required|in:' . implode(',', Money::CURRENCIES),
            'segment_id' => 'string',
            'note' => 'string|max:255',
        ]);
        $amount = Money::toMinor($data['amount'], $data['currency']);
        // Zéro est une remise valable : le gérant constate que rien n'a été remis (aucune vente, ou caisse non rendue).
        // L'écart qui en découle est calculé et signalé comme n'importe quel autre.
        if ($amount === null || $amount < 0) {
            throw Validator::fail('amount', $data['currency'] === 'CDF' ? 'money_cdf' : 'money');
        }
        $rates = $this->cash->segmentRates($shift['station_id'], $shift['id']);
        $rule = null;
        if ($data['segment_id'] !== null) {
            $segment = $rates[$data['segment_id']] ?? throw Validator::fail('segment_id', 'exists');
        } else {
            // Non ventilé : les francs vont à la dernière tranche (le taux ne les concerne pas),
            // les dollars à la tranche désignée par la règle de la station.
            $rule = $data['currency'] === 'USD' ? StationSettings::usdRule($this->settings->all($shift['station_id'])) : 'last';
            $picked = CashCalculator::segmentForUnallocatedUsd(array_values($rates), $rule) ?? throw Validator::fail('shift', 'shift_incomplete');
            $segment = $rates[$picked['segment_id']];
        }
        $usd = $data['currency'] === 'USD';
        $amountCdf = CashCalculator::toCdf($amount, $data['currency'], $segment['rate']);
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId = $this->access->user()->id;

        return $this->db->transaction(function () use ($shift, $segment, $amount, $data, $usd, $amountCdf, $now, $userId, $rule): string {
            $id = $this->cash->addHandover($shift['station_id'], $shift['id'], $segment['segment_id'], $amount, $data['currency'], $usd ? $segment['rate_id'] : null, $amountCdf, $userId, $now, $data['note']);
            $this->logger->log('create', 'cash_handover', $id, $shift['station_id'], null, [
                'shift_id' => $shift['id'], 'amount' => $amount, 'currency' => $data['currency'], 'amount_cdf' => $amountCdf,
                'segment' => $segment['sequence'], 'rate' => $usd ? $segment['rate'] : null, 'allocation' => $rule === null ? 'segment' : 'station_rule:' . $rule,
            ], format_money($amount, $data['currency']));
            $this->refreshExpected($shift);

            return $id;
        });
    }

    /**
     * Vente à crédit : litres servis sans encaissement, valorisés au prix de la tranche.
     *
     * @param array{nozzle_id?: mixed, segment_id?: mixed, customer_name?: mixed, voucher_no?: mixed, volume_l?: mixed, note?: mixed} $input
     */
    public function addCreditSale(string $shiftId, array $input, ?DateTimeImmutable $at = null): string
    {
        $shift = $this->editableShift($shiftId);
        $data = Validator::validate($input, [
            'nozzle_id' => 'required|string',
            'segment_id' => 'string',
            'customer_name' => 'required|string|max:120',
            'voucher_no' => 'string|max:60',
            'volume_l' => 'required|decimal:2|min:0.01|max:999999',
            'note' => 'string|max:255',
        ]);
        $segment = $this->segment($shift, $data['segment_id']);
        $nozzle = array_column($segment['nozzles'], null, 'nozzle_id')[$data['nozzle_id']] ?? throw Validator::fail('nozzle_id', 'exists');
        $volumeCl = (int) Decimal::scaled($data['volume_l'], 2);
        // Une fois la tranche relevée, le crédit ne peut pas dépasser les litres vendus par ce pistolet.
        if ($nozzle['closing'] !== null) {
            $delivered = ShiftCalculator::deliveredCl($nozzle['opening'], $nozzle['closing'], $nozzle['is_rollover'], $nozzle['rollover_index']) ?? 0;
            $already = (int) Decimal::scaled($this->cash->creditVolume($segment['segment_id'], $nozzle['nozzle_id']), 2);
            if ($already + $volumeCl > $delivered - (int) Decimal::scaled($nozzle['non_sale'], 2)) {
                throw Validator::fail('volume_l', 'credit_above_sold', ['nozzle' => $nozzle['code']]);
            }
        }
        $amount = ShiftCalculator::amount($volumeCl, $nozzle['unit_price']);
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId = $this->access->user()->id;

        return $this->db->transaction(function () use ($shift, $segment, $nozzle, $data, $amount, $now, $userId): string {
            $id = $this->cash->addCreditSale(
                $shift['station_id'], $shift['id'], $segment['segment_id'], $nozzle['nozzle_id'], $data['customer_name'], $data['voucher_no'],
                $data['volume_l'], $nozzle['price_id'], $amount, $nozzle['currency'], $now, $data['note'], $userId
            );
            $this->logger->log('create', 'credit_sale', $id, $shift['station_id'], null, [
                'shift_id' => $shift['id'], 'customer_name' => $data['customer_name'], 'voucher_no' => $data['voucher_no'], 'nozzle' => $nozzle['code'],
                'volume_l' => $data['volume_l'], 'amount' => $amount, 'currency' => $nozzle['currency'], 'segment' => $segment['sequence'],
            ], $data['customer_name'] . ' — ' . format_money($amount, $nozzle['currency']));
            $this->refreshExpected($shift);

            return $id;
        });
    }

    /**
     * Dépense payée avec la recette du poste : elle n'est déduite que justifiée (nature et libellé obligatoires).
     *
     * @param array{category?: mixed, description?: mixed, amount?: mixed, currency?: mixed, segment_id?: mixed} $input
     */
    public function addExpense(string $shiftId, array $input, ?DateTimeImmutable $at = null): string
    {
        $shift = $this->editableShift($shiftId);
        $data = Validator::validate($input, [
            'category' => 'required|in:' . implode(',', self::EXPENSE_CATEGORIES),
            'description' => 'required|string|min:3|max:255',
            'amount' => 'required|string|max:20',
            'currency' => 'required|in:' . implode(',', Money::CURRENCIES),
            'segment_id' => 'string',
        ]);
        $amount = Money::toMinor($data['amount'], $data['currency']);
        // Zéro est une remise valable : le gérant constate que rien n'a été remis (aucune vente, ou caisse non rendue).
        // L'écart qui en découle est calculé et signalé comme n'importe quel autre.
        if ($amount === null || $amount < 0) {
            throw Validator::fail('amount', $data['currency'] === 'CDF' ? 'money_cdf' : 'money');
        }
        $rates = $this->cash->segmentRates($shift['station_id'], $shift['id']);
        $segment = $data['segment_id'] !== null
            ? ($rates[$data['segment_id']] ?? throw Validator::fail('segment_id', 'exists'))
            : (array_values($rates)[count($rates) - 1] ?? throw Validator::fail('shift', 'shift_incomplete'));
        $usd = $data['currency'] === 'USD';
        $amountCdf = CashCalculator::toCdf($amount, $data['currency'], $segment['rate']);
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId = $this->access->user()->id;

        return $this->db->transaction(function () use ($shift, $segment, $data, $amount, $usd, $amountCdf, $now, $userId): string {
            $id = $this->cash->addExpense($shift['station_id'], $shift['id'], $segment['segment_id'], $data['category'], $data['description'], $amount, $data['currency'], $usd ? $segment['rate_id'] : null, $amountCdf, $now, $userId);
            $this->logger->log('create', 'cash_expense', $id, $shift['station_id'], null, [
                'shift_id' => $shift['id'], 'category' => $data['category'], 'description' => $data['description'], 'amount' => $amount,
                'currency' => $data['currency'], 'amount_cdf' => $amountCdf, 'segment' => $segment['sequence'],
            ], $data['description'] . ' — ' . format_money($amount, $data['currency']));
            $this->refreshExpected($shift);

            return $id;
        });
    }

    /**
     * Écart figé à la contre-validation : comparé aux seuils de la station, il lève une alerte de
     * déficit ou d'excédent rattachée à la station, au poste et au pompiste.
     *
     * @return array{cash: array<string, mixed>, alert: ?string} contrôle de caisse et type d'alerte levée
     */
    public function settle(array $shift, string $validatorId): array
    {
        $cash = $this->forShift($shift);
        $thresholds = StationSettings::thresholds($this->settings->all($shift['station_id']));
        $shortage = (int) $thresholds['cash_shortage_tolerance_cdf'];
        $surplus = (int) $thresholds['cash_surplus_tolerance_cdf'];
        $type = CashCalculator::alertFor($cash['variance_cdf'], $shortage, $surplus);
        if ($type !== null) {
            $figures = [
                'attendant' => $shift['attendant'], 'amount' => format_money(abs($cash['variance_cdf']), 'CDF'),
                'expected' => format_money($cash['expected_cdf'], 'CDF'), 'remitted' => format_money($cash['remitted_cdf'], 'CDF'),
            ];
            $this->alerts->raise(
                $shift['station_id'],
                $type,
                $type === 'cash_shortage' ? 'critical' : 'warning',
                $type === 'cash_shortage' ? t('cash.alert_cash_shortage', $figures) : t('cash.alert_cash_surplus', $figures),
                [
                    'user_id' => $shift['attendant_id'], 'shift_id' => $shift['id'], 'entity_type' => 'shift', 'entity_id' => $shift['id'],
                    'amount' => $cash['variance_cdf'], 'currency' => 'CDF', 'threshold_value' => (string) ($type === 'cash_shortage' ? $shortage : $surplus),
                ],
                $validatorId,
            );
        }

        return ['cash' => $cash, 'alert' => $type];
    }

    /**
     * Justification d'un écart par le gérant : une nouvelle ligne liée au poste et à son alerte.
     * Le montant est la part de l'écart expliquée, du même signe que lui, sans dépasser ce qui reste à expliquer.
     *
     * @param array{amount?: mixed, reason?: mixed} $input montant saisi sans signe
     */
    public function justify(string $shiftId, array $input): string
    {
        $shift = $this->access->find($shiftId);
        if ($shift['status'] !== 'validated') {
            throw Validator::fail('shift', 'shift_not_validated');
        }
        $data = Validator::validate($input, ['amount' => 'required|string|max:20', 'reason' => 'required|string|min:5|max:255']);
        $absolute = Money::toMinor($data['amount'], 'CDF');
        if ($absolute === null || $absolute <= 0) {
            throw Validator::fail('amount', 'money_cdf');
        }
        $residual = (int) $shift['variance_amount'] - array_sum(array_map(static fn (array $j): int => (int) $j['amount'], $this->cash->justifications($shift['station_id'], $shift['id'])));
        if ($residual === 0 || $absolute > abs($residual)) {
            throw Validator::fail('amount', 'justification_above_variance', ['residual' => format_money(abs($residual), 'CDF')]);
        }
        $amount = $residual < 0 ? -$absolute : $absolute;
        $userId = $this->access->user()->id;

        return $this->db->transaction(function () use ($shift, $amount, $data, $residual, $userId): string {
            $id = $this->cash->addJustification($shift['station_id'], $shift['id'], $this->cash->cashAlertId($shift['station_id'], $shift['id']), $amount, $data['reason'], $userId);
            $this->logger->log('create', 'variance_justification', $id, $shift['station_id'], ['residual' => $residual], [
                'shift_id' => $shift['id'], 'amount' => $amount, 'reason' => $data['reason'], 'residual' => $residual - $amount,
            ], $data['reason']);

            return $id;
        });
    }

    private function refreshExpected(array $shift): void
    {
        if ($shift['status'] === 'closed') {
            $this->shifts->setExpected($shift['station_id'], $shift['id'], $this->forShift($shift)['expected_cdf']);
        }
    }

    /** Poste dont la caisse peut encore être saisie : accessible, non validé, tenu ou clôturable par l'utilisateur. */
    private function editableShift(string $shiftId): array
    {
        $shift = $this->access->find($shiftId);
        if (!$this->access->canOperate($shift)) {
            throw \Petra\Core\HttpException::forbidden();
        }
        if ($shift['status'] === 'validated') {
            $this->access->denyLocked($shift, 'shift_validated');
        }

        return $shift;
    }

    /** Tranche du poste (la dernière par défaut) avec ses pistolets, relevés et prix. */
    private function segment(array $shift, ?string $segmentId): array
    {
        $segments = $this->shifts->segments($shift['station_id'], $shift['id']);
        if ($segments === []) {
            throw Validator::fail('shift', 'shift_incomplete');
        }
        if ($segmentId === null) {
            return $segments[count($segments) - 1];
        }

        return array_column($segments, null, 'segment_id')[$segmentId] ?? throw Validator::fail('segment_id', 'exists');
    }
}
