<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var list<string> $actions  @var list<string> $entities */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="history"></i></span>
        <div>
            <h1><?= e(t('nav.audit')) ?></h1>
            <div class="page-title__meta"><?= e(t('audit.intro')) ?></div>
        </div>
    </div>
    <div class="page-header__actions"><?= $view->partial('partials/export_buttons', ['table' => 'audit', 'exportTitle' => t('nav.audit')]) ?></div>
</div>

<section class="card">
    <form class="filters" data-filters-for="audit">
        <div class="field">
            <label for="filter-entity"><?= e(t('fields.entity_type')) ?></label>
            <select id="filter-entity" name="entity_type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($entities as $entity): ?><option value="<?= e($entity) ?>"><?= e(label('entity', $entity)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-action"><?= e(t('fields.action')) ?></label>
            <select id="filter-action" name="action" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($actions as $action): ?><option value="<?= e($action) ?>"><?= e(label('action', $action)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-from"><?= e(t('common.from')) ?></label>
            <input id="filter-from" name="from" type="date" class="input" data-filter>
        </div>
        <div class="field">
            <label for="filter-to"><?= e(t('common.to')) ?></label>
            <input id="filter-to" name="to" type="date" class="input" data-filter>
        </div>
    </form>
    <table class="display" style="width:100%" data-table="audit" data-source="<?= e(url('/audit/data')) ?>"></table>
</section>
