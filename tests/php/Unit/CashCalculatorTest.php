<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Domain\CashCalculator;
use Petra\Domain\ShiftCalculator;
use PHPUnit\Framework\TestCase;

/** Écart = caisse remise − (CA théorique − ventes à crédit − dépenses), en CDF. */
final class CashCalculatorTest extends TestCase
{
    private static function nozzle(string $opening, string $closing, int $price, string $currency = 'CDF', string $code = 'P1'): array
    {
        return [
            'nozzle_id' => $code, 'code' => $code, 'product_id' => $code, 'product' => $code, 'opening' => $opening, 'closing' => $closing,
            'is_rollover' => false, 'rollover_index' => '1000000.00', 'unit_price' => $price, 'currency' => $currency, 'non_sale' => '0.00',
        ];
    }

    /** Ventes d'un poste : tranches [identifiant, taux, pistolets…]. */
    private static function sales(array ...$segments): array
    {
        $list = [];
        foreach ($segments as $i => [$id, $rate, $nozzles]) {
            $list[] = ['segment_id' => $id, 'sequence' => $i + 1, 'rate' => $rate, 'nozzles' => $nozzles];
        }

        return ShiftCalculator::compute($list);
    }

    private static function handover(string $segment, int $amount, string $currency, int $rate): array
    {
        return ['segment_id' => $segment, 'amount' => $amount, 'currency' => $currency, 'amount_cdf' => CashCalculator::toCdf($amount, $currency, $rate)];
    }

    /** Théorique 500 000, remis 485 000 → écart −15 000 CDF. */
    public function testShortageOfFifteenThousand(): void
    {
        $sales = self::sales(['s1', 2300, [self::nozzle('0.00', '100.00', 5000)]]);
        self::assertSame(500000, $sales['expected_cdf']);

        $cash = CashCalculator::compute($sales, [], [], [self::handover('s1', 485000, 'CDF', 2300)]);

        self::assertSame(500000, $cash['theoretical_cdf']);
        self::assertSame(500000, $cash['expected_cdf']);
        self::assertSame(485000, $cash['remitted_cdf']);
        self::assertSame(-15000, $cash['variance_cdf']);
        self::assertSame(-15000, $cash['residual_cdf']);
    }

    /** Deux tranches, taux 2 300 puis 2 320, remises en francs et en dollars dans chacune. */
    public function testMixedCdfAndUsdOverTwoSegments(): void
    {
        $sales = self::sales(
            ['s1', 2300, [self::nozzle('1000.00', '1300.00', 3450)]],   // 300 L × 3 450 = 1 035 000
            ['s2', 2320, [self::nozzle('1300.00', '1400.00', 3500)]],   // 100 L × 3 500 =   350 000
        );
        $handovers = [
            self::handover('s1', 800000, 'CDF', 2300),
            self::handover('s1', 10000, 'USD', 2300),   // 100 $ × 2 300 = 230 000
            self::handover('s2', 200000, 'CDF', 2320),
            self::handover('s2', 5000, 'USD', 2320),    //  50 $ × 2 320 = 116 000
        ];

        $cash = CashCalculator::compute($sales, [], [], $handovers);

        self::assertSame(1385000, $cash['theoretical_cdf']);
        self::assertSame(1000000, $cash['remitted_cdf_cash']);
        self::assertSame(15000, $cash['remitted_usd'], '150 $ au total');
        self::assertSame(346000, $cash['remitted_usd_cdf'], 'Chaque dollar au taux de sa tranche, pas 150 $ × un seul taux');
        self::assertSame(1346000, $cash['remitted_cdf']);
        self::assertSame(-39000, $cash['variance_cdf']);

        [$first, $second] = $cash['segments'];
        self::assertSame([1035000, 800000, 10000, 230000], [$first['theoretical_cdf'], $first['remitted_cdf_cash'], $first['remitted_usd'], $first['remitted_usd_cdf']]);
        self::assertSame([350000, 200000, 5000, 116000], [$second['theoretical_cdf'], $second['remitted_cdf_cash'], $second['remitted_usd'], $second['remitted_usd_cdf']]);
        // Les mêmes 150 $ convertis à un taux unique donneraient 345 000 ou 348 000 : un autre écart.
        self::assertNotSame(346000, CashCalculator::toCdf(15000, 'USD', 2300));
        self::assertNotSame(346000, CashCalculator::toCdf(15000, 'USD', 2320));
    }

    public function testTheoreticalRevenueConvertsUsdPricesAtTheSegmentRate(): void
    {
        $sales = self::sales(
            ['s1', 2300, [self::nozzle('0.00', '100.00', 125, 'USD')]],   // 125 $ × 2 300 = 287 500
            ['s2', 2320, [self::nozzle('100.00', '200.00', 125, 'USD')]], // 125 $ × 2 320 = 290 000
        );

        $cash = CashCalculator::compute($sales, [], [], []);

        self::assertSame(577500, $cash['theoretical_cdf']);
        self::assertSame([287500, 290000], array_column($cash['segments'], 'theoretical_cdf'));
        self::assertSame(-577500, $cash['variance_cdf'], 'Rien de remis : tout le théorique manque');
    }

