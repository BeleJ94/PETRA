<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Sync\SyncConfig;
use Petra\Sync\SyncStatus;

/** Suivi de la synchronisation : état, dernières passes, conflits. */
final class SyncController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly SyncStatus $status, private readonly SyncConfig $config)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        // Station : ses propres passes. Central : celles des stations de l'organisation du propriétaire.
        $allowed = $this->ctx->auth->stations()->stationIds();

        return $this->render('sync/index', [
            'title' => t('nav.sync'),
            'summary' => $this->status->summary(),
            'history' => array_values(array_filter($this->status->history(), static fn (array $row): bool => in_array($row['station_id'], $allowed, true))),
            'central' => $this->config->isCentral(),
        ]);
    }
}
