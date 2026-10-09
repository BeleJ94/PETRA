<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre « Historique de ce poste » (fragment). @var list<array<string, mixed>> $rows les plus récents d'abord  @var string $label */
$tones = ['open' => 'info', 'closed' => 'warning', 'validated' => 'success'];
// Courbe des écarts de caisse des postes validés, du plus ancien au plus récent (coordonnées entières).
$points = array_values(array_reverse(array_filter($rows, static fn (array $r): bool => $r['status'] === 'validated')));
$values = array_map(static fn (array $r): int => (int) $r['variance_amount'], $points);
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
        <h2><?= e(t('shifts.history_slot_title', ['label' => $label])) ?></h2>
        <?php if ($rows !== []): ?><div class="shift-modal__meta"><?= e(t('shifts.history_hint', ['count' => count($rows)])) ?></div><?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state empty-state--illus"><?= illustration('shift-idle', 'md') ?><p><?= e(t('shifts.history_none')) ?></p></div>
    <?php else: ?>
        <?php if (count($coords) > 1): ?>
            <svg class="spark" viewBox="0 0 <?= e($width) ?> 70" preserveAspectRatio="none" role="img" aria-label="<?= e(t('shifts.card_variance')) ?>">
                <line x1="0" y1="35" x2="<?= e($width) ?>" y2="35" stroke="#C4CAD2" stroke-width="1" stroke-dasharray="4 4"/>
                <polyline fill="none" stroke="#1F4E79" stroke-width="2" vector-effect="non-scaling-stroke" points="<?= e(implode(' ', array_map(static fn (array $c): string => $c[0] . ',' . $c[1], $coords))) ?>"/>
                <?php foreach ($coords as [$x, $y, $value]): ?>
                    <circle cx="<?= e($x) ?>" cy="<?= e($y) ?>" r="3" fill="<?= $value < 0 ? '#B42318' : '#1E7A4C' ?>"/>
                <?php endforeach; ?>
            </svg>
        <?php endif; ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= e(t('reports.col_date')) ?></th><th><?= e(t('reports.col_attendant')) ?></th><th><?= e(t('fields.status')) ?></th>
                <th class="num"><?= e(t('reports.col_expected')) ?></th><th class="num"><?= e(t('reports.col_received')) ?></th><th class="num"><?= e(t('reports.col_variance')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $variance = $row['status'] === 'validated' ? (int) $row['variance_amount'] : null; ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('/shifts/' . $row['id'])) ?>"><?= e(format_date($row['business_date'])) ?></a></td>
                    <td><?= e($row['attendant']) ?></td>
                    <td><span class="badge badge--<?= e($tones[$row['status']] ?? 'neutral') ?>"><?= e(label('shift_status', $row['status'])) ?></span></td>
                    <td class="num"><?= $row['expected_amount'] === null ? '—' : e(format_money((int) $row['expected_amount'], 'CDF')) ?></td>
                    <td class="num"><?= $row['received_amount'] === null ? '—' : e(format_money((int) $row['received_amount'], 'CDF')) ?></td>
                    <td class="num<?= ($variance ?? 0) < 0 ? ' overdue' : '' ?>"><?= $variance === null ? '—' : e(($variance > 0 ? '+' : '') . format_money($variance, 'CDF')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
