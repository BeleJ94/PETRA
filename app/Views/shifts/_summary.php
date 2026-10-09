<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre de détail d'un poste (fragment). @var array $shift  @var array<string, mixed> $report modèle de ReportService */
$text = static fn (mixed $cell): string => is_array($cell) ? (string) $cell['t'] : (string) $cell;
$negative = static fn (mixed $cell): bool => is_array($cell) && str_starts_with((string) $cell['v'], '-');
$sections = array_column($report['sections'], null, 'key');
$tones = ['open' => 'info', 'closed' => 'warning', 'validated' => 'success'];
$tabs = [
    'sales' => ['shifts.tab_sales', ['readings', 'outputs']],
    'cash' => ['shifts.tab_cash', ['handovers', 'credits', 'expenses']],
    'events' => ['shifts.tab_events', ['anomalies', 'operations']],
];
?>
<div class="shift-modal">
    <div class="shift-modal__head">
        <h2><?= e($report['title']) ?></h2>
        <span class="badge badge--<?= e($tones[$shift['status']] ?? 'neutral') ?>"><?= e(label('shift_status', $shift['status'])) ?></span>
        <div class="shift-modal__meta"><?= e($shift['station'] . ' · ' . $shift['attendant'] . ' · ' . $report['period']) ?></div>
    </div>
    <div class="shift-modal__figures">
        <?php foreach ($report['summary'] as [$label, $value]): ?>
            <?php if (in_array($label, [t('reports.col_attendant'), t('fields.status')], true)) { continue; } ?>
            <div><span><?= e($label) ?></span><strong<?= $negative($value) ? ' class="overdue"' : '' ?>><?= e($text($value)) ?></strong></div>
        <?php endforeach; ?>
    </div>
    <div class="tabs" role="tablist">
        <?php foreach ($tabs as $key => [$labelKey]): ?>
            <button type="button" role="tab" data-tab="<?= e($key) ?>" aria-selected="<?= $key === 'sales' ? 'true' : 'false' ?>"><?= e(t($labelKey)) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($tabs as $key => [, $keys]): ?>
        <div class="tab-panel" role="tabpanel" data-panel="<?= e($key) ?>"<?= $key === 'sales' ? '' : ' hidden' ?>>
            <?php foreach ($keys as $sectionKey): ?>
                <?php $section = $sections[$sectionKey] ?? null; ?>
                <?php if ($section === null || ($section['rows'] === [] && !in_array($sectionKey, ['readings', 'handovers', 'operations'], true))) { continue; } ?>
                <h3><?= e($section['title']) ?></h3>
                <?php if ($section['rows'] === []): ?>
                    <p class="muted"><?= e($section['empty']) ?></p>
                <?php else: ?>
                    <div class="table-wrap"><table class="table table--compact">
                        <thead><tr><?php foreach ($section['columns'] as $column): ?><th<?= is_array($column) ? ' class="num"' : '' ?>><?= e(is_array($column) ? $column[0] : $column) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($section['rows'] as $row): ?>
                            <tr><?php foreach ($row as $i => $cell): ?><td class="<?= is_array($section['columns'][$i] ?? null) ? 'num' : '' ?><?= $negative($cell) ? ' overdue' : '' ?>"><?= e($text($cell)) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($section['foot'])): ?>
                            <tfoot><tr><?php foreach ($section['foot'] as $i => $cell): ?><th<?= is_array($section['columns'][$i] ?? null) ? ' class="num"' : '' ?>><?= e($text($cell)) ?></th><?php endforeach; ?></tr></tfoot>
                        <?php endif; ?>
                    </table></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <div class="shift-modal__foot">
        <a class="btn btn--primary" href="<?= e(url('/shifts/' . $shift['id'])) ?>"><i data-lucide="external-link"></i><?= e(t('shifts.full_sheet')) ?></a>
    </div>
</div>
