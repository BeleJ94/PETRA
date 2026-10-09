<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Database;

/**
 * Référentiel des droits (rôles, permissions) : commun à toutes les stations, sans station_id.
 */
final class RoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{id: string, code: string, name: string}> */
    public function roles(): array
    {
        return $this->db->fetchAll("SELECT id, code, name FROM roles ORDER BY FIELD(code, 'owner', 'manager', 'attendant'), name");
    }

    public function findRole(string $id): ?array
    {
        return $this->db->fetchOne('SELECT id, code, name FROM roles WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array{id: string, code: string, description: string}> */
    public function permissions(): array
    {
        return $this->db->fetchAll('SELECT id, code, description FROM permissions ORDER BY code');
    }

    /** @return array<string, list<string>> identifiant de rôle => codes de permission */
    public function matrix(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.code') as $row) {
            $out[$row['role_id']][] = $row['code'];
        }

        return $out;
    }

    /**
     * Remplace les permissions d'un rôle. Les identifiants des lignes suivent la même règle que la
     * migration 0010 : ils restent identiques d'une installation à l'autre.
     *
     * @param list<string> $codes
     */
    public function setPermissions(string $roleId, array $codes, ?string $userId): void
    {
        $this->db->transaction(function () use ($roleId, $codes, $userId): void {
            $this->db->execute('DELETE FROM role_permissions WHERE role_id = :role', ['role' => $roleId]);
            foreach (array_unique($codes) as $code) {
                $this->db->execute(
                    "INSERT INTO role_permissions (id, role_id, permission_id, created_by)
                     SELECT CONCAT('00000000-0000-4000-8001-', RIGHT(:role_a, 6), RIGHT(p.id, 6)), :role_b, p.id, :user
                       FROM permissions p WHERE p.code = :code",
                    ['role_a' => $roleId, 'role_b' => $roleId, 'user' => $userId, 'code' => $code]
                );
            }
        });
    }
}
