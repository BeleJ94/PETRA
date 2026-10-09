<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Repositories\RoleRepository;
use Petra\Services\RoleService;

/** Écran Propriétaire : permissions accordées à chaque rôle. */
final class RoleController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly RoleRepository $roles,
        private readonly RoleService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('roles/index', [
            'title' => t('nav.roles'),
            'roles' => $this->roles->roles(),
            'permissions' => $this->roles->permissions(),
            'matrix' => $this->roles->matrix(),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $codes = $request->body['permissions'] ?? [];
        $role = $this->service->setPermissions($this->id($params), is_array($codes) ? array_values($codes) : []);

        return $this->redirect('/roles', t('roles.saved', ['role' => $role['name']]));
    }
}
