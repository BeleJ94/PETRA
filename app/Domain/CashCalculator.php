<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Contrôle de caisse d'un poste, en CDF entiers. Aucun calcul n'a lieu dans le navigateur.
 *
 *   CA théorique   = Σ (litres vendus × prix de la tranche), converti en CDF au taux de la tranche
 *   caisse attendue = CA théorique − ventes à crédit − dépenses de caisse
 *   caisse remise  = CDF remis + USD remis × taux de la tranche
 *   écart          = caisse remise − caisse attendue     (négatif : déficit ; positif : excédent)
 */
final class CashCalculator
{
    /** Règles de taux pour les dollars remis sans indication de tranche (paramètre de la station). */
    public const USD_RULES = ['last', 'first', 'lowest', 'highest'];

    /** Contre-valeur en CDF d'un montant dans sa plus petite unité, au taux donné. */
    public static function toCdf(int $amount, string $currency, int $rate): int
    {
        return $currency === 'USD' ? Money::usdCentsToCdf($amount, $rate) : $amount;
    }

    /**
     * Tranche dont le taux s'applique aux dollars remis sans ventilation, selon la règle de la station :
     * dernière tranche, première tranche, taux le plus bas ou taux le plus haut du poste.
     *
     * @param list<array{segment_id: string, sequence: int, rate: int}> $segments tranches du poste, dans l'ordre
     * @return array{segment_id: string, sequence: int, rate: int}|null
     */
    public static function segmentForUnallocatedUsd(array $segments, string $rule): ?array
    {
        if ($segments === []) {
            return null;
        }
        $pick = match ($rule) {
            'first' => $segments[0],
            'lowest' => array_reduce($segments, static fn (?array $c, array $s): array => $c === null || $s['rate'] < $c['rate'] ? $s : $c),
            'highest' => array_reduce($segments, static fn (?array $c, array $s): array => $c === null || $s['rate'] > $c['rate'] ? $s : $c),
            default => $segments[count($segments) - 1],
        };

        return ['segment_id' => $pick['segment_id'], 'sequence' => (int) $pick['sequence'], 'rate' => (int) $pick['rate']];
    }

    /**
     * @param array{segments: list<array<string, mixed>>, expected_cdf: int, complete: bool, valid: bool} $sales résultat de ShiftCalculator::compute()
     * @param list<array{segment_id: string, amount: int, currency: string}> $credits ventes à crédit (montant dans la devise du prix)
     * @param list<array{segment_id: ?string, amount_cdf: int}> $expenses dépenses de caisse (contre-valeur figée à la saisie)
     * @param list<array{segment_id: string, amount: int, currency: string, amount_cdf: int}> $handovers remises (contre-valeur figée à la saisie)
     * @param list<array{amount: int}> $justifications parts de l'écart expliquées par le gérant
     * @return array{
     *     theoretical_cdf: int, credit_cdf: int, expenses_cdf: int, expected_cdf: int,
     *     remitted_cdf: int, remitted_cdf_cash: int, remitted_usd: int, remitted_usd_cdf: int,
     *     variance_cdf: int, justified_cdf: int, residual_cdf: int, complete: bool, valid: bool,
     *     segments: list<array<string, int|string>>
     * }
     */
    public static function compute(array $sales, array $credits, array $expenses, array $handovers, array $justifications = []): array
    {
        $segments = [];
        foreach ($sales['segments'] as $segment) {
            $theoretical = 0;
            foreach ($segment['nozzles'] as $nozzle) {
                $theoretical += (int) ($nozzle['amount_cdf'] ?? 0);
            }
            $segments[$segment['segment_id']] = [
                'segment_id' => $segment['segment_id'], 'sequence' => (int) $segment['sequence'], 'rate' => (int) $segment['rate'],
                'theoretical_cdf' => $theoretical, 'credit_cdf' => 0, 'expenses_cdf' => 0,
                'remitted_cdf_cash' => 0, 'remitted_usd' => 0, 'remitted_usd_cdf' => 0,
            ];
        }

        $totals = ['credit_cdf' => 0, 'expenses_cdf' => 0, 'remitted_cdf_cash' => 0, 'remitted_usd' => 0, 'remitted_usd_cdf' => 0];
        foreach ($credits as $credit) {
            $rate = $segments[$credit['segment_id']]['rate'] ?? 0;
            $cdf = self::toCdf((int) $credit['amount'], (string) $credit['currency'], $rate);
            $totals['credit_cdf'] += $cdf;
            if (isset($segments[$credit['segment_id']])) {
                $segments[$credit['segment_id']]['credit_cdf'] += $cdf;
            }
        }
        foreach ($expenses as $expense) {
            $totals['expenses_cdf'] += (int) $expense['amount_cdf'];
            if ($expense['segment_id'] !== null && isset($segments[$expense['segment_id']])) {
                $segments[$expense['segment_id']]['expenses_cdf'] += (int) $expense['amount_cdf'];
            }
        }
        foreach ($handovers as $handover) {
            $usd = $handover['currency'] === 'USD';
            $totals[$usd ? 'remitted_usd_cdf' : 'remitted_cdf_cash'] += (int) $handover['amount_cdf'];
            $totals['remitted_usd'] += $usd ? (int) $handover['amount'] : 0;
            if (isset($segments[$handover['segment_id']])) {
                $segments[$handover['segment_id']][$usd ? 'remitted_usd_cdf' : 'remitted_cdf_cash'] += (int) $handover['amount_cdf'];
                $segments[$handover['segment_id']]['remitted_usd'] += $usd ? (int) $handover['amount'] : 0;
            }
        }

        $theoretical = (int) $sales['expected_cdf'];
        $expected = $theoretical - $totals['credit_cdf'] - $totals['expenses_cdf'];
        $remitted = $totals['remitted_cdf_cash'] + $totals['remitted_usd_cdf'];
        $variance = $remitted - $expected;
        $justified = array_sum(array_map(static fn (array $j): int => (int) $j['amount'], $justifications));

        return [
            'theoretical_cdf' => $theoretical,
            'expected_cdf' => $expected,
            'remitted_cdf' => $remitted,
            'variance_cdf' => $variance,
            'justified_cdf' => $justified,
            'residual_cdf' => $variance - $justified,
            'complete' => (bool) $sales['complete'],
            'valid' => (bool) $sales['valid'],
            'segments' => array_values($segments),
        ] + $totals;
    }

    /**
     * Alerte à lever pour un écart, d'après les seuils de la station : un déficit au-delà de la tolérance
     * de déficit, un excédent au-delà de la tolérance d'excédent. Un écart égal au seuil est toléré.
     *
     * @return 'cash_shortage'|'cash_surplus'|null
     */
    public static function alertFor(int $variance, int $shortageTolerance, int $surplusTolerance): ?string
    {
        if ($variance < 0 && -$variance > $shortageTolerance) {
            return 'cash_shortage';
        }
        if ($variance > 0 && $variance > $surplusTolerance) {
            return 'cash_surplus';
        }

        return null;
    }
}
