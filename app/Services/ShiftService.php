<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeImmutable;
use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\ValidationException;
use Petra\Core\Validator;
use Petra\Domain\Decimal;
use Petra\Domain\ShiftCalculator;
use Petra\Domain\StationSettings;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Repositories\UserRepository;
use Petra\Security\CurrentUser;

/**
 * Postes : ouverture (index initiaux repris des index finaux précédents), sorties non vendues,
 * clôture des index par le pompiste, contre-validation par le gérant.
 * Les volumes et montants viennent de ShiftCalculator ; rien n'est calculé dans le navigateur.
 */
final class ShiftService
{
    /** Permission de clôturer totalement un poste seul, sans la règle des deux personnes. */
    public const SETTLE_ALONE = 'shifts.settle_alone';

    public const OUTPUT_TYPES = ['test', 'calibration', 'internal_use', 'tank_return'];
    /** Clé des pistolets pas encore rattachés à un distributeur (installations d'avant les distributeurs). */
    public const NO_PUMP = 'none';

    public function __construct(
        private readonly Database $db,
        private readonly ShiftRepository $shifts,
        private readonly UserRepository $users,
        private readonly StationSettingRepository $settings,
        private readonly PriceResolver $prices,
        private readonly RateResolver $rates,
        private readonly AlertRepository $alerts,
        private readonly AuditLogger $logger,
        private readonly ShiftAccess $access,
        private readonly CashControl $cash,
    ) {
    }

    /** Poste accessible à l'utilisateur courant (voir ShiftAccess). */
    public function find(string $shiftId): array
    {
        return $this->access->find($shiftId);
    }

    public function ownShiftsOnly(): bool
    {
        return $this->access->ownShiftsOnly();
    }

    /** Volumes et montants du poste, par tranche et par pistolet. */
    public function summary(array $shift): array
    {
        return ShiftCalculator::compute($this->shifts->segments($shift['station_id'], $shift['id']));
    }

