<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Services\DashboardService;

final class HomeController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly DashboardService $dashboard)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        // Le tableau de bord montre recettes et écarts : il est réservé à qui en a le droit (gérant, propriétaire).
        if (!$this->user()->can('dashboard.view')) {
            return $this->render('home/index', ['title' => t('nav.home')]);
        }

        return $this->render('home/dashboard', $this->dashboard->build($request->query) + [
            'title' => t('nav.home'),
            'pageScripts' => ['vendor/chart.umd.min.js', 'js/pt-live.js', 'js/dashboard.js'],
        ]);
    }

    /** Détail d'un bloc du tableau de bord, en tableau compact (fragment HTML ouvert en fenêtre). */
    public function detail(Request $request): Response
    {
        return $this->render('home/_detail', $this->dashboard->detail((string) $request->string('kind'), $request->query), 200, null);
    }

    public function about(Request $request): Response
    {
        return $this->render('home/about', [
            'title' => t('nav.about'),
            'phpVersion' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
        ]);
    }
}
