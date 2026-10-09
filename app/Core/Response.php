<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /** Fichier servi par l'application (jamais directement par le serveur web). */
    public static function binary(string $body, string $contentType): self
    {
        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function send(): void
    {
        // 419 (jeton CSRF expiré) n'est pas un code standard : sans libellé explicite, Apache le remplace par 500.
        $this->status === 419
            ? header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 419 Page Expired', true, 419)
            : http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
