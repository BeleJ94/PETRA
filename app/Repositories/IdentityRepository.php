<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Database;

/**
 * Accès aux comptes pour l'authentification uniquement. Intervient AVANT que les stations
 * autorisées ne soient connues : ne manipule aucune donnée métier rattachée à une station.
 */
final class IdentityRepository
{
    private const SELECT = 'SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** Compte désigné par son identifiant ou, à défaut, par son adresse e-mail. */
    public function findByUsername(string $login): ?array
    {
        $login = mb_strtolower($login);

        return $this->db->fetchOne(self::SELECT . ' WHERE u.username = :username', ['username' => $login])
            ?? $this->db->fetchOne(self::SELECT . ' WHERE u.email = :email', ['email' => $login]);
    }

    public function findActive(string $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE u.id = :id AND u.is_active = 1', ['id' => $id]);
    }

    /** @return list<string> codes des permissions du rôle */
    public function permissions(string $roleId): array
    {
        return array_column($this->db->fetchAll(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :role ORDER BY p.code',
            ['role' => $roleId]
        ), 'code');
    }

    /**
     * Stations attribuées. Propriétaire ($allStations) : toutes celles de l'organisation, désactivées
     * comprises (il garde l'historique et peut les réactiver). Sinon : stations actives de user_stations.
     *
     * @return list<array{id: string, code: string, name: string, is_active: int}>
     */
    public function stations(string $userId, string $organizationId, bool $allStations): array
    {
        if ($allStations) {
            return $this->db->fetchAll(
                'SELECT id, code, name, is_active FROM stations WHERE organization_id = :org ORDER BY is_active DESC, name',
                ['org' => $organizationId]
            );
        }

        return $this->db->fetchAll(
            'SELECT s.id, s.code, s.name, s.is_active FROM user_stations us JOIN stations s ON s.id = us.station_id
              WHERE us.user_id = :user AND s.organization_id = :org AND s.is_active = 1 ORDER BY s.name',
            ['user' => $userId, 'org' => $organizationId]
        );
    }

    public function recordSuccess(string $id): void
    {
        $this->db->execute(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    /** @return bool vrai si cet échec vient de verrouiller le compte */
    public function recordFailure(string $id, int $maxAttempts, int $lockMinutes): bool
    {
        $this->db->execute(
            'UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until = IF(failed_logins >= :max, NOW() + INTERVAL :minutes MINUTE, locked_until)
              WHERE id = :id',
            ['id' => $id, 'max' => $maxAttempts, 'minutes' => $lockMinutes]
        );

        return (int) $this->db->fetchValue('SELECT locked_until IS NOT NULL AND locked_until > NOW() FROM users WHERE id = :id', ['id' => $id]) === 1;
    }

    public function updatePasswordHash(string $id, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = :hash WHERE id = :id', ['hash' => $hash, 'id' => $id]);
    }
}
