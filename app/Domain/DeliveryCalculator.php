<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Écarts d'une livraison, en centilitres entiers. Aucun calcul dans le navigateur.
 *
 *   variation de cuve      = volume jaugé après − volume jaugé avant
 *   écart annoncé / reçu   = reçu − annoncé             (négatif : livré moins que le bon ne l'annonce)
 *   écart reçu / cuve      = variation de cuve − reçu   (négatif : la cuve a moins monté que le reçu déclaré)
 */
final class DeliveryCalculator
{
    /**
     * @param string $announced quantité annoncée par le bon de livraison (litres)
     * @param string $received quantité reçue déclarée par le réceptionnaire
     * @param string $before volume jaugé avant dépotage
     * @param string $after volume jaugé après dépotage
     * @param string $tolerance seuil delivery_variance_l de la station
     * @return array{
     *     tank_variation_l: string, announced_variance_l: string, tank_variance_l: string, tolerance_l: string,
     *     announced_exceeds: bool, tank_exceeds: bool, exceeds: bool, shortage: bool, worst_l: string
     * }
     */
    public static function compute(string $announced, string $received, string $before, string $after, string $tolerance): array
    {
        $announcedCl = (int) Decimal::scaled($announced, 2);
        $receivedCl = (int) Decimal::scaled($received, 2);
        $variation = (int) Decimal::scaled($after, 2) - (int) Decimal::scaled($before, 2);
        $toleranceCl = (int) Decimal::scaled($tolerance, 2);

        $announcedGap = $receivedCl - $announcedCl;
        $tankGap = $variation - $receivedCl;
        $announcedExceeds = abs($announcedGap) > $toleranceCl;
        $tankExceeds = abs($tankGap) > $toleranceCl;
        // Écart le plus grave parmi ceux qui sortent de la tolérance : un manque avant un excédent, puis le plus grand.
        $out = array_filter([$announcedExceeds ? $announcedGap : null, $tankExceeds ? $tankGap : null], static fn (?int $g): bool => $g !== null);
        usort($out, static fn (int $a, int $b): int => [$a >= 0, -abs($a)] <=> [$b >= 0, -abs($b)]);
        $worst = $out[0] ?? 0;

        return [
            'tank_variation_l' => Decimal::unscaled($variation, 2),
            'announced_variance_l' => Decimal::unscaled($announcedGap, 2),
            'tank_variance_l' => Decimal::unscaled($tankGap, 2),
            'tolerance_l' => Decimal::unscaled($toleranceCl, 2),
            'announced_exceeds' => $announcedExceeds,
            'tank_exceeds' => $tankExceeds,
            'exceeds' => $announcedExceeds || $tankExceeds,
            'shortage' => $worst < 0,
            'worst_l' => Decimal::unscaled($worst, 2),
        ];
    }
}
