<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $tank  @var array $station  @var array|null $product  @var array{points: mixed, max_depth_cm: mixed, max_volume_l: mixed} $summary */
$id = $tank['id'];
$active = (bool) old('is_active', $tank['is_active']);
$f = static fn (string $name, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => 'text', 'value' => old($name, $tank[$name] ?? null),
]);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="cylinder"></i></span>
        <div>
            <h1><?= e($tank['name']) ?></h1>
            <div class="page-title__meta">
                <span class="chip"><?= e($tank['code']) ?></span>
                <span><i data-lucide="fuel"></i><?= e($station['name']) ?></span>
                <span><i data-lucide="droplet"></i><?= e($product['name'] ?? '') ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/stations/' . $station['id'])) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
    </div>
</div>

<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title">
            <h2><?= e(t('calibrations.title')) ?></h2>
            <div class="page-header__actions">
                <?= $view->partial('partials/export_buttons', ['table' => 'calibrations', 'exportTitle' => t('calibrations.title') . ' — ' . $tank['code']]) ?>
                <button type="button" class="btn btn--sm" data-open="#import-calibrations"><i data-lucide="upload"></i><?= e(t('calibrations.import')) ?></button>
                <button type="button" class="btn btn--sm btn--primary" data-open="#new-point"><i data-lucide="plus"></i><?= e(t('calibrations.new')) ?></button>
            </div>
        </div>
        <p class="muted small"><?= e(t('calibrations.summary', [
            'points' => (int) $summary['points'],
            'depth' => format_number($summary['max_depth_cm'] ?? 0, 1),
            'volume' => format_number($summary['max_volume_l'] ?? 0, 2),
        ])) ?></p>
        <table class="display" style="width:100%" data-table="calibrations" data-source="<?= e(url("/tanks/$id/calibrations/data")) ?>"></table>

        <template id="new-point">
            <form method="post" action="<?= e(url("/tanks/$id/calibrations")) ?>" class="grid">
                <?= csrf_field() ?>
                <h2><?= e(t('calibrations.new')) ?></h2>
                <p class="muted small"><?= e(t('calibrations.new_hint')) ?></p>
                <label class="field"><?= e(t('fields.depth_cm')) ?><input name="depth_cm" class="input" required inputmode="decimal" placeholder="120,5"></label>
                <label class="field"><?= e(t('fields.volume_l')) ?><input name="volume_l" class="input" required inputmode="decimal" placeholder="14460"></label>
            </form>
        </template>
        <template id="import-calibrations">
            <form method="post" action="<?= e(url("/tanks/$id/calibrations/import")) ?>" enctype="multipart/form-data" class="grid">
                <?= csrf_field() ?>
                <h2><?= e(t('calibrations.import')) ?></h2>
                <p class="muted small"><?= e(t('calibrations.import_hint')) ?></p>
                <pre class="csv-sample">hauteur_cm;volume_l
0;0
10;1200
20,5;2460</pre>
                <label class="field"><?= e(t('fields.file')) ?><input name="file" type="file" class="input" required accept=".csv,text/csv,text/plain"></label>
                <p class="alert alert--info"><i data-lucide="info"></i><span><?= e(t('calibrations.import_replace')) ?></span></p>
            </form>
        </template>
    </section>

    <form method="post" action="<?= e(url("/tanks/$id")) ?>" class="card">
        <?= csrf_field() ?>
        <div class="card__title"><h2><?= e(t('tanks.edit')) ?></h2></div>
        <div class="grid">
            <?= $f('code', ['required' => true, 'attrs' => ['maxlength' => 20]]) ?>
            <?= $f('name', ['required' => true, 'attrs' => ['maxlength' => 80]]) ?>
            <?= $f('capacity_l', ['required' => true, 'attrs' => ['inputmode' => 'decimal']]) ?>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button></div>
    </form>
</div>
