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
use Petra\Repositories\CalibrationRepository;
use Petra\Repositories\NozzleRepository;
use Petra\Repositories\ProductRepository;
use Petra\Repositories\PumpRepository;
use Petra\Repositories\StationRepository;
use Petra\Repositories\TankRepository;
use Petra\Services\CatalogService;

/** Installation d'une station : produits, cuves, pistolets, barèmes de jaugeage. */
final class CatalogController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly StationRepository $stations,
        private readonly ProductRepository $products,
        private readonly TankRepository $tanks,
        private readonly NozzleRepository $nozzles,
        private readonly PumpRepository $pumps,
        private readonly CalibrationRepository $calibrations,
        private readonly CatalogService $service,
    ) {
        parent::__construct($ctx);
    }

    // --- Produits -----------------------------------------------------------------

    public function storeProduct(Request $request, array $params): Response
    {
        $this->service->createProduct($this->id($params), $request->only(['code', 'name']));

        return $this->redirect('/stations/' . $this->id($params), t('products.created'));
    }

    public function toggleProduct(Request $request, array $params): Response
    {
        $product = $this->service->setProductActive($this->id($params), $request->string('is_active') === '1');

        return $this->redirect('/stations/' . $product['station_id'], t('products.updated'));
    }

    // --- Cuves ----------------------------------------------------------------------

    public function tanksData(Request $request, array $params): Response
    {
        $station = $this->station($params);
        $dt = DataTableRequest::fromQuery($request->query, TankRepository::SORTABLE, ['t.code', 'ASC']);

        return $this->json($this->tanks->datatable($station['id'], $dt));
    }

    public function storeTank(Request $request, array $params): Response
    {
        $id = $this->service->createTank($this->id($params), $request->only(['product_id', 'code', 'name', 'capacity_l']));

        return $this->redirect('/tanks/' . $id, t('tanks.created'));
    }

    public function showTank(Request $request, array $params): Response
    {
        $tank = $this->tanks->find($this->id($params)) ?? throw HttpException::notFound();
        $station = $this->stations->find($tank['station_id']) ?? throw HttpException::notFound();

        return $this->render('tanks/show', [
            'title' => $tank['name'],
            'tank' => $tank,
            'station' => $station,
            'product' => $this->products->find($tank['product_id']),
            'summary' => $this->calibrations->summary($tank['station_id'], $tank['id']),
        ]);
    }

    public function updateTank(Request $request, array $params): Response
    {
        $this->service->updateTank($this->id($params), $request->only(['code', 'name', 'capacity_l', 'is_active']));

        return $this->redirect('/tanks/' . $this->id($params), t('tanks.updated'));
    }

    // --- Distributeurs ----------------------------------------------------------------

    public function storePump(Request $request, array $params): Response
    {
        $this->service->createPump($this->id($params), $request->only(['code', 'name']));

        return $this->redirect('/stations/' . $this->id($params), t('pumps.created'));
    }

    public function updatePump(Request $request, array $params): Response
    {
        $pump = $this->service->updatePump($this->id($params), $request->only(['code', 'name', 'is_active']));

        return $this->redirect('/stations/' . $pump['station_id'], t('pumps.updated'));
    }

    // --- Pistolets --------------------------------------------------------------------

    public function nozzlesData(Request $request, array $params): Response
    {
        $station = $this->station($params);
        $dt = DataTableRequest::fromQuery($request->query, NozzleRepository::SORTABLE, ['n.code', 'ASC']);

        return $this->json($this->nozzles->datatable($station['id'], $dt));
    }

    public function storeNozzle(Request $request, array $params): Response
    {
        $this->service->createNozzle($this->id($params), $request->only(['tank_id', 'pump_id', 'code', 'name']));

        return $this->redirect('/stations/' . $this->id($params), t('nozzles.created'));
    }

    public function editNozzle(Request $request, array $params): Response
    {
        $nozzle = $this->nozzles->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('nozzles/form', [
            'title' => $nozzle['name'],
            'nozzle' => $nozzle,
            'station' => $this->stations->find($nozzle['station_id']) ?? throw HttpException::notFound(),
            'tanks' => $this->tanks->listActive($nozzle['station_id']),
            'pumps' => $this->pumps->listForStation($nozzle['station_id']),
            'locked' => $this->nozzles->hasReadings($nozzle['id']),
        ]);
    }

    public function updateNozzle(Request $request, array $params): Response
    {
        $nozzle = $this->service->updateNozzle($this->id($params), $request->only(['tank_id', 'pump_id', 'code', 'name', 'rollover_index', 'is_active']));

        return $this->redirect('/stations/' . $nozzle['station_id'], t('nozzles.updated'));
    }

    // --- Barème de jaugeage --------------------------------------------------------------

    public function calibrationsData(Request $request, array $params): Response
    {
        $tank = $this->tanks->find($this->id($params)) ?? throw HttpException::notFound();
        $dt = DataTableRequest::fromQuery($request->query, CalibrationRepository::SORTABLE, ['c.depth_cm', 'ASC']);

        return $this->json($this->calibrations->datatable($tank['station_id'], $tank['id'], $dt));
    }

    public function storeCalibration(Request $request, array $params): Response
    {
        $this->service->saveCalibrationPoint($this->id($params), $request->only(['depth_cm', 'volume_l']));

        return $this->redirect('/tanks/' . $this->id($params), t('calibrations.saved'));
    }

    public function importCalibrations(Request $request, array $params): Response
    {
        $count = $this->service->importCalibrations($this->id($params), $request->file('file'));

        return $this->redirect('/tanks/' . $this->id($params), t('calibrations.imported', ['count' => $count]));
    }

    private function station(array $params): array
    {
        return $this->stations->find($this->id($params)) ?? throw HttpException::notFound();
    }
}
