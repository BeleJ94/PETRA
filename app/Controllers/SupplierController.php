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
use Petra\Repositories\SupplierRepository;
use Petra\Services\SupplierService;

/** Répertoire des fournisseurs : liste, création et modification en fenêtre. */
final class SupplierController extends Controller
{
    private const FIELDS = ['name', 'contact_name', 'phone', 'email', 'address'];

    public function __construct(
        ControllerContext $ctx,
        private readonly SupplierRepository $suppliers,
        private readonly SupplierService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $context = $this->ctx->auth->stations();
        $station = $context->active();
        $manages = $this->user()->can('suppliers.manage') && !$context->isReadOnly();

        return $this->render('suppliers/index', [
            'title' => t('nav.suppliers'),
            'suppliers' => $this->suppliers->overview(),
            'several' => $station === null && $context->isMultiStation(),
            'canManage' => $manages,
            // Une fiche appartient à une station : il faut en avoir choisi une pour en créer.
            'canCreate' => $manages && $station !== null && $station['is_active'],
            'needsStation' => $manages && $station === null,
            'pageScripts' => ['js/pt-live.js'],
        ]);
    }

    /** Fenêtre de création ou de modification (fragment HTML). */
    public function form(Request $request, array $params): Response
    {
        $context = $this->ctx->auth->stations();
        if (isset($params['id'])) {
            $supplier = $this->suppliers->find($this->id($params)) ?? throw HttpException::notFound();
            $stationId = (string) $supplier['station_id'];
        } else {
            $supplier = null;
            $stationId = $context->writable();
        }
        $names = array_column($context->stations(), 'name', 'id');

        return $this->render('suppliers/_form', ['supplier' => $supplier, 'stationName' => $names[$stationId] ?? ''], 200, null);
    }

    public function store(Request $request): Response
    {
        $this->service->create($this->ctx->auth->stations()->writable(), $request->only(self::FIELDS));

        return $this->done($request, '/suppliers', t('suppliers.created'));
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->only([...self::FIELDS, 'is_active']));

        return $this->done($request, '/suppliers', t('suppliers.updated'));
    }
}
