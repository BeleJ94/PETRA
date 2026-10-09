<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\HttpException;
use Petra\Core\Validator;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Security\Auth;
use Petra\Security\CurrentUser;

/**
 * Accès aux postes. Un pompiste ne voit que ses propres postes ; qui ouvre ou valide les postes
 * voit tous ceux de ses stations.
 */
final class ShiftAccess
{
    public const DENIED_ALERT = 'modification_denied';

    /** Une rafale de tentatives du même utilisateur ne crée qu'une alerte ; le journal, lui, garde tout. */
    private const ALERT_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly Auth $auth,
        private readonly AuditLogger $logger,
        private readonly AlertRepository $alerts,
    ) {
    }

    /**
     * Tentative d'écrire dans un poste qui ne se modifie plus (clôturé pour les relevés, validé pour la caisse) :
     * elle échoue, est inscrite au journal d'audit et signalée au gérant, rattachée à la station, au poste et à son auteur.
     *
     * @param string $rule clé « validation.xxx » du message rendu à l'utilisateur
     */
    public function denyLocked(array $shift, string $rule): never
    {
        $user = $this->user();
        $stationId = (string) $shift['station_id'];
        $summary = t('shifts.locked_summary', ['user' => $user->name(), 'attendant' => (string) ($shift['attendant'] ?? ''), 'status' => label('shift_status', (string) $shift['status'])]);
        $this->logger->log('modification_denied', 'shift', (string) $shift['id'], $stationId, null, ['status' => $shift['status'], 'reason' => $rule, 'role' => $user->roleCode], $summary);
        // Sur le central, les données des stations sont en lecture seule : le journal suffit.
        if (!$this->auth->stations()->isReadOnly() && !$this->alerts->hasRecentOpen($stationId, self::DENIED_ALERT, $user->id, self::ALERT_WINDOW_MINUTES)) {
            $this->alerts->raise($stationId, self::DENIED_ALERT, 'warning', $summary, ['user_id' => $user->id, 'shift_id' => (string) $shift['id'], 'entity_type' => 'shift', 'entity_id' => (string) $shift['id']]);
        }

        throw Validator::fail('shift', $rule);
    }

    public function user(): CurrentUser
    {
        return $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
    }

    public function ownShiftsOnly(): bool
    {
        $user = $this->user();

        return !$user->can('shifts.open') && !$user->can('shifts.validate');
    }

    /** @throws HttpException 404 si le poste n'existe pas, 403 s'il n'est pas accessible à l'utilisateur */
    public function find(string $shiftId): array
    {
        $shift = $this->shifts->find($shiftId) ?? throw HttpException::notFound();
        if ($this->ownShiftsOnly() && $shift['attendant_id'] !== $this->user()->id) {
            throw HttpException::forbidden();
        }

        return $shift;
    }

    /** Le poste est-il tenu par l'utilisateur courant, ou celui-ci peut-il clôturer les postes ? */
    public function canOperate(array $shift): bool
    {
        $user = $this->user();

        return $shift['attendant_id'] === $user->id || $user->can('shifts.close');
    }
}
