<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Session : native ($_SESSION) en HTTP, tableau en mémoire pour les tests et la CLI.
 */
final class Session
{
    private array $store = [];
    private bool $native = false;

    public static function native(array $config): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($config['name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $config['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', (string) $config['lifetime']);
            session_start();
        }
        $session = new self();
        $session->native = true;
        $session->store = &$_SESSION;

        return $session;
    }

    public static function memory(array $data = []): self
    {
        $session = new self();
        $session->store = $data;

        return $session;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }

    /** Message affiché une seule fois à la requête suivante. */
    public function flash(string $key, mixed $value): void
    {
        $this->store['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $this->store['_flash'][$key] ?? $default;
        unset($this->store['_flash'][$key]);

        return $value;
    }

    /** Nouvel identifiant de session (anti-fixation) : à appeler à chaque changement de privilège. */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        foreach (array_keys($this->store) as $key) {
            unset($this->store[$key]);
        }
    }

    public function all(): array
    {
        return $this->store;
    }
}
