<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $product  @var array|null $current  @var string $currentInput  @var list<array<string, mixed>> $open */
$currency = (string) old('currency', $current['currency'] ?? 'CDF');
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="badge-dollar-sign"></i></span>
        <div>
            <h1><?= e($title) ?></h1>
            <div class="page-title__meta">
                <span><?= e(t('pricing.current_value')) ?> : <strong><?= e($current === null ? t('pricing.none') : format_money($current['unit_price'], $current['currency'])) ?></strong></span>
            </div>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/prices/products/' . $product['id'])) ?>" class="card form" data-confirm="<?= e(t('pricing.price_confirm', ['product' => $product['name']])) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $view->partial('partials/field', ['name' => 'unit_price', 'labelKey' => 'fields.unit_price', 'required' => true, 'value' => old('unit_price', $currentInput), 'hint' => t('pricing.price_hint'), 'attrs' => ['inputmode' => 'decimal', 'autofocus' => 'autofocus']]) ?>
        <?= $view->partial('partials/field', ['name' => 'currency', 'labelKey' => 'fields.currency', 'type' => 'select', 'required' => true, 'value' => $currency, 'options' => ['CDF' => 'CDF (FC)', 'USD' => 'USD ($)']]) ?>
        <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'value' => old('note'), 'class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
    </div>
    <p class="muted small"><?= e(t('pricing.effective_now')) ?></p>
    <?= $view->partial('prices/_readings', ['open' => $open]) ?>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/prices')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('pricing.publish')) ?></button>
    </div>
</form>
