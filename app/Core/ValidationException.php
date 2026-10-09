<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use RuntimeException;

/** Erreurs de saisie (clés de traduction par champ), converties en 422 / retour formulaire. */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors champ => message traduit */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }

    public static function withMessage(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
