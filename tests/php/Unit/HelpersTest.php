<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\I18n;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::setCurrent(new I18n(dirname(__DIR__, 3) . '/lang'));
    }

    public function testEscapesHtmlAndAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('l&apos;index', e("l'index"));
        self::assertSame('', e(null));
        self::assertSame('12', e(12));
    }

    public function testJsonForHtmlCannotBreakOutOfScriptBlock(): void
    {
        $json = json_for_html(['x' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $json);
        self::assertSame(['x' => '</script><script>alert(1)</script>'], json_decode($json, true));
    }

    public function testFrenchNumberAndDateFormats(): void
    {
        self::assertSame("1\u{202F}234,50", format_number('1234.50', 2));
        self::assertSame('', format_number(null));
        self::assertSame('24/09/2026', format_date('2026-09-24'));
        self::assertSame('24/09/2026 08:05', format_date('2026-09-24 08:05:00', true));
    }

    /** Montants en plus petite unité : CDF entier, USD en cents. */
    public function testMoneyIsFormattedFromMinorUnits(): void
    {
        self::assertSame("1\u{202F}250\u{202F}000 FC", format_money(1250000, 'CDF'));
        self::assertSame('0 FC', format_money(0, 'CDF'));
        self::assertSame("1\u{202F}234,05 $", format_money(123405, 'USD'));
        self::assertSame('0,07 $', format_money(7, 'USD'));
        self::assertSame('-0,50 $', format_money(-50, 'USD'));
        self::assertSame("-2\u{202F}500 FC", format_money(-2500, 'cdf'));
        self::assertSame('', format_money(null, 'CDF'));
        // Au-delà de la précision d'un float (2^53) : aucun arrondi.
        self::assertSame("9\u{202F}007\u{202F}199\u{202F}254\u{202F}740\u{202F}993,01 $", format_money(9007199254740993_01, 'USD'));
    }

    public function testNavCurrentMatchesSectionPrefix(): void
    {
        self::assertSame(' aria-current="page"', nav_current('/stations/12', '/stations'));
        self::assertSame('', nav_current('/stations-map', '/stations'));
        self::assertSame('', nav_current('/about', '/'));
        self::assertSame(' aria-current="page"', nav_current('/', '/'));
    }

    public function testDrillAttributeIsEscapedJson(): void
    {
        \Petra\Core\Url::configure('', '');
        $attr = drill('shifts', '/shifts/data', 'Postes "ouverts"', ['station_id' => 'abc', 'empty' => '']);
        self::assertStringStartsWith(' data-drill="', $attr);
        self::assertStringNotContainsString('"ouverts"', $attr);
        $spec = json_decode(html_entity_decode(substr($attr, 13, -1), ENT_QUOTES | ENT_HTML5), true);
        self::assertSame(['station_id' => 'abc'], $spec['params']);
        self::assertSame('/shifts/data', $spec['source']);
    }
}
