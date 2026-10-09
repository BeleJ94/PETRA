<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Repositories\UserRepository;
use Petra\Security\PasswordHasher;
use Petra\Services\UserService;

final class UserController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly UserRepository $users,
        private readonly UserService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('users/index', [
            'title' => t('nav.users'),
            'users' => $this->users->listAll(),
            'minLength' => PasswordHasher::MIN_LENGTH,
        ]);
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $user = $this->service->resetPassword($this->id($params), [
            'password' => $request->body['password'] ?? null,
            'password_confirmation' => $request->body['password_confirmation'] ?? null,
        ]);

        return $this->redirect('/users', t('users.password_reset_done', ['user' => $user['username']]));
    }
}
