<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeImmutable;
use Petra\Domain\Decimal;
use Petra\Domain\Period;
use Petra\Domain\StationSettings;
use Petra\Repositories\ReportRepository;
use Petra\Sync\SyncConfig;
use Petra\Sync\SyncStatus;

/**
 * Tableau de bord d'une station, ou consolidé sur plusieurs stations. Les chiffres viennent des
 * agrégations SQL de DashboardRepository ; ici on ne fait qu'assembler, totaliser (en entiers) et classer.
 */
final class DashboardService
{
    public function __construct(
        private readonly ReportRepository $repository,
        private readonly SyncConfig $sync,
        private readonly SyncStatus $syncStatus,
    ) {
    }

    /**
     * @param array{period?: mixed, from?: mixed, to?: mixed, station?: mixed} $query filtres de la page
     * @return array<string, mixed>
     */
    public function build(array $query, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $period = Period::resolve($query['period'] ?? 'day', $query['from'] ?? null, $query['to'] ?? null, $now);
        $context = $this->repository->stations();
        $ids = $this->repository->scope($query['station'] ?? null);
        $names = array_column($context->stations(), 'name', 'id');
        [$from, $to] = [$period->from, $period->to];

        $sales = $this->repository->salesByStation($ids, $from, $to);
        $receipts = $this->repository->receipts($ids, $from, $to);
        $cash = $this->repository->cashVariances($ids, $from, $to);
        $shifts = $this->repository->shiftCounts($ids, $from, $to);
        $stock = $this->repository->stockVariances($ids, $from, $to);
        $deliveries = $this->repository->deliveryTotals($ids, $from, $to);
        $alerts = $this->repository->alertCounts($ids);
        $rates = $this->repository->currentRates($ids, $now->format('Y-m-d H:i:s'));
        $syncs = $this->repository->lastSyncs($ids);
        // Sur le serveur d'une station reliée, sa dernière passe est connue localement.
        $local = $this->syncStatus->summary();
        if ($local !== null && array_key_exists($this->sync->stationId, $syncs)) {
            $syncs[$this->sync->stationId] = $local['last_at'];
        }

        // Une ligne par station : c'est le tableau comparatif, et la base des totaux.
        $comparison = [];
        foreach ($ids as $id) {
            $row = [
                'station_id' => $id, 'station' => $names[$id] ?? '',
                'revenue_cdf' => $sales[$id]['amount_cdf'] ?? 0, 'sold_cl' => Decimal::scaled($sales[$id]['sold_l'] ?? '0', 2) ?? 0,
                'receipts_cdf' => $receipts[$id]['CDF'] ?? 0, 'receipts_usd' => $receipts[$id]['USD'] ?? 0, 'receipts_usd_cdf' => $receipts[$id]['USD_cdf'] ?? 0,
                'cash_variance' => 0, 'shortage' => 0, 'surplus' => 0,
                'shifts_open' => $shifts[$id]['open'] ?? 0, 'shifts_closed' => $shifts[$id]['closed'] ?? 0, 'shifts_validated' => $shifts[$id]['validated'] ?? 0,
                'stock_variance_cl' => Decimal::signed($stock[$id]['variance_l'] ?? '0', 2) ?? 0, 'stock_variance_cdf' => $stock[$id]['variance_cdf'] ?? 0,
                'deliveries' => $deliveries[$id]['deliveries'] ?? 0, 'received_cl' => Decimal::scaled($deliveries[$id]['received_l'] ?? '0', 2) ?? 0,
                'delivery_variance_cl' => Decimal::signed($deliveries[$id]['announced_variance_l'] ?? '0', 2) ?? 0,
                'alerts_open' => $alerts[$id]['open'] ?? 0, 'alerts_acknowledged' => $alerts[$id]['acknowledged'] ?? 0, 'alerts_critical' => $alerts[$id]['critical'] ?? 0,
                'rate' => $rates[$id]['rate'] ?? null, 'rate_at' => $rates[$id]['effective_at'] ?? null,
                'last_sync_at' => $syncs[$id] ?? null,
            ];
            $comparison[$id] = $row;
        }
        foreach ($cash as $line) {
            $comparison[$line['station_id']]['cash_variance'] += $line['variance'];
            $comparison[$line['station_id']]['shortage'] += $line['shortage'];
            $comparison[$line['station_id']]['surplus'] += $line['surplus'];
        }
        foreach ($comparison as &$row) {
            $row['receipts_total_cdf'] = $row['receipts_cdf'] + $row['receipts_usd_cdf'];
            // Écart total de la station : caisse + stock valorisé, en CDF.
            $row['variance_cdf'] = $row['cash_variance'] + $row['stock_variance_cdf'];
        }
        unset($row);
        $comparison = array_values($comparison);
        usort($comparison, static fn (array $a, array $b): int => [$b['revenue_cdf'], $a['station']] <=> [$a['revenue_cdf'], $b['station']]);

        $totals = [];
        foreach (['revenue_cdf', 'sold_cl', 'receipts_cdf', 'receipts_usd', 'receipts_usd_cdf', 'receipts_total_cdf', 'cash_variance', 'shortage', 'surplus',
                  'shifts_open', 'shifts_closed', 'shifts_validated', 'stock_variance_cl', 'stock_variance_cdf', 'deliveries', 'received_cl',
                  'delivery_variance_cl', 'alerts_open', 'alerts_acknowledged', 'alerts_critical', 'variance_cdf'] as $key) {
            $totals[$key] = array_sum(array_column($comparison, $key));
        }

        // Classements des écarts : le plus gros manquant en tête.
        $stationRanking = $comparison;
        usort($stationRanking, static fn (array $a, array $b): int => [$a['variance_cdf'], $a['station']] <=> [$b['variance_cdf'], $b['station']]);
        $attendantRanking = array_map(static fn (array $line): array => $line + ['station' => $names[$line['station_id']] ?? ''], $cash);

        $tanks = array_map(static function (array $tank): array {
            $capacity = Decimal::scaled((string) $tank['capacity_l'], 2) ?? 0;
            $volume = $tank['volume_l'] === null ? null : (Decimal::scaled((string) $tank['volume_l'], 2) ?? 0);
            $low = Decimal::scaled((string) $tank['low_stock_l'], 2) ?? 0;

            return $tank + [
                // Taux de remplissage en dixièmes de pour cent, calculé en entiers.
                'fill_permille' => $volume === null || $capacity <= 0 ? null : max(0, min(1000, intdiv($volume * 1000, $capacity))),
                'is_low' => $volume !== null && $volume <= $low,
            ];
        }, $this->repository->tankStocks($ids, StationSettings::THRESHOLDS['low_stock_l'][1]));

        $products = $this->repository->salesByProduct($ids, $from, $to);
        $byDay = $this->repository->salesByDay($ids, $from, $to);
        $days = array_map(static fn (string $date): array => ['date' => $date, 'amount_cdf' => $byDay[$date]['amount_cdf'] ?? 0, 'sold_l' => $byDay[$date]['sold_l'] ?? '0.00'], $period->dates());
        $selected = $query['station'] ?? null;

        $data = [
            'period' => $period,
            'stations' => $context->stations(),
            'selected' => $selected === 'all' ? 'all' : (count($ids) === 1 ? $ids[0] : 'all'),
            'consolidated' => count($ids) > 1,
            'stationName' => count($ids) === 1 ? ($names[$ids[0]] ?? '') : null,
            'totals' => $totals,
            'rate' => count($ids) === 1 ? ($rates[$ids[0]] ?? null) : null,
            'comparison' => $comparison,
            'stationRanking' => $stationRanking,
            'attendantRanking' => $attendantRanking,
            'products' => $products,
            'days' => $days,
            'tanks' => $tanks,
            'deliveries' => $this->repository->recentDeliveries($ids),
            'alerts' => $this->repository->openAlerts($ids),
            'charts' => [
                'days' => ['labels' => array_column($days, 'date'), 'amounts' => array_column($days, 'amount_cdf')],
                'products' => ['labels' => array_column($products, 'product'), 'litres' => array_column($products, 'sold_l'), 'amounts' => array_column($products, 'amount_cdf')],
                'stations' => [
                    'labels' => array_column($comparison, 'station'),
                    'revenue' => array_column($comparison, 'revenue_cdf'),
                    'variance' => array_column($comparison, 'variance_cdf'),
                ],
            ],
        ];
        // Encaissements par jour, à côté des ventes : l'écart se lit sur le graphique.
        $received = $this->repository->receiptsByDay($ids, $from, $to);
        $data['charts']['days']['received'] = array_map(static fn (string $d): int => ($received[$d]['CDF'] ?? 0) + ($received[$d]['USD_cdf'] ?? 0), $period->dates());

        return $data + $this->insight($ids, $period, $totals, $tanks, $now);
    }

