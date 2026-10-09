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
use Petra\Domain\DeliveryCalculator;
use Petra\Domain\StationSettings;
use Petra\Repositories\DeliveryRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Repositories\StockRepository;
use Petra\Repositories\SupplierRepository;
use Petra\Services\DeliveryService;
use Petra\Services\FileStore;

/** Livraisons de carburant : réception, écarts, photo du bon. */
final class DeliveryController extends Controller
{
    private const FIELDS = [
        'tank_id', 'supplier_id', 'delivery_note_no', 'truck_plate', 'driver_name', 'ordered_volume_l', 'invoiced_volume_l',
        'received_volume_l', 'before_cm', 'after_cm', 'received_by', 'note',
    ];

    public function __construct(
        ControllerContext $ctx,
        private readonly DeliveryRepository $deliveries,
        private readonly StockRepository $stock,
        private readonly SupplierRepository $suppliers,
        private readonly StationSettingRepository $settings,
        private readonly DeliveryService $service,
        private readonly FileStore $files,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $station = $this->ctx->auth->stations()->active();

        return $this->render('deliveries/index', [
            'title' => t('nav.deliveries'),
            'canRecord' => $this->user()->can('deliveries.record') && $station !== null && $station['is_active'],
            'needsStation' => $station === null && $this->user()->can('deliveries.record'),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, DeliveryRepository::SORTABLE, ['d.delivered_at', 'DESC']);

        return $this->json($this->deliveries->datatable($dt, $request->string('tank_id')));
    }

    public function create(Request $request): Response
    {
        $context = $this->ctx->auth->stations();
        $stationId = $context->writable();
        $tanks = array_values(array_filter($this->stock->tanks(), static fn (array $t): bool => $t['station_id'] === $stationId));

        return $this->render('deliveries/form', [
            'title' => t('deliveries.new'),
            'station' => $context->active(),
            'tanks' => $tanks,
            'suppliers' => array_column($this->suppliers->activeForStation($stationId), 'name', 'id'),
            'canManageSuppliers' => $this->user()->can('suppliers.manage'),
            'receivers' => $this->service->receivers($stationId),
            'currentUserId' => $this->user()->id,
            'tolerance' => StationSettings::thresholds($this->settings->all($stationId))['delivery_variance_l'],
            'maxPhotoMb' => intdiv(FileStore::MAX_IMAGE_BYTES, 1_048_576),
        ]);
    }

    public function store(Request $request): Response
    {
        $result = $this->service->receive($this->ctx->auth->stations()->writable(), $request->only(self::FIELDS), $request->file('photo'));

        return $this->redirect('/deliveries/' . $result['id'], t($result['alert'] ? 'deliveries.recorded_with_alert' : 'deliveries.recorded'));
    }

    public function show(Request $request, array $params): Response
    {
        $delivery = $this->deliveries->find($this->id($params)) ?? throw HttpException::notFound();
        $tolerance = StationSettings::thresholds($this->settings->all($delivery['station_id']))['delivery_variance_l'];

        return $this->render('deliveries/show', [
            'title' => t('deliveries.sheet', ['supplier' => $delivery['supplier_name']]),
            'delivery' => $delivery,
            // Les écarts affichés sont ceux figés à la réception ; seule la tolérance actuelle sert à les colorer.
            'figures' => DeliveryCalculator::compute((string) $delivery['invoiced_volume_l'], (string) $delivery['received_volume_l'], (string) $delivery['before_l'], (string) $delivery['after_l'], $tolerance),
        ]);
    }

    /** Photo du bon : lue hors racine web, après vérification de la station (DeliveryRepository::find). */
    public function photo(Request $request, array $params): Response
    {
        $delivery = $this->deliveries->find($this->id($params)) ?? throw HttpException::notFound();
        $file = $delivery['photo_path'] === null ? null : $this->files->read((string) $delivery['photo_path'], (string) $delivery['station_id']);
        if ($file === null) {
            throw HttpException::notFound();
        }

        return Response::binary($file['body'], $file['mime']);
    }
}
