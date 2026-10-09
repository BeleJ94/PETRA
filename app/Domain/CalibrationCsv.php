<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

/**
 * Lecture d'un barème de jaugeage au format CSV : deux colonnes, hauteur (cm) puis volume (litres).
 * Séparateur « ; », tabulation ou « , » ; virgule décimale acceptée avec « ; » ou la tabulation ;
 * ligne d'en-tête facultative. Le fichier est accepté en entier ou refusé en entier.
 */
final class CalibrationCsv
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_ROWS = 5000;

    /**
     * @param string $capacity capacité de la cuve (DECIMAL en chaîne) : aucun volume ne peut la dépasser
     * @return array{rows: list<array{depth_cm: string, volume_l: string}>, errors: list<array{line: int, error: string}>}
     *   error = suffixe de clé i18n « calibrations.csv_… »
     */
    public static function parse(string $content, string $capacity): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $rows = [];
        $errors = [];
        $seen = [];
        $capacityCl = self::scaled($capacity, 2) ?? 0;

        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $separator = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
            $cells = array_map(static fn (string $c): string => trim($c, " \t\""), explode($separator, $line));
            // Avec « , » comme séparateur, « 12,5,1500 » serait ambigu : deux colonnes exactement.
            if (count($cells) < 2 || ($separator === ',' && count($cells) !== 2)) {
                $errors[] = ['line' => $number, 'error' => 'csv_columns'];
                continue;
            }
            $depth = self::scaled($cells[0], 1);
            $volume = self::scaled($cells[1], 2);
            if ($depth === null || $volume === null) {
                // Une première ligne non numérique est un en-tête.
                if ($rows === [] && $errors === [] && !preg_match('/\d/', $cells[0] . $cells[1])) {
                    continue;
                }
                $errors[] = ['line' => $number, 'error' => 'csv_number'];
                continue;
            }
            if ($depth > 99999 || $volume > $capacityCl) {
                $errors[] = ['line' => $number, 'error' => $depth > 99999 ? 'csv_number' : 'csv_capacity'];
                continue;
            }
            if (isset($seen[$depth])) {
                $errors[] = ['line' => $number, 'error' => 'csv_duplicate'];
                continue;
            }
            $seen[$depth] = $volume;
            $rows[] = ['depth_cm' => self::unscaled($depth, 1), 'volume_l' => self::unscaled($volume, 2), 'line' => $number, 'depth' => $depth, 'volume' => $volume];
            if (count($rows) > self::MAX_ROWS) {
                return ['rows' => [], 'errors' => [['line' => $number, 'error' => 'csv_too_many']]];
            }
        }

        // Un barème est croissant : plus la hauteur est grande, plus le volume l'est.
        usort($rows, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);
        foreach ($rows as $i => $row) {
            if ($i > 0 && $row['volume'] < $rows[$i - 1]['volume']) {
                $errors[] = ['line' => $row['line'], 'error' => 'csv_not_increasing'];
            }
        }
        if ($rows === [] && $errors === []) {
            $errors[] = ['line' => 1, 'error' => 'csv_empty'];
        }
        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return [
            'rows' => $errors === [] ? array_map(static fn (array $r): array => ['depth_cm' => $r['depth_cm'], 'volume_l' => $r['volume_l']], $rows) : [],
            'errors' => $errors,
        ];
    }

    /** « 12,5 » avec 1 décimale → 125 ; null si la valeur n'est pas un nombre positif à $decimals décimales au plus. */
    public static function scaled(string $value, int $decimals): ?int
    {
        return Decimal::scaled($value, $decimals);
    }

    private static function unscaled(int $value, int $decimals): string
    {
        $text = str_pad((string) $value, $decimals + 1, '0', STR_PAD_LEFT);

        return substr($text, 0, -$decimals) . '.' . substr($text, -$decimals);
    }
}
