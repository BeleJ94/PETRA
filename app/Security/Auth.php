<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Security;

use Petra\Core\Csrf;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Repositories\IdentityRepository;

/**
 * Authentification par session, verrouillage après échecs répétés.
 */
final class Auth
{
    public const SESSION_USER = 'auth_user_id';
    public const SESSION_FINGERPRINT = 'auth_fingerprint';
    public const SESSION_STATION = 'station_context';

    private ?CurrentUser $user = null;
    private bool $resolved = false;

    /**
     * @param string $stationId station de cette installation (APP_MODE=station) : restreint tout accès à elle seule
     */
    public function __construct(
        private readonly Session $session,
        private readonly IdentityRepository $identities,
        private readonly Csrf $csrf,
        private readonly array $security = ['max_login_attempts' => 5, 'lockout_minutes' => 15],
        private readonly string $stationId = '',
        private readonly bool $central = false,
    ) {
    }

    public function user(): ?CurrentUser
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $id = $this->session->get(self::SESSION_USER);
            if (Uuid::isValid($id)) {
                $row = $this->identities->findActive($id);
                // Mot de passe changé depuis l'ouverture de la session : la session n'est plus valable.
                if ($row !== null && hash_equals(PasswordHasher::fingerprint((string) $row['password_hash']), (string) $this->session->get(self::SESSION_FINGERPRINT))) {
                    $this->user = $this->hydrate($row);
                } else {
                    $this->session->clear();
                }
            }
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Stations autorisées et station active (choisie dans l'en-tête, gardée en session). */
    public function stations(): StationContext
    {
        $user = $this->user();
        if ($user === null) {
            return StationContext::none();
        }
        $active = $this->session->get(self::SESSION_STATION);

        return StationContext::for($user->organizationId, $user->stations, is_string($active) ? $active : null, $user->allStations, $this->central);
    }

    /**
     * @return array{ok: bool, error?: string, user?: CurrentUser, userId?: string, locked?: bool}
     *   error = clé i18n (message volontairement générique pour ne pas révéler l'existence du compte)
     */
    public function attempt(string $username, string $password): array
    {
        $row = $this->identities->findByUsername(trim($username));
        if ($row === null) {
            PasswordHasher::burn($password);

            return ['ok' => false, 'error' => 'auth.invalid'];
        }
        $userId = (string) $row['id'];
        if (!(bool) $row['is_active']) {
            return ['ok' => false, 'error' => 'auth.invalid', 'userId' => $userId];
        }
        if ($row['locked_until'] !== null && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'auth.locked', 'userId' => $userId, 'locked' => true];
        }
        if (!PasswordHasher::verify($password, (string) $row['password_hash'])) {
            $locked = $this->identities->recordFailure(
                $userId,
                (int) $this->security['max_login_attempts'],
                (int) $this->security['lockout_minutes']
            );

            return ['ok' => false, 'error' => $locked ? 'auth.locked' : 'auth.invalid', 'userId' => $userId, 'locked' => $locked];
        }

        $hash = (string) $row['password_hash'];
        if (PasswordHasher::needsRehash($hash)) {
            $hash = PasswordHasher::hash($password);
            $this->identities->updatePasswordHash($userId, $hash);
        }
        $this->identities->recordSuccess($userId);
        $this->session->clear();
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER, $userId);
        $this->session->set(self::SESSION_FINGERPRINT, PasswordHasher::fingerprint($hash));
        $this->user = $this->hydrate($row);
        $this->resolved = true;

        return ['ok' => true, 'user' => $this->user];
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->user = null;
        $this->resolved = true;
    }

    private function hydrate(array $row): CurrentUser
    {
        $stations = $this->identities->stations((string) $row['id'], (string) $row['organization_id'], (bool) $row['all_stations']);
        if ($this->stationId !== '') {
            $stations = array_values(array_filter($stations, fn (array $s): bool => $s['id'] === $this->stationId));
        }

        return new CurrentUser(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['username'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['role_code'],
            (string) $row['role_name'],
            (string) $row['locale'],
            $this->identities->permissions((string) $row['role_id']),
            $stations,
            // Une installation de station ne sert qu'elle-même : pas de vue d'organisation.
            (bool) $row['all_stations'] && $this->stationId === '',
        );
    }
}
