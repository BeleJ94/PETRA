<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\DataTableRequest;
use Petra\Core\Request;
use Petra\Domain\Decimal;
use Petra\Core\Response;
use Petra\Repositories\StockRepository;
use Petra\Services\StockControl;

/** Stock des cuves : situation, jaugeages, écarts. */
final class StockController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly StockRepository $stock,
        private readonly StockControl $control,
    ) {
        parent::__construct($ctx);
    }

    /** Nombre de jours sur lesquels le rythme de vente est mesuré pour estimer l'autonomie. */
    private const AUTONOMY_DAYS = 7;

    /** Une carte par cuve : niveau, autonomie, dernier écart, et le jaugeage à portée de main. */
    public function index(Request $request): Response
    {
        $all = $this->tanks();
        // Filtre par station : parmi les stations affichées, avec leur nombre de cuves et de cuves en stock bas.
        $stations = [];
        foreach ($all as $tank) {
            $stations[$tank['station_id']] ??= ['id' => $tank['station_id'], 'name' => $tank['station'], 'tanks' => 0, 'low' => 0];
            $stations[$tank['station_id']]['tanks']++;
            $stations[$tank['station_id']]['low'] += $tank['is_low'] ? 1 : 0;
        }
        $selected = $request->string('station');
        if ($selected !== null) {
            // Une station hors du périmètre de l'utilisateur est refusée ; une station sans cuve retombe sur « toutes ».
            $this->ctx->auth->stations()->assertAllows($selected);
            $selected = isset($stations[$selected]) ? $selected : null;
        }
        $tanks = $selected === null ? $all : array_values(array_filter($all, static fn (array $t): bool => $t['station_id'] === $selected));
        $withStock = array_filter($tanks, static fn (array $t): bool => $t['theoretical_l'] !== null);
        $autonomies = array_filter(array_column($tanks, 'autonomy_days'), static fn (?int $d): bool => $d !== null);

        return $this->render('stock/index', [
            'title' => t('nav.stock'),
            'tanks' => $tanks,
            'stations' => array_values($stations),
            'selected' => $selected,
            'allCount' => count($all),
            'allLow' => count(array_filter($all, static fn (array $t): bool => (bool) $t['is_low'])),
            'totals' => [
                'stock_cl' => array_sum(array_map(static fn (array $t): int => (int) Decimal::scaled((string) $t['theoretical_l'], 2), $withStock)),
                'capacity_cl' => array_sum(array_map(static fn (array $t): int => (int) Decimal::scaled((string) $t['capacity_l'], 2), $tanks)),
                'low' => count(array_filter($tanks, static fn (array $t): bool => (bool) $t['is_low'])),
                'autonomy_days' => $autonomies === [] ? null : min($autonomies),
            ],
            'canRecord' => $this->user()->can('dips.record') && !$this->ctx->auth->stations()->isReadOnly(),
            'canDeliver' => $this->user()->can('deliveries.record') && !$this->ctx->auth->stations()->isReadOnly(),
            'pageScripts' => ['js/pt-live.js'],
        ]);
    }

    /**
     * Situation des cuves, avec l'autonomie estimée : stock théorique ÷ litres débités par jour sur les
     * sept derniers jours (calcul entier, en centilitres). Null sans stock connu ou sans vente récente.
     *
     * @return list<array<string, mixed>>
     */
    private function tanks(): array
    {
        $now = new \DateTimeImmutable();
        $sold = $this->stock->throughputByTank($now->modify('-' . self::AUTONOMY_DAYS . ' days')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));

        return array_map(static function (array $tank) use ($sold): array {
            $stock = $tank['theoretical_l'] === null ? null : (int) Decimal::scaled((string) $tank['theoretical_l'], 2);
            $week = (int) Decimal::scaled((string) ($sold[$tank['id']] ?? '0'), 2);

            return $tank + [
                'week_l' => Decimal::unscaled($week, 2),
                'autonomy_days' => $stock === null || $week <= 0 ? null : intdiv($stock * self::AUTONOMY_DAYS, $week),
            ];
        }, $this->control->overview());
    }

    /** Cuve visible par l'utilisateur (403 si elle est d'une autre station, 404 si elle n'existe pas). */
    private function tank(string $id): array
    {
        $this->stock->findTank($id) ?? throw \Petra\Core\HttpException::notFound();
        foreach ($this->tanks() as $tank) {
            if ($tank['id'] === $id) {
                return $tank;
            }
        }

        throw \Petra\Core\HttpException::notFound();
    }

    /** Fenêtre de jaugeage (fragment HTML). */
    public function form(Request $request, array $params): Response
    {
        $this->authorize('dips.record');

        return $this->render('stock/_form', ['tank' => $this->tank($this->id($params)), 'dipTypes' => StockControl::DIP_TYPES], 200, null);
    }

    /** Fenêtre « Détail du stock » : d'où vient le stock théorique (fragment HTML). */
    public function detail(Request $request, array $params): Response
    {
        return $this->render('stock/_detail', ['tank' => $this->tank($this->id($params))], 200, null);
    }

    /** Fenêtre « Historique de la cuve » : ses derniers jaugeages et leurs écarts (fragment HTML). */
    public function history(Request $request, array $params): Response
    {
        $tank = $this->tank($this->id($params));

        return $this->render('stock/_history', ['tank' => $tank, 'rows' => $this->stock->tankHistory($tank['id'])], 200, null);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, StockRepository::SORTABLE, ['d.measured_at', 'DESC']);

        return $this->json($this->stock->datatable($dt, $request->string('tank_id')));
    }

    public function storeDip(Request $request, array $params): Response
    {
        $result = $this->control->recordDip($this->id($params), $request->only(['depth_cm', 'water_cm', 'dip_type', 'note']));
        $message = $result['variance_l'] === null
            ? t('stock.dip_initial', ['volume' => format_number($result['volume_l'], 2)])
            : t('stock.dip_recorded', ['volume' => format_number($result['volume_l'], 2), 'variance' => format_number($result['variance_l'], 2)]);

        return $this->done($request, '/stock', $message);
    }
}
