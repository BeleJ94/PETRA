<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre de jaugeage d'une cuve (fragment). @var array<string, mixed> $tank  @var list<string> $dipTypes */
$context = [[t('fields.tank'), $tank['code'] . ' — ' . $tank['product']]];
if ($tank['theoretical_l'] !== null) {
    $context[] = [t('stock.theoretical'), format_number($tank['theoretical_l'], 2) . ' L'];
}
?>
<div class="shift-modal shift-form">
    <div class="shift-modal__head">
        <h2><?= e(t('stock.dip_title', ['tank' => $tank['code']])) ?></h2>
        <div class="shift-modal__meta"><?= e($tank['station'] . ' · ' . $tank['product'] . ' · ' . format_number($tank['capacity_l'], 0) . ' L') ?></div>
    </div>
    <form method="post" action="<?= e(url('/stock/tanks/' . $tank['id'] . '/dips')) ?>" data-ajax-form data-recap-title="<?= e(t('stock.recap_dip')) ?>" data-submit-label="<?= e(t('common.save')) ?>"
          data-recap-warning="<?= e(t('stock.recap_warning')) ?>" data-context="<?= e(json_encode($context, JSON_UNESCAPED_UNICODE)) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <div class="guide-row">
            <figure class="guide-figure"><?= illustration('dip-guide', 'md') ?></figure>
            <div class="guide-figure" style="align-items: flex-start"><figcaption><span><b>1</b> <?= e(t('stock.guide_fuel')) ?></span><span><b>2</b> <?= e(t('stock.guide_water')) ?></span></figcaption></div>
        </div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.depth_cm')) ?>
                <input name="depth_cm" class="input input--xl" required inputmode="decimal" autocomplete="off" data-error-key="depth_cm" data-recap="<?= e(t('fields.depth_cm')) ?>">
            </label>
            <label class="field"><?= e(t('fields.water_cm')) ?>
                <input name="water_cm" class="input input--xl" inputmode="decimal" autocomplete="off" data-error-key="water_cm" data-recap="<?= e(t('fields.water_cm')) ?>">
            </label>
        </div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.dip_type')) ?>
                <select name="dip_type" class="input" required data-recap="<?= e(t('fields.dip_type')) ?>">
                    <?php foreach ($dipTypes as $type): ?><option value="<?= e($type) ?>"<?= $type === 'control' ? ' selected' : '' ?>><?= e(label('dip_type', $type)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.note')) ?><input name="note" class="input" maxlength="255" data-recap="<?= e(t('fields.note')) ?>"></label>
        </div>
        <p class="muted small"><?= e(t('stock.dip_hint')) ?></p>
        <div class="shift-form__actions">
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="ruler"></i><?= e(t('common.save')) ?></button>
        </div>
    </form>
</div>
