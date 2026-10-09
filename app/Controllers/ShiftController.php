<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\DataTableRequest;
use Petra\Core\Request;
use Petra\Domain\StationSettings;
use Petra\Core\Response;
use Petra\Repositories\ShiftRepository;
use Petra\Services\CashControl;
use Petra\Services\ShiftService;

/** Postes : ouverture, sorties non vendues, clôture des index, contre-validation. */
final class ShiftController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ShiftRepository $shifts,
        private readonly ShiftService $service,
        private readonly CashControl $cash,
        private readonly \Petra\Repositories\ReportRepository $reports,
        private readonly \Petra\Repositories\StationSettingRepository $settings,
        private readonly \Petra\Services\ReportService $reportService,
    ) {
        parent::__construct($ctx);
    }

    /**
     * Tableau de la journée : une carte par créneau des horaires de la station (et par poste ouvert hors
     * créneau), avec son état et l'action suivante. L'historique et les détails s'ouvrent en fenêtres.
     */
    public function index(Request $request): Response
    {
        $context = $this->ctx->auth->stations();
        $station = $context->active();
        $user = $this->user();
        $ownOnly = $this->service->ownShiftsOnly();
        $today = date('Y-m-d');
        $date = $request->string('date');
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false && $date <= $today ? $date : $today;

        $stationIds = $context->readIds();
        $shifts = $this->reports->shifts($stationIds, $date, $date);
        if ($ownOnly) {
            $shifts = array_values(array_filter($shifts, static fn (array $s): bool => $s['attendant_id'] === $user->id));
        }
        $sold = [];
        $value = [];
        foreach ($this->reports->saleLines($stationIds, $date, $date) as $line) {
            $sold[$line['shift_id']] = ($sold[$line['shift_id']] ?? 0) + (int) \Petra\Domain\Decimal::signed((string) $line['sold_l'], 2);
            $value[$line['shift_id']] = ($value[$line['shift_id']] ?? 0) + (int) $line['amount_cdf'];
        }
        $handed = array_count_values(array_column($this->reports->handovers($stationIds, $date, $date), 'shift_id'));
        $canClose = $user->can('shifts.close');
        $canValidate = $user->can('shifts.validate');
        $canOpen = $user->can('shifts.open') && !$context->isReadOnly();
        $pumpsOf = $this->shifts->pumpsOfShifts(array_column($shifts, 'id'));

        $boards = [];
        $totals = ['sold_cl' => 0, 'expected' => 0, 'variance' => 0, 'open' => 0, 'shifts' => 0];
        foreach ($context->stations() as $s) {
            if (!in_array($s['id'], $stationIds, true)) {
                continue;
            }
            $ofStation = array_values(array_filter($shifts, static fn (array $row): bool => $row['station_id'] === $s['id']));
            // Un poste peut être ouvert tant qu'un distributeur reste libre : plusieurs postes tournent en parallèle.
            $hasOpen = !$this->shifts->hasFreePump($s['id']);
            $stored = $this->settings->all($s['id']);
            $fixed = StationSettings::shiftMode($stored) === 'fixed';
            $cards = [];
            // Créneaux vides : seulement en horaires fixes, et jamais pour un pompiste (il ne voit que ses postes).
            foreach ($ownOnly || !$fixed ? [] : StationSettings::schedule($stored) as $slot) {
                $cards[$slot['label']] = ['label' => $slot['label'], 'hours' => $slot['start'] . ' – ' . $slot['end'], 'shifts' => []];
            }
            foreach ($ofStation as $row) {
                $key = isset($cards[(string) $row['label']]) ? (string) $row['label'] : 'shift-' . $row['id'];
                // Sans créneau (horaires libres) : le poste se reconnaît à ses distributeurs et à ses heures réelles.
                $cards[$key] ??= ['label' => $row['label'] ?: (isset($pumpsOf[$row['id']]) ? t('shifts.pump_label', ['pumps' => $pumpsOf[$row['id']]]) : t('shifts.unlabelled')), 'hours' => null, 'shifts' => []];
                $cards[$key]['shifts'][] = $row;
            }
            $out = [];
            foreach ($cards as $card) {
                // Un créneau tenu deux fois le même jour (rare) donne deux cartes.
                foreach ($card['shifts'] === [] ? [null] : $card['shifts'] as $row) {
                    $state = $row === null ? 'idle' : ($row['status'] === 'closed' && isset($handed[$row['id']]) ? 'cash' : $row['status']);
                    $mine = $row !== null && ($row['attendant_id'] === $user->id || $canClose);
                    $action = match (true) {
                        $row === null => $canOpen && !$hasOpen && $date === $today && $s['is_active'] && $station !== null
                            ? ['shifts.open_action', '/shifts/open', 'play', 'open', null] : null,
                        $state === 'open' => $mine ? ['shifts.close_action', '/shifts/' . $row['id'] . '/close', 'flag', 'close', $row['id']] : null,
                        $state === 'closed' => $canClose ? ['shifts.cash_action', '/shifts/' . $row['id'] . '/cash', 'wallet', 'cash', $row['id']] : null,
                        $state === 'cash' => $canValidate ? ['shifts.validate_action', '/shifts/' . $row['id'], 'check', 'validate', $row['id']] : null,
                        default => null,
                    };
                    $expected = $row === null ? null : ($row['expected_amount'] !== null ? (int) $row['expected_amount'] : ($value[$row['id']] ?? null));
                    $out[] = [
                        'label' => $card['label'], 'hours' => $card['hours'], 'shift' => $row, 'pumps' => $row === null ? null : ($pumpsOf[$row['id']] ?? null), 'state' => $state, 'action' => $action,
                        'sold_cl' => $row === null ? null : ($sold[$row['id']] ?? null), 'expected' => $expected,
                        'variance' => $row !== null && $row['status'] === 'validated' ? (int) $row['variance_amount'] : null,
                        'history_label' => $row === null ? $card['label'] : $row['label'],
                        // Créneau libre sans bouton : on dit pourquoi, plutôt que de laisser chercher.
                        'hint' => $row !== null || $action !== null || !$canOpen || $date !== $today ? null
                            : ($station === null ? 'shifts.blocked_station' : ($hasOpen ? 'shifts.blocked_open' : null)),
                    ];
                    if ($row !== null) {
                        $totals['shifts']++;
                        $totals['open'] += $row['status'] === 'open' ? 1 : 0;
                        $totals['sold_cl'] += $sold[$row['id']] ?? 0;
                        $totals['expected'] += $expected ?? 0;
                        $totals['variance'] += $row['status'] === 'validated' ? (int) $row['variance_amount'] : 0;
                    }
                }
            }
            $boards[] = ['station' => $s, 'cards' => $out];
        }

        // Sélecteur de période : la semaine (lundi → dimanche) de la journée affichée, avec l'état de chaque jour.
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $summary = $this->shifts->daySummary($stationIds, $monday, date('Y-m-d', strtotime($monday . ' +6 days')), $ownOnly ? $user->id : null);
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $day = date('Y-m-d', strtotime($monday . " +$i days"));
            $info = $summary[$day] ?? ['shifts' => 0, 'pending' => 0, 'variance' => 0];
            $week[] = ['date' => $day, 'future' => $day > $today, 'tone' => match (true) {
                $info['shifts'] === 0 => 'none',
                $info['pending'] > 0 => 'pending',
                $info['variance'] < 0 => 'bad',
                default => 'ok',
            }] + $info;
        }

        return $this->render('shifts/index', [
            'title' => t('nav.shifts'),
            'week' => $week,
            'station' => $station,
            'boards' => $boards,
            'totals' => $totals,
            'date' => $date,
            'today' => $today,
            'ownOnly' => $ownOnly,
            'canSettle' => $user->can(ShiftService::SETTLE_ALONE) && !$context->isReadOnly(),
            'canOpen' => $canOpen && $station !== null && $station['is_active'] && $this->shifts->hasFreePump($station['id']),
            'pageScripts' => ['js/pt-live.js'],
        ]);
    }

    /**
     * Fenêtre de saisie d'une action sur un poste (fragment HTML) : ouvrir, clôturer, recevoir la caisse, valider.
     * Les droits sont ceux des écrans complets ; l'enregistrement passe par les mêmes routes.
     */
    public function form(Request $request): Response
    {
        $action = (string) $request->string('action');
        if ($action === 'open') {
            $this->authorize('shifts.open');
            $context = $this->ctx->auth->stations();
            $stationId = $context->writable();

            return $this->render('shifts/_form', ['action' => 'open', 'station' => $context->active(), 'preset' => $request->string('label')] + $this->service->openingContext($stationId), 200, null);
        }
        $id = (string) $request->string('shift');
        if (!in_array($action, ['close', 'cash', 'validate', 'settle'], true) || !\Petra\Core\Uuid::isValid($id)) {
            throw \Petra\Core\HttpException::notFound();
        }
        $shift = $this->service->find($id);
        $user = $this->user();
        $allowed = match ($action) {
            'close' => $shift['status'] === 'open' && $user->can('shifts.record') && ($shift['attendant_id'] === $user->id || $user->can('shifts.close')),
            'cash' => $shift['status'] === 'closed' && $user->can('shifts.close'),
            'settle' => $shift['status'] !== 'validated' && $user->can(ShiftService::SETTLE_ALONE),
            default => $shift['status'] === 'closed' && $user->can('shifts.validate'),
        };
        if (!$allowed) {
            throw \Petra\Core\HttpException::forbidden();
        }
        $summary = $this->service->summary($shift);
        $hasRollover = false;
        foreach ($summary['segments'] as $segment) {
            foreach ($segment['nozzles'] as $nozzle) {
                $hasRollover = $hasRollover || !empty($nozzle['is_rollover']);
            }
        }

        return $this->render('shifts/_form', [
            'action' => $action, 'shift' => $shift, 'cash' => $this->cash->forShift($shift),
            'segment' => $summary['segments'][count($summary['segments']) - 1] ?? ['nozzles' => []], 'hasRollover' => $hasRollover,
        ], 200, null);
    }

    /** Fenêtre de détail d'un poste : ventes, caisse, événements (fragment HTML). */
    public function summary(Request $request, array $params): Response
    {
        $shift = $this->service->find($this->id($params));

        return $this->render('shifts/_summary', ['shift' => $shift, 'report' => $this->reportService->build(['type' => 'shift', 'shift' => $shift['id']])], 200, null);
    }

    /** Fenêtre « Historique de ce poste » : les derniers postes du même créneau sur la station (fragment HTML). */
    public function history(Request $request): Response
    {
        $stationId = (string) $request->string('station');
        if (!\Petra\Core\Uuid::isValid($stationId)) {
            throw \Petra\Core\HttpException::notFound();
        }
        $label = $request->string('label');
        $rows = $this->shifts->slotHistory($stationId, $label, 30, $this->service->ownShiftsOnly() ? $this->user()->id : null);

        return $this->render('shifts/_history', ['rows' => $rows, 'label' => $label ?? t('shifts.unlabelled')], 200, null);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, ShiftRepository::SORTABLE, ['sh.opened_at', 'DESC']);
        $status = $request->string('status');

        return $this->json($this->shifts->datatable(
            $dt,
            $this->service->ownShiftsOnly() ? $this->user()->id : null,
            in_array($status, ['open', 'closed', 'validated'], true) ? $status : null,
        ));
    }

    public function openForm(Request $request): Response
    {
        $context = $this->ctx->auth->stations();
        $stationId = $context->writable();

        return $this->render('shifts/open', [
            'title' => t('shifts.open_title'),
            'station' => $context->active(),
        ] + $this->service->openingContext($stationId));
    }

    public function open(Request $request): Response
    {
        // pump_ids n'est transmis que s'il a été envoyé : absent, le poste prend tous les distributeurs encore libres.
        $id = $this->service->open($this->ctx->auth->stations()->writable(), [
            'attendant_id' => $request->body['attendant_id'] ?? null,
            'label' => $request->body['label'] ?? null,
            'opening' => $request->body['opening'] ?? null,
        ] + (array_key_exists('pump_ids', $request->body) ? ['pump_ids' => $request->body['pump_ids']] : []));

        return $this->done($request, '/shifts/' . $id, t('shifts.opened'));
    }

    public function show(Request $request, array $params): Response
    {
        $shift = $this->service->find($this->id($params));
        $user = $this->user();
        $mine = $shift['attendant_id'] === $user->id;

        return $this->render('shifts/show', [
            'title' => t('shifts.sheet_title', ['date' => format_date($shift['business_date'])]),
            'shift' => $shift,
            'summary' => $this->service->summary($shift),
            'cash' => $this->cash->forShift($shift),
            'outputs' => $this->shifts->outputs($shift['station_id'], $shift['id']),
            'canRecord' => $shift['status'] === 'open' && $user->can('shifts.record') && ($mine || $user->can('shifts.close')),
            'canValidate' => $shift['status'] === 'closed' && $user->can('shifts.validate') && !$mine && $shift['closed_by'] !== $user->id,
            'outputTypes' => ShiftService::OUTPUT_TYPES,
        ]);
    }

    public function addOutput(Request $request, array $params): Response
    {
        $this->service->addOutput($this->id($params), $request->only(['nozzle_id', 'output_type', 'volume_l', 'reason']));

        return $this->redirect('/shifts/' . $this->id($params), t('shifts.output_added'));
    }

    public function closeForm(Request $request, array $params): Response
    {
        $shift = $this->service->find($this->id($params));
        if ($shift['status'] !== 'open') {
            return $this->redirect('/shifts/' . $shift['id']);
        }
        $summary = $this->service->summary($shift);

        return $this->render('shifts/close', [
            'title' => t('shifts.close_title'),
            'shift' => $shift,
            'segment' => $summary['segments'][count($summary['segments']) - 1],
        ]);
    }

    public function close(Request $request, array $params): Response
    {
        $this->service->close($this->id($params), ['final' => $request->body['final'] ?? null, 'rollover' => $request->body['rollover'] ?? null]);

        return $this->done($request, '/shifts/' . $this->id($params), t('shifts.closed'));
    }

    /** Clôture totale par une seule personne : index, caisse et validation en une opération. */
    public function settle(Request $request, array $params): Response
    {
        $this->service->settle($this->id($params), [
            'final' => $request->body['final'] ?? null, 'rollover' => $request->body['rollover'] ?? null,
            'amount_cdf' => $request->body['amount_cdf'] ?? null, 'amount_usd' => $request->body['amount_usd'] ?? null, 'note' => $request->body['note'] ?? null,
        ]);

        return $this->done($request, '/shifts/' . $this->id($params), t('shifts.settled'));
    }

    public function validate(Request $request, array $params): Response
    {
        $this->service->validate($this->id($params), $request->only(['confirm_rollover', 'note']));

        return $this->done($request, '/shifts/' . $this->id($params), t('shifts.validated'));
    }
}
