<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Stock d'une cuve, en entiers (centilitres, dixièmes de centimètre). Aucun calcul dans le navigateur.
 *
 *   stock théorique = stock initial + livraisons − litres vendus − sorties non vendues (+ retours en cuve)
 *   stock physique  = volume lu au barème pour la hauteur jaugée (interpolation linéaire entre deux points)
 *   écart           = stock physique − stock théorique      (négatif : il manque du carburant)
 */
final class StockCalculator
{
    /**
     * Volume correspondant à une hauteur, par interpolation linéaire entre les deux points du barème
     * qui l'encadrent. Pas d'extrapolation : une hauteur hors barème est refusée.
     *
     * @param list<array{depth_cm: string, volume_l: string}> $points barème de la cuve
     * @param string $depth hauteur jaugée en cm (une décimale au plus)
     * @return array{volume_l: ?string, error: ?string} error : no_calibration | invalid_depth | out_of_range
     */
    public static function volumeAt(array $points, string $depth): array
    {
        $target = Decimal::scaled($depth, 1);
        if ($target === null) {
            return ['volume_l' => null, 'error' => 'invalid_depth'];
        }
        $curve = [];
        foreach ($points as $point) {
            $d = Decimal::scaled((string) $point['depth_cm'], 1);
            $v = Decimal::scaled((string) $point['volume_l'], 2);
            if ($d !== null && $v !== null) {
                $curve[$d] = $v;
            }
        }
        if ($curve === []) {
            return ['volume_l' => null, 'error' => 'no_calibration'];
        }
        ksort($curve);
        if (isset($curve[$target])) {
            return ['volume_l' => Decimal::unscaled($curve[$target], 2), 'error' => null];
        }
        $below = null;
        $above = null;
        foreach ($curve as $d => $v) {
            if ($d < $target) {
                $below = [$d, $v];
            } elseif ($above === null) {
                $above = [$d, $v];
            }
        }
        if ($below === null || $above === null) {
            return ['volume_l' => null, 'error' => 'out_of_range'];
        }
        // v = v0 + (v1 − v0) × (h − h0) / (h1 − h0), arrondi au centilitre le plus proche.
        $span = $above[0] - $below[0];
        $numerator = ($above[1] - $below[1]) * ($target - $below[0]);
        $volume = $below[1] + intdiv(2 * $numerator + $span, 2 * $span);

        return ['volume_l' => Decimal::unscaled($volume, 2), 'error' => null];
    }

    /**
     * Stock théorique en centilitres. Les sorties non vendues ont quitté la cuve en passant au compteur,
     * sauf la part reversée en cuve (retour cuve), qui revient au stock.
     */
    public static function theoreticalCl(int $initial, int $deliveries, int $sold, int $nonSale, int $tankReturn = 0): int
    {
        return $initial + $deliveries - $sold - $nonSale + $tankReturn;
    }

    /**
     * Tolérance d'écart en centilitres : le plus grand entre la tolérance en litres de la station et son
     * pourcentage appliqué au volume débité depuis le dernier jaugeage.
     *
     * @param string $toleranceLitres seuil stock_tolerance_l
     * @param string $tolerancePct seuil stock_tolerance_pct (« 0.50 » = 0,5 %)
     */
    public static function toleranceCl(string $toleranceLitres, string $tolerancePct, int $throughputCl): int
    {
        $litres = Decimal::scaled($toleranceLitres, 2) ?? 0;
        $pct = Decimal::scaled($tolerancePct, 2) ?? 0;
        $share = intdiv(abs($throughputCl) * $pct + 5000, 10000);

        return max($litres, $share);
    }

    /** L'écart sort-il de la tolérance ? Un écart égal à la tolérance est accepté. */
    public static function exceeds(int $varianceCl, int $toleranceCl): bool
    {
        return abs($varianceCl) > $toleranceCl;
    }

    /** Le stock physique est-il sous le niveau bas de la station ? */
    public static function isLow(int $volumeCl, string $lowStockLitres): bool
    {
        return $volumeCl < (Decimal::scaled($lowStockLitres, 2) ?? 0);
    }

    /** Taux de remplissage en pourcentage entier (0 à 100). */
    public static function fillPercent(int $volumeCl, int $capacityCl): int
    {
        return $capacityCl <= 0 ? 0 : max(0, min(100, intdiv($volumeCl * 100 + intdiv($capacityCl, 2), $capacityCl)));
    }
}
