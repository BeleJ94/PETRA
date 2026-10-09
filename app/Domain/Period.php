<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Domain;

use DateTimeImmutable;

/**
 * Période d'un tableau de bord, en journées d'exploitation (bornes comprises) :
 * jour, semaine (du lundi au dimanche), mois, ou période personnalisée.
 */
final class Period
{
    public const TYPES = ['day', 'week', 'month', 'custom'];
    /** Une période personnalisée est bornée : les agrégations restent rapides et les graphiques lisibles. */
    public const MAX_DAYS = 366;

    private function __construct(public readonly string $type, public readonly string $from, public readonly string $to)
    {
    }

    /**
     * @param mixed $type  un des TYPES ; toute autre valeur donne le jour
     * @param mixed $from  $to  dates AAAA-MM-JJ, lues pour une période personnalisée seulement
     */
    public static function resolve(mixed $type, mixed $from, mixed $to, DateTimeImmutable $today): self
    {
        $day = $today->format('Y-m-d');
        switch ($type) {
            case 'week':
                $monday = $today->modify('monday this week');

                return new self('week', $monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'));
            case 'month':
                return new self('month', $today->format('Y-m-01'), $today->format('Y-m-t'));
            case 'custom':
                $start = self::date($from);
                $end = self::date($to);
                if ($start === null && $end === null) {
                    break;
                }
                $start ??= $end;
                $end ??= $start;
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                $limit = (new DateTimeImmutable($start))->modify('+' . (self::MAX_DAYS - 1) . ' days')->format('Y-m-d');

                return new self('custom', $start, min($end, $limit));
        }

        return new self('day', $day, $day);
    }

    public function days(): int
    {
        return (int) (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    /** @return list<string> chaque journée de la période, dans l'ordre */
    public function dates(): array
    {
        $dates = [];
        for ($d = new DateTimeImmutable($this->from), $i = $this->days(); $i > 0; $i--, $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }

        return $dates;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }
}
