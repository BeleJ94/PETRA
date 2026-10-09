<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var bool $canRecord  @var bool $needsStation */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="truck"></i></span>
        <h1><?= e(t('nav.deliveries')) ?></h1>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'deliveries', 'exportTitle' => t('nav.deliveries')]) ?>
        <?php if ($canRecord): ?>
            <a class="btn btn--primary btn--lg" href="<?= e(url('/deliveries/create')) ?>"><i data-lucide="plus"></i><?= e(t('deliveries.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($needsStation): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('deliveries') ?><p><?= e(t('deliveries.pick_station')) ?></p></div></div>
<?php endif; ?>

<section class="card">
    <table class="display" style="width:100%" data-table="deliveries" data-source="<?= e(url('/deliveries/data')) ?>" data-row-link="<?= e(url('/deliveries/{id}')) ?>"></table>
</section>
