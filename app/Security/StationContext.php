<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Security;

use Petra\Core\HttpException;

/**
 * Stations autorisées et station active de l'utilisateur courant.
 * Injecté dans chaque repository : toute lecture d'une donnée métier inclut clause(),
 * toute ligne chargée par identifiant passe par assertAllows(), toute écriture par writable().
 * Le filtrage par station n'est jamais laissé à la requête elle-même.
 */
final class StationContext
{
    /**
     * @param array<string, array{id: string, code: string, name: string, is_active: bool}> $stations indexées par identifiant
     */
    private function __construct(
        private readonly ?string $organizationId,
        private readonly array $stations,
        private readonly ?string $activeId,
        private readonly bool $coversOrganization = false,
        private readonly bool $readOnly = false,
    ) {
    }

    /** Aucun accès : utilisateur anonyme. */
    public static function none(): self
    {
        return new self(null, [], null);
    }

    /**
     * @param iterable<array{id: string, code: string, name: string, is_active?: mixed}> $stations stations attribuées
     * @param string|null $requestedActive station choisie dans l'en-tête (ignorée si elle n'est pas attribuée)
     * @param bool $coversOrganization propriétaire : toutes les stations de l'organisation, désactivées comprises
     * @param bool $readOnly serveur central : les données des stations y arrivent par synchronisation et ne s'y modifient pas
     */
    public static function for(string $organizationId, iterable $stations, ?string $requestedActive = null, bool $coversOrganization = false, bool $readOnly = false): self
    {
        $byId = [];
        foreach ($stations as $station) {
            $byId[(string) $station['id']] = [
                'id' => (string) $station['id'], 'code' => (string) $station['code'], 'name' => (string) $station['name'],
                'is_active' => (bool) ($station['is_active'] ?? true),
            ];
        }
        // Une seule station attribuée : elle est toujours la station active.
        $active = count($byId) === 1 ? (string) array_key_first($byId) : $requestedActive;

        return new self($organizationId, $byId, $active !== null && isset($byId[$active]) ? $active : null, $coversOrganization, $readOnly);
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    /** Vrai pour le propriétaire : il voit et administre toutes les stations de son organisation. */
    public function coversOrganization(): bool
    {
        return $this->coversOrganization;
    }

    /** @return list<array{id: string, code: string, name: string, is_active: bool}> */
    public function stations(): array
    {
        return array_values($this->stations);
    }

    /** @return list<string> */
    public function stationIds(): array
    {
        return array_map('strval', array_keys($this->stations));
    }

    public function isMultiStation(): bool
    {
        return count($this->stations) > 1;
    }

    public function activeId(): ?string
    {
        return $this->activeId;
    }

    /** @return array{id: string, code: string, name: string, is_active: bool}|null */
    public function active(): ?array
    {
        return $this->activeId === null ? null : $this->stations[$this->activeId];
    }

    public function allows(?string $stationId): bool
    {
        return $stationId !== null && isset($this->stations[$stationId]);
    }

    /** @throws HttpException 403 : journalisé et signalé par une alerte (App::handle). */
    public function assertAllows(?string $stationId): void
    {
        if (!$this->allows($stationId)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Station d'une écriture : celle demandée, sinon la station active. Elle doit être attribuée et,
     * sauf pour son administration ($allowInactive), en activité.
     *
     * @throws HttpException 403 si la station n'est pas attribuée, 409 si aucune station n'est choisie ou si elle est désactivée
     */
    public function writable(?string $stationId = null, bool $allowInactive = false): string
    {
        if ($this->readOnly) {
            throw new HttpException(409, 'errors.central_read_only');
        }
        $stationId ??= $this->activeId;
        if ($stationId === null) {
            throw new HttpException(409, 'errors.no_active_station');
        }
        $this->assertAllows($stationId);
        if (!$allowInactive && !$this->stations[$stationId]['is_active']) {
            throw new HttpException(409, 'errors.station_inactive');
        }

        return $stationId;
    }

    /** Serveur central : consultation des stations, sans écriture sur leurs données. */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Station d'une écriture sur ce que le central décide (fiche de station, prix, taux) : elle doit être
     * attribuée. Sur une station, les règles ordinaires de writable() s'appliquent.
     */
    public function administrable(string $stationId, bool $allowInactive = true): string
    {
        if (!$this->readOnly) {
            return $this->writable($stationId, $allowInactive);
        }
        $this->assertAllows($stationId);

        return $stationId;
    }

    /** Stations visées par une lecture : la station active, sinon toutes les stations attribuées. */
    public function readIds(): array
    {
        return $this->activeId !== null ? [$this->activeId] : $this->stationIds();
    }

    /**
     * Fragment SQL + paramètres nommés limitant $column aux stations visées.
     * $column est un identifiant fixé dans le code (jamais une saisie utilisateur).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function clause(string $column = 'station_id', string $prefix = 'st'): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
            throw new \InvalidArgumentException("Colonne de station invalide : $column");
        }
        $ids = $this->readIds();
        if ($ids === []) {
            return ['1=0', []];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ":{$prefix}_$i";
            $params["{$prefix}_$i"] = $id;
        }

        return ["$column IN (" . implode(', ', $placeholders) . ')', $params];
    }
}
