<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/** Nombres décimaux manipulés en entiers mis à l'échelle : aucun calcul ne passe par un float. */
final class Decimal
{
    /** « 12,5 » avec 1 décimale → 125 ; null si la valeur n'est pas un nombre positif à $decimals décimales au plus. */
    public static function scaled(string $value, int $decimals): ?int
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        if (!preg_match('/^(\d{1,12})(?:[.,](\d{1,' . $decimals . '}))?$/', $value, $m)) {
            return null;
        }

        return (int) ($m[1] . str_pad($m[2] ?? '', $decimals, '0'));
    }

    /** Comme scaled(), pour une valeur qui peut être négative (écart, somme d'écarts) : « -95.00 » avec 2 décimales → -9500. */
    public static function signed(string $value, int $decimals): ?int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $scaled = self::scaled($negative ? substr($value, 1) : $value, $decimals);

        return $scaled === null ? null : ($negative ? -$scaled : $scaled);
    }

    /** 125 avec 1 décimale → « 12.5 ». */
    public static function unscaled(int $value, int $decimals): string
    {
        $text = str_pad((string) abs($value), $decimals + 1, '0', STR_PAD_LEFT);

        return ($value < 0 ? '-' : '') . substr($text, 0, -$decimals) . '.' . substr($text, -$decimals);
    }
}
