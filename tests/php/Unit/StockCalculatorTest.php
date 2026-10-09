<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Domain\StockCalculator;
use PHPUnit\Framework\TestCase;

/** Barème par interpolation linéaire, stock théorique, tolérance. */
final class StockCalculatorTest extends TestCase
{
    /** Cuve horizontale : le volume ne croît pas en ligne droite avec la hauteur. */
    private const CHART = [
        ['depth_cm' => '0.0', 'volume_l' => '0.00'],
        ['depth_cm' => '10.0', 'volume_l' => '800.00'],
        ['depth_cm' => '20.0', 'volume_l' => '2200.00'],
        ['depth_cm' => '50.0', 'volume_l' => '8000.00'],
        ['depth_cm' => '100.0', 'volume_l' => '20000.00'],
    ];

    private static function volume(string $depth, array $chart = self::CHART): ?string
    {
        return StockCalculator::volumeAt($chart, $depth)['volume_l'];
    }

    public function testExactPointsAreReadAsTheyAre(): void
    {
        self::assertSame('0.00', self::volume('0'));
        self::assertSame('800.00', self::volume('10'));
        self::assertSame('2200.00', self::volume('20,0'));
        self::assertSame('20000.00', self::volume('100'));
    }

    public function testVolumeIsInterpolatedLinearlyBetweenTheTwoSurroundingPoints(): void
    {
        self::assertSame('1500.00', self::volume('15'), 'Milieu de 10 → 20 cm : 800 + 1 400 / 2');
        self::assertSame('400.00', self::volume('5'));
        self::assertSame('940.00', self::volume('11'), '800 + 1 400 × 1/10');
        self::assertSame('5100.00', self::volume('35'), 'Milieu de 20 → 50 cm');
        self::assertSame('14000.00', self::volume('75'));
        self::assertSame('8.00', self::volume('0,1'), 'Au dixième de centimètre');
        // Chaque intervalle a sa propre pente : ce n'est pas une règle de trois sur toute la cuve.
        self::assertNotSame('3000.00', self::volume('15'));
    }

    public function testInterpolationRoundsToTheNearestCentilitre(): void
    {
        $chart = [['depth_cm' => '0.0', 'volume_l' => '0.00'], ['depth_cm' => '0.3', 'volume_l' => '0.10']];

        self::assertSame('0.03', self::volume('0.1', $chart), '0,0333… → 0,03');
        self::assertSame('0.07', self::volume('0.2', $chart), '0,0666… → 0,07');
        self::assertSame('5000.00', self::volume('50', [['depth_cm' => '0', 'volume_l' => '0'], ['depth_cm' => '100', 'volume_l' => '10000']]));
        self::assertSame('4999999.99', self::volume('50', [['depth_cm' => '0', 'volume_l' => '0'], ['depth_cm' => '100', 'volume_l' => '9999999.98']]), 'Grands volumes sans perte de précision');
    }

    public function testPointsNeedNotBeSorted(): void
    {
        $shuffled = [self::CHART[3], self::CHART[0], self::CHART[4], self::CHART[1], self::CHART[2]];

        self::assertSame('1500.00', self::volume('15', $shuffled));
    }

    public function testNoExtrapolationOutsideTheChart(): void
    {
        self::assertSame(['volume_l' => null, 'error' => 'out_of_range'], StockCalculator::volumeAt(self::CHART, '100.1'));
        self::assertSame('out_of_range', StockCalculator::volumeAt(array_slice(self::CHART, 1), '5')['error'], 'Sous le premier point');
        self::assertSame('no_calibration', StockCalculator::volumeAt([], '10')['error']);
        self::assertSame('out_of_range', StockCalculator::volumeAt([self::CHART[1]], '12')['error'], 'Un seul point ne permet pas d\'interpoler');
        self::assertSame('800.00', self::volume('10', [self::CHART[1]]), 'Mais il se lit tel quel');
        foreach (['-1', 'abc', '10.55', ''] as $depth) {
            self::assertSame('invalid_depth', StockCalculator::volumeAt(self::CHART, $depth)['error'], $depth);
        }
    }

    /** Stock théorique = initial + livraisons − litres vendus − sorties non vendues. */
    public function testTheoreticalStock(): void
    {
        // 12 000 L au départ, 8 000 L livrés, 5 500 L vendus, 40 L d'essais et d'usage interne.
        self::assertSame(1446000, StockCalculator::theoreticalCl(1200000, 800000, 550000, 4000));
        self::assertSame(1200000, StockCalculator::theoreticalCl(1200000, 0, 0, 0));
        // 10 L d'essai reversés en cuve : ils sont sortis au compteur mais reviennent au stock.
        self::assertSame(1447000, StockCalculator::theoreticalCl(1200000, 800000, 550000, 4000, 1000));
        self::assertSame(-50000, StockCalculator::theoreticalCl(0, 0, 50000, 0), 'Un stock négatif révèle un jaugeage ou une livraison manquante');
    }

    public function testToleranceIsTheLargerOfLitresAndPercentOfThroughput(): void
    {
        // 50 L ou 0,5 % du volume débité.
        self::assertSame(5000, StockCalculator::toleranceCl('50.00', '0.50', 0));
        self::assertSame(5000, StockCalculator::toleranceCl('50.00', '0.50', 500000), '0,5 % de 5 000 L = 25 L : les 50 L priment');
        self::assertSame(10000, StockCalculator::toleranceCl('50.00', '0.50', 2000000), '0,5 % de 20 000 L = 100 L');
        self::assertSame(0, StockCalculator::toleranceCl('0', '0', 2000000));
        self::assertSame(3, StockCalculator::toleranceCl('0', '0.33', 1000), '0,33 % de 10 L = 0,033 L');

        self::assertFalse(StockCalculator::exceeds(-5000, 5000), 'Un écart égal à la tolérance est accepté');
        self::assertTrue(StockCalculator::exceeds(-5001, 5000));
        self::assertTrue(StockCalculator::exceeds(5001, 5000));
        self::assertFalse(StockCalculator::exceeds(0, 0));
    }

    public function testLowStockAndFillRate(): void
    {
        self::assertTrue(StockCalculator::isLow(299999, '3000.00'));
        self::assertFalse(StockCalculator::isLow(300000, '3000.00'), 'Au seuil exact : pas encore bas');
        self::assertFalse(StockCalculator::isLow(0, '0'));

        self::assertSame(50, StockCalculator::fillPercent(1500000, 3000000));
        self::assertSame(100, StockCalculator::fillPercent(3100000, 3000000), 'Borné à 100 %');
        self::assertSame(0, StockCalculator::fillPercent(-500, 3000000));
        self::assertSame(0, StockCalculator::fillPercent(100, 0));
        self::assertSame(67, StockCalculator::fillPercent(2, 3));
    }
}
