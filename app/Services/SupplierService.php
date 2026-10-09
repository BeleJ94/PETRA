<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\Validator;
use Petra\Repositories\SupplierRepository;
use Petra\Security\Auth;

/**
 * Répertoire des fournisseurs d'une station. Une fiche ne se supprime pas : elle se désactive, et ne peut
 * alors plus être choisie à la réception d'une livraison. Tout est journalisé.
 */
final class SupplierService
{
    private const RULES = [
        'name' => 'required|string|max:120',
        'contact_name' => 'string|max:120',
        'phone' => 'string|max:40',
        'email' => 'email|max:160',
        'address' => 'string|max:255',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly SupplierRepository $suppliers,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(string $stationId, array $input): string
    {
        $data = self::clean(Validator::validate($input, self::RULES));
        if ($this->suppliers->nameExists($stationId, $data['name'])) {
            throw Validator::fail('name', 'unique');
        }
        $userId = $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');

        return $this->db->transaction(function () use ($stationId, $data, $userId): string {
            $id = $this->suppliers->create($stationId, $data, $userId);
            $this->logger->log('create', 'supplier', $id, $stationId, null, $data, $data['name']);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> la fiche avant modification
     */
    public function update(string $supplierId, array $input): array
    {
        $supplier = $this->suppliers->find($supplierId) ?? throw HttpException::notFound();
        $data = self::clean(Validator::validate($input, self::RULES + ['is_active' => 'bool']));
        if ($this->suppliers->nameExists($supplier['station_id'], $data['name'], $supplierId)) {
            throw Validator::fail('name', 'unique');
        }
        $this->db->transaction(function () use ($supplier, $data): void {
            $this->suppliers->update($supplier['id'], $supplier['station_id'], $data);
            $this->logger->log('update', 'supplier', $supplier['id'], $supplier['station_id'], $supplier, ['is_active' => $data['is_active'] ? 1 : 0] + $data, $data['name']);
        });

        return $supplier;
    }

    /** Espaces superflus retirés : « SEP  Congo » et « SEP Congo » sont la même fiche. */
    private static function clean(array $data): array
    {
        $data['name'] = trim((string) preg_replace('/\s+/u', ' ', (string) $data['name']));
        if ($data['name'] === '') {
            throw Validator::fail('name', 'required');
        }

        return $data;
    }
}
