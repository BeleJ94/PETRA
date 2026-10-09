<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Petra\Domain\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    private static function resolve(mixed $type, mixed $from = null, mixed $to = null, string $today = '2026-10-07'): array
    {
        $p = Period::resolve($type, $from, $to, new DateTimeImmutable($today . ' 15:00:00'));

        return [$p->type, $p->from, $p->to];
    }

    public function testDayWeekAndMonth(): void
    {
        self::assertSame(['day', '2026-10-07', '2026-10-07'], self::resolve('day'));
        self::assertSame(['week', '2026-10-05', '2026-10-11'], self::resolve('week'), 'Du lundi au dimanche');
        self::assertSame(['week', '2026-10-05', '2026-10-11'], self::resolve('week', null, null, '2026-10-11'), 'Un dimanche appartient à la semaine qui finit');
        self::assertSame(['week', '2026-12-28', '2027-01-03'], self::resolve('week', null, null, '2026-12-31'));
        self::assertSame(['month', '2026-10-01', '2026-10-31'], self::resolve('month'));
        self::assertSame(['month', '2028-02-01', '2028-02-29'], self::resolve('month', null, null, '2028-02-10'));
    }

    public function testCustomPeriod(): void
    {
        self::assertSame(['custom', '2026-09-01', '2026-09-30'], self::resolve('custom', '2026-09-01', '2026-09-30'));
        self::assertSame(['custom', '2026-09-01', '2026-09-30'], self::resolve('custom', '2026-09-30', '2026-09-01'), 'Bornes inversées remises dans l\'ordre');
        self::assertSame(['custom', '2026-09-15', '2026-09-15'], self::resolve('custom', '2026-09-15'));
        self::assertSame(['custom', '2026-09-15', '2026-09-15'], self::resolve('custom', null, '2026-09-15'));
        self::assertSame(['custom', '2020-01-01', '2020-12-31'], self::resolve('custom', '2020-01-01', '2026-01-01'), 'Bornée à 366 jours');
    }

    public function testInvalidInputFallsBackToToday(): void
    {
        foreach ([['custom', null, null], ['custom', '2026-13-45', 'x'], ['custom', ['a'], 12], ['année', null, null], [null, null, null], [['week'], null, null]] as [$type, $from, $to]) {
            self::assertSame(['day', '2026-10-07', '2026-10-07'], self::resolve($type, $from, $to));
        }
        self::assertSame(['custom', '2026-09-15', '2026-09-15'], self::resolve('custom', '2026-09-15', '2026-02-30'), 'Une date impossible est ignorée');
    }

    public function testSignedDecimal(): void
    {
        self::assertSame(-9500, \Petra\Domain\Decimal::signed('-95.00', 2));
        self::assertSame(9500, \Petra\Domain\Decimal::signed('95', 2));
        self::assertSame(0, \Petra\Domain\Decimal::signed('0.00', 2));
        self::assertNull(\Petra\Domain\Decimal::signed('--1', 2));
        self::assertNull(\Petra\Domain\Decimal::signed('1e3', 2));
    }

    public function testDates(): void
    {
        $period = Period::resolve('custom', '2026-02-27', '2026-03-02', new DateTimeImmutable('2026-10-07'));

        self::assertSame(4, $period->days());
        self::assertSame(['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02'], $period->dates());
        self::assertSame(1, Period::resolve('day', null, null, new DateTimeImmutable('2026-10-07'))->days());
    }
}
