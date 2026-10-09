<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Controllers\AlertController;
use Petra\Controllers\AuditController;
use Petra\Controllers\AuthController;
use Petra\Controllers\CashController;
use Petra\Controllers\CatalogController;
use Petra\Controllers\DeliveryController;
use Petra\Controllers\HomeController;
use Petra\Controllers\PricingController;
use Petra\Controllers\RoleController;
use Petra\Controllers\ShiftController;
use Petra\Controllers\StationController;
use Petra\Controllers\StockController;
use Petra\Controllers\SupplierController;
use Petra\Controllers\SyncApiController;
use Petra\Controllers\SyncController;
use Petra\Controllers\UserController;
use Petra\Core\Router;

/*
 * Toutes les routes exigent une session, sauf mention 'auth' => false.
 * Toute route POST est protégée par CSRF (App::dispatch).
 * 'permission' : code de la table permissions, accordé ou non au rôle par le propriétaire.
 */
return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], ['auth' => false]);
    $r->post('/login', [AuthController::class, 'login'], ['auth' => false]);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/context/station', [AuthController::class, 'switchStation']);

    $r->get('/', [HomeController::class, 'index']);
    $r->get('/about', [HomeController::class, 'about']);
    $r->get('/dashboard/detail', [HomeController::class, 'detail'], ['permission' => 'dashboard.view']);

    // Rapports (par poste, journalier, mensuel) : consultation, impression, exports PDF et Excel
    $r->get('/reports', [\Petra\Controllers\ReportController::class, 'index'], ['permission' => 'reports.view']);

    // Alertes (écarts, accès refusés)
    $r->get('/alerts', [AlertController::class, 'index'], ['permission' => 'alerts.view']);
    $r->get('/alerts/data', [AlertController::class, 'data'], ['permission' => 'alerts.view']);
    $r->get('/alerts/{id}', [AlertController::class, 'show'], ['permission' => 'alerts.view']);
    $r->post('/alerts/{id}/acknowledge', [AlertController::class, 'acknowledge'], ['permission' => 'alerts.resolve']);
    $r->post('/alerts/{id}/resolve', [AlertController::class, 'resolve'], ['permission' => 'alerts.resolve']);

    // Postes : ouverture, sorties non vendues, clôture des index, contre-validation
    $r->get('/shifts', [ShiftController::class, 'index'], ['permission' => 'shifts.view']);
    $r->get('/shifts/data', [ShiftController::class, 'data'], ['permission' => 'shifts.view']);
    $r->get('/shifts/open', [ShiftController::class, 'openForm'], ['permission' => 'shifts.open']);
    $r->get('/shifts/history', [ShiftController::class, 'history'], ['permission' => 'shifts.view']);
    $r->get('/shifts/form', [ShiftController::class, 'form'], ['permission' => 'shifts.view']);
    $r->get('/shifts/{id}/summary', [ShiftController::class, 'summary'], ['permission' => 'shifts.view']);
    $r->post('/shifts', [ShiftController::class, 'open'], ['permission' => 'shifts.open']);
    $r->get('/shifts/{id}', [ShiftController::class, 'show'], ['permission' => 'shifts.view']);
    $r->post('/shifts/{id}/outputs', [ShiftController::class, 'addOutput'], ['permission' => 'shifts.record']);
    $r->get('/shifts/{id}/close', [ShiftController::class, 'closeForm'], ['permission' => 'shifts.record']);
    $r->post('/shifts/{id}/close', [ShiftController::class, 'close'], ['permission' => 'shifts.record']);
    $r->post('/shifts/{id}/validate', [ShiftController::class, 'validate'], ['permission' => 'shifts.validate']);
    $r->post('/shifts/{id}/settle', [ShiftController::class, 'settle'], ['permission' => 'shifts.settle_alone']);

    // Caisse du poste : remises, ventes à crédit, dépenses, justification de l'écart
    $r->get('/shifts/{id}/cash', [CashController::class, 'show'], ['permission' => 'shifts.view']);
    $r->post('/shifts/{id}/handovers', [CashController::class, 'addHandover'], ['permission' => 'shifts.close']);
    $r->post('/shifts/{id}/credit-sales', [CashController::class, 'addCreditSale'], ['permission' => 'credit.record']);
    $r->post('/shifts/{id}/expenses', [CashController::class, 'addExpense'], ['permission' => 'expenses.record']);
    $r->post('/shifts/{id}/justifications', [CashController::class, 'justify'], ['permission' => 'shifts.validate']);

    // Stock des cuves : situation, jaugeages, écarts
    $r->get('/stock', [StockController::class, 'index'], ['permission' => 'reports.view']);
    $r->get('/stock/dips/data', [StockController::class, 'data'], ['permission' => 'reports.view']);
    $r->post('/stock/tanks/{id}/dips', [StockController::class, 'storeDip'], ['permission' => 'dips.record']);
    $r->get('/stock/tanks/{id}/form', [StockController::class, 'form'], ['permission' => 'dips.record']);
    $r->get('/stock/tanks/{id}/detail', [StockController::class, 'detail'], ['permission' => 'reports.view']);
    $r->get('/stock/tanks/{id}/history', [StockController::class, 'history'], ['permission' => 'reports.view']);

    // Livraisons de carburant : réception, écarts, photo du bon
    $r->get('/deliveries', [DeliveryController::class, 'index'], ['permission' => 'reports.view']);
    $r->get('/deliveries/data', [DeliveryController::class, 'data'], ['permission' => 'reports.view']);
    $r->get('/deliveries/create', [DeliveryController::class, 'create'], ['permission' => 'deliveries.record']);
    $r->post('/deliveries', [DeliveryController::class, 'store'], ['permission' => 'deliveries.record']);
    $r->get('/deliveries/{id}', [DeliveryController::class, 'show'], ['permission' => 'reports.view']);
    $r->get('/deliveries/{id}/photo', [DeliveryController::class, 'photo'], ['permission' => 'reports.view']);

    // Répertoire des fournisseurs : choisis à la réception d'une livraison
    $r->get('/suppliers', [SupplierController::class, 'index'], ['permission' => 'reports.view']);
    $r->get('/suppliers/form', [SupplierController::class, 'form'], ['permission' => 'suppliers.manage']);
    $r->post('/suppliers', [SupplierController::class, 'store'], ['permission' => 'suppliers.manage']);
    $r->get('/suppliers/{id}/form', [SupplierController::class, 'form'], ['permission' => 'suppliers.manage']);
    $r->post('/suppliers/{id}', [SupplierController::class, 'update'], ['permission' => 'suppliers.manage']);

    // Prix par produit et taux USD/CDF (versions)
    $r->get('/prices', [PricingController::class, 'index'], ['permission' => 'prices.view']);
    $r->get('/prices/data', [PricingController::class, 'pricesData'], ['permission' => 'prices.view']);
    $r->get('/rates/data', [PricingController::class, 'ratesData'], ['permission' => 'prices.view']);
    $r->get('/prices/products/{id}/new', [PricingController::class, 'newPrice'], ['permission' => 'prices.manage']);
    $r->post('/prices/products/{id}', [PricingController::class, 'storePrice'], ['permission' => 'prices.manage']);
    $r->get('/rates/new', [PricingController::class, 'newRate'], ['permission' => 'rates.manage']);
    $r->post('/rates', [PricingController::class, 'storeRate'], ['permission' => 'rates.manage']);

    // Stations : fiche, horaires des postes, fuseau, seuils
    $r->get('/stations', [StationController::class, 'index'], ['permission' => 'stations.manage']);
    $r->get('/stations/data', [StationController::class, 'data'], ['permission' => 'stations.manage']);
    $r->get('/stations/create', [StationController::class, 'create'], ['permission' => 'stations.manage']);
    $r->post('/stations', [StationController::class, 'store'], ['permission' => 'stations.manage']);
    $r->get('/stations/{id}', [StationController::class, 'show'], ['permission' => 'stations.manage']);
    $r->get('/stations/{id}/edit', [StationController::class, 'edit'], ['permission' => 'stations.manage']);
    $r->post('/stations/{id}', [StationController::class, 'update'], ['permission' => 'stations.manage']);
    $r->post('/stations/{id}/deactivate', [StationController::class, 'deactivate'], ['permission' => 'stations.manage']);
    $r->post('/stations/{id}/activate', [StationController::class, 'activate'], ['permission' => 'stations.manage']);
    $r->post('/stations/{id}/schedule', [StationController::class, 'saveSchedule'], ['permission' => 'settings.manage']);
    $r->post('/stations/{id}/thresholds', [StationController::class, 'saveThresholds'], ['permission' => 'settings.manage']);

    // Installation d'une station : produits, cuves, pistolets, barèmes de jaugeage
    $r->post('/stations/{id}/products', [CatalogController::class, 'storeProduct'], ['permission' => 'catalog.manage']);
    $r->post('/products/{id}/active', [CatalogController::class, 'toggleProduct'], ['permission' => 'catalog.manage']);
    $r->get('/stations/{id}/tanks/data', [CatalogController::class, 'tanksData'], ['permission' => 'catalog.manage']);
    $r->post('/stations/{id}/tanks', [CatalogController::class, 'storeTank'], ['permission' => 'catalog.manage']);
    $r->get('/tanks/{id}', [CatalogController::class, 'showTank'], ['permission' => 'catalog.manage']);
    $r->post('/tanks/{id}', [CatalogController::class, 'updateTank'], ['permission' => 'catalog.manage']);
    $r->post('/stations/{id}/pumps', [CatalogController::class, 'storePump'], ['permission' => 'catalog.manage']);
    $r->post('/pumps/{id}', [CatalogController::class, 'updatePump'], ['permission' => 'catalog.manage']);
    $r->get('/stations/{id}/nozzles/data', [CatalogController::class, 'nozzlesData'], ['permission' => 'catalog.manage']);
    $r->post('/stations/{id}/nozzles', [CatalogController::class, 'storeNozzle'], ['permission' => 'catalog.manage']);
    $r->get('/nozzles/{id}', [CatalogController::class, 'editNozzle'], ['permission' => 'catalog.manage']);
    $r->post('/nozzles/{id}', [CatalogController::class, 'updateNozzle'], ['permission' => 'catalog.manage']);
    $r->get('/tanks/{id}/calibrations/data', [CatalogController::class, 'calibrationsData'], ['permission' => 'catalog.manage']);
    $r->post('/tanks/{id}/calibrations', [CatalogController::class, 'storeCalibration'], ['permission' => 'catalog.manage']);
    $r->post('/tanks/{id}/calibrations/import', [CatalogController::class, 'importCalibrations'], ['permission' => 'catalog.manage']);

    // Synchronisation : suivi (station et central), API du central authentifiée par jeton de station
    $r->get('/sync', [SyncController::class, 'index'], ['permission' => 'sync.manage']);
    $r->post('/api/sync/push', [SyncApiController::class, 'push'], ['auth' => false, 'csrf' => false]);
    $r->post('/api/sync/pull', [SyncApiController::class, 'pull'], ['auth' => false, 'csrf' => false]);
    $r->post('/api/sync/backup', [SyncApiController::class, 'backup'], ['auth' => false, 'csrf' => false]);

    // Journal d'audit : consultation seule
    $r->get('/audit', [AuditController::class, 'index'], ['permission' => 'audit.view']);
    $r->get('/audit/data', [AuditController::class, 'data'], ['permission' => 'audit.view']);

    // Administration (propriétaire)
    $r->get('/users', [UserController::class, 'index'], ['permission' => 'users.manage']);
    $r->post('/users/{id}/password', [UserController::class, 'resetPassword'], ['permission' => 'users.manage']);
    $r->get('/roles', [RoleController::class, 'index'], ['permission' => 'organization.manage']);
    $r->post('/roles/{id}/permissions', [RoleController::class, 'update'], ['permission' => 'organization.manage']);
};
