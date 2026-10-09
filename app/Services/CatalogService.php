<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\ValidationException;
use Petra\Core\Validator;
use Petra\Domain\CalibrationCsv;
use Petra\Repositories\CalibrationRepository;
use Petra\Repositories\NozzleRepository;
use Petra\Repositories\ProductRepository;
use Petra\Repositories\PumpRepository;
use Petra\Repositories\StationRepository;
use Petra\Repositories\TankRepository;
use Petra\Security\Auth;

/** Installation d'une station : produits, cuves, pistolets et barèmes de jaugeage. Tout est journalisé. */
final class CatalogService
{
    public function __construct(
        private readonly Database $db,
        private readonly StationRepository $stations,
        private readonly ProductRepository $products,
        private readonly TankRepository $tanks,
        private readonly NozzleRepository $nozzles,
        private readonly PumpRepository $pumps,
        private readonly CalibrationRepository $calibrations,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    // --- Produits -----------------------------------------------------------------

    public function createProduct(string $stationId, array $input): string
    {
        $this->station($stationId);
        $data = Validator::validate($input, ['code' => 'required|string|max:20', 'name' => 'required|string|max:80']);
        $data['code'] = self::code($data['code']);
        if ($this->products->codeExists($stationId, $data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($stationId, $data): string {
            $id = $this->products->create($stationId, $data, $this->userId());
            $this->logger->log('create', 'product', $id, $stationId, null, $data, $data['name']);

            return $id;
        });
    }

    public function setProductActive(string $productId, bool $active): array
    {
        $product = $this->products->find($productId) ?? throw HttpException::notFound();
        $this->db->transaction(function () use ($product, $active): void {
            $this->products->setActive($product['id'], $product['station_id'], $active);
            $this->logger->log($active ? 'activate' : 'deactivate', 'product', $product['id'], $product['station_id'], ['is_active' => $product['is_active']], ['is_active' => $active ? 1 : 0], $product['name']);
        });

        return $product;
    }

    // --- Cuves ----------------------------------------------------------------------

    public function createTank(string $stationId, array $input): string
    {
        $this->station($stationId);
        $data = Validator::validate($input, [
            'product_id' => 'required|string',
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:80',
            'capacity_l' => 'required|decimal:2|min:1|max:9999999',
        ]);
        $data['code'] = self::code($data['code']);
        $product = $this->products->find($data['product_id']);
        if ($product === null || $product['station_id'] !== $stationId || !(bool) $product['is_active']) {
            throw Validator::fail('product_id', 'exists');
        }
        if ($this->tanks->codeExists($stationId, $data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($stationId, $data): string {
            $id = $this->tanks->create($stationId, $data, $this->userId());
            $this->logger->log('create', 'tank', $id, $stationId, null, $data, $data['name']);

            return $id;
        });
    }

    public function updateTank(string $tankId, array $input): array
    {
        $tank = $this->tanks->find($tankId) ?? throw HttpException::notFound();
        $data = Validator::validate($input, [
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:80',
            'capacity_l' => 'required|decimal:2|min:1|max:9999999',
            'is_active' => 'bool',
        ]);
        $data['code'] = self::code($data['code']);
        if ($this->tanks->codeExists($tank['station_id'], $data['code'], $tankId)) {
            throw Validator::fail('code', 'unique');
        }
        // La capacité ne peut pas descendre sous le plus grand volume du barème.
        $maxVolume = $this->calibrations->summary($tank['station_id'], $tankId)['max_volume_l'];
        if ($maxVolume !== null && CalibrationCsv::scaled($data['capacity_l'], 2) < CalibrationCsv::scaled((string) $maxVolume, 2)) {
            throw Validator::fail('capacity_l', 'capacity_below_calibration', ['volume' => (string) $maxVolume]);
        }
        if (!$data['is_active'] && $this->tanks->activeNozzleCount($tankId) > 0) {
            throw Validator::fail('is_active', 'tank_has_nozzles');
        }
        $this->db->transaction(function () use ($tank, $data): void {
            $this->tanks->update($tank['id'], $tank['station_id'], $data);
            $this->logger->log('update', 'tank', $tank['id'], $tank['station_id'], $tank, ['is_active' => $data['is_active'] ? 1 : 0] + $data, $data['name']);
        });

        return $tank;
    }

    // --- Distributeurs ----------------------------------------------------------------

    public function createPump(string $stationId, array $input): string
    {
        $this->station($stationId);
        $data = Validator::validate($input, ['code' => 'required|string|max:20', 'name' => 'required|string|max:80']);
        $data['code'] = self::code($data['code']);
        if ($this->pumps->codeExists($stationId, $data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($stationId, $data): string {
            $id = $this->pumps->create($stationId, $data, $this->userId());
            $this->logger->log('create', 'pump', $id, $stationId, null, $data, $data['name']);

            return $id;
        });
    }

    public function updatePump(string $pumpId, array $input): array
    {
        $pump = $this->pumps->find($pumpId) ?? throw HttpException::notFound();
        $data = Validator::validate($input, ['code' => 'required|string|max:20', 'name' => 'required|string|max:80', 'is_active' => 'bool']);
        $data['code'] = self::code($data['code']);
        if ($this->pumps->codeExists($pump['station_id'], $data['code'], $pumpId)) {
            throw Validator::fail('code', 'unique');
        }
        // Un distributeur tenu par un poste ouvert ne se retire pas du service : le poste doit d'abord être clôturé.
        if (!$data['is_active'] && (bool) $pump['is_active'] && $this->pumps->inOpenShift($pumpId)) {
            throw Validator::fail('is_active', 'pump_in_use');
        }
        $this->db->transaction(function () use ($pump, $data): void {
            $this->pumps->update($pump['id'], $pump['station_id'], $data);
            $this->logger->log('update', 'pump', $pump['id'], $pump['station_id'], $pump, ['is_active' => $data['is_active'] ? 1 : 0] + $data, $data['name']);
        });

        return $pump;
    }

    // --- Pistolets --------------------------------------------------------------------

    public function createNozzle(string $stationId, array $input): string
    {
        $this->station($stationId);
        $data = $this->validateNozzle($stationId, $input, null);

        return $this->db->transaction(function () use ($stationId, $data): string {
            $id = $this->nozzles->create($stationId, $data, $this->userId());
            $this->logger->log('create', 'nozzle', $id, $stationId, null, $data, $data['name']);

            return $id;
        });
    }

    public function updateNozzle(string $nozzleId, array $input): array
    {
        $nozzle = $this->nozzles->find($nozzleId) ?? throw HttpException::notFound();
        $data = $this->validateNozzle($nozzle['station_id'], $input, $nozzleId, $nozzle);
        $data['rollover_index'] ??= (string) $nozzle['rollover_index'];
        // Dès qu'un index a été relevé, le pistolet ne change plus de distributeur ni de cuve (donc de produit) :
        // ses index passés resteraient rattachés au mauvais produit. On le désactive et on en crée un autre.
        if ($this->nozzles->hasReadings($nozzleId)) {
            foreach (['pump_id', 'tank_id'] as $field) {
                if ($data[$field] !== $nozzle[$field]) {
                    throw Validator::fail($field, 'locked_after_use');
                }
            }
        }
        $this->db->transaction(function () use ($nozzle, $data): void {
            $this->nozzles->update($nozzle['id'], $nozzle['station_id'], $data);
            $this->logger->log('update', 'nozzle', $nozzle['id'], $nozzle['station_id'], $nozzle, ['is_active' => $data['is_active'] ? 1 : 0] + $data, $data['name']);
        });

        return $nozzle;
    }

    private function validateNozzle(string $stationId, array $input, ?string $nozzleId, ?array $current = null): array
    {
        $data = Validator::validate($input, [
            'tank_id' => 'required|string',
            'pump_id' => ($nozzleId === null ? 'required|' : '') . 'string',
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:80',
            'rollover_index' => 'decimal:2|min:1|max:9999999999',
            'is_active' => 'bool',
        ]);
        if ($nozzleId === null) {
            $data['is_active'] = true;
            unset($data['rollover_index']);
        }
        $data['code'] = self::code($data['code']);
        // La cuve doit appartenir à la même station : un pistolet ne puise jamais dans la cuve d'une autre.
        $tank = $this->tanks->find($data['tank_id']);
        if ($tank === null || $tank['station_id'] !== $stationId || ($data['is_active'] && !(bool) $tank['is_active'])) {
            throw Validator::fail('tank_id', 'exists');
        }
        // Le distributeur est celui de la station ; à la modification, il reste celui du pistolet s'il n'est pas renvoyé.
        $data['pump_id'] ??= $current['pump_id'] ?? null;
        if ($data['pump_id'] !== null && $data['pump_id'] !== ($current['pump_id'] ?? null)) {
            $pump = $this->pumps->find($data['pump_id']);
            if ($pump === null || $pump['station_id'] !== $stationId || !(bool) $pump['is_active']) {
                throw Validator::fail('pump_id', 'exists');
            }
        }
        if ($this->nozzles->codeExists($stationId, $data['code'], $nozzleId)) {
            throw Validator::fail('code', 'unique');
        }

        return $data;
    }

    // --- Barème de jaugeage --------------------------------------------------------------

    /** Saisie d'un point : ajoute la hauteur ou remplace son volume. */
    public function saveCalibrationPoint(string $tankId, array $input): array
    {
        $tank = $this->tanks->find($tankId) ?? throw HttpException::notFound();
        $data = Validator::validate($input, [
            'depth_cm' => 'required|decimal:1|min:0|max:9999',
            'volume_l' => 'required|decimal:2|min:0|max:9999999',
        ]);
        if (CalibrationCsv::scaled($data['volume_l'], 2) > CalibrationCsv::scaled((string) $tank['capacity_l'], 2)) {
            throw Validator::fail('volume_l', 'volume_above_capacity', ['capacity' => (string) $tank['capacity_l']]);
        }
        if (!$this->calibrations->keepsIncreasing($tankId, $data['depth_cm'], $data['volume_l'])) {
            throw Validator::fail('volume_l', 'calibration_not_increasing');
        }
        $previous = $this->calibrations->volumeAt($tankId, $data['depth_cm']);

        $this->db->transaction(function () use ($tank, $data, $previous): void {
            $this->calibrations->upsert($tank['station_id'], $tank['id'], $data['depth_cm'], $data['volume_l'], $this->userId());
            $this->logger->log(
                $previous === null ? 'create' : 'update',
                'tank_calibration',
                $tank['id'],
                $tank['station_id'],
                $previous === null ? null : ['depth_cm' => $data['depth_cm'], 'volume_l' => $previous],
                $data,
                $tank['code'] . ' — ' . $data['depth_cm'] . ' cm'
            );
        });

        return $tank;
    }

    /**
     * Import CSV : remplace tout le barème de la cuve. Le fichier est accepté en entier ou refusé en entier.
     *
     * @param array{tmp_name?: string, size?: int, error?: int}|null $file fichier envoyé
     * @return int nombre de points importés
     */
    public function importCalibrations(string $tankId, ?array $file): int
    {
        $tank = $this->tanks->find($tankId) ?? throw HttpException::notFound();
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
            throw Validator::fail('file', 'required');
        }
        if ((int) filesize($file['tmp_name']) > CalibrationCsv::MAX_BYTES) {
            throw Validator::fail('file', 'file_too_large');
        }
        $parsed = CalibrationCsv::parse((string) file_get_contents($file['tmp_name']), (string) $tank['capacity_l']);
        if ($parsed['errors'] !== []) {
            $messages = [];
            foreach (array_slice($parsed['errors'], 0, 10) as $i => $error) {
                $messages['file_' . $i] = t('calibrations.csv_line', ['line' => $error['line']]) . ' ' . t('calibrations.' . $error['error'], ['capacity' => (string) $tank['capacity_l']]);
            }
            if (count($parsed['errors']) > 10) {
                $messages['file_more'] = t('calibrations.csv_more', ['count' => count($parsed['errors']) - 10]);
            }
            throw new ValidationException($messages);
        }

        $before = $this->calibrations->summary($tank['station_id'], $tankId);
        $this->db->transaction(function () use ($tank, $parsed, $before): void {
            $this->calibrations->replaceAll($tank['station_id'], $tank['id'], $parsed['rows'], $this->userId());
            $last = $parsed['rows'][count($parsed['rows']) - 1];
            $this->logger->log(
                'import',
                'tank_calibration',
                $tank['id'],
                $tank['station_id'],
                ['points' => (int) $before['points'], 'max_depth_cm' => $before['max_depth_cm'], 'max_volume_l' => $before['max_volume_l']],
                ['points' => count($parsed['rows']), 'max_depth_cm' => $last['depth_cm'], 'max_volume_l' => $last['volume_l']],
                $tank['code']
            );
        });

        return count($parsed['rows']);
    }

    // ---------------------------------------------------------------------------------------

    private function station(string $stationId): array
    {
        return $this->stations->find($stationId) ?? throw HttpException::notFound();
    }

    private static function code(string $code): string
    {
        $code = mb_strtoupper($code);
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/', $code)) {
            throw Validator::fail('code', 'code_format');
        }

        return $code;
    }

    private function userId(): string
    {
        return $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');
    }
}
