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
use Petra\Core\Validator;
use Petra\Domain\CashCalculator;
use Petra\Domain\Decimal;
use Petra\Domain\ShiftCalculator;
use Petra\Domain\StationSettings;
use Petra\Domain\StockCalculator;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Repositories\StockRepository;
use Petra\Security\Auth;

/**
 * Suivi du stock par cuve. Le dernier jaugeage sert de stock initial ; le stock théorique s'en déduit
 * avec les livraisons et les volumes débités depuis. Chaque nouveau jaugeage est converti en litres par
 * le barème, comparé au théorique, et lève une alerte si l'écart sort de la tolérance ou si le stock est bas.
 */
final class StockControl
{
    public const DIP_TYPES = ['opening', 'closing', 'before_delivery', 'after_delivery', 'control'];

    public function __construct(
        private readonly Database $db,
        private readonly StockRepository $stock,
        private readonly ShiftRepository $shifts,
        private readonly StationSettingRepository $settings,
        private readonly PriceResolver $prices,
        private readonly RateResolver $rates,
        private readonly AlertRepository $alerts,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Situation de chaque cuve des stations visées : dernier jaugeage, mouvements depuis, stock théorique.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(?DateTimeImmutable $at = null): array
    {
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $out = [];
        foreach ($this->stock->tanks() as $tank) {
            $position = $this->position($tank['id'], $now);
            $capacity = (int) Decimal::scaled((string) $tank['capacity_l'], 2);
            $low = StationSettings::thresholds($this->settings->all($tank['station_id']))['low_stock_l'];
            $out[] = $tank + $position + [
                'fill_percent' => $position['theoretical_l'] === null ? null : StockCalculator::fillPercent((int) Decimal::scaled($position['theoretical_l'], 2), $capacity),
                'is_low' => $position['theoretical_l'] !== null && StockCalculator::isLow((int) Decimal::scaled($position['theoretical_l'], 2), $low),
                'low_stock_l' => $low,
            ];
        }

        return $out;
    }

    /**
     * Stock théorique d'une cuve à l'instant $now : stock initial (dernier jaugeage) + livraisons
     * − litres vendus − sorties non vendues (+ retours en cuve) depuis ce jaugeage.
     *
     * @param string $incoming litres d'une livraison en cours de réception, pas encore enregistrée
     * @return array{last_dip: ?array, initial_l: ?string, deliveries_l: string, sold_l: string, non_sale_l: string, tank_return_l: string,
     *               throughput_l: string, theoretical_l: ?string, unread_sales: bool}
     */
    public function position(string $tankId, string $now, string $incoming = '0'): array
    {
        $last = $this->stock->lastDip($tankId);
        $from = $last['measured_at'] ?? '1970-01-01 00:00:00';
        $deliveries = (int) Decimal::scaled($this->stock->deliveredVolume($tankId, $from, $now), 2) + (int) Decimal::scaled($incoming, 2);
        $delivered = 0;
        $nonSale = 0;
        $tankReturn = 0;
        foreach ($this->stock->closedSegments($tankId, $from, $now) as $segment) {
            $delivered += ShiftCalculator::deliveredCl($segment['opening'], $segment['closing'], $segment['is_rollover'], $segment['rollover_index']) ?? 0;
            $nonSale += (int) Decimal::scaled($segment['non_sale'], 2);
            $tankReturn += (int) Decimal::scaled($segment['tank_return'], 2);
        }
        $sold = $delivered - $nonSale;
        $initial = $last === null ? null : (int) Decimal::scaled((string) $last['volume_l'], 2);

        return [
            'last_dip' => $last,
            'initial_l' => $initial === null ? null : Decimal::unscaled($initial, 2),
            'deliveries_l' => Decimal::unscaled($deliveries, 2),
            'sold_l' => Decimal::unscaled($sold, 2),
            'non_sale_l' => Decimal::unscaled($nonSale, 2),
            'tank_return_l' => Decimal::unscaled($tankReturn, 2),
            'throughput_l' => Decimal::unscaled($delivered, 2),
            'theoretical_l' => $initial === null ? null : Decimal::unscaled(StockCalculator::theoreticalCl($initial, $deliveries, $sold, $nonSale, $tankReturn), 2),
            'unread_sales' => $this->stock->hasUnreadSales($tankId),
        ];
    }

    /**
     * Enregistre un jaugeage : hauteur en cm, convertie en litres par interpolation sur le barème,
     * comparée au stock théorique. Le premier jaugeage d'une cuve fixe son stock initial, sans écart.
     *
     * @param array{depth_cm?: mixed, water_cm?: mixed, dip_type?: mixed, note?: mixed} $input
     * @param bool $varianceAlert faux pour le jaugeage après dépotage : son écart est signalé par l'alerte de livraison
     * @param string $incoming litres reçus de la livraison en cours, comptés dans le théorique de ce jaugeage
     * @return array{id: string, volume_l: string, theoretical_l: ?string, variance_l: ?string, variance_amount: ?int, alerts: list<string>}
     */
    public function recordDip(string $tankId, array $input, ?DateTimeImmutable $at = null, bool $varianceAlert = true, string $incoming = '0'): array
    {
        $tank = $this->stock->findTank($tankId) ?? throw HttpException::notFound();
        if (!(bool) $tank['is_active']) {
            throw Validator::fail('tank_id', 'exists');
        }
        $data = Validator::validate($input, [
            'depth_cm' => 'required|decimal:1|min:0|max:9999',
            'water_cm' => 'decimal:1|min:0|max:9999',
            'dip_type' => 'required|in:' . implode(',', self::DIP_TYPES),
            'note' => 'string|max:255',
        ]);
        $read = StockCalculator::volumeAt($this->stock->calibration($tankId), $data['depth_cm']);
        if ($read['error'] !== null) {
            throw Validator::fail('depth_cm', match ($read['error']) {
                'no_calibration' => 'dip_no_calibration',
                'out_of_range' => 'dip_out_of_range',
                default => 'dip_invalid_depth',
            });
        }
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $position = $this->position($tankId, $now, $incoming);
        if ($position['last_dip'] !== null && $position['last_dip']['measured_at'] >= $now) {
            throw Validator::fail('depth_cm', 'effective_order');
        }
        $stationId = (string) $tank['station_id'];
        $volume = (int) Decimal::scaled($read['volume_l'], 2);
        $variance = $position['theoretical_l'] === null ? null : $volume - (int) Decimal::scaled($position['theoretical_l'], 2);
        $amount = $variance === null ? null : $this->valueOf($stationId, (string) $tank['product_id'], $variance, $now);
        $thresholds = StationSettings::thresholds($this->settings->all($stationId));
        $tolerance = StockCalculator::toleranceCl($thresholds['stock_tolerance_l'], $thresholds['stock_tolerance_pct'], (int) Decimal::scaled($position['throughput_l'], 2));
        $userId = $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');
        // Rattaché au poste ouvert quand il n'y en a qu'un ; avec plusieurs postes en parallèle, le jaugeage est celui de la station.
        $shift = $this->shifts->currentOpen($stationId);

        return $this->db->transaction(function () use ($tank, $stationId, $data, $read, $position, $volume, $variance, $amount, $thresholds, $tolerance, $now, $userId, $shift, $varianceAlert): array {
            $row = [
                'dip_type' => $data['dip_type'], 'depth_cm' => $data['depth_cm'], 'volume_l' => $read['volume_l'], 'water_cm' => $data['water_cm'],
                'theoretical_l' => $position['theoretical_l'], 'variance_l' => $variance === null ? null : Decimal::unscaled($variance, 2),
                'variance_amount' => $amount, 'note' => $data['note'],
            ];
            $id = $this->stock->addDip($stationId, $tank['id'], $shift['id'] ?? null, $row, $now, $userId);
            $raised = [];

            if ($varianceAlert && $variance !== null && StockCalculator::exceeds($variance, $tolerance)) {
                $figures = [
                    'tank' => $tank['code'], 'product' => $tank['product'], 'variance' => format_number(Decimal::unscaled(abs($variance), 2), 2),
                    'physical' => format_number($read['volume_l'], 2), 'theoretical' => format_number($position['theoretical_l'], 2),
                ];
                $this->alerts->raise(
                    $stationId,
                    'stock_variance',
                    $variance < 0 ? 'critical' : 'warning',
                    $variance < 0 ? t('stock.alert_shortage', $figures) : t('stock.alert_surplus', $figures),
                    [
                        'user_id' => $userId, 'shift_id' => $shift['id'] ?? null, 'entity_type' => 'tank', 'entity_id' => $tank['id'],
                        'volume_l' => Decimal::unscaled($variance, 2), 'amount' => $amount, 'currency' => $amount === null ? null : 'CDF',
                        'threshold_value' => Decimal::unscaled($tolerance, 2),
                    ],
                    $userId,
                );
                $raised[] = 'stock_variance';
            }
            // Stock bas : une seule alerte ouverte par cuve, tant qu'elle n'est pas traitée.
            if (StockCalculator::isLow($volume, $thresholds['low_stock_l']) && !$this->stock->hasOpenAlert($stationId, 'low_stock', $tank['id'])) {
                $this->alerts->raise(
                    $stationId,
                    'low_stock',
                    'warning',
                    t('stock.alert_low', ['tank' => $tank['code'], 'product' => $tank['product'], 'volume' => format_number($read['volume_l'], 2), 'threshold' => format_number($thresholds['low_stock_l'], 2)]),
                    ['user_id' => $userId, 'entity_type' => 'tank', 'entity_id' => $tank['id'], 'volume_l' => $read['volume_l'], 'threshold_value' => $thresholds['low_stock_l']],
                    $userId,
                );
                $raised[] = 'low_stock';
            }

            $this->logger->log('create', 'tank_dip', $id, $stationId, null, $row + [
                'tank' => $tank['code'], 'initial_l' => $position['initial_l'], 'deliveries_l' => $position['deliveries_l'], 'sold_l' => $position['sold_l'],
                'non_sale_l' => $position['non_sale_l'], 'tank_return_l' => $position['tank_return_l'], 'tolerance_l' => Decimal::unscaled($tolerance, 2), 'alerts' => $raised,
            ], $tank['code'] . ' — ' . $data['depth_cm'] . ' cm');

            return ['id' => $id, 'volume_l' => $read['volume_l'], 'theoretical_l' => $position['theoretical_l'], 'variance_l' => $row['variance_l'], 'variance_amount' => $amount, 'alerts' => $raised];
        });
    }

    /**
     * Valeur en CDF d'un écart de volume, au prix en vigueur à l'instant du jaugeage (converti au taux
     * en vigueur si le prix est en dollars). Null si aucun prix n'est en vigueur.
     */
    public function valueOf(string $stationId, string $productId, int $varianceCl, string $at): ?int
    {
        $price = $this->prices->at($productId, $at);
        if ($price === null) {
            return null;
        }
        $amount = ShiftCalculator::amount($varianceCl, $price['unit_price']);
        if ($price['currency'] !== 'USD') {
            return $amount;
        }
        $rate = $this->rates->at($stationId, $at);

        return $rate === null ? null : CashCalculator::toCdf($amount, 'USD', $rate['rate']);
    }
}
