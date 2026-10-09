<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Jeton CSRF synchronisé par session. Transmis par champ `_csrf` (formulaires)
 * ou en-tête `X-CSRF-Token` (AJAX, positionné globalement par app.js).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    private static ?self $current = null;

    public function __construct(private readonly Session $session)
    {
    }

    public static function setCurrent(self $csrf): void
    {
        self::$current = $csrf;
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('CSRF non initialisé');
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        $token = $this->session->get(self::KEY);

        return is_string($token) && is_string($candidate) && $candidate !== '' && hash_equals($token, $candidate);
    }

    public function validateRequest(Request $request): void
    {
        $candidate = $request->body['_csrf'] ?? $request->header('x-csrf-token');
        if (!$this->isValid(is_string($candidate) ? $candidate : null)) {
            throw HttpException::csrf();
        }
    }

    /** Nouveau jeton après connexion/déconnexion. */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }
}
