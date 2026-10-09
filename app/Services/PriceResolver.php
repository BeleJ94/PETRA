<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeInterface;
use Petra\Core\HttpException;
use Petra\Repositories\PriceRepository;

/**
 * Prix en vigueur à une date donnée. Tout calcul utilise la version en vigueur à l'instant de
 * l'opération, jamais le prix du jour.
 */
final class PriceResolver
{
    public function __construct(private readonly PriceRepository $prices)
    {
    }

    /**
     * @param DateTimeInterface|string|null $at instant de l'opération (null : maintenant)
     * @return array{id: string, station_id: string, product_id: string, unit_price: int, currency: string, effective_at: string}|null
     */
    public function at(string $productId, DateTimeInterface|string|null $at = null): ?array
    {
        $row = $this->prices->versionAt($productId, self::instant($at));
        if ($row === null) {
            return null;
        }

        return ['unit_price' => (int) $row['unit_price']] + $row;
    }

    /** @throws HttpException 409 si aucun prix n'était en vigueur à cet instant */
    public function require(string $productId, DateTimeInterface|string|null $at = null): array
    {
        return $this->at($productId, $at) ?? throw new HttpException(409, 'errors.no_price');
    }

    public static function instant(DateTimeInterface|string|null $at): string
    {
        return $at instanceof DateTimeInterface ? $at->format('Y-m-d H:i:s') : ($at ?? date('Y-m-d H:i:s'));
    }
}
