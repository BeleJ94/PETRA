<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

use Petra\Core\Uuid;

/**
 * Rôle de cette installation dans la synchronisation.
 * - central : reçoit les stations, décide des comptes, des prix et des taux ; lecture seule sur leurs opérations ;
 * - station synchronisée : STATION_ID, SYNC_URL et SYNC_TOKEN renseignés ;
 * - station autonome : aucune synchronisation, tout se gère sur place.
 */
final class SyncConfig
{
    /** Tables poussées vers le central : true = ajout seul (jamais réécrites), false = état modifiable de la station. */
    public const UP = [
        'products' => false, 'tanks' => false, 'tank_calibrations' => false, 'pumps' => false, 'nozzles' => false, 'station_settings' => false,
        'suppliers' => false,
        'prices' => true, 'exchange_rates' => true,
        'shifts' => false, 'shift_segments' => true, 'meter_readings' => true, 'cash_handovers' => true, 'non_sale_outputs' => true,
        'credit_sales' => true, 'cash_expenses' => true, 'variance_justifications' => true, 'tank_dips' => true, 'deliveries' => true,
        'alerts' => false, 'audit_log' => true,
    ];

    /** Statuts qui ne reculent jamais : un état plus ancien reçu après coup est un conflit, pas une mise à jour. */
    public const FORWARD_ONLY = [
        'shifts' => ['open', 'closed', 'validated'],
        'alerts' => ['open', 'acknowledged', 'resolved'],
    ];

    public function __construct(
        public readonly string $mode = 'station',
        public readonly string $stationId = '',
        public readonly string $url = '',
        public readonly string $token = '',
    ) {
    }

    /** @param array{mode: string, station_id: string, sync: array{url: string, token: string}} $config */
    public static function fromConfig(array $config): self
    {
        return new self((string) $config['mode'], (string) $config['station_id'], rtrim((string) $config['sync']['url'], '/'), (string) $config['sync']['token']);
    }

    public function isCentral(): bool
    {
        return $this->mode === 'central';
    }

    /** Station reliée à un central : ce que le central décide (comptes, droits, fiche de station) ne se modifie plus ici. */
    public function isSyncedStation(): bool
    {
        return $this->mode === 'station' && Uuid::isValid($this->stationId) && $this->url !== '' && $this->token !== '';
    }

    /**
     * Le central doit être joint en HTTPS ; HTTP n'est toléré que vers la machine locale (essais).
     */
    public function urlIsAcceptable(): bool
    {
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
