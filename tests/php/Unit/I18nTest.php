<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testCatalogueHasNoEmptyTranslation(): void
    {
        $fr = require self::root() . '/lang/fr.php';
        self::assertNotEmpty($fr);
        foreach ($fr as $key => $text) {
            self::assertNotSame('', trim($text), "Traduction vide : $key");
        }
    }

    /** Toute clé littérale utilisée dans le code (PHP et JS) doit exister. */
    public function testEveryKeyUsedInCodeExists(): void
    {
        $fr = require self::root() . '/lang/fr.php';
        $files = array_merge(
            iterator_to_array(new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/app')), '/\.php$/')),
            array_map(static fn ($f) => new \SplFileInfo($f), glob(self::root() . '/public/assets/js/*.js') ?: [])
        );
        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            preg_match_all("/\\bt\\('([a-z_]+\\.[a-z0-9_.]+)'/", $source, $m);
            preg_match_all("/\\['\\/[^']*', '[a-z0-9-]+', '([a-z_]+\\.[a-z0-9_.]+)'/", $source, $m2);
            preg_match_all("/'(errors\\.[a-z_]+)'/", $source, $m3);
            foreach (array_merge($m[1], $m2[1], $m3[1]) as $key) {
                if (!isset($fr[$key])) {
                    $missing[] = $key . ' (' . basename((string) $file) . ')';
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testDerivedKeysExist(): void
    {
        $i18n = new I18n(self::root() . '/lang');
        foreach (['station', 'central'] as $mode) {
            self::assertTrue($i18n->has('mode.' . $mode), 'mode.' . $mode);
        }
        foreach (['required', 'string', 'array', 'int', 'numeric', 'decimal', 'email', 'date', 'datetime', 'in', 'min_value', 'max_value', 'min_length', 'max_length'] as $rule) {
            self::assertTrue($i18n->has('validation.' . $rule), 'validation.' . $rule);
        }
    }

    public function testCreditMentionIsExact(): void
    {
        self::assertSame('Petra — développé par BELEJ-CONSULTING', (new I18n(self::root() . '/lang'))->get('app.credit'));
    }

    public function testFallbackAndParameters(): void
    {
        $i18n = new I18n(self::root() . '/lang', 'en');
        self::assertSame('Accueil', $i18n->get('nav.home'), 'Repli sur le français');
        self::assertSame('3 ligne(s)', $i18n->get('js.rows_count', ['n' => 3]));
        self::assertSame('unknown.key', $i18n->get('unknown.key'));
    }

    public function testJsCatalogueOnlyExposesWhitelistedPrefixes(): void
    {
        $catalogue = (new I18n(self::root() . '/lang'))->jsCatalogue();
        self::assertArrayHasKey('js.dt.search', $catalogue);
        self::assertArrayHasKey('fields.code', $catalogue);
        self::assertArrayNotHasKey('errors.server', $catalogue);
    }
}
