<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var bool $canCreate */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="fuel"></i></span>
        <h1><?= e(t('nav.stations')) ?></h1>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'stations', 'exportTitle' => t('nav.stations')]) ?>
        <?php if ($canCreate): ?>
            <a class="btn btn--primary" href="<?= e(url('/stations/create')) ?>"><i data-lucide="plus"></i><?= e(t('stations.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <form class="filters" data-filters-for="stations">
        <div class="field">
            <label for="filter-active"><?= e(t('fields.status')) ?></label>
            <select id="filter-active" name="is_active" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <option value="1"><?= e(t('stations.active')) ?></option>
                <option value="0"><?= e(t('stations.inactive')) ?></option>
            </select>
        </div>
    </form>
    <table class="display" style="width:100%" data-table="stations" data-source="<?= e(url('/stations/data')) ?>" data-row-link="<?= e(url('/stations/{id}')) ?>"></table>
</section>
