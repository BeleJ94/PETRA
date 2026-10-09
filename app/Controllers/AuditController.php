<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\DataTableRequest;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Core\Validator;
use Petra\Repositories\AuditLogRepository;
use Petra\Services\AuditLogger;

/** Journal d'audit : consultation seule. */
final class AuditController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly AuditLogRepository $audit)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('audit/index', [
            'title' => t('nav.audit'),
            'actions' => AuditLogger::ACTIONS,
            'entities' => AuditLogger::ENTITIES,
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, AuditLogRepository::SORTABLE, ['a.created_at', 'DESC']);
        $filters = Validator::validate($request->query, ['action' => 'string|max:40', 'entity_type' => 'string|max:40', 'from' => 'date', 'to' => 'date']);

        return $this->json($this->audit->datatable($dt, $filters));
    }
}