    /**
     * Ce qui oriente la lecture : comparaison avec la période précédente de même durée, tendance sur sept
     * jours, et ce qui demande une action maintenant.
     *
     * @return array<string, mixed>
     */
    private function insight(array $ids, Period $period, array $totals, array $tanks, DateTimeImmutable $now): array
    {
        $r = $this->repository;
        // Période précédente : même nombre de jours, juste avant.
        $days = $period->days();
        $prevTo = (new DateTimeImmutable($period->from))->modify('-1 day')->format('Y-m-d');
        $prevFrom = (new DateTimeImmutable($period->from))->modify("-$days days")->format('Y-m-d');
        $previous = ['revenue_cdf' => 0, 'sold_cl' => 0, 'receipts_total_cdf' => 0, 'cash_variance' => 0];
        foreach ($r->salesByStation($ids, $prevFrom, $prevTo) as $row) {
            $previous['revenue_cdf'] += $row['amount_cdf'];
            $previous['sold_cl'] += Decimal::scaled($row['sold_l'], 2) ?? 0;
        }
        foreach ($r->receipts($ids, $prevFrom, $prevTo) as $row) {
            $previous['receipts_total_cdf'] += $row['CDF'] + $row['USD_cdf'];
        }
        foreach ($r->cashVariances($ids, $prevFrom, $prevTo) as $row) {
            $previous['cash_variance'] += $row['variance'];
        }
        // Évolution en pour cent, entière ; null quand la période précédente est vide.
        $delta = static fn (int $current, int $before): ?int => $before === 0 ? null : intdiv(($current - $before) * 100, abs($before));

        // Tendance : les sept jours qui finissent à la fin de la période.
        $trendFrom = (new DateTimeImmutable($period->to))->modify('-6 days')->format('Y-m-d');
        $sales = $r->salesByDay($ids, $trendFrom, $period->to);
        $cash = $r->receiptsByDay($ids, $trendFrom, $period->to);
        $variance = [];
        foreach ($r->shifts($ids, $trendFrom, $period->to) as $shift) {
            if ($shift['status'] === 'validated') {
                $variance[$shift['business_date']] = ($variance[$shift['business_date']] ?? 0) + (int) $shift['variance_amount'];
            }
        }
        $trend = ['revenue' => [], 'sold' => [], 'received' => [], 'variance' => []];
        for ($i = 0; $i < 7; $i++) {
            $day = (new DateTimeImmutable($trendFrom))->modify("+$i days")->format('Y-m-d');
            $trend['revenue'][] = $sales[$day]['amount_cdf'] ?? 0;
            $trend['sold'][] = Decimal::scaled($sales[$day]['sold_l'] ?? '0', 2) ?? 0;
            $trend['received'][] = ($cash[$day]['CDF'] ?? 0) + ($cash[$day]['USD_cdf'] ?? 0);
            $trend['variance'][] = $variance[$day] ?? 0;
        }

        // À faire maintenant : au plus quatre cartes, les plus pressantes d'abord.
        $todos = [];
        $critical = array_values(array_filter($r->openAlerts($ids, 20), static fn (array $a): bool => $a['severity'] === 'critical'));
        if ($critical !== []) {
            // « modal » : la carte ouvre la liste des alertes en tableau, dans une fenêtre ; le lien reste pour un navigateur sans JavaScript.
            $todos[] = ['kind' => 'alert', 'icon' => 'siren', 'tone' => 'danger', 'count' => count($critical), 'text' => $critical[0]['message'],
                        'station' => $critical[0]['station'], 'url' => count($critical) === 1 ? '/alerts/' . $critical[0]['id'] : '/alerts', 'modal' => 'alerts'];
        }
        $pending = $r->shiftsToValidate($ids);
        if ($pending !== []) {
            $first = $pending[0];
            $todos[] = ['kind' => 'shift', 'icon' => 'check-check', 'tone' => 'warning', 'count' => count($pending),
                        'text' => trim($first['attendant'] . ($first['label'] ? ' — ' . $first['label'] : '')) . ' · ' . format_date($first['business_date']),
                        'station' => $first['station'], 'url' => '/shifts?date=' . $first['business_date']];
        }
        foreach (array_filter($tanks, static fn (array $t): bool => (bool) $t['is_low']) as $tank) {
            $todos[] = ['kind' => 'stock', 'icon' => 'cylinder', 'tone' => 'danger', 'count' => 1,
                        'text' => $tank['code'] . ' · ' . $tank['product'] . ' — ' . format_number((string) $tank['volume_l'], 0) . ' L',
                        'station' => $tank['station'], 'url' => '/stock?station=' . $tank['station_id']];
        }

        return [
            'previous' => $previous + ['from' => $prevFrom, 'to' => $prevTo],
            'deltas' => [
                'revenue' => $delta($totals['revenue_cdf'], $previous['revenue_cdf']),
                'sold' => $delta($totals['sold_cl'], $previous['sold_cl']),
                'received' => $delta($totals['receipts_total_cdf'], $previous['receipts_total_cdf']),
                'variance' => $totals['cash_variance'] - $previous['cash_variance'],
            ],
            'trend' => $trend,
            'todos' => array_slice($todos, 0, 4),
            'todoCount' => count($todos),
        ];
    }

