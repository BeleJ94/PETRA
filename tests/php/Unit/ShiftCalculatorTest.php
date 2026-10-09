<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Domain\ShiftCalculator;
use PHPUnit\Framework\TestCase;

/** Litres vendus = (final − initial) − sorties non vendues, par pistolet et par tranche. */
final class ShiftCalculatorTest extends TestCase
{
    private static function nozzle(string $code, string $opening, ?string $closing, string $nonSale = '0.00', int $price = 3450, string $currency = 'CDF', bool $rollover = false, string $product = 'Essence'): array
    {
        return [
            'nozzle_id' => $code, 'code' => $code, 'product_id' => $product, 'product' => $product, 'opening' => $opening, 'closing' => $closing,
            'is_rollover' => $rollover, 'rollover_index' => '1000000.00', 'unit_price' => $price, 'currency' => $currency, 'non_sale' => $nonSale,
        ];
    }

    private static function segment(int $sequence, int $rate, array ...$nozzles): array
    {
        return ['segment_id' => "s$sequence", 'sequence' => $sequence, 'rate' => $rate, 'nozzles' => $nozzles];
    }

    public function testSoldLitresAreDeliveredMinusNonSaleOutputs(): void
    {
        $result = ShiftCalculator::compute([self::segment(1, 2300, self::nozzle('P1', '1000.00', '1250.50', '12.50'))]);
        $line = $result['segments'][0]['nozzles'][0];

        self::assertSame('250.50', $line['delivered_l']);
        self::assertSame('12.50', $line['non_sale_l']);
        self::assertSame('238.00', $line['sold_l']);
        self::assertSame(821100, $line['amount'], '238 L × 3 450 FC');
        self::assertSame(821100, $line['amount_cdf']);
        self::assertSame(['238.00', '12.50', '250.50', 821100], [$result['sold_l'], $result['non_sale_l'], $result['delivered_l'], $result['expected_cdf']]);
        self::assertTrue($result['complete']);
        self::assertTrue($result['valid']);
    }

    public function testEachSegmentUsesItsOwnPriceAndRate(): void
    {
        $result = ShiftCalculator::compute([
            self::segment(1, 2300, self::nozzle('P1', '1000.00', '1300.00', '0.00', 3450), self::nozzle('P2', '500.00', '600.00', '0.00', 125, 'USD', false, 'Mazout')),
            self::segment(2, 2320, self::nozzle('P1', '1300.00', '1400.00', '5.00', 3500), self::nozzle('P2', '600.00', '700.00', '0.00', 125, 'USD', false, 'Mazout')),
        ]);

        self::assertSame(1035000, $result['segments'][0]['nozzles'][0]['amount_cdf'], '300 L × 3 450');
        self::assertSame(332500, $result['segments'][1]['nozzles'][0]['amount_cdf'], '95 L × 3 500');
        // Mazout en dollars : 100 L × 1,25 $ = 125,00 $, convertis au taux de chaque tranche.
        self::assertSame(12500, $result['segments'][0]['nozzles'][1]['amount']);
        self::assertSame(287500, $result['segments'][0]['nozzles'][1]['amount_cdf'], '125 $ à 2 300');
        self::assertSame(290000, $result['segments'][1]['nozzles'][1]['amount_cdf'], '125 $ à 2 320');

        self::assertSame(1035000 + 332500 + 287500 + 290000, $result['expected_cdf']);
        self::assertSame('595.00', $result['sold_l']);
        self::assertSame(['sold_l' => '395.00', 'amount_cdf' => 1367500], array_intersect_key($result['products']['Essence'], ['sold_l' => 1, 'amount_cdf' => 1]));
        self::assertSame(['sold_l' => '200.00', 'amount_cdf' => 577500], array_intersect_key($result['products']['Mazout'], ['sold_l' => 1, 'amount_cdf' => 1]));
    }

