<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var list<array<string, mixed>> $tanks  @var array{stock_cl: int, capacity_cl: int, low: int, autonomy_days: ?int} $totals
 * @var bool $canRecord  @var bool $canDeliver
 */
use Petra\Domain\Decimal;

$n2 = static fn (?string $value): string => $value === null ? '—' : format_number($value, 2);
$litres = static fn (int $cl): string => format_number(Decimal::unscaled($cl, 2), 0) . ' L';
/** @var list<array{id: string, name: string, tanks: int, low: int}> $stations  @var ?string $selected  @var int $allCount  @var int $allLow */
$several = count($stations) > 1 && $selected === null;
?>
<div data-live>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="cylinder"></i></span>
        <h1><?= e(t('nav.stock')) ?></h1>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn"<?= drill('dips', '/stock/dips/data', t('stock.history')) ?>><i data-lucide="history"></i><?= e(t('stock.history_all')) ?></button>
        <?php if ($canDeliver): ?>
            <a class="btn btn--primary btn--lg" href="<?= e(url('/deliveries/create')) ?>"><i data-lucide="truck"></i><?= e(t('stock.deliver_action')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (count($stations) > 1): ?>
    <nav class="station-filter" aria-label="<?= e(t('stock.filter_station')) ?>">
        <a class="chip<?= $selected === null ? ' is-active' : '' ?>" href="<?= e(url('/stock')) ?>" data-day-link<?= $selected === null ? ' aria-current="true"' : '' ?>>
            <?= e(t('common.all_stations')) ?><span class="chip__count"><?= e($allCount) ?></span><?php if ($allLow > 0): ?><span class="chip__alert" title="<?= e(t('stock.low_count')) ?>"><?= e($allLow) ?></span><?php endif; ?>
        </a>
        <?php foreach ($stations as $item): ?>
            <a class="chip<?= $selected === $item['id'] ? ' is-active' : '' ?>" href="<?= e(url('/stock', ['station' => $item['id']])) ?>" data-day-link<?= $selected === $item['id'] ? ' aria-current="true"' : '' ?>>
                <?= e($item['name']) ?><span class="chip__count"><?= e($item['tanks']) ?></span><?php if ($item['low'] > 0): ?><span class="chip__alert" title="<?= e(t('stock.low_count')) ?>"><?= e($item['low']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($tanks === []): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('stock') ?><p><?= e(t('stock.no_tank')) ?></p></div></div>
<?php else: ?>
<div class="daybar">
    <div class="daybar__figures" style="margin-left: 0">
        <div style="text-align: left"><span><?= e(t('stock.total')) ?></span><strong data-stock="total"><?= e($litres($totals['stock_cl'])) ?></strong>
            <span><?= e(t('stock.total_hint', ['capacity' => format_number(Decimal::unscaled($totals['capacity_cl'], 2), 0)])) ?></span></div>
        <div style="text-align: left"><span><?= e(t('stock.autonomy')) ?></span>
            <strong data-stock="autonomy"><?= e($totals['autonomy_days'] === null ? t('stock.autonomy_unknown') : t('stock.autonomy_days', ['days' => $totals['autonomy_days']])) ?></strong></div>
        <div style="text-align: left"><span><?= e(t('stock.low_count')) ?></span>
            <strong data-stock="low" class="<?= $totals['low'] > 0 ? 'overdue' : '' ?>"><?= e($totals['low'] > 0 ? $totals['low'] : t('stock.low_none')) ?></strong></div>
    </div>
</div>

<div class="stock-cards">
    <?php foreach ($tanks as $tank): ?>
        <?php
        $last = $tank['last_dip'];
        $capacityCl = (int) Decimal::scaled((string) $tank['capacity_l'], 2);
        $lowCl = (int) Decimal::scaled((string) $tank['low_stock_l'], 2);
        $detail = url('/stock/tanks/' . $tank['id'] . '/detail');
        ?>
        <article class="shift-card stock-card<?= $tank['is_low'] ? ' stock-card--low' : '' ?>" data-tank="<?= e($tank['code']) ?>">
            <button type="button" class="shift-card__open" data-modal-url="<?= e($detail) ?>" aria-label="<?= e(t('stock.more_action') . ' — ' . $tank['code']) ?>"></button>
            <div class="stock-card__art">
                <?= fuel_tank($last === null ? null : (int) $tank['fill_percent'], $capacityCl > 0 ? intdiv($lowCl * 100, $capacityCl) : null, (bool) $tank['is_low'], $tank['code'] . ' : ' . ($last === null ? t('stock.never_dipped') : $tank['fill_percent'] . ' %')) ?>
                <?php if ($tank['is_low']): ?><span class="shift-card__state badge badge--danger"><?= e(t('stock.low')) ?></span><?php endif; ?>
            </div>
            <div class="shift-card__body">
                <div class="stock-card__summary">
                    <div>
                        <h3><?= e($tank['product']) ?></h3>
                        <span class="muted small"><?= e($tank['code'] . ($several ? ' · ' . $tank['station'] : '')) ?></span>
                    </div>
                    <div class="stock-card__volume">
                        <?php if ($last === null): ?>
                            <strong>—</strong><span><?= e(t('stock.never_dipped')) ?></span>
                        <?php else: ?>
                            <strong><?= e(format_number($tank['theoretical_l'], 0)) ?> L</strong>
                            <span><?= e($tank['autonomy_days'] === null ? t('stock.of_capacity_short', ['capacity' => format_number($tank['capacity_l'], 0)]) : t('stock.autonomy_line', ['days' => $tank['autonomy_days']])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="shift-card__foot">
                    <?php if ($canRecord && (int) $tank['calibration_count'] > 0): ?>
                        <button type="button" class="btn btn--primary" data-modal-url="<?= e(url('/stock/tanks/' . $tank['id'] . '/form')) ?>" data-modal-width="640"><i data-lucide="ruler"></i><?= e(t('stock.dip_action')) ?></button>
                    <?php endif; ?>
                    <button type="button" class="btn" data-modal-url="<?= e($detail) ?>"><i data-lucide="list"></i><?= e(t('stock.more_action')) ?></button>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