    /**
     * Détail d'un bloc du tableau de bord, en tableau compact pour une fenêtre.
     *
     * @return array{title: string, subtitle: string, sections: list<array{title: ?string, columns: list<mixed>, rows: list<list<string>>, foot?: list<string>, empty: string}>}
     */
    public function detail(string $kind, array $query, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $period = Period::resolve($query['period'] ?? 'day', $query['from'] ?? null, $query['to'] ?? null, $now);
        $ids = $this->repository->scope($query['station'] ?? null);
        $r = $this->repository;
        [$from, $to] = [$period->from, $period->to];
        $multi = count($ids) > 1;
        $names = array_column($r->stations()->stations(), 'name', 'id');
        $cdf = static fn (int $amount, bool $signed = false): string => ($signed && $amount > 0 ? '+' : '') . format_money($amount, 'CDF');
        $litres = static fn (mixed $value): string => format_number((string) $value, 2) . ' L';
        $num = static fn (string $label): array => [$label, 'num'];
        $withStation = static fn (string $station, array $cells): array => $multi ? array_merge([$station], $cells) : $cells;
        $stationColumn = static fn (array $columns): array => $multi ? array_merge([t('fields.station')], $columns) : $columns;
        $sections = [];

        switch ($kind) {
            case 'sales':
                $title = t('dashboard.detail_sales');
                $byDay = $r->salesByDay($ids, $from, $to);
                $rows = [];
                foreach ($byDay as $day => $row) {
                    $rows[] = [format_date((string) $day), $litres($row['sold_l']), $cdf($row['amount_cdf'])];
                }
                $sections[] = ['title' => t('dashboard.detail_by_day'), 'columns' => [t('dashboard.date'), $num(t('dashboard.litres')), $num(t('dashboard.revenue'))], 'rows' => $rows, 'empty' => t('dashboard.no_sales')];
                $sections[] = ['title' => t('dashboard.by_product'), 'columns' => [t('fields.product'), $num(t('dashboard.litres')), $num(t('dashboard.revenue'))],
                               'rows' => array_map(static fn (array $p): array => [$p['product'], $litres($p['sold_l']), $cdf($p['amount_cdf'])], $r->salesByProduct($ids, $from, $to)), 'empty' => t('dashboard.no_sales')];
                break;
            case 'receipts':
                $title = t('dashboard.detail_receipts');
                $rows = [];
                $byDay = $r->receiptsByDay($ids, $from, $to);
                ksort($byDay);
                foreach ($byDay as $day => $row) {
                    $rows[] = [format_date((string) $day), $cdf($row['CDF']), format_money($row['USD'], 'USD'), $cdf($row['USD_cdf']), $cdf($row['CDF'] + $row['USD_cdf'])];
                }
                $sections[] = ['title' => null, 'columns' => [t('dashboard.date'), $num(t('dashboard.receipts_cdf')), $num(t('dashboard.receipts_usd')), $num(t('dashboard.usd_value_short')), $num(t('dashboard.total'))], 'rows' => $rows, 'empty' => t('dashboard.no_receipts')];
                break;
            case 'variance':
                $title = t('dashboard.detail_variance');
                $shifts = array_values(array_filter($r->shifts($ids, $from, $to), static fn (array $s): bool => $s['status'] === 'validated'));
                usort($shifts, static fn (array $a, array $b): int => (int) $a['variance_amount'] <=> (int) $b['variance_amount']);
                $sections[] = ['title' => null, 'columns' => $stationColumn([t('dashboard.date'), t('dashboard.shift'), t('dashboard.attendant'), $num(t('dashboard.expected')), $num(t('dashboard.received_cash')), $num(t('dashboard.variance_short'))]),
                               'rows' => array_map(static fn (array $s): array => $withStation($s['station'], [format_date($s['business_date']), (string) ($s['label'] ?: '—'), $s['attendant'], $cdf((int) $s['expected_amount']), $cdf((int) $s['received_amount']), $cdf((int) $s['variance_amount'], true)]), $shifts),
                               'empty' => t('dashboard.no_validated_shift')];
                break;
            case 'alerts':
                // Les alertes à traiter ne dépendent pas de la période : tant qu'elles sont ouvertes, elles comptent.
                $title = t('dashboard.detail_alerts');
                $sections[] = ['title' => null, 'columns' => $stationColumn([t('dashboard.date'), t('dashboard.col_severity'), t('dashboard.col_alert'), t('dashboard.col_concerned'), t('dashboard.col_status')]),
                               'rows' => array_map(static fn (array $a): array => $withStation($a['station'], [format_date($a['created_at'], true), label('severity', $a['severity']), $a['message'], $a['concerned'] !== '' ? $a['concerned'] : '—', label('alert_status', $a['status'])]), $r->pendingAlerts($ids)),
                               'empty' => t('dashboard.no_alert')];
                $link = ['/alerts', t('dashboard.alerts_page')];
                $timeless = true;
                break;
            case 'attendants':
                $title = t('dashboard.ranking_attendants');
                $sections[] = ['title' => null, 'columns' => $stationColumn([t('dashboard.attendant'), $num(t('dashboard.shifts')), $num(t('dashboard.expected')), $num(t('dashboard.shortage')), $num(t('dashboard.surplus')), $num(t('dashboard.net_variance'))]),
                               'rows' => array_map(static fn (array $a): array => $withStation($names[$a['station_id']] ?? '', [$a['attendant'], (string) $a['shifts'], $cdf($a['expected']), $cdf($a['shortage']), $cdf($a['surplus']), $cdf($a['variance'], true)]), $r->cashVariances($ids, $from, $to)),
                               'empty' => t('dashboard.no_validated_shift')];
                break;
            case 'deliveries':
                $title = t('dashboard.recent_deliveries');
                $sections[] = ['title' => null, 'columns' => $stationColumn([t('dashboard.date'), t('fields.tank'), t('dashboard.supplier'), $num(t('dashboard.announced')), $num(t('dashboard.received')), $num(t('dashboard.variance_short')), t('dashboard.received_by')]),
                               'rows' => array_map(static fn (array $d): array => $withStation($d['station'], [format_date($d['at'], true), $d['tank'] . ' · ' . $d['product'], $d['supplier_name'], $litres($d['invoiced_volume_l']), $litres($d['received_volume_l']), $d['announced_variance_l'] === null ? '—' : $litres($d['announced_variance_l']), $d['user']]), $r->deliveries($ids, $from, $to)),
                               'empty' => t('dashboard.no_delivery')];
                break;
            case 'stations':
                $title = t('dashboard.comparison');
                $data = $this->build($query, $now);
                $sections[] = ['title' => null, 'columns' => [t('fields.station'), $num(t('dashboard.revenue')), $num(t('dashboard.litres')), $num(t('dashboard.receipts_total_short')), $num(t('dashboard.cash_variance')), $num(t('dashboard.stock_variance')), $num(t('dashboard.open_alerts')), t('dashboard.last_sync')],
                               'rows' => array_map(static fn (array $s): array => [$s['station'], $cdf($s['revenue_cdf']), $litres(Decimal::unscaled($s['sold_cl'], 2)), $cdf($s['receipts_total_cdf']), $cdf($s['cash_variance'], true), $litres(Decimal::unscaled($s['stock_variance_cl'], 2)), (string) $s['alerts_open'], $s['last_sync_at'] === null ? t('dashboard.never_synced') : format_date($s['last_sync_at'], true)], $data['comparison']),
                               'foot' => [t('dashboard.total'), $cdf($data['totals']['revenue_cdf']), $litres(Decimal::unscaled($data['totals']['sold_cl'], 2)), $cdf($data['totals']['receipts_total_cdf']), $cdf($data['totals']['cash_variance'], true), $litres(Decimal::unscaled($data['totals']['stock_variance_cl'], 2)), (string) $data['totals']['alerts_open'], ''],
                               'empty' => t('common.none')];
                break;
            default:
                throw \Petra\Core\HttpException::notFound();
        }

        return [
            'title' => $title,
            'subtitle' => (count($ids) === 1 ? ($names[$ids[0]] ?? '') : t('common.all_stations'))
                . (isset($timeless) ? '' : ' · ' . ($from === $to ? format_date($from) : format_date($from) . ' – ' . format_date($to))),
            'sections' => $sections,
            'link' => $link ?? null,
        ];
    }
}