    public function testFinalIndexBelowOpeningIsInvalidUnlessRolloverIsDeclared(): void
    {
        self::assertNull(ShiftCalculator::deliveredCl('1000.00', '999.99'));
        self::assertSame(0, ShiftCalculator::deliveredCl('1000.00', '1000.00'), 'Rien de vendu : valable');
        self::assertSame(1, ShiftCalculator::deliveredCl('1000.00', '1000.01'));

        // Compteur à six chiffres : de 999 900,00 à 1 000 000,00 (100 L) puis de 0 à 150,25.
        self::assertSame(25025, ShiftCalculator::deliveredCl('999900.00', '150.25', true, '1000000.00'));
        self::assertSame(1, ShiftCalculator::deliveredCl('999999.99', '0.00', true, '1000000.00'));

        // Passage à zéro déclaré à tort : l'index final n'est pas reparti plus bas.
        self::assertNull(ShiftCalculator::deliveredCl('1000.00', '1200.00', true, '1000000.00'));
        self::assertNull(ShiftCalculator::deliveredCl('1000.00', '1000.00', true, '1000000.00'));
        // Index initial au-delà de la capacité déclarée du compteur.
        self::assertNull(ShiftCalculator::deliveredCl('5000.00', '10.00', true, '1000.00'));
        self::assertNull(ShiftCalculator::deliveredCl('abc', '10.00'));

        $result = ShiftCalculator::compute([self::segment(1, 2300, self::nozzle('P1', '1000.00', '900.00'), self::nozzle('P2', '999900.00', '150.25', '0.25', 3450, 'CDF', true))]);
        self::assertFalse($result['valid']);
        self::assertSame('index_below_opening', $result['segments'][0]['nozzles'][0]['error']);
        self::assertNull($result['segments'][0]['nozzles'][0]['sold_l']);
        self::assertSame('250.00', $result['segments'][0]['nozzles'][1]['sold_l']);
        self::assertSame(862500, $result['expected_cdf'], 'Seul le pistolet valide est compté');
    }

    public function testNonSaleOutputsCannotExceedDeliveredVolume(): void
    {
        $result = ShiftCalculator::compute([self::segment(1, 2300, self::nozzle('P1', '1000.00', '1010.00', '10.01'))]);

        self::assertFalse($result['valid']);
        self::assertSame('non_sale_above_delivered', $result['segments'][0]['nozzles'][0]['error']);
        self::assertSame('-0.01', $result['segments'][0]['nozzles'][0]['sold_l']);

        $exact = ShiftCalculator::compute([self::segment(1, 2300, self::nozzle('P1', '1000.00', '1010.00', '10.00'))]);
        self::assertTrue($exact['valid'], 'Tout le volume débité peut être une sortie non vendue');
        self::assertSame(['0.00', 0], [$exact['sold_l'], $exact['expected_cdf']]);
    }

    public function testAnOpenSegmentIsReportedAsIncomplete(): void
    {
        $result = ShiftCalculator::compute([
            self::segment(1, 2300, self::nozzle('P1', '1000.00', '1100.00')),
            self::segment(2, 2320, self::nozzle('P1', '1100.00', null, '3.00')),
        ]);

        self::assertFalse($result['complete']);
        self::assertTrue($result['valid']);
        self::assertSame('100.00', $result['sold_l'], 'Seules les tranches relevées sont comptées');
        self::assertNull($result['segments'][1]['nozzles'][0]['sold_l']);
        self::assertSame('3.00', $result['segments'][1]['nozzles'][0]['non_sale_l']);
    }

    public function testAmountsAreRoundedToTheNearestUnitWithoutFloats(): void
    {
        self::assertSame(3450, ShiftCalculator::amount(100, 3450));
        self::assertSame(35, ShiftCalculator::amount(1, 3450), '0,01 L × 3 450 = 34,5 → 35');
        self::assertSame(34, ShiftCalculator::amount(1, 3449), '34,49 → 34');
        self::assertSame(0, ShiftCalculator::amount(0, 3450));
        self::assertSame(-35, ShiftCalculator::amount(-1, 3450));
        // 0,1 + 0,2 en flottant ne vaut pas 0,3 : ici les centilitres restent exacts.
        self::assertSame(30, ShiftCalculator::deliveredCl('0.10', '0.40'));
        self::assertSame(3449999996550, ShiftCalculator::amount(99999999900, 3450), 'Très grands volumes sans perte de précision');

        $result = ShiftCalculator::compute([self::segment(1, 2300, self::nozzle('P1', '0.10', '0.40', '0.00', 3333))]);
        self::assertSame('0.30', $result['sold_l']);
        self::assertSame(1000, $result['expected_cdf'], '0,30 L × 3 333 = 999,9 → 1 000');
    }

    public function testAShiftWithoutSegmentsIsEmpty(): void
    {
        $empty = ShiftCalculator::compute([]);
        self::assertSame(['0.00', 0, true, true, []], [$empty['sold_l'], $empty['expected_cdf'], $empty['complete'], $empty['valid'], $empty['products']]);
    }
}
