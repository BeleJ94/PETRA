<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php
/** @var array<string, mixed> $report modèle produit par ReportService : il sert à l'écran, à l'impression et aux exports */
$f = $report['filters'];
$text = static fn (mixed $cell): string => is_array($cell) ? (string) $cell['t'] : (string) $cell;
$negative = static fn (mixed $cell): bool => is_array($cell) && str_starts_with((string) $cell['v'], '-');
$align = static fn (mixed $column): string => is_array($column) ? ' class="num"' : '';
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="file-bar-chart"></i></span>
        <h1><?= e(t('nav.reports')) ?></h1>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn" data-report-export="excel"><i data-lucide="file-spreadsheet"></i><?= e(t('common.export_excel')) ?></button>
        <button type="button" class="btn" data-report-export="pdf"><i data-lucide="file-text"></i><?= e(t('common.export_pdf')) ?></button>
        <button type="button" class="btn btn--primary" data-report-print><i data-lucide="printer"></i><?= e(t('reports.print')) ?></button>
    </div>
</div>

<form method="get" action="<?= e(url('/reports')) ?>" class="filters filters--compact" data-report-filters>
    <div class="field">
        <label for="report-type"><?= e(t('reports.type')) ?></label>
        <select id="report-type" name="type" class="input" data-report-type>
            <?php foreach (\Petra\Services\ReportService::TYPES as $type): ?>
                <option value="<?= e($type) ?>"<?= $f['type'] === $type ? ' selected' : '' ?>><?= e(label('report_type', $type)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (count($f['stations']) > 1): ?>
        <div class="field">
            <label for="report-station"><?= e(t('fields.station')) ?></label>
            <select id="report-station" name="station" class="input">
                <option value="all"<?= $f['station'] === 'all' ? ' selected' : '' ?>><?= e(t('common.all_stations')) ?></option>
                <?php foreach ($f['stations'] as $station): ?>
                    <option value="<?= e($station['id']) ?>"<?= $f['station'] === $station['id'] ? ' selected' : '' ?>><?= e($station['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="field" data-for-type="daily shift"<?= $f['type'] === 'monthly' ? ' hidden' : '' ?>>
        <label for="report-date"><?= e(t('reports.date')) ?></label>
        <input type="date" id="report-date" name="date" class="input" value="<?= e($f['date']) ?>"<?= $f['type'] === 'monthly' ? ' disabled' : '' ?>>
    </div>
    <div class="field" data-for-type="monthly"<?= $f['type'] === 'monthly' ? '' : ' hidden' ?>>
        <label for="report-month"><?= e(t('reports.month')) ?></label>
        <input type="month" id="report-month" name="month" class="input" value="<?= e($f['month']) ?>"<?= $f['type'] === 'monthly' ? '' : ' disabled' ?>>
    </div>
    <div class="field" data-for-type="shift"<?= $f['type'] === 'shift' ? '' : ' hidden' ?>>
        <label for="report-shift"><?= e(t('reports.shift_choice')) ?></label>
        <select id="report-shift" name="shift" class="input"<?= $f['type'] === 'shift' ? '' : ' disabled' ?>>
            <option value=""><?= e(t('reports.choose_shift')) ?></option>
            <?php foreach ($f['shifts'] as $shift): ?>
                <option value="<?= e($shift['id']) ?>"<?= $f['shift'] === $shift['id'] ? ' selected' : '' ?>><?= e($shift['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <button type="submit" class="btn btn--primary"><i data-lucide="filter"></i><?= e(t('reports.show')) ?></button>
    </div>
</form>

<?php /* Cadre d'impression : son pied vide, répété par le navigateur sur chaque page, réserve la place du pied fixe du rapport. */ ?>
<table class="report-frame" role="presentation"><tbody><tr><td>
<article class="report" id="report">
    <header class="report__head">
        <div>
            <p class="report__brand"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" aria-hidden="true">Petra</p>
            <h2 class="report__title"><?= e($report['title']) ?></h2>
        </div>
        <dl class="report__meta">
            <dt><?= e(t('reports.meta_station')) ?></dt><dd data-report-meta="station"><?= e($report['station']) ?></dd>
            <dt><?= e(t('reports.meta_period')) ?></dt><dd data-report-meta="period"><?= e($report['period']) ?></dd>
            <dt><?= e(t('reports.meta_generated')) ?></dt><dd data-report-meta="generated"><?= e($report['generated_at']) ?></dd>
            <dt><?= e(t('reports.meta_author')) ?></dt><dd data-report-meta="author"><?= e($report['author']) ?></dd>
        </dl>
    </header>

    <?php if ($report['type'] === 'shift' && $report['sections'] === []): ?>
        <p class="muted"><?= e(t('reports.pick_shift')) ?></p>
    <?php endif; ?>

    <?php if ($report['summary'] !== []): ?>
        <section class="report__summary" aria-label="<?= e(t('reports.key_figures')) ?>">
            <?php foreach ($report['summary'] as [$label, $value]): ?>
                <div class="report__figure">
                    <span class="report__figure-label"><?= e($label) ?></span>
                    <strong<?= $negative($value) ? ' class="overdue"' : '' ?>><?= e($text($value)) ?></strong>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php foreach ($report['sections'] as $section): ?>
        <section class="report__section" data-section="<?= e($section['key']) ?>">
            <h3><?= e($section['title']) ?></h3>
            <?php if ($section['rows'] === []): ?>
                <p class="muted"><?= e($section['empty']) ?></p>
            <?php else: ?>
                <div class="table-wrap"><table class="table table--compact report__table">
                    <thead><tr>
                        <?php foreach ($section['columns'] as $column): ?>
                            <th<?= $align($column) ?>><?= e(is_array($column) ? $column[0] : $column) ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $i => $cell): ?>
                                <td class="<?= is_array($section['columns'][$i] ?? null) ? 'num' : '' ?><?= $negative($cell) ? ' overdue' : '' ?>"><?= e($text($cell)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($section['foot'])): ?>
                        <tfoot><tr>
                            <?php foreach ($section['foot'] as $i => $cell): ?>
                                <th<?= $align($section['columns'][$i] ?? null) ?>><?= e($text($cell)) ?></th>
                            <?php endforeach; ?>
                        </tr></tfoot>
                    <?php endif; ?>
                </table></div>
            <?php endif; ?>
            <?php if (!empty($section['note'])): ?><p class="report__note"><?= e($section['note']) ?></p><?php endif; ?>
        </section>
    <?php endforeach; ?>

    <footer class="report__foot">
        <span><?= e($report['credit']) ?></span>
        <span><?= e($report['station'] . ' · ' . $report['period'] . ' · ' . t('reports.meta_generated') . ' ' . $report['generated_at'] . ' · ' . $report['author']) ?></span>
    </footer>
</article>
</td></tr></tbody><tfoot><tr><td><div class="report-frame__space"></div></td></tr></tfoot></table>

<script type="application/json" id="report-data"><?= json_for_html([
    'title' => $report['title'], 'file' => $report['file'], 'station' => $report['station'], 'period' => $report['period'],
    'generated_at' => $report['generated_at'], 'author' => $report['author'], 'summary' => $report['summary'], 'sections' => $report['sections'],
    'labels' => ['station' => t('reports.meta_station'), 'period' => t('reports.meta_period'), 'generated' => t('reports.meta_generated'), 'author' => t('reports.meta_author'), 'figures' => t('reports.key_figures')],
]) ?></script>
