<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre « Détail du stock » d'une cuve : d'où vient le stock théorique (fragment). @var array<string, mixed> $tank */
use Petra\Domain\Decimal;

$n2 = static fn (?string $value): string => $value === null ? '—' : format_number($value, 2);
$last = $tank['last_dip'];
$capacityCl = (int) Decimal::scaled((string) $tank['capacity_l'], 2);
$lowCl = (int) Decimal::scaled((string) $tank['low_stock_l'], 2);
?>
<div class="shift-modal">
    <div class="shift-modal__head">
        <h2><?= e(t('stock.detail_title', ['tank' => $tank['code']])) ?></h2>
        <?php if ($tank['is_low']): ?><span class="badge badge--danger"><?= e(t('stock.low')) ?></span><?php endif; ?>
        <div class="shift-modal__meta"><?= e($tank['station'] . ' · ' . $tank['product']) ?></div>
    </div>
    <?php if ($last === null): ?>
        <div class="empty-state empty-state--illus"><?= illustration('stock', 'md') ?><p><?= e(t((int) $tank['calibration_count'] === 0 ? 'stock.needs_calibration' : 'stock.needs_first_dip')) ?></p></div>
    <?php else: ?>
        <div class="tank-card__visual">
            <div class="stock-detail__art"><?= fuel_tank((int) $tank['fill_percent'], $capacityCl > 0 ? intdiv($lowCl * 100, $capacityCl) : null, (bool) $tank['is_low'], $tank['fill_percent'] . ' %') ?></div>
            <div class="tank-card__figures">
                <div class="tank-card__level">
                    <strong><?= e($n2($tank['theoretical_l'])) ?> L</strong>
                    <span class="muted"><?= e(t('stock.of_capacity', ['capacity' => format_number($tank['capacity_l'], 0), 'percent' => $tank['fill_percent']])) ?></span>
                </div>
                <p class="muted small tank-card__legend"><span class="tankv__key"></span><?= e(t('stock.low_mark', ['litres' => format_number($tank['low_stock_l'], 0)])) ?></p>
            </div>
        </div>
        <dl class="stock-lines">
            <dt><?= e(t('stock.initial', ['date' => format_date($last['measured_at'], true)])) ?></dt><dd><?= e($n2($tank['initial_l'])) ?></dd>
            <dt><?= e(t('stock.deliveries')) ?></dt><dd>+ <?= e($n2($tank['deliveries_l'])) ?></dd>
            <dt><?= e(t('shifts.sold')) ?></dt><dd>− <?= e($n2($tank['sold_l'])) ?></dd>
            <dt><?= e(t('shifts.non_sale')) ?></dt><dd>− <?= e($n2($tank['non_sale_l'])) ?></dd>
            <?php if ($tank['tank_return_l'] !== '0.00'): ?><dt><?= e(t('stock.tank_return')) ?></dt><dd>+ <?= e($n2($tank['tank_return_l'])) ?></dd><?php endif; ?>
            <dt class="is-total"><?= e(t('stock.theoretical')) ?></dt><dd class="is-total"><?= e($n2($tank['theoretical_l'])) ?> L</dd>
        </dl>
        <?php if ($tank['unread_sales']): ?><p class="stock-card__note"><i data-lucide="gauge"></i><?= e(t('stock.unread_sales')) ?></p><?php endif; ?>
        <div class="shift-modal__figures" style="margin-top: var(--space-3)">
            <div><span><?= e(t('stock.card_dip')) ?></span><strong><?= e(format_date($last['measured_at'], true)) ?></strong></div>
            <div><span><?= e(t('stock.autonomy_card')) ?></span><strong><?= e($tank['autonomy_days'] === null ? t('stock.autonomy_unknown') : t('stock.autonomy_days', ['days' => $tank['autonomy_days']])) ?></strong></div>
            <div><span><?= e(t('stock.last_variance')) ?></span>
                <strong class="<?= $last['variance_l'] !== null && str_starts_with((string) $last['variance_l'], '-') ? 'overdue' : '' ?>"><?= $last['variance_l'] === null ? '—' : e($n2((string) $last['variance_l'])) . ' L' ?></strong></div>
            <?php if ($last['variance_amount'] !== null): ?>
                <div><span><?= e(t('fields.variance_amount')) ?></span><strong><?= e(format_money((int) $last['variance_amount'], 'CDF')) ?></strong></div>
            <?php endif; ?>
        </div>
        <p class="muted small"><?= e(t('stock.autonomy_hint', ['litres' => format_number($tank['week_l'], 0)])) ?></p>
    <?php endif; ?>
    <div class="shift-form__actions">
        <button type="button" class="btn" data-modal-url="<?= e(url('/stock/tanks/' . $tank['id'] . '/history')) ?>" data-modal-width="820"><i data-lucide="history"></i><?= e(t('stock.history_action')) ?></button>
        <button type="button" class="btn btn--primary" data-modal-close><?= e(t('common.close')) ?></button>
    </div>
</div>
