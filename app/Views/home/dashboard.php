<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php
/**
 * Tableau de bord : l'état du jour, ce qui est à faire, quatre chiffres, la chaîne de contrôle, deux graphiques
 * et les cuves. Chaque bloc ouvre son détail en tableau compact dans une fenêtre.
 *
 * @var \Petra\Domain\Period $period
 * @var list<array<string, mixed>> $stations  @var string $selected  @var bool $consolidated  @var ?string $stationName
 * @var array<string, int> $totals  @var ?array{rate: int, effective_at: string} $rate
 * @var list<array<string, mixed>> $comparison  @var list<array<string, mixed>> $products  @var list<array<string, mixed>> $days
 * @var list<array<string, mixed>> $tanks  @var array<string, mixed> $charts
 * @var array<string, mixed> $previous  @var array<string, ?int> $deltas  @var array<string, list<int>> $trend
 * @var list<array<string, mixed>> $todos  @var int $todoCount
 */
use Petra\Domain\Decimal;

$litres = static fn (int $cl, int $decimals = 0): string => format_number(Decimal::unscaled($cl, 2), $decimals) . ' L';
$signed = static fn (int $amount): string => ($amount > 0 ? '+' : '') . format_money($amount, 'CDF');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekAgo = date('Y-m-d', strtotime('-6 days'));
$range = $period->from === $period->to ? format_date($period->from) : format_date($period->from) . ' – ' . format_date($period->to);
// Filtres courants, repris par chaque lien et chaque fenêtre de détail.
$periodQuery = match (true) {
    $period->type === 'day' => [],
    $period->type === 'custom' => ['period' => 'custom', 'from' => $period->from, 'to' => $period->to],
    default => ['period' => $period->type],
};
$stationQuery = count($stations) > 1 ? ['station' => $selected] : [];
$link = static fn (array $periodPart, ?array $stationPart = null): string => url('/', $periodPart + ($stationPart ?? $stationQuery));
$detail = static fn (string $kind): string => url('/dashboard/detail', ['kind' => $kind] + $periodQuery + $stationQuery);
$shortcuts = [
    [t('period.day'), [], $period->type === 'day'],
    [t('dashboard.period_yesterday'), ['period' => 'custom', 'from' => $yesterday, 'to' => $yesterday], $period->from === $yesterday && $period->to === $yesterday],
    [t('dashboard.period_7days'), ['period' => 'custom', 'from' => $weekAgo, 'to' => $today], $period->from === $weekAgo && $period->to === $today],
    [t('period.month'), ['period' => 'month'], $period->type === 'month'],
];
$isCustom = !array_filter(array_column($shortcuts, 2));
// Évolution par rapport à la période précédente de même durée.
$trendChip = static function (?int $percent): string {
    if ($percent === null) {
        return '<span class="trend trend--flat">' . e(t('dashboard.no_previous')) . '</span>';
    }
    $tone = $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat');
    $icon = $percent > 0 ? 'trending-up' : ($percent < 0 ? 'trending-down' : 'minus');

    return '<span class="trend trend--' . $tone . '"><i data-lucide="' . $icon . '"></i>' . e(($percent > 0 ? '+' : '') . $percent . ' %') . '</span>';
};
$stockedCl = 0;
foreach ($tanks as $tank) {
    $stockedCl += $tank['volume_l'] === null ? 0 : (int) Decimal::scaled((string) $tank['volume_l'], 2);
}
$attention = $todoCount > 0;
?>
<div data-live>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="layout-dashboard"></i></span>
        <div>
            <h1><?= e($consolidated ? t('dashboard.title_consolidated') : t('dashboard.title_station', ['station' => (string) $stationName])) ?></h1>
            <p class="muted small" data-dashboard-range><?= e($range) ?><?php if ($rate !== null): ?> · <span data-kpi="rate"><?= e('1 $ = ' . format_money($rate['rate'], 'CDF')) ?></span><?php endif; ?></p>
        </div>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn" data-modal-url="<?= e($detail('alerts')) ?>" data-modal-width="1000"><i data-lucide="siren"></i><?= e(t('dashboard.alerts_button')) ?><?php if ($totals['alerts_open'] > 0): ?><span class="chip__alert"><?= e($totals['alerts_open']) ?></span><?php endif; ?></button>
        <button type="button" class="btn" data-modal-url="<?= e($detail('deliveries')) ?>" data-modal-width="900"><i data-lucide="truck"></i><?= e(t('dashboard.recent_deliveries')) ?></button>
        <button type="button" class="btn" data-modal-url="<?= e($detail('attendants')) ?>" data-modal-width="860"><i data-lucide="users"></i><?= e(t('dashboard.ranking_attendants_short')) ?></button>
    </div>
