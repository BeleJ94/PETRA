<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Calculs d'un poste, par pistolet et par tranche. Tout est fait en entiers (centilitres, plus petite
 * unité monétaire) ; aucun calcul n'a lieu dans le navigateur.
 *
 *   litres débités = index final − index initial            (+ passage à zéro déclaré)
 *   litres vendus  = litres débités − sorties non vendues   (essai, étalonnage, usage interne, retour cuve)
 *   montant        = litres vendus × prix de la tranche
 *   montant en CDF = montant, ou montant en dollars converti au taux de la tranche
 */
final class ShiftCalculator
{
    /**
     * Volume passé au compteur entre deux index, en centilitres.
     * Avec passage à zéro : du relevé initial jusqu'à l'index de retour à zéro, puis de zéro au relevé final.
     *
     * @return int|null null si l'index final est inférieur à l'initial sans passage à zéro (ou l'inverse)
     */
    public static function deliveredCl(string $opening, string $closing, bool $rollover = false, string $rolloverIndex = '0'): ?int
    {
        $from = Decimal::scaled($opening, 2);
        $to = Decimal::scaled($closing, 2);
        if ($from === null || $to === null) {
            return null;
        }
        if (!$rollover) {
            return $to >= $from ? $to - $from : null;
        }
        $wrap = Decimal::scaled($rolloverIndex, 2);
        // Un passage à zéro n'existe que si le compteur est reparti plus bas, sans dépasser sa capacité.
        if ($wrap === null || $from >= $wrap || $to >= $from) {
            return null;
        }

        return ($wrap - $from) + $to;
    }

    /** Montant de $volumeCl centilitres au prix $unitPrice du litre (plus petite unité), arrondi au plus proche. */
    public static function amount(int $volumeCl, int $unitPrice): int
    {
        $product = abs($volumeCl) * $unitPrice;
        $amount = intdiv($product + 50, 100);

        return $volumeCl < 0 ? -$amount : $amount;
    }

    /**
     * @param list<array{
     *     segment_id: string, sequence: int, rate: int,
     *     nozzles: list<array{nozzle_id: string, code: string, product_id: string, product: string, opening: string, closing: ?string,
     *                        is_rollover: bool, rollover_index: string, unit_price: int, currency: string, non_sale: string}>
     * }> $segments tranches du poste, dans l'ordre
     * @return array{
     *     segments: list<array<string, mixed>>, products: array<string, array{product: string, sold_l: string, amount_cdf: int}>,
     *     delivered_l: string, non_sale_l: string, sold_l: string, expected_cdf: int, complete: bool, valid: bool
     * }
     *   complete : chaque pistolet de chaque tranche a son relevé final ; valid : aucun volume incohérent
     */
    public static function compute(array $segments): array
    {
        $out = ['segments' => [], 'products' => [], 'complete' => true, 'valid' => true];
        $totals = ['delivered' => 0, 'non_sale' => 0, 'sold' => 0, 'expected' => 0];

        foreach ($segments as $segment) {
            $lines = [];
            foreach ($segment['nozzles'] as $nozzle) {
                $nonSale = Decimal::scaled($nozzle['non_sale'], 2) ?? 0;
                $line = $nozzle + ['delivered_l' => null, 'non_sale_l' => Decimal::unscaled($nonSale, 2), 'sold_l' => null, 'amount' => null, 'amount_cdf' => null, 'error' => null];
                if ($nozzle['closing'] === null) {
                    $out['complete'] = false;
                    $lines[] = $line;
                    continue;
                }
                $delivered = self::deliveredCl($nozzle['opening'], $nozzle['closing'], $nozzle['is_rollover'], $nozzle['rollover_index']);
                if ($delivered === null) {
                    $out['valid'] = false;
                    $lines[] = ['error' => 'index_below_opening'] + $line;
                    continue;
                }
                $sold = $delivered - $nonSale;
                if ($sold < 0) {
                    $out['valid'] = false;
                    $line['error'] = 'non_sale_above_delivered';
                }
                $amount = self::amount($sold, $nozzle['unit_price']);
                $amountCdf = $nozzle['currency'] === 'USD' ? Money::usdCentsToCdf($amount, $segment['rate']) : $amount;
                $lines[] = ['delivered_l' => Decimal::unscaled($delivered, 2), 'sold_l' => Decimal::unscaled($sold, 2), 'amount' => $amount, 'amount_cdf' => $amountCdf] + $line;

                $totals['delivered'] += $delivered;
                $totals['non_sale'] += $nonSale;
                $totals['sold'] += $sold;
                $totals['expected'] += $amountCdf;
                $product = &$out['products'][$nozzle['product_id']];
                $product ??= ['product' => $nozzle['product'], 'sold_cl' => 0, 'amount_cdf' => 0];
                $product['sold_cl'] += $sold;
                $product['amount_cdf'] += $amountCdf;
                unset($product);
            }
            $out['segments'][] = ['nozzles' => $lines] + $segment;
        }
        foreach ($out['products'] as &$product) {
            $product = ['product' => $product['product'], 'sold_l' => Decimal::unscaled($product['sold_cl'], 2), 'amount_cdf' => $product['amount_cdf']];
        }
        unset($product);

        return $out + [
            'delivered_l' => Decimal::unscaled($totals['delivered'], 2),
            'non_sale_l' => Decimal::unscaled($totals['non_sale'], 2),
            'sold_l' => Decimal::unscaled($totals['sold'], 2),
            'expected_cdf' => $totals['expected'],
        ];
    }
}
