<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Montants dans la plus petite unité : CDF entier, USD en cents. Jamais de float.
 */
final class Money
{
    public const CURRENCIES = ['CDF', 'USD'];

    /**
     * Saisie « 3450 » (CDF) ou « 1,25 » (USD) → plus petite unité. Null si la saisie n'est pas un montant
     * positif valable dans cette devise (le franc congolais n'a pas de décimales).
     */
    public static function toMinor(string $input, string $currency): ?int
    {
        $cents = Decimal::scaled($input, 2);
        if ($cents === null) {
            return null;
        }
        if ($currency === 'USD') {
            return $cents;
        }

        return $cents % 100 === 0 ? intdiv($cents, 100) : null;
    }

    /** Plus petite unité → saisie réaffichable (« 3450 », « 1.25 »). */
    public static function toInput(int $amount, string $currency): string
    {
        return $currency === 'USD' ? Decimal::unscaled($amount, 2) : (string) $amount;
    }

    /**
     * Contre-valeur en CDF d'un montant en cents de dollar, au taux $rate (CDF entiers pour 1 USD).
     * Arrondi au franc le plus proche, la demi-unité s'éloignant de zéro.
     */
    public static function usdCentsToCdf(int $cents, int $rate): int
    {
        $product = abs($cents) * $rate;
        $cdf = intdiv($product + 50, 100);

        return $cents < 0 ? -$cdf : $cdf;
    }
}
