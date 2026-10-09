<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

final class Url
{
    private static string $basePath = '';
    private static string $publicDir = '';

    public static function configure(string $basePath, string $publicDir): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$publicDir = rtrim($publicDir, '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function to(string $path = '/', array $query = []): string
    {
        $url = self::$basePath . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /** URL d'un asset avec empreinte de cache basée sur la date de modification. */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = self::$publicDir . '/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return self::$basePath . '/assets/' . $path . '?v=' . $version;
    }
}
