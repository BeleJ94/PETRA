<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $station  @var string|null $id  @var list<string> $timezones */
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $station[$name] ?? null),
]);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="fuel"></i></span>
        <h1><?= e($title) ?></h1>
    </div>
</div>

<form method="post" action="<?= e(url($id ? "/stations/$id" : '/stations')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $f('code', 'text', ['required' => true, 'hint' => t('stations.code_hint'), 'attrs' => ['maxlength' => 20, 'autocapitalize' => 'characters']]) ?>
        <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 120]]) ?>
        <?= $f('city', 'text', ['attrs' => ['maxlength' => 120]]) ?>
        <?= $f('timezone', 'select', ['required' => true, 'options' => array_combine($timezones, $timezones)]) ?>
        <?= $f('address', 'text', ['class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/stations/$id" : '/stations')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
