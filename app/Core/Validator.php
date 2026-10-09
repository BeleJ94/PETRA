<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Validation déclarative : ['champ' => 'required|int|min:0', ...].
 * Règles : required, nullable (implicite), string, int, numeric, min:n, max:n, in:a,b,c,
 * email, date (Y-m-d), datetime (Y-m-d H:i ou Y-m-dTH:i), bool, array,
 * decimal:n (volumes DECIMAL : retourne une chaîne « 1234.50 », jamais un float).
 * Retourne les données nettoyées et typées ; lève ValidationException sinon.
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $clean = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                // Un nombre décimal peut être saisi avec l'espace des milliers : « 9 950,5 ».
                if (self::decimals($rulesList) !== null) {
                    $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);
                }
            }
            $isEmpty = $value === null || $value === '' || $value === [];

            if (in_array('bool', $rulesList, true)) {
                $clean[$field] = in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
                continue;
            }
            if ($isEmpty) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = self::message('required', $field);
                }
                $clean[$field] = in_array('array', $rulesList, true) ? [] : null;
                continue;
            }

            $error = null;
            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::check($name, $arg, $value, $rulesList);
                if ($error !== null) {
                    $errors[$field] = self::message($error, $field, ['arg' => (string) $arg]);
                    break;
                }
            }
            if ($error === null) {
                $clean[$field] = self::cast($value, $rulesList);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /** @return string|null clé d'erreur */
    private static function check(string $rule, ?string $arg, mixed $value, array $rules): ?string
    {
        $numeric = in_array('int', $rules, true) || in_array('numeric', $rules, true) || self::decimals($rules) !== null;

        return match ($rule) {
            'required', 'nullable' => null,
            'string' => is_string($value) ? null : 'string',
            'array' => is_array($value) ? null : 'array',
            'int' => is_scalar($value) && preg_match('/^-?\d+$/', (string) $value) ? null : 'int',
            'numeric' => is_scalar($value) && is_numeric(str_replace(',', '.', (string) $value)) ? null : 'numeric',
            'decimal' => is_scalar($value) && preg_match('/^-?\d+(?:[.,]\d{1,' . max(1, (int) ($arg ?? 2)) . '})?$/', (string) $value) ? null : 'decimal',
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'email',
            'date' => is_string($value) && self::isDate($value, 'Y-m-d') ? null : 'date',
            'datetime' => is_string($value) && (self::isDate($value, 'Y-m-d\TH:i') || self::isDate($value, 'Y-m-d H:i')
                || self::isDate($value, 'Y-m-d H:i:s')) ? null : 'datetime',
            'in' => is_scalar($value) && in_array((string) $value, explode(',', (string) $arg), true) ? null : 'in',
            'min' => $numeric
                ? ((float) str_replace(',', '.', (string) $value) >= (float) $arg ? null : 'min_value')
                : (mb_strlen((string) $value) >= (int) $arg ? null : 'min_length'),
            'max' => $numeric
                ? ((float) str_replace(',', '.', (string) $value) <= (float) $arg ? null : 'max_value')
                : (mb_strlen((string) $value) <= (int) $arg ? null : 'max_length'),
            default => throw new \InvalidArgumentException("Règle inconnue : $rule"),
        };
    }

    private static function cast(mixed $value, array $rules): mixed
    {
        if (in_array('int', $rules, true)) {
            return (int) $value;
        }
        $decimals = self::decimals($rules);
        if ($decimals !== null) {
            [$int, $frac] = array_pad(explode('.', str_replace(',', '.', (string) $value), 2), 2, '');

            return $int . '.' . str_pad($frac, $decimals, '0');
        }
        if (in_array('numeric', $rules, true)) {
            return (float) str_replace(',', '.', (string) $value);
        }
        if (in_array('datetime', $rules, true)) {
            return date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', (string) $value)));
        }

        return $value;
    }

    /** Nombre de décimales de la règle decimal:n (2 par défaut), null si absente. */
    private static function decimals(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if ($rule === 'decimal' || str_starts_with($rule, 'decimal:')) {
                return max(1, (int) (explode(':', $rule, 2)[1] ?? 2));
            }
        }

        return null;
    }

    private static function isDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /** Erreur métier sur un champ (ex. unicité), message traduit « validation.$error ». */
    public static function fail(string $field, string $error, array $params = []): ValidationException
    {
        return ValidationException::withMessage($field, self::message($error, $field, $params));
    }

    private static function message(string $error, string $field, array $params = []): string
    {
        $i18n = I18n::current();
        $label = $i18n->has("fields.$field") ? $i18n->get("fields.$field") : $field;

        return $i18n->get("validation.$error", ['field' => $label] + $params);
    }
}
