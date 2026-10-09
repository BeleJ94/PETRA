<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array $station  @var list<array<string, mixed>> $tanks  @var array<string, string> $suppliers  @var bool $canManageSuppliers
 *  @var array<string, string> $receivers  @var string $currentUserId
 * @var string $tolerance  @var int $maxPhotoMb
 */
$f = static fn (string $name, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => 'text', 'value' => old($name),
]);
$big = static fn (string $name, bool $required = true) => $f($name, ['required' => $required, 'class' => 'field--xl', 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]);
$tankOptions = ['' => ''];
foreach ($tanks as $tank) {
    if ((int) $tank['calibration_count'] > 0) {
        $tankOptions[$tank['id']] = $tank['code'] . ' — ' . $tank['product'] . ' (' . format_number($tank['capacity_l'], 0) . ' L)';
    }
}
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="truck"></i></span>
        <div>
            <h1><?= e(t('deliveries.new')) ?></h1>
            <div class="page-title__meta"><span><i data-lucide="map-pin"></i><?= e($station['name']) ?></span></div>
        </div>
    </div>
</div>

<figure class="card guide-steps">
    <?= illustration('delivery-steps', 'wide') ?>
    <figcaption><span><b>1</b> <?= e(t('deliveries.step_before')) ?></span><span><b>2</b> <?= e(t('deliveries.step_unload')) ?></span><span><b>3</b> <?= e(t('deliveries.step_after')) ?></span></figcaption>
</figure>

<?php if (count($tankOptions) === 1): ?>
    <div class="alert alert--error"><i data-lucide="alert-triangle"></i><div><?= e(t('deliveries.no_tank')) ?></div></div>
<?php elseif ($suppliers === []): ?>
    <div class="alert alert--error"><i data-lucide="alert-triangle"></i><div>
        <?= e(t('deliveries.no_supplier')) ?>
        <?php if ($canManageSuppliers): ?><a href="<?= e(url('/suppliers')) ?>"><strong><?= e(t('deliveries.no_supplier_action')) ?></strong></a><?php else: ?><?= e(t('deliveries.no_supplier_ask')) ?><?php endif; ?>
    </div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/deliveries')) ?>" enctype="multipart/form-data" class="card tablet-form" data-confirm="<?= e(t('deliveries.confirm')) ?>">
    <?= csrf_field() ?>
    <h2><?= e(t('deliveries.section_note')) ?></h2>
    <div class="form-grid">
        <?= $f('supplier_id', ['type' => 'select', 'required' => true, 'options' => ['' => ''] + $suppliers]) ?>
        <?= $f('delivery_note_no', ['attrs' => ['maxlength' => 60]]) ?>
        <?= $f('truck_plate', ['attrs' => ['maxlength' => 30]]) ?>
        <?= $f('driver_name', ['attrs' => ['maxlength' => 120]]) ?>
        <?= $f('tank_id', ['type' => 'select', 'required' => true, 'labelKey' => 'fields.tank', 'options' => $tankOptions]) ?>
        <?= $f('received_by', ['type' => 'select', 'required' => true, 'options' => $receivers, 'value' => old('received_by', $currentUserId)]) ?>
    </div>

    <p class="muted small"><?= e(t('deliveries.supplier_hint')) ?><?php if ($canManageSuppliers): ?> <a href="<?= e(url('/suppliers')) ?>"><?= e(t('deliveries.supplier_manage')) ?></a><?php endif; ?></p>

    <h2><?= e(t('deliveries.section_quantities')) ?></h2>
    <div class="form-grid form-grid--3">
        <?= $big('ordered_volume_l', false) ?>
        <?= $big('invoiced_volume_l') ?>
        <?= $big('received_volume_l') ?>
    </div>

    <h2><?= e(t('deliveries.section_dips')) ?></h2>
    <p class="muted small"><?= e(t('deliveries.dips_hint')) ?></p>
    <div class="form-grid">
        <?= $big('before_cm') ?>
        <?= $big('after_cm') ?>
    </div>

    <h2><?= e(t('deliveries.section_notes')) ?></h2>
    <div class="form-grid">
        <?= $f('note', ['labelKey' => 'fields.observations', 'attrs' => ['maxlength' => 255]]) ?>
        <div class="field">
            <label for="f-photo"><?= e(t('fields.photo')) ?></label>
            <input id="f-photo" name="photo" type="file" class="input" accept="image/jpeg,image/png,image/webp">
            <span class="hint"><?= e(t('deliveries.photo_hint', ['max' => $maxPhotoMb])) ?></span>
            <?php if (field_error('photo')): ?><span class="error"><?= e(field_error('photo')) ?></span><?php endif; ?>
        </div>
    </div>
    <p class="muted small"><?= e(t('deliveries.tolerance_hint', ['tolerance' => format_number($tolerance, 2)])) ?></p>

    <div class="form-actions">
        <a class="btn btn--lg" href="<?= e(url('/deliveries')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary btn--lg"><i data-lucide="check"></i><?= e(t('deliveries.record')) ?></button>
    </div>
</form>
<?php endif; ?>
