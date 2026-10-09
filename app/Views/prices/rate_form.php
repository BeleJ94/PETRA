<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array|null $current  @var list<array<string, mixed>> $open */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="arrow-left-right"></i></span>
        <div>
            <h1><?= e($title) ?></h1>
            <div class="page-title__meta">
                <span><?= e(t('pricing.current_value')) ?> : <strong><?= e($current === null ? t('pricing.none') : '1 $ = ' . format_money($current['rate'], 'CDF')) ?></strong></span>
            </div>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/rates')) ?>" class="card form" data-confirm="<?= e(t('pricing.rate_confirm')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $view->partial('partials/field', ['name' => 'rate', 'labelKey' => 'fields.rate', 'required' => true, 'value' => old('rate', $current['rate'] ?? ''), 'hint' => t('pricing.rate_hint'), 'attrs' => ['inputmode' => 'numeric', 'autofocus' => 'autofocus']]) ?>
        <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'value' => old('note'), 'attrs' => ['maxlength' => 255]]) ?>
    </div>
    <p class="muted small"><?= e(t('pricing.effective_now')) ?></p>
    <?= $view->partial('prices/_readings', ['open' => $open]) ?>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/prices')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('pricing.publish')) ?></button>
    </div>
</form>
