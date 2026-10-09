<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\DataTableRequest;
use Petra\Core\HttpException;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\AuditLogRepository;
use Petra\Services\AuditLogger;

/** Centre d'alertes : par station ou consolidé, statuts ouverte / vue / traitée. */
final class AlertController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly AlertRepository $alerts,
        private readonly AuditLogRepository $audit,
        private readonly AuditLogger $logger,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $context = $this->ctx->auth->stations();

        return $this->render('alerts/index', [
            'title' => t('nav.alerts'),
            'pending' => $this->alerts->listRecent(['open', 'acknowledged'], 50),
            // Vue consolidée : une carte par station, quand plusieurs stations sont affichées ensemble.
            'stations' => $context->isMultiStation() && $context->activeId() === null ? $this->alerts->countsByStation() : [],
            'canResolve' => $this->user()->can('alerts.resolve'),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, AlertRepository::SORTABLE, ['a.created_at', 'DESC']);

        return $this->json($this->alerts->datatable($dt, [
            'status' => $request->string('status'),
            'severity' => $request->string('severity'),
            'alert_type' => $request->string('alert_type'),
        ]));
    }

    public function show(Request $request, array $params): Response
    {
        $alert = $this->alerts->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('alerts/show', [
            'title' => t('alerts.sheet'),
            'alert' => $alert,
            'history' => $this->audit->forEntity('alert', $alert['id'], $alert['station_id']),
            'canResolve' => $this->user()->can('alerts.resolve'),
        ]);
    }

    public function acknowledge(Request $request, array $params): Response
    {
        $alert = $this->alerts->find($this->id($params)) ?? throw HttpException::notFound();
        if ($this->alerts->acknowledge($alert['id'], $this->user()->id)) {
            $this->logger->log('acknowledge', 'alert', $alert['id'], $alert['station_id'], ['status' => $alert['status']], ['status' => 'acknowledged'], $alert['message']);
        }

        return $this->redirect($this->back($request) === '/' ? '/alerts' : $this->back($request), t('alerts.acknowledged'));
    }

    public function resolve(Request $request, array $params): Response
    {
        $alert = $this->alerts->find($this->id($params)) ?? throw HttpException::notFound();
        $note = $request->string('resolution_note');
        if ($this->alerts->resolve($alert['id'], $this->user()->id, $note === null ? null : mb_substr($note, 0, 255))) {
            $this->logger->log('resolve', 'alert', $alert['id'], $alert['station_id'], ['status' => $alert['status']], ['status' => 'resolved', 'resolution_note' => $note], $alert['message']);
        }

        return $this->redirect('/alerts', t('alerts.resolved'));
    }
}
