<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Security\Auth;
use Petra\Services\AuditLogger;

final class AuthController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly AuditLogger $logger)
    {
        parent::__construct($ctx);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->ctx->auth->check()) {
            return $this->redirect('/');
        }

        return $this->render('auth/login', ['title' => t('auth.title'), 'username' => ''], 200, 'layouts/minimal');
    }

    public function login(Request $request): Response
    {
        $username = (string) $request->string('username');
        $password = $request->body['password'] ?? '';
        $result = $this->ctx->auth->attempt($username, is_string($password) ? $password : '');
        if (!$result['ok']) {
            if (isset($result['userId'])) {
                $this->logger->log(!empty($result['locked']) ? 'login_locked' : 'login_failed', 'user', $result['userId'], null, null, null, $username, $result['userId']);
            }

            return $this->render('auth/login', ['title' => t('auth.title'), 'username' => $username, 'error' => t($result['error'])], 422, 'layouts/minimal');
        }
        $user = $result['user'];
        $this->logger->log('login', 'user', $user->id, null, null, null, $user->username);

        return $this->redirect('/');
    }

    public function logout(Request $request): Response
    {
        $user = $this->ctx->auth->user();
        if ($user !== null) {
            $this->logger->log('logout', 'user', $user->id, null, null, null, $user->username);
        }
        $this->ctx->auth->logout();

        return $this->redirect('/login');
    }

    /**
     * Station active (sélecteur de l'en-tête). Vide = toutes les stations attribuées.
     * Une station non attribuée est refusée (403, journalisé, alerte).
     */
    public function switchStation(Request $request): Response
    {
        $stationId = $request->string('station_id');
        if ($stationId === null) {
            $this->ctx->session->remove(Auth::SESSION_STATION);
        } else {
            $this->ctx->auth->stations()->assertAllows($stationId);
            $this->ctx->session->set(Auth::SESSION_STATION, $stationId);
        }

        return Response::redirect(url($this->back($request)));
    }
}
