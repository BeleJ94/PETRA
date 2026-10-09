<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Domain\StationSettings;
use Petra\Repositories\CashRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Services\CashControl;
use Petra\Services\ShiftAccess;

/** Caisse d'un poste : remises, ventes à crédit, dépenses, écart et justifications. */
final class CashController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ShiftAccess $access,
        private readonly ShiftRepository $shifts,
        private readonly CashRepository $cash,
        private readonly StationSettingRepository $settings,
        private readonly CashControl $control,
    ) {
        parent::__construct($ctx);
    }

    public function show(Request $request, array $params): Response
    {
        $shift = $this->access->find($this->id($params));
        $stationId = $shift['station_id'];
        $user = $this->user();
        $editable = $shift['status'] !== 'validated' && $this->access->canOperate($shift);
        $segments = $this->shifts->segments($stationId, $shift['id']);

        return $this->render('shifts/cash', [
            'title' => t('cash.title'),
            'shift' => $shift,
            'cash' => $this->control->forShift($shift),
            'segments' => $segments,
            'handovers' => $this->cash->handovers($stationId, $shift['id']),
            'credits' => $this->cash->creditSales($stationId, $shift['id']),
            'expenses' => $this->cash->expenses($stationId, $shift['id']),
            'justifications' => $this->cash->justifications($stationId, $shift['id']),
            'usdRule' => StationSettings::usdRule($this->settings->all($stationId)),
            'categories' => CashControl::EXPENSE_CATEGORIES,
            'tolerances' => StationSettings::thresholds($this->settings->all($stationId)),
            'canHandover' => $editable && $user->can('shifts.close'),
            'canCredit' => $editable && $user->can('credit.record'),
            'canExpense' => $editable && $user->can('expenses.record'),
            'canJustify' => $shift['status'] === 'validated' && $user->can('shifts.validate'),
        ]);
    }

    public function addHandover(Request $request, array $params): Response
    {
        $this->control->addHandover($this->id($params), $request->only(['amount', 'currency', 'segment_id', 'note']));

        return $this->done($request, '/shifts/' . $this->id($params) . '/cash', t('cash.handover_added'));
    }

    public function addCreditSale(Request $request, array $params): Response
    {
        $this->control->addCreditSale($this->id($params), $request->only(['nozzle_id', 'segment_id', 'customer_name', 'voucher_no', 'volume_l', 'note']));

        return $this->back2($params, t('cash.credit_added'));
    }

    public function addExpense(Request $request, array $params): Response
    {
        $this->control->addExpense($this->id($params), $request->only(['category', 'description', 'amount', 'currency', 'segment_id']));

        return $this->back2($params, t('cash.expense_added'));
    }

    public function justify(Request $request, array $params): Response
    {
        $this->control->justify($this->id($params), $request->only(['amount', 'reason']));

        return $this->back2($params, t('cash.justified'));
    }

    private function back2(array $params, string $message): Response
    {
        return $this->redirect('/shifts/' . $this->id($params) . '/cash', $message);
    }
}
