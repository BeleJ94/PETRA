<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeInterface;
use Petra\Core\HttpException;
use Petra\Domain\Money;
use Petra\Repositories\ExchangeRateRepository;

/**
 * Taux USD → CDF en vigueur à une date donnée, par station. Une opération garde le taux de son
 * instant : un taux publié plus tard ne la touche pas.
 */
final class RateResolver
{
    public function __construct(private readonly ExchangeRateRepository $rates)
    {
    }

    /**
     * @param DateTimeInterface|string|null $at instant de l'opération (null : maintenant)
     * @return array{id: string, station_id: string, rate: int, effective_at: string}|null
     */
    public function at(string $stationId, DateTimeInterface|string|null $at = null): ?array
    {
        $row = $this->rates->versionAt($stationId, PriceResolver::instant($at));
        if ($row === null) {
            return null;
        }

        return ['rate' => (int) $row['rate']] + $row;
    }

    /** @throws HttpException 409 si aucun taux n'était en vigueur à cet instant */
    public function require(string $stationId, DateTimeInterface|string|null $at = null): array
    {
        return $this->at($stationId, $at) ?? throw new HttpException(409, 'errors.no_rate');
    }

    /** Contre-valeur en CDF d'un montant en cents de dollar, au taux en vigueur à l'instant $at. */
    public function toCdf(string $stationId, int $usdCents, DateTimeInterface|string|null $at = null): int
    {
        return Money::usdCentsToCdf($usdCents, $this->require($stationId, $at)['rate']);
    }
}
