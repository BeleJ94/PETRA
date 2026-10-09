<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Security;

/** Mots de passe en argon2id. Un ancien hachage est accepté à la connexion puis remplacé. */
final class PasswordHasher
{
    public const MIN_LENGTH = 10;

    /** Hachage factice : même durée de vérification pour un compte inexistant que pour un compte réel. */
    private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Zf2vYtwDWSmmpUQJz7Fz5cQ9qf0fMRQbkUOxo2jU7Bs';

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public static function burn(string $password): void
    {
        password_verify($password, self::DUMMY);
    }

    /** Empreinte courte du hachage, gardée en session : changer le mot de passe ferme les sessions ouvertes. */
    public static function fingerprint(string $hash): string
    {
        return substr(hash('sha256', $hash), 0, 16);
    }
}