    public function testCreditSalesAndExpensesReduceTheExpectedCash(): void
    {
        $sales = self::sales(['s1', 2300, [self::nozzle('0.00', '100.00', 5000)]], ['s2', 2320, [self::nozzle('100.00', '200.00', 5000)]]);
        $credits = [
            ['segment_id' => 's1', 'amount' => 50000, 'currency' => 'CDF'],
            ['segment_id' => 's2', 'amount' => 1000, 'currency' => 'USD'],  // 10 $ × 2 320 = 23 200
        ];
        $expenses = [['segment_id' => 's1', 'amount_cdf' => 12000], ['segment_id' => null, 'amount_cdf' => 3000]];

        $cash = CashCalculator::compute($sales, $credits, $expenses, [self::handover('s2', 911800, 'CDF', 2320)]);

        self::assertSame([1000000, 73200, 15000, 911800], [$cash['theoretical_cdf'], $cash['credit_cdf'], $cash['expenses_cdf'], $cash['expected_cdf']]);
        self::assertSame(0, $cash['variance_cdf']);
        self::assertSame([50000, 23200], array_column($cash['segments'], 'credit_cdf'));
        self::assertSame([12000, 0], array_column($cash['segments'], 'expenses_cdf'), 'Une dépense sans tranche compte dans le total seulement');
    }

    public function testSurplusAndJustifications(): void
    {
        $sales = self::sales(['s1', 2300, [self::nozzle('0.00', '100.00', 5000)]]);

        $surplus = CashCalculator::compute($sales, [], [], [self::handover('s1', 502500, 'CDF', 2300)]);
        self::assertSame(2500, $surplus['variance_cdf']);

        $shortage = CashCalculator::compute($sales, [], [], [self::handover('s1', 485000, 'CDF', 2300)], [['amount' => -10000], ['amount' => -2000]]);
        self::assertSame([-15000, -12000, -3000], [$shortage['variance_cdf'], $shortage['justified_cdf'], $shortage['residual_cdf']], 'L\'écart constaté ne change pas ; seul le reste à expliquer diminue');
    }

    public function testAlertDependsOnTheStationThresholds(): void
    {
        self::assertSame('cash_shortage', CashCalculator::alertFor(-15000, 5000, 5000));
        self::assertNull(CashCalculator::alertFor(-5000, 5000, 5000), 'Un écart égal au seuil est toléré');
        self::assertSame('cash_shortage', CashCalculator::alertFor(-5001, 5000, 5000));
        self::assertNull(CashCalculator::alertFor(-15000, 20000, 0), 'Seuil propre à la station');
        self::assertSame('cash_surplus', CashCalculator::alertFor(2500, 5000, 2000));
        self::assertNull(CashCalculator::alertFor(2000, 5000, 2000));
        self::assertNull(CashCalculator::alertFor(0, 0, 0));
        self::assertSame('cash_shortage', CashCalculator::alertFor(-1, 0, 0), 'Tolérance zéro : le moindre franc alerte');
        self::assertSame('cash_surplus', CashCalculator::alertFor(1, 0, 0));
    }

    public function testStationRulePicksTheSegmentForUnallocatedDollars(): void
    {
        $segments = [
            ['segment_id' => 's1', 'sequence' => 1, 'rate' => 2310],
            ['segment_id' => 's2', 'sequence' => 2, 'rate' => 2300],
            ['segment_id' => 's3', 'sequence' => 3, 'rate' => 2320],
            ['segment_id' => 's4', 'sequence' => 4, 'rate' => 2305],
        ];

        self::assertSame(
            ['last' => 's4', 'first' => 's1', 'lowest' => 's2', 'highest' => 's3', 'inconnue' => 's4'],
            array_map(static fn (string $rule): string => CashCalculator::segmentForUnallocatedUsd($segments, $rule)['segment_id'], array_combine(
                ['last', 'first', 'lowest', 'highest', 'inconnue'],
                ['last', 'first', 'lowest', 'highest', 'inconnue']
            ))
        );
        self::assertNull(CashCalculator::segmentForUnallocatedUsd([], 'last'));
        self::assertSame(['last', 'first', 'lowest', 'highest'], CashCalculator::USD_RULES);
    }

    public function testConversionIsExactOnLargeAmounts(): void
    {
        self::assertSame(485000, CashCalculator::toCdf(485000, 'CDF', 2300), 'Les francs ne sont pas convertis');
        self::assertSame(2300, CashCalculator::toCdf(100, 'USD', 2300));
        self::assertSame(23000000000000, CashCalculator::toCdf(1000000000000, 'USD', 2300));
    }
}
