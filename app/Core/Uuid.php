<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Clés primaires : UUID version 7 (RFC 9562), générés en PHP, en minuscules.
 * Les 48 premiers bits portent l'horodatage en millisecondes : les identifiants créés sur des
 * stations différentes ne se télescopent pas et restent à peu près ordonnés dans les index.
 */
final class Uuid
{
    public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function v7(): string
    {
        $bytes = substr(pack('J', (int) (microtime(true) * 1000)), 2) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
