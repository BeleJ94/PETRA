<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\Request;
use Petra\Repositories\AuditLogRepository;
use Petra\Security\Auth;

/**
 * Journal d'audit : qui (utilisateur), quoi (entité + action + résumé), quand,
 * avant/après (seuls les champs modifiés pour une mise à jour).
 */
final class AuditLogger
{
    /** Actions journalisées : chacune a son libellé « action.xxx ». */
    public const ACTIONS = [
        'login', 'logout', 'login_failed', 'login_locked', 'access_denied', 'modification_denied', 'password_reset', 'permissions',
        'create', 'update', 'activate', 'deactivate', 'import', 'publish', 'open', 'close', 'validate', 'acknowledge', 'resolve',
    ];

    /** Entités journalisées : chacune a son libellé « entity.xxx ». */
    public const ENTITIES = [
        'user', 'role', 'route', 'station', 'station_schedule', 'station_settings', 'product', 'tank', 'pump', 'nozzle', 'tank_calibration',
        'price', 'exchange_rate', 'shift', 'non_sale_output', 'cash_handover', 'credit_sale', 'cash_expense', 'variance_justification',
        'tank_dip', 'delivery', 'supplier', 'alert',
    ];

    /** Champs jamais journalisés. */
    private const REDACTED = ['password', 'password_hash', 'password_confirmation', '_csrf', 'sync_token_hash'];

    /** Champs techniques non significatifs pour un diff. */
    private const IGNORED = ['updated_at', 'created_at'];

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly Auth $auth,
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * @param string|null $actorId auteur à consigner quand il n'est pas (ou pas encore) l'utilisateur de la session
     * @return string|null identifiant de l'entrée, null si aucune modification réelle
     */
    public function log(
        string $action,
        string $entityType,
        ?string $entityId,
        ?string $stationId,
        ?array $before = null,
        ?array $after = null,
        ?string $summary = null,
        ?string $actorId = null,
    ): ?string {
        if ($before !== null && $after !== null) {
            [$before, $after] = self::diff($before, $after);
            if ($before === [] && $after === []) {
                return null;
            }
        }
        $user = $this->auth->user();

        return $this->repository->insert([
            'station_id' => $stationId,
            'user_id' => $user?->id ?? $actorId,
            'user_name' => $user?->name() ?? ($actorId === null ? 'system' : null),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'summary' => $summary,
            'before_data' => self::encode($before),
            'after_data' => self::encode($after),
            'ip' => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent(),
        ]);
    }

    /**
     * Ne conserve que les champs dont la valeur a changé (comparaison en chaîne : "5" == 5).
     * Seuls les champs écrits ($after) sont comparés : une ligne complète peut être passée en « avant ».
     *
     * @return array{0: array, 1: array}
     */
    public static function diff(array $before, array $after): array
    {
        $b = [];
        $a = [];
        foreach (array_keys($after) as $key) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (self::normalize($old) !== self::normalize($new)) {
                // Un champ de contexte absent de l'état initial (motif, commentaire…) n'apparaît qu'en « après ».
                if (array_key_exists($key, $before)) {
                    $b[$key] = $old;
                }
                $a[$key] = $new;
            }
        }

        return [$b, $a];
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value) ?: null;
        }

        return (string) $value;
    }

    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '***';
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
