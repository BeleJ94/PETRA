<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers  clés en minuscules
     * @param array<string, mixed> $server
     * @param array<string, array{name?: string, tmp_name?: string, size?: int, error?: int}> $files fichiers envoyés ($_FILES)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $server = [],
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::normalizePath($uri),
            $_GET,
            $body,
            $headers,
            $_SERVER,
            $_FILES,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/index.php' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** Chaîne nettoyée (trim) ou null si vide. */
    public function string(string $key): ?string
    {
        $value = $this->input($key);
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function int(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param list<string> $keys */
    public function only(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            $value = $this->body[$key] ?? null;
            $data[$key] = is_string($value) ? trim($value) : $value;
        }

        return $data;
    }

    /** Fichier unique envoyé sous $key (null si absent). */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && isset($file['tmp_name']) && !is_array($file['tmp_name']) ? $file : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function wantsJson(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest'
            || str_contains($this->header('accept') ?? '', 'application/json');
    }

    public function ip(): ?string
    {
        return isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : null;
    }

    public function userAgent(): ?string
    {
        $ua = $this->header('user-agent');

        return $ua === null ? null : mb_substr($ua, 0, 255);
    }
}
