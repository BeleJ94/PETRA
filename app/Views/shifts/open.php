<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array $station  @var list<array<string, mixed>> $staff  @var list<array{label: string, start: string, end: string}> $schedule
 * @var string|null $slot  @var list<array<string, mixed>> $nozzles  @var list<array<string, mixed>> $pumps  @var string $mode  @var list<string> $blockers
 */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="play"></i></span>
        <div>
            <h1><?= e(t('shifts.open_title')) ?></h1>
            <div class="page-title__meta"><span><i data-lucide="map-pin"></i><?= e($station['name']) ?></span><span><i data-lucide="calendar"></i><?= e(format_date(date('Y-m-d H:i:s'), true)) ?></span></div>
        </div>
    </div>
</div>

<?php if ($blockers !== []): ?>
    <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div>
        <?= e(t('shifts.cannot_open')) ?>
        <ul class="alert__list"><?php foreach ($blockers as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?></ul>
    </div></div>
    <a class="btn" href="<?= e(url('/shifts')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
<?php else: ?>
<form method="post" action="<?= e(url('/shifts')) ?>" class="card tablet-form" data-confirm="<?= e(t('shifts.open_confirm')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="attendant_id" class="required"><?= e(t('fields.attendant')) ?></label>
            <select id="attendant_id" name="attendant_id" class="input" required>
                <option value=""></option>
                <?php foreach ($staff as $person): ?>
                    <option value="<?= e($person['id']) ?>"<?= old('attendant_id') === $person['id'] ? ' selected' : '' ?>><?= e($person['name'] . ' — ' . $person['role_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($staff === []): ?><span class="error"><?= e(t('shifts.no_staff')) ?></span><?php endif; ?>
        </div>
        <?php if ($mode === 'fixed'): ?>
        <div class="field">
            <label for="label"><?= e(t('fields.shift_label')) ?></label>
            <select id="label" name="label" class="input">
                <option value=""><?= e(t('shifts.outside_schedule')) ?></option>
                <?php foreach ($schedule as $item): ?>
                    <option value="<?= e($item['label']) ?>"<?= old('label', $slot) === $item['label'] ? ' selected' : '' ?>><?= e($item['label'] . ' (' . $item['start'] . ' – ' . $item['end'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <div class="field"><span><?= e(t('shifts.opening_time')) ?></span><strong><?= e(date('H:i')) ?></strong><span class="hint"><?= e(t('shifts.free_hint')) ?></span></div>
        <?php endif; ?>
    </div>

    <p class="muted small"><?= e(t('shifts.opening_hint')) ?></p>
    <?= $view->partial('shifts/_pumps', ['pumps' => $pumps, 'recap' => false]) ?>

    <div class="form-actions">
        <a class="btn btn--lg" href="<?= e(url('/shifts')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary btn--lg"><i data-lucide="play"></i><?= e(t('shifts.open_action')) ?></button>
    </div>
</form>
<?php endif; ?>
