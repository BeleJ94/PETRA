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
use Petra\Domain\Money;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\ExchangeRateRepository;
use Petra\Repositories\PriceRepository;
use Petra\Repositories\ProductRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Security\Auth;
use Petra\Sync\SyncConfig;

/**
 * Publication d'un prix ou d'un taux USD/CDF. Chaque modification est une nouvelle version, en vigueur
 * à l'instant de sa publication ; elle lève une alerte et s'écrit au journal avec l'ancienne et la
 * nouvelle valeur. Si des postes sont ouverts, leurs index intermédiaires sont exigés : la tranche en
 * cours est clôturée à ces index et une nouvelle tranche s'ouvre au nouveau prix ou au nouveau taux.
 */
final class PricingService
{
    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
        private readonly ExchangeRateRepository $rates,
        private readonly ShiftRepository $shifts,
        private readonly AlertRepository $alerts,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
        private readonly SyncConfig $sync,
    ) {
    }

    /**
     * @param array{unit_price?: mixed, currency?: mixed, note?: mixed, readings?: mixed} $input
     * @param DateTimeImmutable|null $at instant de publication (null : maintenant)
     * @return string identifiant de la nouvelle version
     */
    public function publishPrice(string $productId, array $input, ?DateTimeImmutable $at = null): string
    {
        $product = $this->products->find($productId) ?? throw HttpException::notFound();
        $stationId = (string) $product['station_id'];
        if (!(bool) $product['is_active']) {
            throw Validator::fail('product_id', 'exists');
        }
        $data = Validator::validate($input, [
            // Montant lu par Money::toMinor : « 3 500 » comme « 1,25 » sont acceptés.
            'unit_price' => 'required|string|max:20',
            'currency' => 'required|in:' . implode(',', Money::CURRENCIES),
            'note' => 'string|max:255',
        ]);
        $unitPrice = Money::toMinor($data['unit_price'], $data['currency']);
        if ($unitPrice === null || $unitPrice <= 0) {
            throw Validator::fail('unit_price', $data['currency'] === 'CDF' ? 'money_cdf' : 'money');
        }
        $effectiveAt = self::effectiveAt($at, $this->prices->latest($productId));
        $current = $this->prices->versionAt($productId, $effectiveAt);
        if ($current !== null && (int) $current['unit_price'] === $unitPrice && $current['currency'] === $data['currency']) {
            throw Validator::fail('unit_price', 'same_value');
        }
        $open = $this->sync->isCentral() ? [] : $this->shifts->openSegments($stationId, $productId);
        $readings = self::validateReadings($open, $input['readings'] ?? null);
        $userId = $this->userId();

        return $this->db->transaction(function () use ($product, $stationId, $data, $unitPrice, $effectiveAt, $current, $open, $readings, $userId): string {
            $id = $this->prices->create($stationId, $product['id'], $unitPrice, $data['currency'], $effectiveAt, $data['note'], $userId);
            $this->splitSegments($stationId, $open, $readings, 'price_change', $effectiveAt, $userId);

            $old = $current === null ? null : format_money((int) $current['unit_price'], (string) $current['currency']);
            $new = format_money($unitPrice, $data['currency']);
            $this->sync->isCentral() || $this->alerts->raise(
                $stationId,
                'price_change',
                'info',
                t('pricing.price_alert', ['product' => $product['name'], 'old' => $old ?? t('pricing.none'), 'new' => $new]),
                ['user_id' => $userId, 'entity_type' => 'price', 'entity_id' => $id, 'amount' => $unitPrice, 'currency' => $data['currency']],
                $userId,
            );
            $this->logger->log(
                'publish',
                'price',
                $id,
                $stationId,
                $current === null ? [] : ['unit_price' => (int) $current['unit_price'], 'currency' => $current['currency'], 'effective_at' => $current['effective_at']],
                ['unit_price' => $unitPrice, 'currency' => $data['currency'], 'effective_at' => $effectiveAt, 'product' => $product['code'], 'segments_opened' => count($open)],
                $product['name'] . ' : ' . ($old ?? '—') . ' → ' . $new
            );

            return $id;
        });
    }

    /**
     * @param array{rate?: mixed, note?: mixed, readings?: mixed} $input
     * @param DateTimeImmutable|null $at instant de publication (null : maintenant)
     * @return string identifiant de la nouvelle version
     */
    public function publishRate(string $stationId, array $input, ?DateTimeImmutable $at = null): string
    {
        $data = Validator::validate($input, ['rate' => 'required|int|min:1|max:100000000', 'note' => 'string|max:255']);
        $effectiveAt = self::effectiveAt($at, $this->rates->latest($stationId));
        $current = $this->rates->versionAt($stationId, $effectiveAt);
        if ($current !== null && (int) $current['rate'] === $data['rate']) {
            throw Validator::fail('rate', 'same_value');
        }
        $open = $this->sync->isCentral() ? [] : $this->shifts->openSegments($stationId);
        $readings = self::validateReadings($open, $input['readings'] ?? null);
        $userId = $this->userId();

        return $this->db->transaction(function () use ($stationId, $data, $effectiveAt, $current, $open, $readings, $userId): string {
            $id = $this->rates->create($stationId, $data['rate'], $effectiveAt, $data['note'], $userId);
            $this->splitSegments($stationId, $open, $readings, 'rate_change', $effectiveAt, $userId);

            $old = $current === null ? null : format_money((int) $current['rate'], 'CDF');
            $new = format_money($data['rate'], 'CDF');
            $this->sync->isCentral() || $this->alerts->raise(
                $stationId,
                'rate_change',
                'info',
                t('pricing.rate_alert', ['old' => $old ?? t('pricing.none'), 'new' => $new]),
                ['user_id' => $userId, 'entity_type' => 'exchange_rate', 'entity_id' => $id, 'amount' => $data['rate'], 'currency' => 'CDF'],
                $userId,
            );
            $this->logger->log(
                'publish',
                'exchange_rate',
                $id,
                $stationId,
                $current === null ? [] : ['rate' => (int) $current['rate'], 'effective_at' => $current['effective_at']],
                ['rate' => $data['rate'], 'effective_at' => $effectiveAt, 'segments_opened' => count($open)],
                '1 $ : ' . ($old ?? '—') . ' → ' . $new
            );

            return $id;
        });
    }

    /**
     * Une version prend effet à sa publication, après toutes les versions existantes : on ne réécrit
     * pas le passé et on ne programme pas un changement qui tomberait au milieu d'un poste sans relevé.
     */
    private static function effectiveAt(?DateTimeImmutable $at, ?array $latest): string
    {
        $effectiveAt = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        if ($latest !== null && $latest['effective_at'] >= $effectiveAt) {
            throw Validator::fail('effective_at', 'effective_order');
        }

        return $effectiveAt;
    }

    /**
     * Index intermédiaires : un par pistolet de chaque poste ouvert, au moins égal à l'index d'ouverture
     * de la tranche en cours.
     *
     * @param list<array{shift_id: string, attendant: string, nozzles: list<array<string, string>>}> $open
     * @param mixed $input readings[shift_id][nozzle_id] = index
     * @return array<string, array<string, string>> index normalisés, par poste puis par pistolet
     */
    private static function validateReadings(array $open, mixed $input): array
    {
        $readings = [];
        $errors = [];
        foreach ($open as $shift) {
            foreach ($shift['nozzles'] as $nozzle) {
                $raw = is_array($input) && is_array($input[$shift['shift_id']] ?? null) ? ($input[$shift['shift_id']][$nozzle['nozzle_id']] ?? '') : '';
                $key = 'reading_' . $nozzle['nozzle_id'];
                $scaled = is_scalar($raw) ? Decimal::scaled((string) $raw, 2) : null;
                if (!is_scalar($raw) || trim((string) $raw) === '') {
                    $errors[$key] = t('validation.reading_required', ['nozzle' => $nozzle['code'], 'attendant' => $shift['attendant']]);
                } elseif ($scaled === null) {
                    $errors[$key] = t('validation.reading_invalid', ['nozzle' => $nozzle['code']]);
                } elseif ($scaled < Decimal::scaled($nozzle['opening_index'], 2)) {
                    $errors[$key] = t('validation.reading_below_opening', ['nozzle' => $nozzle['code'], 'opening' => $nozzle['opening_index']]);
                } else {
                    $readings[$shift['shift_id']][$nozzle['nozzle_id']] = Decimal::unscaled($scaled, 2);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $readings;
    }

    /**
     * Clôture la tranche en cours de chaque poste aux index relevés, puis ouvre la suivante aux mêmes
     * index, au prix et au taux en vigueur à $effectiveAt (la nouvelle version vient d'être insérée).
     */
    private function splitSegments(string $stationId, array $open, array $readings, string $reason, string $effectiveAt, string $userId): void
    {
        if ($open === []) {
            return;
        }
        $rate = $this->rates->versionAt($stationId, $effectiveAt) ?? throw new HttpException(409, 'errors.no_rate');
        foreach ($open as $shift) {
            $segmentId = $this->shifts->addSegment($stationId, $shift['shift_id'], $shift['sequence'] + 1, $reason, $effectiveAt, (string) $rate['id'], $userId);
            foreach ($shift['nozzles'] as $nozzle) {
                $index = $readings[$shift['shift_id']][$nozzle['nozzle_id']];
                $price = $this->prices->versionAt($nozzle['product_id'], $effectiveAt) ?? throw new HttpException(409, 'errors.no_price');
                $this->shifts->addReading($stationId, $shift['shift_id'], $shift['segment_id'], $nozzle['nozzle_id'], 'closing', $index, $nozzle['price_id'], $effectiveAt, $userId);
                $this->shifts->addReading($stationId, $shift['shift_id'], $segmentId, $nozzle['nozzle_id'], 'opening', $index, (string) $price['id'], $effectiveAt, $userId);
            }
        }
    }

    private function userId(): string
    {
        return $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');
    }
}
