<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $nozzle  @var array $station  @var list<array<string, mixed>> $tanks  @var list<array<string, mixed>> $pumps  @var bool $locked index déjà relevés : distributeur et cuve figés */
$active = (bool) old('is_active', $nozzle['is_active']);
$f = static fn (string $name, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => 'text', 'value' => old($name, $nozzle[$name] ?? null),
]);
$tankOptions = [];
foreach ($tanks as $tank) {
    $tankOptions[$tank['id']] = $tank['code'] . ' — ' . $tank['product'];
}
$pumpOptions = $nozzle['pump_id'] === null ? ['' => ''] : [];
foreach ($pumps as $pump) {
    if ($pump['is_active'] || $pump['id'] === $nozzle['pump_id']) {
        $pumpOptions[$pump['id']] = $pump['code'] . ' — ' . $pump['name'];
    }
}
// Champ figé : désactivé à l'écran, donc non envoyé ; le serveur garde la valeur en place.
$frozen = $locked ? ['disabled' => 'disabled'] : [];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="fuel"></i></span>
        <div>
            <h1><?= e($nozzle['name']) ?></h1>
            <div class="page-title__meta"><span class="chip"><?= e($nozzle['code']) ?></span><span><?= e($station['name']) ?></span></div>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/nozzles/' . $nozzle['id'])) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $f('code', ['required' => true, 'attrs' => ['maxlength' => 20]]) ?>
        <?= $f('name', ['required' => true, 'attrs' => ['maxlength' => 80]]) ?>
        <?= $view->partial('partials/field', ['name' => 'pump_id', 'labelKey' => 'fields.pump', 'type' => 'select', 'required' => !$locked, 'options' => $pumpOptions, 'value' => old('pump_id', $nozzle['pump_id']), 'attrs' => $frozen]) ?>
        <?= $view->partial('partials/field', ['name' => 'tank_id', 'labelKey' => 'fields.tank', 'type' => 'select', 'required' => !$locked, 'options' => $tankOptions, 'value' => old('tank_id', $nozzle['tank_id']), 'attrs' => $frozen]) ?>
        <?php if ($locked): ?><input type="hidden" name="tank_id" value="<?= e($nozzle['tank_id']) ?>"><?php endif; ?>
        <?= $f('rollover_index', ['hint' => t('nozzles.rollover_hint'), 'attrs' => ['inputmode' => 'decimal']]) ?>
        <div class="field">
            <input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
        </div>
    </div>
    <?php if ($locked): ?><p class="muted small"><i data-lucide="lock"></i> <?= e(t('nozzles.locked_hint')) ?></p><?php endif; ?>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/stations/' . $station['id'])) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
