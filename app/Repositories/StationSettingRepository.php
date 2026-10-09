<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

/** Réglages d'une station (seuils, horaires) : clé / valeur dans station_settings. */
final class StationSettingRepository extends Repository
{
    /** @return array<string, string> clé => valeur */
    public function all(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return array_column(
            $this->db->fetchAll('SELECT setting_key, setting_value FROM station_settings WHERE station_id = :s', ['s' => $stationId]),
            'setting_value',
            'setting_key'
        );
    }

    /** @param array<string, string> $values */
    public function save(string $stationId, array $values, string $userId): void
    {
        $stationId = $this->stations->writable($stationId, true);
        foreach ($values as $key => $value) {
            $this->db->execute(
                'INSERT INTO station_settings (id, station_id, setting_key, setting_value, created_by, updated_by)
                 VALUES (:id, :station, :key, :value, :created_by, :updated_by)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                ['id' => Uuid::v7(), 'station' => $stationId, 'key' => $key, 'value' => $value, 'created_by' => $userId, 'updated_by' => $userId]
            );
        }
    }
}
