<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

use Petra\Core\Validator;

/**
 * Réglages d'une station gardés dans station_settings : seuils de contrôle et horaires des postes.
 * Tous les seuils sont paramétrables par station ; une clé absente prend la valeur par défaut d'ici.
 */
final class StationSettings
{
    public const SCHEDULE_KEY = 'shift_schedule';
    /** Organisation des postes : « fixed » (créneaux horaires nommés) ou « free » (ouverture et clôture libres). */
    public const MODE_KEY = 'shift_mode';
    public const MODES = ['fixed', 'free'];
    /** Tranche dont le taux convertit les dollars remis sans indication de tranche : last, first, lowest, highest. */
    public const USD_RULE_KEY = 'usd_unallocated_rate';
    public const DEFAULT_USD_RULE = 'last';
    public const MAX_SLOTS = 4;

    /**
     * Seuils : clé => [règle de validation, valeur par défaut].
     * Volumes et pourcentages en chaîne décimale, montants en CDF entiers.
     */
    public const THRESHOLDS = [
        'stock_tolerance_pct' => ['required|decimal:2|min:0|max:100', '0.50'],
        'stock_tolerance_l' => ['required|decimal:2|min:0|max:9999999', '50.00'],
        'low_stock_l' => ['required|decimal:2|min:0|max:9999999', '3000.00'],
        'cash_shortage_tolerance_cdf' => ['required|int|min:0|max:1000000000000', '5000'],
        'cash_surplus_tolerance_cdf' => ['required|int|min:0|max:1000000000000', '5000'],
        'delivery_variance_l' => ['required|decimal:2|min:0|max:9999999', '100.00'],
    ];

    /**
     * Délais des contrôles quotidiens (bin/daily-checks.php), en heures : clé => [minimum, maximum, valeur par défaut].
     * Facultatifs à la saisie : une valeur absente garde celle en place.
     */
    public const DELAYS = [
        'shift_max_open_hours' => [1, 168, '16'],
        'sync_max_delay_hours' => [1, 720, '24'],
    ];

    /** Horaires par défaut : 06h–12h et 12h–18h. */
    public const DEFAULT_SCHEDULE = [
        ['label' => 'Matin', 'start' => '06:00', 'end' => '12:00'],
        ['label' => 'Après-midi', 'start' => '12:00', 'end' => '18:00'],
    ];

    /**
     * @param array<string, string> $stored lignes de station_settings (clé => valeur)
     * @return array<string, string> les six seuils, valeurs par défaut comprises
     */
    public static function thresholds(array $stored): array
    {
        $out = [];
        foreach (self::THRESHOLDS as $key => [, $default]) {
            $out[$key] = (string) ($stored[$key] ?? $default);
        }

        return $out;
    }

    /**
     * @param array<string, string> $stored
     * @return array<string, string> délais des contrôles quotidiens, valeurs par défaut comprises
     */
    public static function delays(array $stored): array
    {
        $out = [];
        foreach (self::DELAYS as $key => [$min, $max, $default]) {
            $value = (string) ($stored[$key] ?? $default);
            $out[$key] = preg_match('/^\d+$/', $value) && (int) $value >= $min && (int) $value <= $max ? $value : $default;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> délais envoyés et valides (les champs absents ou vides sont ignorés)
     */
    public static function validateDelays(array $input): array
    {
        $out = [];
        foreach (self::DELAYS as $key => [$min, $max]) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = (string) Validator::validate([$key => $value], [$key => "required|int|min:$min|max:$max"])[$key];
        }

        return $out;
    }

    /** @param array<string, string> $stored */
    public static function usdRule(array $stored): string
    {
        $rule = (string) ($stored[self::USD_RULE_KEY] ?? self::DEFAULT_USD_RULE);

        return in_array($rule, CashCalculator::USD_RULES, true) ? $rule : self::DEFAULT_USD_RULE;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> seuils validés, prêts à être enregistrés
     */
    public static function validateThresholds(array $input): array
    {
        $data = Validator::validate($input, array_map(static fn (array $definition): string => $definition[0], self::THRESHOLDS));

        return array_map('strval', $data);
    }

    /** @param array<string, string> $stored */
    public static function shiftMode(array $stored): string
    {
        return in_array($stored[self::MODE_KEY] ?? null, self::MODES, true) ? (string) $stored[self::MODE_KEY] : 'fixed';
    }

    /**
     * @param array<string, string> $stored
     * @return list<array{label: string, start: string, end: string}>
     */
    public static function schedule(array $stored): array
    {
        $decoded = json_decode((string) ($stored[self::SCHEDULE_KEY] ?? ''), true);

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : self::DEFAULT_SCHEDULE;
    }

    /**
     * Valide les horaires saisis : lignes vides ignorées, heures HH:MM, aucun chevauchement.
     * Un poste peut passer minuit (22:00 → 06:00).
     *
     * @param mixed $rows lignes du formulaire : [['label' => , 'start' => , 'end' => ], …]
     * @return list<array{label: string, start: string, end: string}>
     */
    public static function validateSchedule(mixed $rows): array
    {
        $slots = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = is_array($row) ? array_map(static fn ($v) => is_string($v) ? trim($v) : '', $row) + ['label' => '', 'start' => '', 'end' => ''] : null;
            if ($row === null || ($row['label'] === '' && $row['start'] === '' && $row['end'] === '')) {
                continue;
            }
            if ($row['label'] === '' || mb_strlen($row['label']) > 16) {
                throw Validator::fail('shift_schedule', 'schedule_label');
            }
            foreach (['start', 'end'] as $bound) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $row[$bound])) {
                    throw Validator::fail('shift_schedule', 'schedule_time');
                }
            }
            if ($row['start'] === $row['end']) {
                throw Validator::fail('shift_schedule', 'schedule_time');
            }
            $slots[] = ['label' => $row['label'], 'start' => $row['start'], 'end' => $row['end']];
        }
        if ($slots === [] || count($slots) > self::MAX_SLOTS) {
            throw Validator::fail('shift_schedule', 'schedule_count', ['max' => self::MAX_SLOTS]);
        }

        $taken = [];
        foreach ($slots as $slot) {
            $start = self::minutes($slot['start']);
            $length = (self::minutes($slot['end']) - $start + 1440) % 1440;
            for ($i = 0; $i < $length; $i++) {
                $minute = ($start + $i) % 1440;
                if (isset($taken[$minute])) {
                    throw Validator::fail('shift_schedule', 'schedule_overlap');
                }
                $taken[$minute] = true;
            }
        }

        return $slots;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
