<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Security;

final class CurrentUser
{
    public const ROLE_OWNER = 'owner';

    /**
     * @param list<string> $permissions codes accordés au rôle (table role_permissions)
     * @param list<array{id: string, code: string, name: string, is_active: mixed}> $stations stations attribuées
     * @param bool $allStations toutes les stations de l'organisation (propriétaire)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $username,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $roleCode,
        public readonly string $roleName,
        public readonly string $locale,
        public readonly array $permissions,
        public readonly array $stations,
        public readonly bool $allStations = false,
    ) {
    }

    public function name(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1) . mb_substr($this->lastName, 0, 1));
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isOwner(): bool
    {
        return $this->roleCode === self::ROLE_OWNER;
    }
}
