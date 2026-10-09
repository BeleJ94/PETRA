<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

/** Répertoire des fournisseurs de carburant d'une station. */
final class SupplierRepository extends Repository
{
    /** Livraisons d'une fiche : celles qui la désignent, et celles d'avant le répertoire qui portent son nom. */
    private const DELIVERIES = 'd.station_id = s.id_station AND (d.supplier_id = s.id OR (d.supplier_id IS NULL AND d.supplier_name = s.name))
                                AND NOT EXISTS (SELECT 1 FROM deliveries c WHERE c.corrects_id = d.id)';

    /**
     * Fiches des stations visées, avec leur activité : nombre de livraisons, volume reçu, dernière livraison.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        [$scope, $params] = $this->stations->clause('s.station_id');
        $deliveries = str_replace('s.id_station', 's.station_id', self::DELIVERIES);

        return $this->db->fetchAll(
            "SELECT s.*, st.name AS station,
                    (SELECT COUNT(*) FROM deliveries d WHERE $deliveries) AS delivery_count,
                    (SELECT COALESCE(SUM(d.received_volume_l), 0) FROM deliveries d WHERE $deliveries) AS received_l,
                    (SELECT MAX(d.delivered_at) FROM deliveries d WHERE $deliveries) AS last_delivery_at
               FROM suppliers s JOIN stations st ON st.id = s.station_id
              WHERE $scope
              ORDER BY s.is_active DESC, s.name, st.name",
            $params
        );
    }

    /** @return list<array<string, mixed>> fiches en activité d'une station, pour la réception d'une livraison */
    public function activeForStation(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll('SELECT id, name FROM suppliers WHERE station_id = :s AND is_active = 1 ORDER BY name', ['s' => $stationId]);
    }

    public function find(string $id): ?array
    {
        return $this->scopedRow('suppliers', $id);
    }

    public function nameExists(string $stationId, string $name, ?string $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM suppliers WHERE station_id = :s AND name = :n AND id <> :id',
            ['s' => $stationId, 'n' => $name, 'id' => $exceptId ?? '']
        );
    }

    /** @param array{name: string, contact_name: ?string, phone: ?string, email: ?string, address: ?string} $data */
    public function create(string $stationId, array $data, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO suppliers (id, station_id, name, contact_name, phone, email, address, created_by)
             VALUES (:id, :s, :name, :contact, :phone, :email, :address, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'name' => $data['name'], 'contact' => $data['contact_name'],
                'phone' => $data['phone'], 'email' => $data['email'], 'address' => $data['address'], 'user' => $userId,
            ]
        );

        return $id;
    }

    /** @param array{name: string, contact_name: ?string, phone: ?string, email: ?string, address: ?string, is_active: bool} $data */
    public function update(string $id, string $stationId, array $data): void
    {
        $this->db->execute(
            'UPDATE suppliers SET name = :name, contact_name = :contact, phone = :phone, email = :email, address = :address, is_active = :active
              WHERE id = :id AND station_id = :s',
            [
                'name' => $data['name'], 'contact' => $data['contact_name'], 'phone' => $data['phone'], 'email' => $data['email'],
                'address' => $data['address'], 'active' => $data['is_active'] ? 1 : 0, 'id' => $id, 's' => $this->stations->writable($stationId),
            ]
        );
    }
}
