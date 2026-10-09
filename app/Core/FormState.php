<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/** Erreurs de validation et saisie précédente, réaffichées après redirection. */
final class FormState
{
    private static array $errors = [];
    private static array $old = [];

    public static function load(array $errors, array $old): void
    {
        self::$errors = $errors;
        self::$old = $old;
    }

    public static function error(string $field): ?string
    {
        return self::$errors[$field] ?? null;
    }

    public static function errors(): array
    {
        return self::$errors;
    }

    public static function old(string $field, mixed $default = null): mixed
    {
        return array_key_exists($field, self::$old) ? self::$old[$field] : $default;
    }

    public static function hasOld(): bool
    {
        return self::$old !== [];
    }
}
