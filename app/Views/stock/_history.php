<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre « Historique de la cuve » (fragment). @var array<string, mixed> $tank  @var list<array<string, mixed>> $rows les plus récents d'abord */
use Petra\Domain\Decimal;

// Courbe des écarts de jaugeage, du plus ancien au plus récent (coordonnées entières, en centilitres).
$points = array_values(array_reverse(array_filter($rows, static fn (array $r): bool => $r['variance_l'] !== null)));
$values = array_map(static fn (array $r): int => (int) Decimal::signed((string) $r['variance_l'], 2), $points);
$peak = max(1, max(array_map('abs', $values ?: [0])));
$width = 600;
$coords = [];
foreach ($values as $i => $value) {
    $x = count($values) === 1 ? intdiv($width, 2) : intdiv($i * ($width - 20), count($values) - 1) + 10;
    $coords[] = [$x, 35 - intdiv($value * 28, $peak), $value];
}
?>
<div class="shift-modal">
    <div class="shift-modal__head">
        <h2><?= e(t('stock.history_title', ['tank' => $tank['code']])) ?></h2>
        <div class="shift-modal__meta"><?= e($tank['station'] . ' · ' . $tank['product']) ?><?= $rows === [] ? '' : ' · ' . e(t('stock.history_hint', ['count' => count($rows)])) ?></div>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state empty-state--illus"><?= illustration('stock', 'md') ?><p><?= e(t('stock.history_none')) ?></p></div>
    <?php else: ?>
        <?php if (count($coords) > 1): ?>
            <svg class="spark" viewBox="0 0 <?= e($width) ?> 70" preserveAspectRatio="none" role="img" aria-label="<?= e(t('stock.col_variance')) ?>">
                <line x1="0" y1="35" x2="<?= e($width) ?>" y2="35" stroke="#C4CAD2" stroke-width="1" stroke-dasharray="4 4"/>
                <polyline fill="none" stroke="#1F4E79" stroke-width="2" vector-effect="non-scaling-stroke" points="<?= e(implode(' ', array_map(static fn (array $c): string => $c[0] . ',' . $c[1], $coords))) ?>"/>
                <?php foreach ($coords as [$x, $y, $value]): ?>
                    <circle cx="<?= e($x) ?>" cy="<?= e($y) ?>" r="3" fill="<?= $value < 0 ? '#B42318' : '#1E7A4C' ?>"/>
                <?php endforeach; ?>
            </svg>
        <?php endif; ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= e(t('reports.col_date')) ?></th><th><?= e(t('fields.dip_type')) ?></th><th class="num"><?= e(t('stock.col_depth')) ?></th>
                <th class="num"><?= e(t('stock.col_measured')) ?></th><th class="num"><?= e(t('stock.col_theoretical')) ?></th><th class="num"><?= e(t('stock.col_variance')) ?></th><th><?= e(t('stock.col_author')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $negative = $row['variance_l'] !== null && str_starts_with((string) $row['variance_l'], '-'); ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($row['measured_at'], true)) ?></td>
                    <td><?= e(label('dip_type', $row['dip_type'])) ?></td>
                    <td class="num"><?= e(format_number($row['depth_cm'], 1)) ?> cm</td>
                    <td class="num"><?= e(format_number($row['volume_l'], 2)) ?> L</td>
                    <td class="num"><?= $row['theoretical_l'] === null ? '—' : e(format_number($row['theoretical_l'], 2)) . ' L' ?></td>
                    <td class="num<?= $negative ? ' overdue' : '' ?>"><?= $row['variance_l'] === null ? '—' : e(format_number($row['variance_l'], 2)) . ' L' ?></td>
                    <td><?= e($row['author']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <div class="shift-form__actions">
        <button type="button" class="btn" data-modal-url="<?= e(url('/stock/tanks/' . $tank['id'] . '/detail')) ?>"><i data-lucide="arrow-left"></i><?= e(t('stock.more_action')) ?></button>
        <button type="button" class="btn btn--primary" data-modal-close><?= e(t('common.close')) ?></button>
    </div>
</div>
