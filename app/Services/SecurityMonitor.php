<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\Request;
use Petra\Repositories\AlertRepository;
use Petra\Security\Auth;

/**
 * Accès refusés : chaque refus est écrit au journal d'audit et signalé par une alerte
 * rattachée à la station de l'utilisateur.
 */
final class SecurityMonitor
{
    public const ALERT_TYPE = 'access_denied';

    /** Une rafale de refus du même utilisateur ne crée qu'une alerte ; le journal, lui, garde tout. */
    private const ALERT_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly Auth $auth,
        private readonly AuditLogger $logger,
        private readonly AlertRepository $alerts,
    ) {
    }

    public function accessDenied(Request $request, ?string $permission = null): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            return;
        }
        $context = $this->auth->stations();
        // Station de rattachement : la station active, sinon la première attribuée.
        $stationId = $context->activeId() ?? ($context->stationIds()[0] ?? null);
        $summary = $request->method . ' ' . $request->path . ($permission !== null ? ' — ' . $permission : '');

        $this->logger->log('access_denied', 'route', null, $stationId, null, [
            'method' => $request->method,
            'path' => $request->path,
            'permission' => $permission,
            'role' => $user->roleCode,
        ], $summary);

        if ($stationId !== null && !$this->alerts->hasRecentOpen($stationId, self::ALERT_TYPE, $user->id, self::ALERT_WINDOW_MINUTES)) {
            $this->alerts->raise(
                $stationId,
                self::ALERT_TYPE,
                'warning',
                t('alerts.access_denied_message', ['user' => $user->name(), 'target' => $summary]),
                ['user_id' => $user->id, 'entity_type' => 'user', 'entity_id' => $user->id],
            );
        }
    }
}
