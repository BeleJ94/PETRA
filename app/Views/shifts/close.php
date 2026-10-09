<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $shift  @var array{nozzles: list<array<string, mixed>>, sequence: int} $segment */
$oldFinal = old('final', []);
$oldRollover = old('rollover', []);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="flag"></i></span>
        <div>
            <h1><?= e(t('shifts.close_title')) ?></h1>
            <div class="page-title__meta"><span><?= e($shift['station']) ?></span><span><?= e($shift['attendant']) ?></span><?php if ($shift['label']): ?><span class="chip"><?= e($shift['label']) ?></span><?php endif; ?></div>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/shifts/' . $shift['id'] . '/close')) ?>" class="card tablet-form" data-confirm="<?= e(t('shifts.close_confirm')) ?>">
    <?= csrf_field() ?>
    <div class="guide-row">
        <figure class="guide-figure"><?= illustration('meter-guide', 'md') ?><figcaption><span><b>1</b> <?= e(t('shifts.guide_index')) ?></span></figcaption></figure>
        <p class="muted"><?= e(t('shifts.close_hint')) ?></p>
    </div>
    <div class="nozzle-grid">
        <?php foreach ($segment['nozzles'] as $nozzle): ?>
            <?php $error = field_error('final_' . $nozzle['nozzle_id']); $id = 'final-' . $nozzle['nozzle_id']; ?>
            <div class="nozzle-card">
                <div class="nozzle-card__head"><strong><?= e($nozzle['code']) ?></strong><span><?= e($nozzle['product']) ?></span></div>
                <div class="muted small"><?= e(t('fields.opening_index')) ?> : <strong><?= e(format_number($nozzle['opening'], 2)) ?></strong></div>
                <?php if ($nozzle['non_sale_l'] !== '0.00'): ?>
                    <div class="muted small"><?= e(t('shifts.non_sale')) ?> : <?= e(format_number($nozzle['non_sale_l'], 2)) ?> L</div>
                <?php endif; ?>
                <label for="<?= e($id) ?>" class="nozzle-card__label"><?= e(t('fields.final_index')) ?></label>
                <input id="<?= e($id) ?>" name="final[<?= e($nozzle['nozzle_id']) ?>]" class="input input--xl" required inputmode="decimal" autocomplete="off"
                       value="<?= e($oldFinal[$nozzle['nozzle_id']] ?? '') ?>"<?= $error ? ' aria-invalid="true"' : '' ?>>
                <?php if ($error): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
                <label class="check"><input type="checkbox" name="rollover[<?= e($nozzle['nozzle_id']) ?>]" value="1"<?= !empty($oldRollover[$nozzle['nozzle_id']]) ? ' checked' : '' ?>> <?= e(t('shifts.rollover_declare')) ?></label>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="muted small"><?= e(t('shifts.rollover_hint')) ?></p>
    <div class="form-actions">
        <a class="btn btn--lg" href="<?= e(url('/shifts/' . $shift['id'])) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary btn--lg"><i data-lucide="flag"></i><?= e(t('shifts.close_action')) ?></button>
    </div>
</form>
