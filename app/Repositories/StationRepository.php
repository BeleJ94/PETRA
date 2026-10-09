<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\HttpException;
use Petra\Core\Uuid;

/**
 * Stations de l'organisation. Administration : la station active de l'en-tête ne restreint pas la liste,
 * mais seules les stations attribuées sont visibles (toutes, désactivées comprises, pour le propriétaire).
 */
final class StationRepository extends Repository
{
    public const SORTABLE = ['code' => 's.code', 'name' => 's.name', 'city' => 's.city', 'is_active' => 's.is_active'];

    /** @return array{0: string, 1: array<string, string>} */
    private function scope(string $column): array
    {
        $ids = $this->stations->stationIds();
        if ($ids === []) {
            return ['1=0', []];
        }
        $params = [];
        foreach ($ids as $i => $id) {
            $params["sid_$i"] = $id;
        }

        return ["$column IN (:" . implode(', :', array_keys($params)) . ')', $params];
    }

    public function datatable(DataTableRequest $dt, ?bool $active = null): array
    {
        [$scopeSql, $params] = $this->scope('s.id');
        $filters = [];
        $filterParams = [];
        if ($active !== null) {
            $filters[] = 's.is_active = :active';
            $filterParams['active'] = $active ? 1 : 0;
        }

        return $this->paginate(
            $dt,
            's.id, s.code, s.name, s.city, s.timezone, s.is_active,
             (SELECT COUNT(*) FROM tanks t WHERE t.station_id = s.id AND t.is_active = 1) AS tank_count,
             (SELECT COUNT(*) FROM nozzles n WHERE n.station_id = s.id AND n.is_active = 1) AS nozzle_count',
            'stations s',
            [$scopeSql],
            $params,
            $filters,
            $filterParams,
            ['s.code', 's.name', 's.city'],
        );
    }

    public function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM stations WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $this->stations->assertAllows($id);

        return $row;
    }

    public function codeExists(string $code, ?string $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM stations WHERE organization_id = :org AND code = :code AND id <> :id',
            ['org' => (string) $this->stations->organizationId(), 'code' => $code, 'id' => $exceptId ?? '']
        );
    }

    /**
     * Nouvelle station de l'organisation : réservé à qui administre toute l'organisation (propriétaire).
     *
     * @param array{code: string, name: string, city: ?string, address: ?string, timezone: string} $data
     */
    public function create(array $data, string $userId): string
    {
        if (!$this->stations->coversOrganization()) {
            throw HttpException::forbidden();
        }
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO stations (id, organization_id, code, name, city, address, timezone, created_by)
             VALUES (:id, :org, :code, :name, :city, :address, :timezone, :user)',
            ['id' => $id, 'org' => (string) $this->stations->organizationId(), 'user' => $userId] + self::params($data)
        );

        return $id;
    }

    /** @param array{code: string, name: string, city: ?string, address: ?string, timezone: string} $data */
    public function update(string $id, array $data): void
    {
        $this->db->execute(
            'UPDATE stations SET code = :code, name = :name, city = :city, address = :address, timezone = :timezone WHERE id = :id',
            ['id' => $this->stations->administrable($id)] + self::params($data)
        );
    }

    public function setActive(string $id, bool $active): void
    {
        $this->db->execute(
            'UPDATE stations SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $this->stations->administrable($id)]
        );
    }

    public function setTimezone(string $id, string $timezone): void
    {
        $this->db->execute('UPDATE stations SET timezone = :tz WHERE id = :id', ['tz' => $timezone, 'id' => $this->stations->administrable($id)]);
    }

    public function openShiftCount(string $id): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM shifts WHERE station_id = :id AND status = 'open'",
            ['id' => $this->stations->administrable($id)]
        );
    }

    private static function params(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'timezone' => $data['timezone'],
        ];
    }
}
