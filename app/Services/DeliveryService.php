<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeImmutable;
use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\Validator;
use Petra\Domain\Decimal;
use Petra\Domain\DeliveryCalculator;
use Petra\Domain\StationSettings;
use Petra\Domain\StockCalculator;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\DeliveryRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Repositories\StockRepository;
use Petra\Repositories\SupplierRepository;
use Petra\Repositories\UserRepository;
use Petra\Security\Auth;

/**
 * Réception d'une livraison : jaugeage avant et après dépotage, quantités commandée / annoncée / reçue,
 * écarts annoncé / reçu et reçu / variation de cuve, alerte au-delà de la tolérance de la station.
 * Les deux jaugeages deviennent des jaugeages de la cuve : le stock repart du volume jaugé après.
 */
final class DeliveryService
{
    public function __construct(
        private readonly Database $db,
        private readonly DeliveryRepository $deliveries,
        private readonly StockRepository $stock,
        private readonly SupplierRepository $suppliers,
        private readonly StockControl $stockControl,
        private readonly UserRepository $users,
        private readonly StationSettingRepository $settings,
        private readonly AlertRepository $alerts,
        private readonly FileStore $files,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Personnes pouvant être désignées réceptionnaire : le personnel de la station et l'utilisateur courant.
     *
     * @return array<string, string> identifiant => nom
     */
    public function receivers(string $stationId): array
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');

        return [$user->id => $user->name()] + array_column($this->users->shiftStaff($stationId), 'name', 'id');
    }

