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
use Petra\Domain\Money;
use Petra\Repositories\ExchangeRateRepository;
use Petra\Repositories\PriceRepository;
use Petra\Repositories\ProductRepository;
use Petra\Repositories\ShiftRepository;
use Petra\Services\PriceResolver;
use Petra\Services\PricingService;
use Petra\Services\RateResolver;

/** Prix par produit et taux USD/CDF de la station active : versions, historique, publication. */
final class PricingController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
        private readonly ExchangeRateRepository $rates,
        private readonly ShiftRepository $shifts,
        private readonly PriceResolver $priceResolver,
        private readonly RateResolver $rateResolver,
        private readonly PricingService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $station = $this->ctx->auth->stations()->active();
        $products = [];
        if ($station !== null) {
            foreach ($this->products->listForStation($station['id']) as $product) {
                if ((int) $product['is_active'] === 1) {
                    $products[] = $product + ['price' => $this->priceResolver->at($product['id'])];
                }
            }
        }
        $user = $this->user();

        return $this->render('prices/index', [
            'title' => t('nav.prices'),
            'station' => $station,
            'products' => $products,
            'rate' => $station === null ? null : $this->rateResolver->at($station['id']),
            'openShifts' => $station === null ? 0 : count($this->shifts->openSegments($station['id'])),
            'canPrices' => $user->can('prices.manage') && ($station['is_active'] ?? false),
            'canRates' => $user->can('rates.manage') && ($station['is_active'] ?? false),
        ]);
    }

    public function pricesData(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, PriceRepository::SORTABLE, ['pr.effective_at', 'DESC']);

        return $this->json($this->prices->datatable($dt, $request->string('product_id')));
    }

    public function ratesData(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, ExchangeRateRepository::SORTABLE, ['x.effective_at', 'DESC']);

        return $this->json($this->rates->datatable($dt));
    }

    public function newPrice(Request $request, array $params): Response
    {
        $product = $this->products->find($this->id($params)) ?? throw HttpException::notFound();
        $current = $this->priceResolver->at($product['id']);

        return $this->render('prices/price_form', [
            'title' => t('pricing.new_price', ['product' => $product['name']]),
            'product' => $product,
            'current' => $current,
            'currentInput' => $current === null ? '' : Money::toInput($current['unit_price'], $current['currency']),
            'open' => $this->shifts->openSegments($product['station_id'], $product['id']),
        ]);
    }

    public function storePrice(Request $request, array $params): Response
    {
        $this->service->publishPrice($this->id($params), $request->only(['unit_price', 'currency', 'note']) + ['readings' => $request->body['readings'] ?? null]);

        return $this->redirect('/prices', t('pricing.price_published'));
    }

    public function newRate(Request $request): Response
    {
        $stationId = $this->ctx->auth->stations()->writable();

        return $this->render('prices/rate_form', [
            'title' => t('pricing.new_rate'),
            'current' => $this->rateResolver->at($stationId),
            'open' => $this->shifts->openSegments($stationId),
        ]);
    }

    public function storeRate(Request $request): Response
    {
        $this->service->publishRate($this->ctx->auth->stations()->writable(), $request->only(['rate', 'note']) + ['readings' => $request->body['readings'] ?? null]);

        return $this->redirect('/prices', t('pricing.rate_published'));
    }
}
