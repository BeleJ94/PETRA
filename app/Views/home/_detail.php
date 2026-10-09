<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * Détail d'un bloc du tableau de bord, en tableaux compacts (fragment ouvert en fenêtre).
 *
 * @var string $title  @var string $subtitle
 * @var list<array{title: ?string, columns: list<mixed>, rows: list<list<string>>, foot?: list<string>, empty: string}> $sections
 * @var array{0: string, 1: string}|null $link page complète correspondante
 */
?>
<div class="shift-modal">
    <div class="shift-modal__head">
        <h2><?= e($title) ?></h2>
        <div class="shift-modal__meta"><?= e($subtitle) ?></div>
    </div>
    <?php foreach ($sections as $section): ?>
        <?php if ($section['title'] !== null): ?><h3 class="shift-form__title"><?= e($section['title']) ?></h3><?php endif; ?>
        <?php if ($section['rows'] === []): ?>
            <p class="muted"><?= e($section['empty']) ?></p>
        <?php else: ?>
            <div class="table-wrap table-wrap--modal"><table class="table table--compact">
                <thead><tr>
                    <?php foreach ($section['columns'] as $column): ?><th<?= is_array($column) ? ' class="num"' : '' ?>><?= e(is_array($column) ? $column[0] : $column) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($section['rows'] as $row): ?>
                    <tr><?php foreach ($row as $i => $cell): ?><td class="<?= is_array($section['columns'][$i] ?? null) ? 'num' : '' ?><?= str_starts_with($cell, '-') && is_array($section['columns'][$i] ?? null) ? ' overdue' : '' ?>"><?= e($cell) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($section['foot'])): ?>
                    <tfoot><tr><?php foreach ($section['foot'] as $i => $cell): ?><th<?= is_array($section['columns'][$i] ?? null) ? ' class="num"' : '' ?>><?= e($cell) ?></th><?php endforeach; ?></tr></tfoot>
                <?php endif; ?>
            </table></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="shift-form__actions">
        <?php if (!empty($link)): ?><a class="btn btn--ghost" href="<?= e(url($link[0])) ?>"><i data-lucide="external-link"></i><?= e($link[1]) ?></a><?php endif; ?>
        <button type="button" class="btn btn--primary" data-modal-close><?= e(t('common.close')) ?></button>
    </div>
</div>
