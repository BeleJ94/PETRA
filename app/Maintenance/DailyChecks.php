<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Maintenance;

use DateTimeImmutable;
use Petra\Core\Database;
use Petra\Core\Uuid;
use Petra\Domain\Decimal;
use Petra\Domain\StationSettings;
use Petra\Repositories\ReportRepository;
use Petra\Security\StationContext;
use Petra\Sync\SyncConfig;

/**
 * Contrôles quotidiens (bin/daily-checks.php, tâche planifiée) : ils lèvent une alerte pour
 *  - un poste resté ouvert au-delà du délai de la station ;
 *  - une cuve dont le stock théorique est passé sous le seuil de stock bas ;
 *  - une station qui n'a pas synchronisé depuis le délai de la station (24 h par défaut).
 *
 * Sur un serveur de station : les trois contrôles, pour ses propres données. Sur le central : la
 * synchronisation de chaque station seulement — postes et stocks sont surveillés par la station qui les
 * possède, et ses alertes arrivent au central par synchronisation.
 *
 * Une alerte n'est pas levée deux fois : tant que la précédente n'est pas traitée, elle suffit.
 */
final class DailyChecks
{
    public function __construct(private readonly Database $db, private readonly SyncConfig $sync)
    {
    }

    /** @return list<array{type: string, station: string, message: string}> alertes levées par cette passe */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $raised = [];
        foreach ($this->stations() as $station) {
            $settings = StationSettings::delays($this->settings($station['id'])) + ['low_stock_l' => StationSettings::thresholds($this->settings($station['id']))['low_stock_l']];
            if (!$this->sync->isCentral()) {
                array_push($raised, ...$this->openShifts($station, (int) $settings['shift_max_open_hours'], $now));
                array_push($raised, ...$this->lowStock($station, (string) $settings['low_stock_l'], $now));
            }
            array_push($raised, ...$this->staleSync($station, (int) $settings['sync_max_delay_hours'], $now));
        }

        return $raised;
    }

    /** Stations surveillées : celle de ce serveur s'il est relié à un central, sinon toutes les stations actives. */
    private function stations(): array
    {
        $own = !$this->sync->isCentral() && Uuid::isValid($this->sync->stationId);

        return $this->db->fetchAll(
            'SELECT id, organization_id, code, name, last_sync_at, sync_token_hash IS NOT NULL AS has_token FROM stations WHERE is_active = 1'
            . ($own ? ' AND id = :id' : '') . ' ORDER BY name',
            $own ? ['id' => $this->sync->stationId] : []
        );
    }

    /** @return array<string, string> */
    private function settings(string $stationId): array
    {
        return array_column($this->db->fetchAll('SELECT setting_key, setting_value FROM station_settings WHERE station_id = :s', ['s' => $stationId]), 'setting_value', 'setting_key');
    }

    private function openShifts(array $station, int $hours, DateTimeImmutable $now): array
    {
        $raised = [];
        foreach ($this->db->fetchAll(
            "SELECT sh.id, sh.attendant_id, sh.opened_at, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS attendant
               FROM shifts sh JOIN users u ON u.id = sh.attendant_id
              WHERE sh.station_id = :s AND sh.status = 'open' AND sh.opened_at <= :limit",
            ['s' => $station['id'], 'limit' => $now->modify("-$hours hours")->format('Y-m-d H:i:s')]
        ) as $shift) {
            $message = t('checks.shift_open', ['attendant' => $shift['attendant'], 'opened' => format_date($shift['opened_at'], true), 'hours' => $hours]);
            $raised[] = $this->raise($station, 'shift_not_closed', 'warning', 'shift', $shift['id'], $message, ['shift_id' => $shift['id'], 'user_id' => $shift['attendant_id'], 'threshold_value' => "$hours h"]);
        }

        return array_values(array_filter($raised));
    }

    /** Stock théorique à cet instant (dernier jaugeage + livraisons − litres débités + retours), comparé au seuil de la station. */
    private function lowStock(array $station, string $threshold, DateTimeImmutable $now): array
    {
        // Lecture par le dépôt commun, restreint à cette station.
        $repository = new ReportRepository($this->db, StationContext::for((string) $station['organization_id'], [$station]));
        $limit = Decimal::scaled($threshold, 2) ?? 0;
        $raised = [];
        foreach ($repository->stockAt([(string) $station['id']], $now->format('Y-m-d H:i:s')) as $tank) {
            $volume = $tank['theoretical_l'] === null ? null : Decimal::signed((string) $tank['theoretical_l'], 2);
            if ($volume === null || $volume > $limit) {
                continue;
            }
            $message = t('checks.low_stock', ['tank' => $tank['code'], 'product' => $tank['product'], 'volume' => format_number((string) $tank['theoretical_l'], 2), 'threshold' => format_number($threshold, 2)]);
            $raised[] = $this->raise($station, 'low_stock', $volume <= 0 ? 'critical' : 'warning', 'tank', (string) $tank['id'], $message, ['volume_l' => (string) $tank['theoretical_l'], 'threshold_value' => $threshold]);
        }

        return array_values(array_filter($raised));
    }

    private function staleSync(array $station, int $hours, DateTimeImmutable $now): array
    {
        if ($this->sync->isCentral()) {
            // Le central ne juge que les stations reliées (jeton généré).
            if (!(bool) $station['has_token']) {
                return [];
            }
            $last = $station['last_sync_at'];
        } elseif ($this->sync->isSyncedStation()) {
            $last = $this->db->fetchValue("SELECT MAX(finished_at) FROM sync_log WHERE station_id = :s AND direction = 'push' AND status = 'success'", ['s' => $station['id']]);
        } else {
            return [];
        }
        if ($last !== null && (string) $last > $now->modify("-$hours hours")->format('Y-m-d H:i:s')) {
            return [];
        }
        $message = $last === null
            ? t('checks.sync_never', ['station' => $station['name']])
            : t('checks.sync_stale', ['station' => $station['name'], 'last' => format_date((string) $last, true), 'hours' => $hours]);

        return array_values(array_filter([$this->raise($station, 'sync_stale', 'critical', 'station', (string) $station['id'], $message, ['threshold_value' => "$hours h"])]));
    }

    /** @return array{type: string, station: string, message: string}|null null si une alerte non traitée existe déjà */
    private function raise(array $station, string $type, string $severity, string $entityType, string $entityId, string $message, array $context = []): ?array
    {
        if ($this->db->fetchValue(
            "SELECT 1 FROM alerts WHERE station_id = :s AND alert_type = :t AND entity_type = :et AND entity_id = :id AND status <> 'resolved' LIMIT 1",
            ['s' => $station['id'], 't' => $type, 'et' => $entityType, 'id' => $entityId]
        )) {
            return null;
        }
        $this->db->execute(
            'INSERT INTO alerts (id, station_id, shift_id, user_id, alert_type, severity, entity_type, entity_id, message, volume_l, threshold_value)
             VALUES (:id, :s, :shift, :user, :type, :severity, :et, :eid, :message, :volume, :threshold)',
            [
                'id' => Uuid::v7(), 's' => $station['id'], 'shift' => $context['shift_id'] ?? null, 'user' => $context['user_id'] ?? null, 'type' => $type, 'severity' => $severity,
                'et' => $entityType, 'eid' => $entityId, 'message' => mb_substr($message, 0, 255), 'volume' => $context['volume_l'] ?? null, 'threshold' => $context['threshold_value'] ?? null,
            ]
        );

        return ['type' => $type, 'station' => (string) $station['name'], 'message' => $message];
    }
}