</div>

<div class="dash-filters">
    <nav class="period__shortcuts" aria-label="<?= e(t('dashboard.period')) ?>">
        <?php foreach ($shortcuts as [$label, $query, $active]): ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>" href="<?= e($link($query)) ?>" data-day-link><?= e($label) ?></a>
        <?php endforeach; ?>
        <details class="period-custom<?= $isCustom ? ' is-active' : '' ?>">
            <summary class="chip<?= $isCustom ? ' is-active' : '' ?>"><i data-lucide="calendar"></i><?= e($isCustom ? $range : t('period.custom')) ?></summary>
            <form method="get" action="<?= e(url('/')) ?>" class="period-custom__form">
                <input type="hidden" name="period" value="custom">
                <?php if (count($stations) > 1): ?><input type="hidden" name="station" value="<?= e($selected) ?>"><?php endif; ?>
                <label><?= e(t('common.from')) ?><input type="date" name="from" class="input" value="<?= e($period->from) ?>" max="<?= e($today) ?>" required></label>
                <label><?= e(t('common.to')) ?><input type="date" name="to" class="input" value="<?= e($period->to) ?>" max="<?= e($today) ?>" required></label>
                <button type="submit" class="btn btn--primary"><?= e(t('dashboard.apply')) ?></button>
            </form>
        </details>
    </nav>
    <?php if (count($stations) > 1): ?>
        <nav class="station-filter" aria-label="<?= e(t('fields.station')) ?>" data-station-filter>
            <a class="chip<?= $selected === 'all' ? ' is-active' : '' ?>" href="<?= e($link($periodQuery, ['station' => 'all'])) ?>" data-day-link><?= e(t('common.all_stations')) ?></a>
            <?php foreach ($stations as $station): ?>
                <a class="chip<?= $selected === $station['id'] ? ' is-active' : '' ?>" href="<?= e($link($periodQuery, ['station' => $station['id']])) ?>" data-day-link data-station="<?= e($station['id']) ?>"><?= e($station['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>

<?php /* --- 1. État du jour, et ce qui est à faire ------------------------------------------------------- */ ?>
<section class="dash-status dash-status--<?= $attention ? 'attention' : 'ok' ?>" data-status="<?= $attention ? 'attention' : 'ok' ?>">
    <div class="dash-status__art"><?= illustration($attention ? 'attention' : 'all-clear', 'sm') ?></div>
    <div class="dash-status__text">
        <h2><?= e($attention ? t('dashboard.status_attention', ['count' => $todoCount]) : t('dashboard.status_ok')) ?></h2>
        <p><?= e($attention ? t('dashboard.status_attention_hint') : t('dashboard.status_ok_hint')) ?></p>
    </div>
    <?php if ($attention): ?>
        <div class="dash-todos">
            <?php foreach ($todos as $todo): ?>
                <a class="dash-todo dash-todo--<?= e($todo['tone']) ?>" href="<?= e(url($todo['url'])) ?>" data-todo="<?= e($todo['kind']) ?>"<?= isset($todo['modal']) ? ' data-modal-url="' . e($detail($todo['modal'])) . '" data-modal-width="1000"' : '' ?>>
                    <span class="dash-todo__icon"><i data-lucide="<?= e($todo['icon']) ?>"></i></span>
                    <span class="dash-todo__body">
                        <strong><?= e(str_replace(':count', (string) $todo['count'], label('dashboard_todo', $todo['kind']))) ?></strong>
                        <span><?= e(($consolidated ? $todo['station'] . ' · ' : '') . $todo['text']) ?></span>
                    </span>
                    <i data-lucide="chevron-right"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* --- 2. Quatre chiffres clés ---------------------------------------------------------------------------- */ ?>
<div class="dash-kpis">
    <button type="button" class="dash-kpi" data-modal-url="<?= e($detail('sales')) ?>" data-modal-width="760">
        <span class="dash-kpi__label"><?= e($period->type === 'day' ? t('dashboard.revenue_day') : t('dashboard.revenue')) ?></span>
        <strong class="dash-kpi__value" data-kpi="revenue"><?= e(format_money($totals['revenue_cdf'], 'CDF')) ?></strong>
        <span class="dash-kpi__foot"><?= $trendChip($deltas['revenue']) ?><?= sparkline($trend['revenue']) ?></span>
    </button>
    <button type="button" class="dash-kpi" data-modal-url="<?= e($detail('sales')) ?>" data-modal-width="760">
        <span class="dash-kpi__label"><?= e(t('dashboard.litres')) ?></span>
        <strong class="dash-kpi__value" data-kpi="litres"><?= e($litres($totals['sold_cl'])) ?></strong>
        <span class="dash-kpi__foot"><?= $trendChip($deltas['sold']) ?><?= sparkline($trend['sold']) ?></span>
    </button>
    <button type="button" class="dash-kpi" data-modal-url="<?= e($detail('receipts')) ?>" data-modal-width="760">
        <span class="dash-kpi__label"><?= e(t('dashboard.cashed')) ?></span>
        <strong class="dash-kpi__value" data-kpi="receipts"><?= e(format_money($totals['receipts_total_cdf'], 'CDF')) ?></strong>
        <span class="dash-kpi__hint"><?= e(t('dashboard.of_which_usd')) ?> <span data-kpi="receipts-usd"><?= e(format_money($totals['receipts_usd'], 'USD')) ?></span></span>
        <span class="dash-kpi__foot"><?= $trendChip($deltas['received']) ?><?= sparkline($trend['received']) ?></span>
    </button>
    <button type="button" class="dash-kpi<?= $totals['cash_variance'] < 0 ? ' dash-kpi--bad' : '' ?>" data-modal-url="<?= e($detail('variance')) ?>" data-modal-width="900">
        <span class="dash-kpi__label"><?= e(t('dashboard.cash_variance')) ?></span>
        <strong class="dash-kpi__value" data-kpi="cash-variance"><?= e($signed($totals['cash_variance'])) ?></strong>
        <span class="dash-kpi__hint"><?= e(t('dashboard.shifts_hint', ['validated' => $totals['shifts_validated'], 'closed' => $totals['shifts_closed'], 'open' => $totals['shifts_open']])) ?></span>
        <span class="dash-kpi__foot"><span class="trend trend--flat"><?= e(t('dashboard.previous_value', ['value' => $signed((int) $previous['cash_variance'])])) ?></span><?= sparkline($trend['variance'], $totals['cash_variance'] < 0 ? '#B42318' : '#1E7A4C') ?></span>
    </button>
</div>

<?php /* --- 3. La chaîne de contrôle, illustrée -------------------------------------------------------------------- */ ?>
<?php
$chain = [
    ['deliveries', t('dashboard.chain_received'), $litres($totals['received_cl']), t('dashboard.chain_received_hint', ['count' => $totals['deliveries']]), ['modal', $detail('deliveries'), 900]],
    ['stock', t('dashboard.chain_stored'), $litres($stockedCl), t('dashboard.chain_stored_hint'), ['link', url('/stock')]],
    ['shift-open', t('dashboard.chain_sold'), $litres($totals['sold_cl']), format_money($totals['revenue_cdf'], 'CDF'), ['modal', $detail('sales'), 760]],
    ['shift-cash', t('dashboard.chain_cashed'), format_money($totals['receipts_total_cdf'], 'CDF'), t('dashboard.chain_cashed_hint'), ['modal', $detail('receipts'), 760]],
];
$gaps = [
    [$totals['delivery_variance_cl'], ($totals['delivery_variance_cl'] > 0 ? '+' : '') . $litres($totals['delivery_variance_cl']), t('dashboard.gap_delivery')],
    [$totals['stock_variance_cl'], ($totals['stock_variance_cl'] > 0 ? '+' : '') . $litres($totals['stock_variance_cl']), t('dashboard.gap_stock')],
    [$totals['cash_variance'], $signed($totals['cash_variance']), t('dashboard.gap_cash')],
];
?>
<section class="card chain" aria-label="<?= e(t('dashboard.chain_title')) ?>">
    <div class="card__title"><h2><?= e(t('dashboard.chain_title')) ?></h2><span class="muted small"><?= e(t('dashboard.chain_hint')) ?></span></div>
    <div class="chain__row">
        <?php foreach ($chain as $i => [$picture, $label, $value, $hint, $target]): ?>
            <?php if ($target[0] === 'modal'): ?>
                <button type="button" class="chain__step" data-modal-url="<?= e($target[1]) ?>" data-modal-width="<?= e($target[2]) ?>">
            <?php else: ?>
                <a class="chain__step" href="<?= e($target[1]) ?>">
            <?php endif; ?>
                <?= illustration($picture, 'sm') ?>
                <span class="chain__label"><?= e($label) ?></span>
                <strong data-chain="<?= e($i) ?>"><?= e($value) ?></strong>
                <small><?= e($hint) ?></small>
            <?= $target[0] === 'modal' ? '</button>' : '</a>' ?>
            <?php if (isset($gaps[$i])): ?>
                <div class="chain__gap<?= $gaps[$i][0] < 0 ? ' chain__gap--bad' : '' ?>" title="<?= e($gaps[$i][2]) ?>">
                    <span class="chain__pipe"></span>
                    <span><?= e($gaps[$i][2]) ?></span>
                    <strong><?= e($gaps[$i][1]) ?></strong>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>

<?php /* --- 4. Stations (propriétaire) --------------------------------------------------------------------------------- */ ?>
<?php if ($consolidated): ?>
<section class="card" id="comparison">
    <div class="card__title">
        <h2><?= e(t('dashboard.stations_title')) ?></h2>
        <button type="button" class="btn btn--ghost" data-modal-url="<?= e($detail('stations')) ?>" data-modal-width="1040"><i data-lucide="table"></i><?= e(t('dashboard.comparison')) ?></button>
    </div>
    <div class="station-cards">
        <?php foreach ($comparison as $i => $row): ?>
            <?php $syncOld = $row['last_sync_at'] !== null && strtotime((string) $row['last_sync_at']) < time() - 86400; ?>
            <a class="station-card<?= $row['variance_cdf'] < 0 ? ' station-card--bad' : '' ?>" href="<?= e($link($periodQuery, ['station' => $row['station_id']])) ?>" data-day-link data-station="<?= e($row['station_id']) ?>">
                <?= illustration('station', 'sm') ?>
                <span class="station-card__body">
                    <strong><?= e($row['station']) ?><?php if ($i === 0 && $row['revenue_cdf'] > 0): ?> <span class="badge badge--success"><?= e(t('dashboard.best_station')) ?></span><?php endif; ?></strong>
                    <span class="station-card__value"><?= e(format_money($row['revenue_cdf'], 'CDF')) ?></span>
                    <span class="station-card__meta">
                        <span class="<?= $row['cash_variance'] < 0 ? 'overdue' : '' ?>"><?= e(t('dashboard.gap_cash')) ?> : <?= e($signed($row['cash_variance'])) ?></span>
                        <?php if ($row['alerts_open'] > 0): ?><span class="overdue"><i data-lucide="siren"></i><?= e($row['alerts_open']) ?></span><?php endif; ?>
                        <span class="<?= $syncOld ? 'overdue' : 'muted' ?>"><i data-lucide="<?= $row['last_sync_at'] === null ? 'cloud-off' : 'cloud-check' ?>"></i><?= e($row['last_sync_at'] === null ? t('dashboard.never_synced') : format_date($row['last_sync_at'], true)) ?></span>
                    </span>
                </span>
                <i data-lucide="chevron-right"></i>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php /* --- 5. Deux graphiques ------------------------------------------------------------------------------------------------ */ ?>
<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title"><h2><?= e(t('dashboard.sales_vs_cash')) ?></h2>
            <button type="button" class="btn btn--ghost" data-modal-url="<?= e($detail('sales')) ?>" data-modal-width="760"><i data-lucide="table"></i><?= e(t('dashboard.details')) ?></button></div>
        <?php if (count($days) < 2): ?>
            <div class="empty-state"><i data-lucide="bar-chart-3"></i><p><?= e(t('dashboard.trend_hint')) ?></p></div>
        <?php else: ?>
            <div class="chart-box"><canvas id="chart-days" role="img" aria-label="<?= e(t('dashboard.sales_vs_cash')) ?>"></canvas></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('dashboard.by_product_title')) ?></h2></div>
        <?php if ($products === []): ?>
            <div class="empty-state"><i data-lucide="fuel"></i><p><?= e(t('dashboard.no_sales')) ?></p></div>
        <?php else: ?>
            <div class="chart-box chart-box--donut"><canvas id="chart-products" role="img" aria-label="<?= e(t('dashboard.by_product_title')) ?>"></canvas></div>
            <ul class="legend">
                <?php foreach ($products as $i => $product): ?>
                    <li><span class="legend__dot legend__dot--<?= e($i % 5) ?>"></span><span><?= e($product['product']) ?></span><strong><?= e(format_number($product['sold_l'], 0)) ?> L</strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php /* --- 6. Les cuves, en miniature --------------------------------------------------------------------------------------------- */ ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('dashboard.tank_stock')) ?></h2><a class="btn btn--ghost" href="<?= e(url('/stock')) ?>"><?= e(t('dashboard.see_all')) ?></a></div>
    <?php if ($tanks === []): ?>
        <p class="muted"><?= e(t('common.none')) ?></p>
    <?php else: ?>
        <div class="tank-minis">
            <?php foreach ($tanks as $tank): ?>
                <?php $capCl = (int) Decimal::scaled((string) $tank['capacity_l'], 2); $lowCl = (int) Decimal::scaled((string) $tank['low_stock_l'], 2); ?>
                <button type="button" class="tank-mini<?= $tank['is_low'] ? ' tank-mini--low' : '' ?>" data-modal-url="<?= e(url('/stock/tanks/' . $tank['id'] . '/detail')) ?>" data-tank="<?= e($tank['code']) ?>">
                    <?= fuel_tank($tank['fill_permille'] === null ? null : intdiv((int) $tank['fill_permille'], 10), $capCl > 0 ? intdiv($lowCl * 100, $capCl) : null, (bool) $tank['is_low'], $tank['code']) ?>
                    <span class="tank-mini__text">
                        <strong><?= e($tank['product']) ?></strong>
                        <span><?= e(($consolidated ? $tank['station'] . ' · ' : '') . $tank['code']) ?> · <?= $tank['volume_l'] === null ? e(t('dashboard.never_dipped')) : e(format_number($tank['volume_l'], 0)) . ' L' ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script type="application/json" id="dashboard-data"><?= json_for_html($charts + ['labels' => [
    'revenue' => t('dashboard.revenue'), 'litres' => t('dashboard.litres'), 'received' => t('dashboard.cashed'), 'variance' => t('dashboard.total_variance'),
]]) ?></script>
</div>