    /**
     * @param array<string, mixed> $input champs du formulaire
     * @param array{tmp_name?: string, error?: int}|null $photo photo du bon ou du dépotage (facultative)
     * @return array{id: string, figures: array<string, mixed>, alert: bool}
     */
    public function receive(string $stationId, array $input, ?array $photo = null, ?DateTimeImmutable $at = null): array
    {
        $data = Validator::validate($input, [
            'tank_id' => 'required|string',
            'supplier_id' => 'required|string',
            'delivery_note_no' => 'string|max:60',
            'truck_plate' => 'string|max:30',
            'driver_name' => 'string|max:120',
            'ordered_volume_l' => 'decimal:2|min:0.01|max:9999999',
            'invoiced_volume_l' => 'required|decimal:2|min:0.01|max:9999999',
            'received_volume_l' => 'required|decimal:2|min:0|max:9999999',
            'before_cm' => 'required|decimal:1|min:0|max:9999',
            'after_cm' => 'required|decimal:1|min:0|max:9999',
            'received_by' => 'required|string',
            'note' => 'string|max:255',
        ]);
        $tank = $this->stock->findTank($data['tank_id']);
        if ($tank === null || $tank['station_id'] !== $stationId || !(bool) $tank['is_active']) {
            throw Validator::fail('tank_id', 'exists');
        }
        // Le fournisseur vient du répertoire de la station ; son nom du jour est recopié sur la livraison (ajout seul).
        $supplier = $this->suppliers->find($data['supplier_id']);
        if ($supplier === null || $supplier['station_id'] !== $stationId || !(bool) $supplier['is_active']) {
            throw Validator::fail('supplier_id', 'exists');
        }
        $data['supplier_name'] = (string) $supplier['name'];
        if (!isset($this->receivers($stationId)[$data['received_by']])) {
            throw Validator::fail('received_by', 'exists');
        }
        // Les deux hauteurs doivent se lire sur le barème, et la cuve ne peut pas baisser pendant un dépotage.
        $chart = $this->stock->calibration($tank['id']);
        foreach (['before_cm', 'after_cm'] as $field) {
            $read = StockCalculator::volumeAt($chart, $data[$field]);
            if ($read['error'] !== null) {
                throw Validator::fail($field, $read['error'] === 'no_calibration' ? 'dip_no_calibration' : 'dip_out_of_range');
            }
            $volumes[$field] = $read['volume_l'];
        }
        if (Decimal::scaled($data['after_cm'], 1) < Decimal::scaled($data['before_cm'], 1)) {
            throw Validator::fail('after_cm', 'after_below_before');
        }
        $tolerance = StationSettings::thresholds($this->settings->all($stationId))['delivery_variance_l'];
        $figures = DeliveryCalculator::compute($data['invoiced_volume_l'], $data['received_volume_l'], $volumes['before_cm'], $volumes['after_cm'], $tolerance);

        $at ??= new DateTimeImmutable();
        $userId = $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');
        $photoPath = FileStore::wasSent($photo) ? $this->files->storeImage($photo, $stationId, 'deliveries') : null;

        try {
            return $this->db->transaction(function () use ($stationId, $tank, $data, $figures, $at, $userId, $photoPath): array {
                // Jaugeage avant : contrôle ordinaire du stock. Jaugeage après : son écart est celui de la livraison,
                // signalé par l'alerte de livraison et non par une seconde alerte de stock.
                $before = $this->stockControl->recordDip($tank['id'], ['depth_cm' => $data['before_cm'], 'dip_type' => 'before_delivery', 'note' => $data['supplier_name']], $at);
                $deliveredAt = $at->modify('+1 second');
                $row = [
                    'tank_id' => $tank['id'], 'supplier_id' => $data['supplier_id'], 'supplier_name' => $data['supplier_name'], 'delivery_note_no' => $data['delivery_note_no'],
                    'truck_plate' => $data['truck_plate'], 'driver_name' => $data['driver_name'], 'ordered_volume_l' => $data['ordered_volume_l'],
                    'invoiced_volume_l' => $data['invoiced_volume_l'], 'received_volume_l' => $data['received_volume_l'],
                    'announced_variance_l' => $figures['announced_variance_l'], 'tank_variance_l' => $figures['tank_variance_l'],
                    'received_by' => $data['received_by'], 'note' => $data['note'], 'photo_path' => $photoPath,
                ];
                // Le théorique du jaugeage après compte le reçu de cette livraison, enregistrée juste ensuite avec ses deux jaugeages.
                $after = $this->stockControl->recordDip($tank['id'], ['depth_cm' => $data['after_cm'], 'dip_type' => 'after_delivery', 'note' => $data['supplier_name']], $deliveredAt, false, $data['received_volume_l']);
                $id = $this->deliveries->create($stationId, $row + ['dip_before_id' => $before['id'], 'dip_after_id' => $after['id']], $deliveredAt->format('Y-m-d H:i:s'), $userId);

                if ($figures['exceeds']) {
                    $worst = (int) Decimal::scaled(ltrim($figures['worst_l'], '-'), 2) * ($figures['shortage'] ? -1 : 1);
                    $amount = $this->stockControl->valueOf($stationId, (string) $tank['product_id'], $worst, $deliveredAt->format('Y-m-d H:i:s'));
                    $this->alerts->raise(
                        $stationId,
                        'delivery_variance',
                        $figures['shortage'] ? 'critical' : 'warning',
                        t('deliveries.alert', [
                            'supplier' => $data['supplier_name'], 'tank' => $tank['code'], 'announced' => format_number($data['invoiced_volume_l'], 2),
                            'received' => format_number($data['received_volume_l'], 2), 'variation' => format_number($figures['tank_variation_l'], 2),
                        ]),
                        [
                            'user_id' => $data['received_by'], 'entity_type' => 'delivery', 'entity_id' => $id, 'volume_l' => $figures['worst_l'],
                            'amount' => $amount, 'currency' => $amount === null ? null : 'CDF', 'threshold_value' => $figures['tolerance_l'],
                        ],
                        $userId,
                    );
                }
                $this->logger->log('create', 'delivery', $id, $stationId, null, $row + [
                    'tank' => $tank['code'], 'before_cm' => $data['before_cm'], 'before_l' => $before['volume_l'], 'after_cm' => $data['after_cm'], 'after_l' => $after['volume_l'],
                    'tank_variation_l' => $figures['tank_variation_l'], 'tolerance_l' => $figures['tolerance_l'], 'alert' => $figures['exceeds'],
                ], $data['supplier_name'] . ' — ' . $data['received_volume_l'] . ' L');

                return ['id' => $id, 'figures' => $figures, 'alert' => $figures['exceeds']];
            });
        } catch (\Throwable $e) {
            // Rien n'a été enregistré : la photo déjà rangée ne doit pas rester orpheline.
            if ($photoPath !== null) {
                $this->files->delete($photoPath);
            }
            throw $e;
        }
    }
}