    /**
     * Ce qu'il faut pour ouvrir un poste sur la station : personnel, horaires (s'ils sont fixes), distributeurs
     * encore libres avec leurs pistolets et les index repris.
     *
     * @return array{staff: list<array<string, mixed>>, mode: string, schedule: list<array{label: string, start: string, end: string}>, slot: ?string,
     *               pumps: list<array{key: string, code: string, name: string, products: list<string>, nozzles: list<array<string, mixed>>}>,
     *               nozzles: list<array<string, mixed>>, blockers: list<string>}
     */
    public function openingContext(string $stationId, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable();
        $stored = $this->settings->all($stationId);
        $mode = StationSettings::shiftMode($stored);
        $schedule = $mode === 'fixed' ? StationSettings::schedule($stored) : [];
        $all = $this->shifts->nozzlesToOpen($stationId);
        // Seuls les distributeurs qu'aucun poste ouvert ne tient peuvent être confiés à un nouveau poste.
        $nozzles = array_values(array_filter($all, static fn (array $n): bool => !$n['busy']));
        $blockers = [];
        if ($all === []) {
            $blockers[] = t('shifts.blocker_nozzles');
        } elseif ($nozzles === []) {
            $blockers[] = t('shifts.blocker_open');
        }
        if ($this->rates->at($stationId, $at) === null) {
            $blockers[] = t('shifts.blocker_rate');
        }
        foreach ($nozzles as &$nozzle) {
            $nozzle['price'] = $this->prices->at($nozzle['product_id'], $at);
            if ($nozzle['price'] === null) {
                $blockers[] = t('shifts.blocker_price', ['product' => $nozzle['product']]);
            }
        }
        unset($nozzle);
        $pumps = [];
        foreach ($nozzles as $nozzle) {
            $key = $nozzle['pump_id'] ?? self::NO_PUMP;
            $pumps[$key] ??= ['key' => $key, 'code' => $nozzle['pump_code'] ?? '—', 'name' => $nozzle['pump_name'] ?? t('shifts.no_pump'), 'products' => [], 'nozzles' => []];
            $pumps[$key]['nozzles'][] = $nozzle;
            if (!in_array($nozzle['product'], $pumps[$key]['products'], true)) {
                $pumps[$key]['products'][] = $nozzle['product'];
            }
        }

        return [
            'staff' => $this->users->shiftStaff($stationId),
            'mode' => $mode,
            'pumps' => array_values($pumps),
            'schedule' => $schedule,
            'slot' => self::slotAt($schedule, $at->format('H:i')),
            'nozzles' => $nozzles,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** Nom du poste dont l'horaire contient l'heure donnée (un poste peut passer minuit). */
    public static function slotAt(array $schedule, string $time): ?string
    {
        foreach ($schedule as $slot) {
            $inside = $slot['start'] < $slot['end']
                ? $time >= $slot['start'] && $time < $slot['end']
                : $time >= $slot['start'] || $time < $slot['end'];
            if ($inside) {
                return $slot['label'];
            }
        }

        return null;
    }

    /**
     * Ouvre un poste sur les distributeurs confiés au pompiste (pump_ids ; à défaut, tous ceux encore libres).
     * Plusieurs postes peuvent être ouverts en même temps, mais un distributeur n'est tenu que par un seul et un
     * pompiste ne tient qu'un poste. L'index initial de chaque pistolet est l'index final précédent :
     * une valeur saisie n'est lue que pour un pistolet jamais relevé (mise en service).
     *
     * @param array{attendant_id?: mixed, label?: mixed, pump_ids?: mixed, opening?: mixed} $input
     */
    public function open(string $stationId, array $input, ?DateTimeImmutable $at = null): string
    {
        $at ??= new DateTimeImmutable();
        $now = $at->format('Y-m-d H:i:s');
        $context = $this->openingContext($stationId, $at);
        if ($context['blockers'] !== []) {
            throw new ValidationException(array_combine(array_map(static fn (int $i): string => "blocker_$i", array_keys($context['blockers'])), $context['blockers']));
        }
        $data = Validator::validate($input, ['attendant_id' => 'required|string', 'label' => 'string|max:40']);
        if (!in_array($data['attendant_id'], array_column($context['staff'], 'id'), true)) {
            throw Validator::fail('attendant_id', 'exists');
        }
        if ($context['mode'] === 'free') {
            // Horaires libres : le poste n'a pas de nom, seulement son heure d'ouverture et, plus tard, de clôture.
            $data['label'] = null;
        } elseif ($data['label'] !== null && !in_array($data['label'], array_column($context['schedule'], 'label'), true)) {
            throw Validator::fail('label', 'in');
        }
        if ($this->shifts->attendantHasOpen($stationId, $data['attendant_id'])) {
            throw Validator::fail('attendant_id', 'attendant_busy');
        }
        $pumps = array_column($context['pumps'], null, 'key');
        $chosen = is_array($input['pump_ids'] ?? null) ? array_values(array_unique(array_filter($input['pump_ids'], static fn (mixed $v): bool => is_string($v) && $v !== ''))) : [];
        if ($chosen === []) {
            if (array_key_exists('pump_ids', $input)) {
                throw Validator::fail('pump_ids', 'required');
            }
            $chosen = array_keys($pumps);
        }
        $nozzles = [];
        foreach ($chosen as $key) {
            if (!isset($pumps[$key])) {
                throw Validator::fail('pump_ids', 'pump_busy');
            }
            array_push($nozzles, ...$pumps[$key]['nozzles']);
        }
        $pumpCodes = implode(', ', array_map(static fn (string $key): string => $pumps[$key]['code'], $chosen));

        $openings = [];
        $errors = [];
        foreach ($nozzles as $nozzle) {
            if ($nozzle['last_index'] !== null) {
                $openings[$nozzle['nozzle_id']] = $nozzle['last_index'];
                continue;
            }
            $raw = is_array($input['opening'] ?? null) ? ($input['opening'][$nozzle['nozzle_id']] ?? '') : '';
            $scaled = is_scalar($raw) ? Decimal::scaled((string) $raw, 2) : null;
            if ($scaled === null) {
                $errors['opening_' . $nozzle['nozzle_id']] = t('validation.first_index_required', ['nozzle' => $nozzle['code']]);
                continue;
            }
            $openings[$nozzle['nozzle_id']] = Decimal::unscaled($scaled, 2);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $rate = $this->rates->require($stationId, $at);
        $userId = $this->user()->id;

        return $this->db->transaction(function () use ($stationId, $data, $nozzles, $pumpCodes, $openings, $rate, $now, $userId): string {
            if ($this->shifts->lockNozzles(array_column($nozzles, 'nozzle_id')) !== []) {
                throw Validator::fail('pump_ids', 'pump_busy');
            }
            $shiftId = $this->shifts->create($stationId, $data['attendant_id'], substr($now, 0, 10), $data['label'], $now, $userId);
            $segmentId = $this->shifts->addSegment($stationId, $shiftId, 1, 'shift_open', $now, $rate['id'], $userId);
            foreach ($nozzles as $nozzle) {
                $this->shifts->addReading($stationId, $shiftId, $segmentId, $nozzle['nozzle_id'], 'opening', $openings[$nozzle['nozzle_id']], $nozzle['price']['id'], $now, $userId);
            }
            $this->logger->log('open', 'shift', $shiftId, $stationId, null, [
                'attendant_id' => $data['attendant_id'], 'label' => $data['label'], 'pumps' => $pumpCodes, 'opened_at' => $now, 'opening' => array_column(
                    array_map(static fn (array $n): array => ['code' => $n['code'], 'index' => $openings[$n['nozzle_id']]], $nozzles),
                    'index',
                    'code'
                ),
            ], $data['label'] ?? $pumpCodes);

            return $shiftId;
        });
    }

    /**
     * Sortie non vendue : volume passé au compteur sans être vendu, rattaché à la tranche en cours.
     *
     * @param array{nozzle_id?: mixed, output_type?: mixed, volume_l?: mixed, reason?: mixed} $input
     */
    public function addOutput(string $shiftId, array $input, ?DateTimeImmutable $at = null): string
    {
        $shift = $this->writableShift($shiftId);
        $data = Validator::validate($input, [
            'nozzle_id' => 'required|string',
            'output_type' => 'required|in:' . implode(',', self::OUTPUT_TYPES),
            'volume_l' => 'required|decimal:2|min:0.01|max:999999',
            'reason' => 'required|string|max:255',
        ]);
        $current = $this->currentSegment($shift);
        if (!in_array($data['nozzle_id'], array_column($current['nozzles'], 'nozzle_id'), true)) {
            throw Validator::fail('nozzle_id', 'exists');
        }
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId = $this->user()->id;

        return $this->db->transaction(function () use ($shift, $current, $data, $now, $userId): string {
            $id = $this->shifts->addOutput($shift['station_id'], $shift['id'], $current['segment_id'], $data['nozzle_id'], $data['output_type'], $data['volume_l'], $data['reason'], $now, $userId);
            $this->logger->log('create', 'non_sale_output', $id, $shift['station_id'], null, $data + ['shift_id' => $shift['id']], $data['output_type'] . ' — ' . $data['volume_l'] . ' L');

            return $id;
        });
    }

    /**
     * Clôture des index par le pompiste. Un index final inférieur à l'initial est refusé, sauf passage à
     * zéro du compteur déclaré pour ce pistolet ; le gérant devra le confirmer à la contre-validation.
     *
     * @param array{final?: mixed, rollover?: mixed} $input final[nozzle_id] = index, rollover[nozzle_id] = 1
     * @return array résumé calculé du poste
     */
    public function close(string $shiftId, array $input, ?DateTimeImmutable $at = null): array
    {
        $shift = $this->writableShift($shiftId);
        $segments = $this->shifts->segments($shift['station_id'], $shift['id']);
        $last = count($segments) - 1;
        $errors = [];
        $rollovers = [];
        foreach ($segments[$last]['nozzles'] as $i => $nozzle) {
            $key = 'final_' . $nozzle['nozzle_id'];
            $raw = is_array($input['final'] ?? null) ? ($input['final'][$nozzle['nozzle_id']] ?? '') : '';
            $scaled = is_scalar($raw) ? Decimal::scaled((string) $raw, 2) : null;
            $rollover = is_array($input['rollover'] ?? null) && !empty($input['rollover'][$nozzle['nozzle_id']]);
            if (!is_scalar($raw) || trim((string) $raw) === '') {
                $errors[$key] = t('validation.final_required', ['nozzle' => $nozzle['code']]);
                continue;
            }
            if ($scaled === null) {
                $errors[$key] = t('validation.reading_invalid', ['nozzle' => $nozzle['code']]);
                continue;
            }
            $closing = Decimal::unscaled($scaled, 2);
            $delivered = ShiftCalculator::deliveredCl($nozzle['opening'], $closing, $rollover, $nozzle['rollover_index']);
            if ($delivered === null) {
                $errors[$key] = t($rollover ? 'validation.rollover_invalid' : 'validation.final_below_opening', ['nozzle' => $nozzle['code'], 'opening' => $nozzle['opening']]);
                continue;
            }
            if ($delivered < (Decimal::scaled($nozzle['non_sale'], 2) ?? 0)) {
                $errors[$key] = t('validation.non_sale_above_delivered', ['nozzle' => $nozzle['code'], 'volume' => $nozzle['non_sale']]);
                continue;
            }
            $segments[$last]['nozzles'][$i]['closing'] = $closing;
            $segments[$last]['nozzles'][$i]['is_rollover'] = $rollover;
            if ($rollover) {
                $rollovers[] = $nozzle['code'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $summary = ShiftCalculator::compute($segments);
        if (!$summary['complete'] || !$summary['valid']) {
            throw Validator::fail('shift', 'shift_incomplete');
        }
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $user = $this->user();

        $this->db->transaction(function () use ($shift, $segments, $last, $summary, $rollovers, $now, $user): void {
            $segment = $segments[$last];
            $priceIds = array_column($this->currentSegment($shift)['nozzles'], 'price_id', 'nozzle_id');
            foreach ($segment['nozzles'] as $nozzle) {
                $this->shifts->addReading($shift['station_id'], $shift['id'], $segment['segment_id'], $nozzle['nozzle_id'], 'closing', $nozzle['closing'], $priceIds[$nozzle['nozzle_id']], $now, $user->id, $nozzle['is_rollover']);
            }
            $this->shifts->close($shift['station_id'], $shift['id'], $now, $user->id, $summary['expected_cdf']);
            $this->shifts->setExpected($shift['station_id'], $shift['id'], $this->cash->forShift($shift)['expected_cdf']);
            if ($rollovers !== []) {
                $this->alerts->raise(
                    $shift['station_id'],
                    'meter_rollover',
                    'warning',
                    t('shifts.rollover_alert', ['nozzles' => implode(', ', $rollovers), 'user' => $user->name()]),
                    ['user_id' => $user->id, 'shift_id' => $shift['id'], 'entity_type' => 'shift', 'entity_id' => $shift['id']],
                    $user->id,
                );
            }
            $this->logger->log('close', 'shift', $shift['id'], $shift['station_id'], ['status' => 'open'], [
                'status' => 'closed', 'closed_at' => $now, 'sold_l' => $summary['sold_l'], 'non_sale_l' => $summary['non_sale_l'],
                'expected_amount' => $summary['expected_cdf'], 'rollover' => $rollovers,
                'final' => array_column($segment['nozzles'], 'closing', 'code'),
            ], $shift['label']);
        });

        return $summary;
    }

    /**
     * Contre-validation par le gérant : jamais par le pompiste du poste. Un passage à zéro déclaré doit
     * être confirmé explicitement. Le poste validé est ensuite verrouillé.
     *
     * @param array{confirm_rollover?: mixed, note?: mixed} $input
     */
    public function validate(string $shiftId, array $input, ?DateTimeImmutable $at = null): void
    {
        $shift = $this->find($shiftId);
        if ($shift['status'] !== 'closed') {
            throw Validator::fail('shift', 'shift_not_closed');
        }
        $user = $this->user();
        // Règle des deux personnes : le poste est validé par un autre que celui qui l'a tenu ou clôturé.
        // Seule la permission de clôture totale (Propriétaire) la lève ; le journal garde alors la mention « validé seul ».
        $alone = $shift['attendant_id'] === $user->id || $shift['closed_by'] === $user->id;
        if ($alone && !$user->can(self::SETTLE_ALONE)) {
            throw Validator::fail('shift', 'four_eyes');
        }
        $data = Validator::validate($input, ['confirm_rollover' => 'bool', 'note' => 'string|max:255']);
        $summary = $this->summary($shift);
        $rollovers = [];
        foreach ($summary['segments'] as $segment) {
            foreach ($segment['nozzles'] as $nozzle) {
                if ($nozzle['is_rollover']) {
                    $rollovers[] = $nozzle['code'];
                }
            }
        }
        if ($rollovers !== [] && !$data['confirm_rollover']) {
            throw Validator::fail('confirm_rollover', 'rollover_unconfirmed', ['nozzles' => implode(', ', $rollovers)]);
        }
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        if (!$summary['complete'] || !$summary['valid']) {
            throw Validator::fail('shift', 'shift_incomplete');
        }

        $this->db->transaction(function () use ($shift, $data, $rollovers, $now, $user, $alone): void {
            // L'écart est figé ici : caisse remise − caisse attendue, avec alerte si un seuil de la station est franchi.
            $settled = $this->cash->settle($shift, $user->id);
            $cash = $settled['cash'];
            $this->shifts->validate($shift['station_id'], $shift['id'], $now, $user->id, $data['note'], $cash['expected_cdf'], $cash['remitted_cdf'], $cash['variance_cdf']);
            $this->logger->log('validate', 'shift', $shift['id'], $shift['station_id'], ['status' => 'closed'], [
                'status' => 'validated', 'validated_at' => $now, 'rollover_confirmed' => $rollovers, 'note' => $data['note'],
                'theoretical' => $cash['theoretical_cdf'], 'credit' => $cash['credit_cdf'], 'expenses' => $cash['expenses_cdf'],
                'expected_amount' => $cash['expected_cdf'], 'received_amount' => $cash['remitted_cdf'], 'variance_amount' => $cash['variance_cdf'], 'alert' => $settled['alert'],
            ] + ($alone ? ['sole_validation' => true] : []), $shift['label']);
        });
    }

    /**
     * Clôture totale d'un poste par une seule personne (permission shifts.settle_alone, donnée au Propriétaire) :
     * index finaux si le poste est encore ouvert, argent reçu, puis validation — tout ou rien.
     * Les calculs, l'écart, les alertes et le journal sont ceux des trois étapes habituelles.
     *
     * @param array{final?: mixed, rollover?: mixed, amount_cdf?: mixed, amount_usd?: mixed, note?: mixed} $input
     */
    public function settle(string $shiftId, array $input, ?DateTimeImmutable $at = null): void
    {
        $user = $this->user();
        if (!$user->can(self::SETTLE_ALONE)) {
            throw HttpException::forbidden();
        }
        $shift = $this->find($shiftId);
        if ($shift['status'] === 'validated') {
            $this->access->denyLocked($shift, 'shift_validated');
        }
        $note = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        $amounts = [];
        foreach (['CDF' => 'amount_cdf', 'USD' => 'amount_usd'] as $currency => $key) {
            $value = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
            if ($value !== '') {
                $amounts[$currency] = $value;
            }
        }
        // Rien de saisi et rien de déjà remis : on exige un montant, quitte à écrire 0.
        if ($amounts === [] && (int) $this->db->fetchValue('SELECT COUNT(*) FROM cash_handovers WHERE shift_id = :s', ['s' => $shiftId]) === 0) {
            throw Validator::fail('amount_cdf', 'required');
        }

        $this->db->transaction(function () use ($shift, $shiftId, $input, $amounts, $note, $at): void {
            if ($shift['status'] === 'open') {
                $this->close($shiftId, ['final' => $input['final'] ?? null, 'rollover' => $input['rollover'] ?? null], $at);
            }
            foreach ($amounts as $currency => $value) {
                $this->cash->addHandover($shiftId, ['amount' => $value, 'currency' => $currency, 'note' => $note], $at);
            }
            // Qui clôture seul confirme lui-même un éventuel passage à zéro qu'il vient de déclarer.
            $this->validate($shiftId, ['confirm_rollover' => '1', 'note' => $note], $at);
        });
    }

    /** Poste ouvert que l'utilisateur courant peut saisir : le pompiste du poste, ou qui peut clôturer les postes. */
    private function writableShift(string $shiftId): array
    {
        $shift = $this->find($shiftId);
        if (!$this->access->canOperate($shift)) {
            throw HttpException::forbidden();
        }
        if ($shift['status'] !== 'open') {
            $this->access->denyLocked($shift, 'shift_not_open');
        }

        return $shift;
    }

    /** Tranche en cours du poste, avec le relevé d'ouverture et le prix de chaque pistolet. */
    private function currentSegment(array $shift): array
    {
        foreach ($this->shifts->openSegments($shift['station_id']) as $open) {
            if ($open['shift_id'] === $shift['id']) {
                return $open;
            }
        }

        throw Validator::fail('shift', 'shift_not_open');
    }

    private function user(): CurrentUser
    {
        return $this->access->user();
    }
}
