<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'errors.not_found');
    }

    public static function forbidden(): self
    {
        return new self(403, 'errors.forbidden');
    }

    public static function csrf(): self
    {
        return new self(419, 'errors.csrf');
    }
}
