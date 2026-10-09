<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\HttpException;
use Petra\Repositories\RoleRepository;
use Petra\Security\Auth;
use Petra\Security\CurrentUser;
use Petra\Sync\SyncConfig;

final class RoleService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
        private readonly SyncConfig $sync,
    ) {
    }

    /**
     * Remplace les permissions d'un rôle. Le rôle Propriétaire garde toujours tous les droits :
     * il ne peut pas se retirer l'accès à cet écran.
     *
     * @param list<string> $codes permissions cochées (les codes inconnus sont ignorés)
     */
    public function setPermissions(string $roleId, array $codes): array
    {
        if ($this->sync->isSyncedStation()) {
            throw new HttpException(409, 'errors.central_only');
        }
        $role = $this->roles->findRole($roleId) ?? throw HttpException::notFound();
        if ($role['code'] === CurrentUser::ROLE_OWNER) {
            throw HttpException::forbidden();
        }
        $known = array_column($this->roles->permissions(), 'code');
        $codes = array_values(array_intersect($known, array_filter($codes, 'is_string')));
        $before = $this->roles->matrix()[$roleId] ?? [];

        $this->roles->setPermissions($roleId, $codes, $this->auth->user()?->id);
        $this->logger->log('permissions', 'role', $roleId, null, ['permissions' => $before], ['permissions' => $codes], $role['name']);

        return $role;
    }
}
