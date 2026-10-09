<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Catalogue de traductions à plat (clé pointée => texte), paramètres :name.
 * Repli sur le français puis sur la clé si une traduction manque.
 */
final class I18n
{
    public const FALLBACK = 'fr';

    private static ?self $current = null;

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(private readonly string $langDir, private string $locale = self::FALLBACK)
    {
    }

    public static function setCurrent(self $i18n): void
    {
        self::$current = $i18n;
    }

    public static function current(): self
    {
        return self::$current ??= new self(dirname(__DIR__, 2) . '/lang');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @return array<string, string> */
    public function catalogue(?string $locale = null): array
    {
        $locale ??= $this->locale;
        if (!isset($this->catalogues[$locale])) {
            $file = $this->langDir . '/' . basename($locale) . '.php';
            $this->catalogues[$locale] = is_file($file) ? require $file : [];
        }

        return $this->catalogues[$locale];
    }

    public function has(string $key): bool
    {
        return isset($this->catalogue()[$key]);
    }

    public function get(string $key, array $params = []): string
    {
        $text = $this->catalogue()[$key] ?? $this->catalogue(self::FALLBACK)[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /** Préfixes de clés exposés au JavaScript (libellés de tableaux, statuts, messages). */
    public const JS_PREFIXES = ['js.', 'fields.', 'entity.', 'action.', 'alert_status.', 'severity.'];

    /** @return array<string, string> */
    public function jsCatalogue(): array
    {
        $out = [];
        foreach (array_keys($this->catalogue(self::FALLBACK)) as $key) {
            foreach (self::JS_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $out[$key] = $this->get($key);
                    break;
                }
            }
        }

        return $out;
    }
}
