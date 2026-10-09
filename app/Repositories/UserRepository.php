<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\Uuid;

/**
 * Comptes de l'organisation de l'utilisateur courant. Les utilisateurs ne portent pas de station_id :
 * le périmètre est l'organisation, fournie par StationContext.
 */
final class UserRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.is_active, u.all_stations, u.failed_logins,
                    u.last_login_at, (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
                    r.code AS role_code, r.name AS role_name,
                    (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                       FROM user_stations us JOIN stations s ON s.id = us.station_id WHERE us.user_id = u.id) AS stations
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.organization_id = :org
              ORDER BY u.last_name, u.first_name",
            ['org' => (string) $this->stations->organizationId()]
        );
    }

    /**
     * Utilisateurs actifs attribués à la station et autorisés à saisir un poste.
     *
     * @return list<array{id: string, name: string, role_name: string}>
     */
    public function shiftStaff(string $stationId): array
    {
        $this->stations->assertAllows($stationId);

        return $this->db->fetchAll(
            "SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
               JOIN user_stations us ON us.user_id = u.id AND us.station_id = :station
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id AND p.code = 'shifts.record'
              WHERE u.organization_id = :org AND u.is_active = 1
              ORDER BY r.code = 'attendant' DESC, name",
            ['station' => $stationId, 'org' => (string) $this->stations->organizationId()]
        );
    }

    public function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.organization_id = :org',
            ['id' => $id, 'org' => (string) $this->stations->organizationId()]
        );
    }

    /** Nouveau mot de passe : lève aussi le verrouillage. */
    public function setPassword(string $id, string $hash): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = :hash, failed_logins = 0, locked_until = NULL WHERE id = :id AND organization_id = :org',
            ['hash' => $hash, 'id' => $id, 'org' => (string) $this->stations->organizationId()]
        );
    }
}
