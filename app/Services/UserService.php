<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\HttpException;
use Petra\Core\Validator;
use Petra\Repositories\UserRepository;
use Petra\Security\PasswordHasher;
use Petra\Sync\SyncConfig;

final class UserService
{
    public function __construct(private readonly UserRepository $users, private readonly AuditLogger $logger, private readonly SyncConfig $sync)
    {
    }

    /**
     * Réinitialisation par le propriétaire : nouveau mot de passe, compte déverrouillé,
     * sessions ouvertes de l'utilisateur fermées (l'empreinte du mot de passe change).
     *
     * @param array{password?: mixed, password_confirmation?: mixed} $input
     */
    public function resetPassword(string $userId, array $input): array
    {
        if ($this->sync->isSyncedStation()) {
            throw new HttpException(409, 'errors.central_only');
        }
        $user = $this->users->find($userId) ?? throw HttpException::notFound();
        $data = Validator::validate($input, [
            'password' => 'required|string|min:' . PasswordHasher::MIN_LENGTH . '|max:200',
            'password_confirmation' => 'required|string',
        ]);
        if ($data['password'] !== $data['password_confirmation']) {
            throw Validator::fail('password_confirmation', 'confirmed');
        }

        $this->users->setPassword($userId, PasswordHasher::hash($data['password']));
        $this->logger->log('password_reset', 'user', $userId, null, null, null, $user['username']);

        return $user;
    }
}
