<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeImmutable;
use Petra\Core\Uuid;
use Petra\Domain\Decimal;
use Petra\Repositories\ReportRepository;
use Petra\Security\Auth;

/**
 * Rapports par poste, journalier et mensuel, pour une station ou en consolidé.
 *
 * Un rapport est un modèle unique — en-tête (station, période, date de génération, auteur, mention),
 * chiffres clés, sections en tableaux — que la page affiche et que reports.js exporte tel quel en PDF et
 * en Excel : l'écran, l'impression et les exports portent donc exactement les mêmes chiffres.
 *
 * Une cellule est un texte, ou ['t' => texte affiché, 'v' => valeur native, 'f' => CDF | USD | L] pour Excel.
 */
final class ReportService
{
    public const TYPES = ['daily', 'monthly', 'shift'];

    private bool $multi = false;

    public function __construct(private readonly ReportRepository $repository, private readonly Auth $auth)
    {
    }

    /**
     * @param array{type?: mixed, station?: mixed, date?: mixed, month?: mixed, shift?: mixed} $query
     * @return array<string, mixed>
     */
    public function build(array $query, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $type = in_array($query['type'] ?? null, self::TYPES, true) ? $query['type'] : 'daily';
        $context = $this->repository->stations();
        $ids = $this->repository->scope($query['station'] ?? null);
        $names = array_column($context->stations(), 'name', 'id');
        $date = self::date($query['date'] ?? null) ?? $now->format('Y-m-d');
        $month = is_string($query['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $query['month']) ? $query['month'] : $now->format('Y-m');

        $shift = null;
        if ($type === 'shift') {
            $shift = Uuid::isValid($query['shift'] ?? null) ? $this->repository->shift((string) $query['shift']) : null;
            if ($shift !== null) {
                $ids = [(string) $shift['station_id']];
                $date = (string) $shift['business_date'];
            }
        }
        $this->multi = count($ids) > 1;
        $station = $this->multi ? t('common.all_stations') : ($names[$ids[0] ?? ''] ?? '—');

        $report = match (true) {
            $type === 'shift' && $shift !== null => $this->shiftReport($shift),
            $type === 'shift' => ['title' => t('reports.shift'), 'period' => format_date($date), 'summary' => [], 'sections' => [], 'file' => 'rapport-poste'],
            $type === 'monthly' => $this->monthlyReport($ids, $month, $now),
            default => $this->dailyReport($ids, $date, $now),
        };

        return $report + [
            'type' => $type,
            'station' => $station,
            'generated_at' => format_date($now->format('Y-m-d H:i:s'), true),
            'author' => $this->auth->user()?->name() ?? '',
            'credit' => t('app.credit'),
            'filters' => [
                'type' => $type, 'date' => $date, 'month' => $month, 'shift' => $shift['id'] ?? null,
                'station' => ($query['station'] ?? null) === 'all' ? 'all' : (count($ids) === 1 ? $ids[0] : 'all'),
                'stations' => $context->stations(),
                // Postes proposés pour le rapport par poste : ceux de la journée choisie.
                'shifts' => $type === 'shift' ? array_map(fn (array $s): array => ['id' => $s['id'], 'label' => $this->shiftLabel($s, true)], $this->repository->shifts($ids, $date, $date)) : [],
            ],
        ];
    }

    // --- Journalier : les dix questions du contrôle ------------------------------------------------

    private function dailyReport(array $ids, string $date, DateTimeImmutable $now): array
    {
        $r = $this->repository;
        $shifts = $r->shifts($ids, $date, $date);
        $lines = $r->saleLines($ids, $date, $date);
        $handovers = $r->handovers($ids, $date, $date);
        $deliveries = $r->deliveries($ids, $date, $date);
        $dips = $r->dips($ids, $date, $date);
        $alerts = $r->alerts($ids, $date, $date);
        // Stock en fin de journée ; pour la journée en cours, à l'instant de la génération.
        $stockAt = min($date . ' 23:59:59', max($now->format('Y-m-d H:i:s'), $date . ' 00:00:00'));
        $money = $this->shiftMoney($shifts, $lines, $handovers);

        $sections = [
            $this->productSection($lines, t('reports.q_litres')),
            $this->shiftSection($shifts, $money, t('reports.q_money')),
            $this->rateSection($r->ratesUsed($ids, $date, $date), t('reports.q_rates')),
            $this->stockSection($r->stockAt($ids, $stockAt), t('reports.q_stock', ['at' => format_date($stockAt, true)])),
            $this->deliverySection($deliveries, t('reports.q_deliveries')),
            $this->anomalySection($shifts, $dips, $deliveries, $alerts, t('reports.q_anomalies')),
            $this->operationSection($this->operations($shifts, $handovers, $r->outputs($ids, $date, $date), $r->creditSales($ids, $date, $date), $r->expenses($ids, $date, $date), $deliveries, $dips, $r->publications($ids, $date, $date)), t('reports.q_operations')),
        ];

        return [
            'title' => t('reports.daily'),
            'period' => format_date($date),
            'file' => 'rapport-journalier-' . $date,
            'summary' => $this->summary($lines, $money),
            'sections' => $sections,
        ];
    }

    // --- Mensuel ------------------------------------------------------------------------------------

    private function monthlyReport(array $ids, string $month, DateTimeImmutable $now): array
    {
        $r = $this->repository;
        $from = $month . '-01';
        $to = (new DateTimeImmutable($from))->format('Y-m-t');
        $shifts = $r->shifts($ids, $from, $to);
        $lines = $r->saleLines($ids, $from, $to);
        $handovers = $r->handovers($ids, $from, $to);
        $deliveries = $r->deliveries($ids, $from, $to);
        $dips = $r->dips($ids, $from, $to);
        $money = $this->shiftMoney($shifts, $lines, $handovers);
        $stockAt = min($to . ' 23:59:59', max($now->format('Y-m-d H:i:s'), $from . ' 00:00:00'));

        // Synthèse par jour : seules les journées d'activité figurent.
        $days = [];
        foreach ($shifts as $shift) {
            $day = &$days[$shift['business_date']];
            $day ??= ['shifts' => 0, 'sold' => 0, 'expected' => 0, 'cdf' => 0, 'usd' => 0, 'usd_cdf' => 0, 'variance' => 0];
            $m = $money[$shift['id']];
            $day['shifts']++;
            foreach (['sold', 'expected', 'cdf', 'usd', 'usd_cdf'] as $key) {
                $day[$key] += $m[$key];
            }
            $day['variance'] += $shift['status'] === 'validated' ? (int) $shift['variance_amount'] : 0;
            unset($day);
        }
        ksort($days);
        $total = ['shifts' => 0, 'sold' => 0, 'expected' => 0, 'cdf' => 0, 'usd' => 0, 'usd_cdf' => 0, 'variance' => 0];
        $rows = [];
        foreach ($days as $date => $day) {
            foreach ($day as $key => $value) {
                $total[$key] += $value;
            }
            $rows[] = [format_date($date), (string) $day['shifts'], $this->l($day['sold']), $this->cdf($day['expected']), $this->cdf($day['cdf']), $this->usd($day['usd']),
                       $this->cdf($day['cdf'] + $day['usd_cdf']), $this->cdf($day['variance'], true)];
        }
        $daySection = [
            'key' => 'days', 'title' => t('reports.by_day'), 'empty' => t('reports.no_activity'),
            'columns' => [t('reports.col_date'), [t('reports.col_shifts'), 'num'], [t('reports.col_sold'), 'num'], [t('reports.col_expected'), 'num'], [t('reports.col_cdf'), 'num'],
                          [t('reports.col_usd'), 'num'], [t('reports.col_received'), 'num'], [t('reports.col_variance'), 'num']],
            'rows' => $rows,
            'foot' => $rows === [] ? null : [t('reports.total'), (string) $total['shifts'], $this->l($total['sold']), $this->cdf($total['expected']), $this->cdf($total['cdf']), $this->usd($total['usd']),
                                             $this->cdf($total['cdf'] + $total['usd_cdf']), $this->cdf($total['variance'], true)],
        ];

        $sections = [$daySection, $this->productSection($lines, t('reports.by_product'))];
        if ($this->multi) {
            $sections[] = $this->groupSection($shifts, $money, 'station', t('reports.by_station'), t('fields.station'));
        }
        $sections[] = $this->groupSection($shifts, $money, 'attendant', t('reports.by_attendant'), t('reports.col_attendant'));
        $sections[] = $this->rateSection($r->ratesUsed($ids, $from, $to), t('reports.rates'));
        $sections[] = $this->deliverySection($deliveries, t('reports.deliveries'));
        $sections[] = $this->anomalySection($shifts, $dips, $deliveries, [], t('reports.variances'));
        $sections[] = $this->stockSection($r->stockAt($ids, $stockAt), t('reports.stock', ['at' => format_date($stockAt, true)]));

        return [
            'title' => t('reports.monthly'),
            'period' => format_date($from) . ' – ' . format_date($to),
            'file' => 'rapport-mensuel-' . $month,
            'summary' => $this->summary($lines, $money),
            'sections' => $sections,
        ];
    }

    // --- Par poste ------------------------------------------------------------------------------------

    private function shiftReport(array $shift): array
    {
        $r = $this->repository;
        $ids = [(string) $shift['station_id']];
        $date = (string) $shift['business_date'];
        $id = (string) $shift['id'];
        $lines = $r->saleLines($ids, $date, $date, $id);
        $handovers = $r->handovers($ids, $date, $date, $id);
        $outputs = $r->outputs($ids, $date, $date, $id);
        $credits = $r->creditSales($ids, $date, $date, $id);
        $expenses = $r->expenses($ids, $date, $date, $id);
        $money = $this->shiftMoney([$shift], $lines, $handovers);

        $rows = [];
        $sold = 0;
        $expected = 0;
        foreach ($lines as $line) {
            $sold += (int) Decimal::signed((string) $line['sold_l'], 2);
            $expected += (int) $line['amount_cdf'];
            $rows[] = [(string) $line['sequence'], $line['nozzle'], $line['product'], $this->n($line['opening']), $this->n($line['closing']), $this->lit($line['non_sale_l']),
                       $this->lit($line['sold_l']), $this->money((int) $line['unit_price'], $line['currency']), $this->cdf((int) $line['amount_cdf'])];
        }
        $sections = [[
            'key' => 'readings', 'title' => t('reports.readings'), 'empty' => t('reports.no_reading'),
            'columns' => [t('reports.col_segment'), t('reports.col_nozzle'), t('fields.product'), [t('reports.col_opening'), 'num'], [t('reports.col_closing'), 'num'],
                          [t('reports.col_non_sale'), 'num'], [t('reports.col_sold'), 'num'], [t('reports.col_price'), 'num'], [t('reports.col_amount'), 'num']],
            'rows' => $rows,
            'foot' => $rows === [] ? null : [t('reports.total'), '', '', '', '', '', $this->l($sold), '', $this->cdf($expected)],
        ]];
        $sections[] = $this->rateSection($r->ratesUsed($ids, $date, $date, $id), t('reports.rates'));
        $sections[] = [
            'key' => 'handovers', 'title' => t('reports.handovers'), 'empty' => t('reports.no_handover'),
            'columns' => [t('reports.col_time'), [t('reports.col_amount'), 'num'], [t('reports.col_rate'), 'num'], [t('reports.col_value_cdf'), 'num'], t('reports.col_received_by')],
            'rows' => array_map(fn (array $h): array => [format_date($h['at'], true), $this->money((int) $h['amount'], $h['currency']), $h['rate'] === null ? '' : $this->cdf((int) $h['rate']), $this->cdf((int) $h['amount_cdf']), $h['user']], $handovers),
            'foot' => $handovers === [] ? null : [t('reports.total'), '', '', $this->cdf($money[$id]['cdf'] + $money[$id]['usd_cdf']), ''],
        ];
        $sections[] = [
            'key' => 'outputs', 'title' => t('reports.outputs'), 'empty' => t('common.none'),
            'columns' => [t('reports.col_time'), t('reports.col_nozzle'), t('reports.col_type'), [t('reports.col_volume'), 'num'], t('reports.col_detail'), t('reports.col_user')],
            'rows' => array_map(fn (array $o): array => [format_date($o['at'], true), $o['nozzle'], label('output_type', $o['output_type']), $this->lit($o['volume_l']), $o['reason'], $o['user']], $outputs),
        ];
        $sections[] = [
            'key' => 'credits', 'title' => t('reports.credit_sales'), 'empty' => t('common.none'),
            'columns' => [t('reports.col_time'), t('reports.col_customer'), t('reports.col_voucher'), [t('reports.col_volume'), 'num'], [t('reports.col_amount'), 'num'], t('reports.col_user')],
            'rows' => array_map(fn (array $c): array => [format_date($c['at'], true), $c['customer_name'], (string) $c['voucher_no'], $this->lit($c['volume_l']), $this->money((int) $c['amount'], $c['currency']), $c['user']], $credits),
        ];
        $sections[] = [
            'key' => 'expenses', 'title' => t('reports.expenses'), 'empty' => t('common.none'),
            'columns' => [t('reports.col_time'), t('reports.col_type'), t('reports.col_detail'), [t('reports.col_amount'), 'num'], [t('reports.col_value_cdf'), 'num'], t('reports.col_user')],
            'rows' => array_map(fn (array $e): array => [format_date($e['at'], true), $e['category'], $e['description'], $this->money((int) $e['amount'], $e['currency']), $this->cdf((int) $e['amount_cdf']), $e['user']], $expenses),
        ];
        $sections[] = $this->anomalySection([$shift], [], [], $r->alerts($ids, $date, $date, $id), t('reports.anomalies'));
        $sections[] = $this->operationSection($this->operations([$shift], $handovers, $outputs, $credits, $expenses, [], [], []), t('reports.operations'));

        $summary = $this->summary($lines, $money);
        array_unshift($summary, [t('reports.col_attendant'), $shift['attendant']], [t('fields.status'), label('shift_status', $shift['status'])]);
        if ($shift['status'] === 'validated') {
            $summary[] = [t('reports.col_variance'), $this->cdf((int) $shift['variance_amount'], true)];
        }

        return [
            'title' => t('reports.shift') . ($shift['label'] ? ' — ' . $shift['label'] : ''),
            'period' => format_date($date) . ' · ' . format_date($shift['opened_at'], true) . ' → ' . ($shift['closed_at'] ? format_date($shift['closed_at'], true) : t('reports.still_open')),
            'file' => 'rapport-poste-' . $date,
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    // --- Sections communes ---------------------------------------------------------------------------

    /**
     * Par poste : litres, attendu (ventes relevées) et remises, totalisés en entiers.
     *
     * @return array<string, array{sold: int, expected: int, cdf: int, usd: int, usd_cdf: int}>
     */
    private function shiftMoney(array $shifts, array $lines, array $handovers): array
    {
        $out = [];
        foreach ($shifts as $shift) {
            $out[$shift['id']] = ['sold' => 0, 'expected' => 0, 'cdf' => 0, 'usd' => 0, 'usd_cdf' => 0];
        }
        foreach ($lines as $line) {
            if (isset($out[$line['shift_id']])) {
                $out[$line['shift_id']]['sold'] += (int) Decimal::signed((string) $line['sold_l'], 2);
                $out[$line['shift_id']]['expected'] += (int) $line['amount_cdf'];
            }
        }
        foreach ($handovers as $h) {
            if (!isset($out[$h['shift_id']])) {
                continue;
            }
            if ($h['currency'] === 'USD') {
                $out[$h['shift_id']]['usd'] += (int) $h['amount'];
                $out[$h['shift_id']]['usd_cdf'] += (int) $h['amount_cdf'];
            } else {
                $out[$h['shift_id']]['cdf'] += (int) $h['amount'];
            }
        }

        return $out;
    }

    /** Chiffres clés : litres par produit, attendu, remis en CDF et en USD. */
    private function summary(array $lines, array $money): array
    {
        $products = [];
        foreach ($lines as $line) {
            $products[$line['product']] = ($products[$line['product']] ?? 0) + (int) Decimal::signed((string) $line['sold_l'], 2);
        }
        ksort($products);
        $summary = [];
        foreach ($products as $name => $cl) {
            $summary[] = [t('reports.litres_of', ['product' => $name]), $this->l($cl)];
        }
        $sum = static fn (string $key): int => array_sum(array_column($money, $key));
        $summary[] = [t('reports.col_expected'), $this->cdf($sum('expected'))];
        $summary[] = [t('reports.col_cdf'), $this->cdf($sum('cdf'))];
        $summary[] = [t('reports.col_usd'), $this->usd($sum('usd'))];
        $summary[] = [t('reports.col_received'), $this->cdf($sum('cdf') + $sum('usd_cdf'))];

        return $summary;
    }

    private function productSection(array $lines, string $title): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = ($this->multi ? $line['station_id'] . '|' : '') . $line['product'];
            $groups[$key] ??= ['station_id' => $line['station_id'], 'product' => $line['product'], 'sold' => 0, 'amount' => 0];
            $groups[$key]['sold'] += (int) Decimal::signed((string) $line['sold_l'], 2);
            $groups[$key]['amount'] += (int) $line['amount_cdf'];
        }
        $names = array_column($this->repository->stations()->stations(), 'name', 'id');
        $rows = array_map(fn (array $g): array => $this->withStation($names[$g['station_id']] ?? '', [$g['product'], $this->l($g['sold']), $this->cdf($g['amount'])]), array_values($groups));
        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [
            'key' => 'products', 'title' => $title, 'empty' => t('reports.no_sales'),
            'columns' => $this->withStation(t('fields.station'), [t('fields.product'), [t('reports.col_sold'), 'num'], [t('reports.col_amount'), 'num']]),
            'rows' => $rows,
            'foot' => $rows === [] ? null : $this->withStation('', [t('reports.total'), $this->l(array_sum(array_column($groups, 'sold'))), $this->cdf(array_sum(array_column($groups, 'amount')))]),
        ];
    }

    private function shiftSection(array $shifts, array $money, string $title): array
    {
        $rows = [];
        $total = ['expected' => 0, 'cdf' => 0, 'usd' => 0, 'usd_cdf' => 0, 'variance' => 0];
        foreach ($shifts as $shift) {
            $m = $money[$shift['id']];
            $validated = $shift['status'] === 'validated';
            foreach (['expected', 'cdf', 'usd', 'usd_cdf'] as $key) {
                $total[$key] += $m[$key];
            }
            $total['variance'] += $validated ? (int) $shift['variance_amount'] : 0;
            $rows[] = $this->withStation($shift['station'], [
                $this->shiftLabel($shift), label('shift_status', $shift['status']), $this->cdf($m['expected']), $this->cdf($m['cdf']), $this->usd($m['usd']),
                $this->cdf($m['usd_cdf']), $this->cdf($m['cdf'] + $m['usd_cdf']), $validated ? $this->cdf((int) $shift['variance_amount'], true) : t('reports.not_validated'),
            ]);
        }

        return [
            'key' => 'shifts', 'title' => $title, 'empty' => t('reports.no_shift'), 'note' => t('reports.variance_note'),
            'columns' => $this->withStation(t('fields.station'), [t('reports.col_shift'), t('fields.status'), [t('reports.col_expected'), 'num'], [t('reports.col_cdf'), 'num'], [t('reports.col_usd'), 'num'],
                                                               [t('reports.col_usd_value'), 'num'], [t('reports.col_received'), 'num'], [t('reports.col_variance'), 'num']]),
            'rows' => $rows,
            'foot' => $rows === [] ? null : $this->withStation('', [t('reports.total'), '', $this->cdf($total['expected']), $this->cdf($total['cdf']), $this->usd($total['usd']),
                                                                   $this->cdf($total['usd_cdf']), $this->cdf($total['cdf'] + $total['usd_cdf']), $this->cdf($total['variance'], true)]),
        ];
    }

    /** Regroupement des postes par station ou par pompiste, du plus gros manquant au plus gros excédent. */
    private function groupSection(array $shifts, array $money, string $by, string $title, string $column): array
    {
        $groups = [];
        foreach ($shifts as $shift) {
            $key = $by === 'station' ? $shift['station_id'] : $shift['station_id'] . '|' . $shift['attendant_id'];
            $groups[$key] ??= ['name' => $shift[$by], 'station' => $shift['station'], 'shifts' => 0, 'sold' => 0, 'expected' => 0, 'received' => 0, 'variance' => 0];
            $m = $money[$shift['id']];
            $groups[$key]['shifts']++;
            $groups[$key]['sold'] += $m['sold'];
            $groups[$key]['expected'] += $m['expected'];
            $groups[$key]['received'] += $m['cdf'] + $m['usd_cdf'];
            $groups[$key]['variance'] += $shift['status'] === 'validated' ? (int) $shift['variance_amount'] : 0;
        }
        usort($groups, static fn (array $a, array $b): int => [$a['variance'], $a['name']] <=> [$b['variance'], $b['name']]);
        $withStation = $by === 'attendant' && $this->multi;

        return [
            'key' => 'by-' . $by, 'title' => $title, 'empty' => t('reports.no_shift'),
            'columns' => array_merge([$column], $withStation ? [t('fields.station')] : [], [[t('reports.col_shifts'), 'num'], [t('reports.col_sold'), 'num'], [t('reports.col_expected'), 'num'], [t('reports.col_received'), 'num'], [t('reports.col_variance'), 'num']]),
            'rows' => array_map(fn (array $g): array => array_merge([$g['name']], $withStation ? [$g['station']] : [], [(string) $g['shifts'], $this->l($g['sold']), $this->cdf($g['expected']), $this->cdf($g['received']), $this->cdf($g['variance'], true)]), $groups),
        ];
    }

    private function rateSection(array $rates, string $title): array
    {
        return [
            'key' => 'rates', 'title' => $title, 'empty' => t('reports.no_rate'),
            'columns' => $this->withStation(t('fields.station'), [[t('reports.col_rate'), 'num'], t('reports.col_effective'), [t('reports.col_segments'), 'num'], t('reports.col_published_by')]),
            'rows' => array_map(fn (array $x): array => $this->withStation($x['station'], [$this->cdf((int) $x['rate']), format_date($x['effective_at'], true), (string) $x['segments'], $x['published_by']]), $rates),
        ];
    }

    private function stockSection(array $tanks, string $title): array
    {
        return [
            'key' => 'stock', 'title' => $title, 'empty' => t('common.none'), 'note' => t('reports.stock_note'),
            'columns' => $this->withStation(t('fields.station'), [t('fields.tank'), t('fields.product'), t('reports.col_last_dip'), [t('reports.col_dip_volume'), 'num'], [t('reports.col_delivered'), 'num'],
                                                               [t('reports.col_throughput'), 'num'], [t('reports.col_theoretical'), 'num'], [t('reports.col_capacity'), 'num']]),
            'rows' => array_map(fn (array $k): array => $this->withStation($k['station'], [
                $k['code'], $k['product'], $k['dip_at'] === null ? t('reports.never_dipped') : format_date($k['dip_at'], true), $k['dip_l'] === null ? '' : $this->lit($k['dip_l']),
                $this->lit($k['delivered_l']), $this->lit($k['throughput_l']), $k['theoretical_l'] === null ? '—' : $this->lit($k['theoretical_l']), $this->lit($k['capacity_l']),
            ]), $tanks),
        ];
    }

    private function deliverySection(array $deliveries, string $title): array
    {
        $received = array_sum(array_map(static fn (array $d): int => (int) Decimal::signed((string) $d['received_volume_l'], 2), $deliveries));

        return [
            'key' => 'deliveries', 'title' => $title, 'empty' => t('reports.no_delivery'),
            'columns' => $this->withStation(t('fields.station'), [t('reports.col_time'), t('fields.tank'), t('fields.product'), t('reports.col_supplier'), t('reports.col_note_no'),
                                                               [t('reports.col_announced'), 'num'], [t('reports.col_received_l'), 'num'], [t('reports.col_gap'), 'num'], t('reports.col_received_by')]),
            'rows' => array_map(fn (array $d): array => $this->withStation($d['station'], [
                format_date($d['at'], true), $d['tank'], $d['product'], $d['supplier_name'], (string) $d['delivery_note_no'], $this->lit($d['invoiced_volume_l']), $this->lit($d['received_volume_l']),
                $d['announced_variance_l'] === null ? '' : $this->lit($d['announced_variance_l'], true), $d['user'],
            ]), $deliveries),
            'foot' => $deliveries === [] ? null : $this->withStation('', [t('reports.total'), '', '', '', '', '', $this->l($received), '', '']),
        ];
    }

    /** Écarts de caisse des postes validés, écarts de jaugeage, écarts de livraison, puis alertes levées. */
    private function anomalySection(array $shifts, array $dips, array $deliveries, array $alerts, string $title): array
    {
        $rows = [];
        foreach ($shifts as $shift) {
            if ($shift['status'] === 'validated' && (int) $shift['variance_amount'] !== 0) {
                $rows[] = [$shift['validated_at'], $shift['station'], t('reports.kind_cash'), $this->shiftLabel($shift), $this->cdf((int) $shift['variance_amount'], true), $shift['attendant']];
            }
        }
        foreach ($dips as $dip) {
            if ($dip['variance_l'] !== null && (int) Decimal::signed((string) $dip['variance_l'], 2) !== 0) {
                $detail = $dip['tank'] . ' · ' . $dip['product'] . ' — ' . label('dip_type', $dip['dip_type']) . ($dip['variance_amount'] === null ? '' : ' (' . format_money((int) $dip['variance_amount'], 'CDF') . ')');
                $rows[] = [$dip['at'], $dip['station'], t('reports.kind_stock'), $detail, $this->lit($dip['variance_l'], true), $dip['user']];
            }
        }
        foreach ($deliveries as $d) {
            if ($d['announced_variance_l'] !== null && (int) Decimal::signed((string) $d['announced_variance_l'], 2) !== 0) {
                $rows[] = [$d['at'], $d['station'], t('reports.kind_delivery'), $d['tank'] . ' · ' . $d['supplier_name'], $this->lit($d['announced_variance_l'], true), $d['user']];
            }
        }
        foreach ($alerts as $alert) {
            $rows[] = [$alert['at'], $alert['station'], t('reports.kind_alert', ['severity' => label('severity', $alert['severity'])]), $alert['message'], label('alert_status', $alert['status']), (string) $alert['user']];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));

        return [
            'key' => 'anomalies', 'title' => $title, 'empty' => t('reports.no_anomaly'),
            'columns' => $this->withStation(t('fields.station'), [t('reports.col_time'), t('reports.col_type'), t('reports.col_detail'), [t('reports.col_value'), 'num'], t('reports.col_concerned')]),
            'rows' => array_map(fn (array $row): array => $this->withStation($row[1], [format_date($row[0], true), $row[2], $row[3], $row[4], $row[5]]), $rows),
        ];
    }

    /**
     * Journal des opérations, dans l'ordre : qui a fait quoi, et quand.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> [instant, station, opération, détail, utilisateur]
     */
    private function operations(array $shifts, array $handovers, array $outputs, array $credits, array $expenses, array $deliveries, array $dips, array $publications): array
    {
        $names = array_column($this->repository->stations()->stations(), 'name', 'id');
        $ops = [];
        foreach ($shifts as $s) {
            $label = $this->shiftLabel($s);
            $ops[] = [$s['opened_at'], $s['station'], t('reports.op_shift_open'), $label, $s['opened_by']];
            if ($s['closed_at'] !== null) {
                $ops[] = [$s['closed_at'], $s['station'], t('reports.op_shift_close'), $label, (string) $s['closed_by']];
            }
            if ($s['validated_at'] !== null) {
                $ops[] = [$s['validated_at'], $s['station'], t('reports.op_shift_validate'), $label, (string) $s['validated_by']];
            }
        }
        foreach ($handovers as $h) {
            $ops[] = [$h['at'], $names[$h['station_id']] ?? '', t('reports.op_handover'), format_money((int) $h['amount'], $h['currency']), $h['user']];
        }
        foreach ($outputs as $o) {
            $ops[] = [$o['at'], $names[$o['station_id']] ?? '', t('reports.op_output'), label('output_type', $o['output_type']) . ' · ' . $o['nozzle'] . ' · ' . format_number($o['volume_l'], 2) . ' L', $o['user']];
        }
        foreach ($credits as $c) {
            $ops[] = [$c['at'], $names[$c['station_id']] ?? '', t('reports.op_credit'), $c['customer_name'] . ' · ' . format_money((int) $c['amount'], $c['currency']), $c['user']];
        }
        foreach ($expenses as $e) {
            $ops[] = [$e['at'], $names[$e['station_id']] ?? '', t('reports.op_expense'), $e['description'] . ' · ' . format_money((int) $e['amount'], $e['currency']), $e['user']];
        }
        foreach ($deliveries as $d) {
            $ops[] = [$d['at'], $d['station'], t('reports.op_delivery'), $d['tank'] . ' · ' . $d['supplier_name'] . ' · ' . format_number($d['received_volume_l'], 2) . ' L', $d['user']];
        }
        foreach ($dips as $d) {
            $ops[] = [$d['at'], $d['station'], t('reports.op_dip'), $d['tank'] . ' · ' . label('dip_type', $d['dip_type']) . ' · ' . format_number($d['volume_l'], 2) . ' L', $d['user']];
        }
        foreach ($publications as $p) {
            $ops[] = $p['kind'] === 'price'
                ? [$p['at'], $names[$p['station_id']] ?? '', t('reports.op_price'), $p['product'] . ' · ' . format_money((int) $p['value'], $p['currency']), $p['user']]
                : [$p['at'], $names[$p['station_id']] ?? '', t('reports.op_rate'), '1 $ = ' . format_money((int) $p['value'], 'CDF'), $p['user']];
        }
        usort($ops, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $ops;
    }

    private function operationSection(array $operations, string $title): array
    {
        return [
            'key' => 'operations', 'title' => $title, 'empty' => t('common.none'),
            'columns' => $this->withStation(t('fields.station'), [t('reports.col_time'), t('reports.col_operation'), t('reports.col_detail'), t('reports.col_user')]),
            'rows' => array_map(fn (array $op): array => $this->withStation($op[1], [format_date($op[0], true), $op[2], $op[3], $op[4]]), $operations),
        ];
    }

    // --- Cellules ------------------------------------------------------------------------------------

    /** En consolidé, chaque ligne commence par sa station. */
    private function withStation(mixed $station, array $cells): array
    {
        return $this->multi ? array_merge([$station], $cells) : $cells;
    }

    private function shiftLabel(array $shift, bool $withTime = false): string
    {
        return trim(($withTime ? substr((string) $shift['opened_at'], 11, 5) . ' · ' : '') . $shift['attendant'] . ($shift['label'] ? ' (' . $shift['label'] . ')' : '')
            . ($withTime && $this->multi ? ' · ' . $shift['station'] : ''));
    }

    private function cdf(int $amount, bool $signed = false): array
    {
        return ['t' => ($signed && $amount > 0 ? '+' : '') . format_money($amount, 'CDF'), 'v' => $amount, 'f' => 'CDF'];
    }

    private function usd(int $cents): array
    {
        return ['t' => format_money($cents, 'USD'), 'v' => Decimal::unscaled($cents, 2), 'f' => 'USD'];
    }

    private function money(int $amount, string $currency): array
    {
        return $currency === 'USD' ? $this->usd($amount) : $this->cdf($amount);
    }

    /** Litres à partir de centilitres. */
    private function l(int $cl, bool $signed = false): array
    {
        return $this->lit(Decimal::unscaled($cl, 2), $signed);
    }

    /** Litres à partir d'une valeur DECIMAL de la base. */
    private function lit(mixed $value, bool $signed = false): array
    {
        $cl = Decimal::signed((string) $value, 2) ?? 0;

        return ['t' => ($signed && $cl > 0 ? '+' : '') . format_number(Decimal::unscaled($cl, 2), 2) . ' L', 'v' => Decimal::unscaled($cl, 2), 'f' => 'L'];
    }

    /** Index de compteur. */
    private function n(mixed $value): array
    {
        return ['t' => format_number((string) $value, 2), 'v' => (string) $value, 'f' => 'N'];
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
