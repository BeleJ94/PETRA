<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\HttpException;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Maintenance\BackupTransfer;
use Petra\Sync\SyncConfig;
use Petra\Sync\SyncReceiver;

/**
 * API de synchronisation du serveur central. Pas de session ni de jeton CSRF : chaque requête est
 * authentifiée par le jeton de la station (en-tête Authorization) et ne porte que sur cette station.
 */
final class SyncApiController
{
    public function __construct(private readonly SyncReceiver $receiver, private readonly SyncConfig $config, private readonly BackupTransfer $backups)
    {
    }

    public function push(Request $request): Response
    {
        $station = $this->station($request);
        $batch = $request->body['batch'] ?? null;
        if (!is_array($batch)) {
            throw new HttpException(400, 'errors.sync_bad_request');
        }

        return Response::json($this->receiver->push($station['id'], array_values($batch)));
    }

    public function pull(Request $request): Response
    {
        return Response::json($this->receiver->pull($this->station($request)['id']));
    }

    /** Copie d'une sauvegarde de la station, envoyée par morceaux par bin/backup.php. */
    public function backup(Request $request): Response
    {
        $station = $this->station($request);
        $body = $request->body;

        return Response::json($this->backups->receive($station['id'], $body['name'] ?? null, $body['index'] ?? null, $body['data'] ?? null, ($body['last'] ?? false) === true, $body['sha256'] ?? null));
    }

    /** Station du jeton ; le station_id annoncé par la requête doit être le sien. */
    private function station(Request $request): array
    {
        // Cette API n'existe que sur le serveur central.
        if (!$this->config->isCentral()) {
            throw HttpException::notFound();
        }
        $authorization = (string) $request->header('authorization');
        $token = str_starts_with($authorization, 'Bearer ') ? trim(substr($authorization, 7)) : '';
        $stationId = (string) ($request->header('x-station-id') ?? '');
        $station = $this->receiver->authenticate($stationId, $token);
        if (($request->body['station_id'] ?? $stationId) !== $station['id']) {
            throw new HttpException(401, 'errors.sync_unauthorized');
        }

        return $station;
    }
}
