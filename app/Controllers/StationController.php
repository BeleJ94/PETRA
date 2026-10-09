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
use Petra\Domain\StationSettings;
use Petra\Repositories\ProductRepository;
use Petra\Repositories\StationRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Repositories\TankRepository;
use Petra\Services\StationService;

/** Écran Propriétaire : stations, horaires des postes, fuseau et seuils. */
final class StationController extends Controller
{
    private const FIELDS = ['code', 'name', 'city', 'address', 'timezone'];

    public function __construct(
        ControllerContext $ctx,
        private readonly StationRepository $stations,
        private readonly StationSettingRepository $settings,
        private readonly ProductRepository $products,
        private readonly TankRepository $tanks,
        private readonly \Petra\Repositories\PumpRepository $pumps,
        private readonly StationService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('stations/index', [
            'title' => t('nav.stations'),
            'canCreate' => $this->ctx->auth->stations()->coversOrganization(),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, StationRepository::SORTABLE, ['s.name', 'ASC']);
        $active = $request->string('is_active');

        return $this->json($this->stations->datatable($dt, $active === null ? null : $active === '1'));
    }

    public function create(Request $request): Response
    {
        return $this->render('stations/form', [
            'title' => t('stations.new'),
            'station' => ['timezone' => date_default_timezone_get()],
            'id' => null,
            'timezones' => StationService::timezones(),
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->create($request->only(self::FIELDS));

        return $this->redirect('/stations/' . $id, t('stations.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $station = $this->find($params);
        $stored = $this->settings->all($station['id']);
        $user = $this->user();

        return $this->render('stations/show', [
            'title' => $station['name'],
            'station' => $station,
            'schedule' => StationSettings::schedule($stored),
            'shiftMode' => StationSettings::shiftMode($stored),
            'pumps' => $this->pumps->listForStation($station['id']),
            'thresholds' => StationSettings::thresholds($stored),
            'delays' => $stored,
            'usdRule' => StationSettings::usdRule($stored),
            'timezones' => StationService::timezones(),
            'products' => $this->products->listForStation($station['id']),
            'tanks' => $this->tanks->listActive($station['id']),
            'canSettings' => $user->can('settings.manage'),
            'canCatalog' => $user->can('catalog.manage'),
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $station = $this->find($params);

        return $this->render('stations/form', [
            'title' => t('stations.edit', ['station' => $station['name']]),
            'station' => $station,
            'id' => $station['id'],
            'timezones' => StationService::timezones(),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->only(self::FIELDS));

        return $this->redirect('/stations/' . $this->id($params), t('stations.updated'));
    }

    public function deactivate(Request $request, array $params): Response
    {
        $station = $this->service->setActive($this->id($params), false);

        return $this->redirect('/stations/' . $station['id'], t('stations.deactivated', ['station' => $station['name']]));
    }

    public function activate(Request $request, array $params): Response
    {
        $station = $this->service->setActive($this->id($params), true);

        return $this->redirect('/stations/' . $station['id'], t('stations.activated', ['station' => $station['name']]));
    }

    public function saveSchedule(Request $request, array $params): Response
    {
        $this->service->saveSchedule($this->id($params), ['timezone' => $request->body['timezone'] ?? null, 'slots' => $request->body['slots'] ?? null]);

        return $this->redirect('/stations/' . $this->id($params), t('stations.schedule_saved'));
    }

    public function saveThresholds(Request $request, array $params): Response
    {
        $this->service->saveThresholds($this->id($params), $request->only([...array_keys(StationSettings::THRESHOLDS), ...array_keys(StationSettings::DELAYS), StationSettings::USD_RULE_KEY]));

        return $this->redirect('/stations/' . $this->id($params), t('stations.thresholds_saved'));
    }

    private function find(array $params): array
    {
        return $this->stations->find($this->id($params)) ?? throw HttpException::notFound();
    }
}
