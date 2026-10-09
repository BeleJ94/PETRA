<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Uuid;

/** Livraisons de carburant (ajout seul : la base refuse UPDATE et DELETE). */
final class DeliveryRepository extends Repository
{
    public const SORTABLE = [
        'delivered_at' => 'd.delivered_at', 'supplier_name' => 'd.supplier_name', 'tank' => 't.code',
        'received_volume_l' => 'd.received_volume_l', 'announced_variance_l' => 'd.announced_variance_l', 'tank_variance_l' => 'd.tank_variance_l',
    ];

    public function datatable(DataTableRequest $dt, ?string $tankId = null): array
    {
        [$scope, $params] = $this->stations->clause('d.station_id');
        $filters = ['NOT EXISTS (SELECT 1 FROM deliveries c WHERE c.corrects_id = d.id)'];
        $filterParams = [];
        if ($tankId !== null) {
            $filters[] = 'd.tank_id = :tank';
            $filterParams['tank'] = $tankId;
        }

        return $this->paginate(
            $dt,
            "d.id, d.delivered_at, d.supplier_name, d.delivery_note_no, d.ordered_volume_l, d.invoiced_volume_l, d.received_volume_l,
             d.announced_variance_l, d.tank_variance_l, (d.photo_path IS NOT NULL) AS has_photo,
             t.code AS tank, p.name AS product, s.name AS station, CONCAT(u.first_name, ' ', u.last_name) AS receiver",
            'deliveries d JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id JOIN stations s ON s.id = d.station_id JOIN users u ON u.id = d.received_by',
            [$scope],
            $params,
            $filters,
            $filterParams,
            ['d.supplier_name', 'd.delivery_note_no', 't.code', 'p.name'],
        );
    }

    /** Livraison avec sa cuve, son réceptionnaire et ses deux jaugeages. */
    public function find(string $id): ?array
    {
        $row = $this->scopedRow('deliveries', $id);
        if ($row === null) {
            return null;
        }

        return $row + ($this->db->fetchOne(
            "SELECT t.code AS tank, t.capacity_l, p.name AS product, s.name AS station,
                    CONCAT(u.first_name, ' ', u.last_name) AS receiver, CONCAT(a.first_name, ' ', a.last_name) AS author,
                    b.depth_cm AS before_cm, b.volume_l AS before_l, f.depth_cm AS after_cm, f.volume_l AS after_l
               FROM deliveries d
               JOIN tanks t ON t.id = d.tank_id JOIN products p ON p.id = t.product_id JOIN stations s ON s.id = d.station_id
               JOIN users u ON u.id = d.received_by JOIN users a ON a.id = d.created_by
               LEFT JOIN tank_dips b ON b.id = d.dip_before_id LEFT JOIN tank_dips f ON f.id = d.dip_after_id
              WHERE d.id = :id",
            ['id' => $id]
        ) ?? []);
    }

    /**
     * @param array{tank_id: string, supplier_id: string, supplier_name: string, delivery_note_no: ?string, truck_plate: ?string, driver_name: ?string,
     *              ordered_volume_l: ?string, invoiced_volume_l: string, received_volume_l: string, announced_variance_l: string,
     *              tank_variance_l: string, dip_before_id: string, dip_after_id: string, received_by: string, note: ?string, photo_path: ?string} $data
     */
    public function create(string $stationId, array $data, string $deliveredAt, string $userId): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO deliveries (id, station_id, tank_id, supplier_id, supplier_name, delivery_note_no, truck_plate, driver_name, ordered_volume_l,
                                    invoiced_volume_l, received_volume_l, announced_variance_l, tank_variance_l, dip_before_id, dip_after_id,
                                    delivered_at, received_by, note, photo_path, created_by)
             VALUES (:id, :s, :tank, :supplier_id, :supplier, :note_no, :plate, :driver, :ordered, :invoiced, :received, :announced_variance, :tank_variance,
                     :before, :after, :at, :receiver, :note, :photo, :user)',
            [
                'id' => $id, 's' => $this->stations->writable($stationId), 'tank' => $data['tank_id'], 'supplier_id' => $data['supplier_id'], 'supplier' => $data['supplier_name'],
                'note_no' => $data['delivery_note_no'], 'plate' => $data['truck_plate'], 'driver' => $data['driver_name'], 'ordered' => $data['ordered_volume_l'],
                'invoiced' => $data['invoiced_volume_l'], 'received' => $data['received_volume_l'], 'announced_variance' => $data['announced_variance_l'],
                'tank_variance' => $data['tank_variance_l'], 'before' => $data['dip_before_id'], 'after' => $data['dip_after_id'], 'at' => $deliveredAt,
                'receiver' => $data['received_by'], 'note' => $data['note'], 'photo' => $data['photo_path'], 'user' => $userId,
            ]
        );

        return $id;
    }
}
