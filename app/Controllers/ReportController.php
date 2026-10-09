<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Services\ReportService;

/** Rapports par poste, journalier et mensuel : la page affichée est aussi la source des exports. */
final class ReportController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly ReportService $reports)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('reports/index', [
            'title' => t('nav.reports'),
            'report' => $this->reports->build($request->query),
            'pageScripts' => ['js/reports.js'],
        ]);
    }
}
