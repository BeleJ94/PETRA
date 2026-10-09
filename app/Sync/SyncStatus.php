<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

use Petra\Core\Database;

/** État de la synchronisation d'une station, pour l'indicateur de l'en-tête et l'écran de suivi. */
final class SyncStatus
{
    public function __construct(private readonly Database $db, private readonly SyncConfig $config)
    {
    }

    /**
     * @return array{state: 'offline'|'pending'|'synced', pending: int, conflicts: int, last_at: ?string, last_error: ?string}|null
     *   null : cette installation ne synchronise pas (central ou station autonome)
     */
    public function summary(): ?array
    {
        if (!$this->config->isSyncedStation()) {
            return null;
        }
        $station = ['s' => $this->config->stationId];
        $pending = (int) $this->db->fetchValue("SELECT COUNT(*) FROM sync_queue WHERE station_id = :s AND status IN ('pending', 'failed')", $station);
        $conflicts = (int) $this->db->fetchValue("SELECT COUNT(*) FROM sync_queue WHERE station_id = :s AND status = 'conflict'", $station);
        $last = $this->db->fetchOne("SELECT status, finished_at, started_at, error_message FROM sync_log WHERE station_id = :s AND status <> 'running' ORDER BY started_at DESC, id DESC LIMIT 1", $station);
        $lastSuccess = $this->db->fetchValue("SELECT MAX(finished_at) FROM sync_log WHERE station_id = :s AND status = 'success'", $station);

        // Hors ligne : la dernière passe n'a pas joint le central. Sinon en attente tant que la file n'est pas vide.
        $state = $last !== null && $last['status'] === 'offline' ? 'offline' : ($pending > 0 || $last === null ? 'pending' : 'synced');

        return [
            'state' => $state,
            'pending' => $pending,
            'conflicts' => $conflicts,
            'last_at' => $lastSuccess === null ? null : (string) $lastSuccess,
            'last_error' => $last !== null && $last['status'] !== 'success' ? (string) $last['error_message'] : null,
        ];
    }

    /** @return list<array<string, mixed>> dernières passes, les plus récentes d'abord */
    public function history(int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, s.name AS station FROM sync_log l JOIN stations s ON s.id = l.station_id'
            . ($this->config->isSyncedStation() ? ' WHERE l.station_id = :s' : '')
            . ' ORDER BY l.started_at DESC, l.id DESC LIMIT ' . max(1, min(200, $limit)),
            $this->config->isSyncedStation() ? ['s' => $this->config->stationId] : []
        );
    }
}
