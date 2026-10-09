<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var \Petra\Security\StationContext $stationContext  @var string $currentPath */
$active = $stationContext->active();
?>
<?php if ($stationContext->isMultiStation()): ?>
    <form method="post" action="<?= e(url('/context/station')) ?>" class="station-switch">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($currentPath) ?>">
        <label for="station-switch" class="sr-only"><?= e(t('nav.active_station')) ?></label>
        <i data-lucide="<?= $active ? 'map-pin' : 'globe-2' ?>"></i>
        <select id="station-switch" name="station_id" data-autosubmit title="<?= e(t('nav.active_station')) ?>">
            <option value=""><?= e(t('common.all_stations')) ?></option>
            <?php foreach ($stationContext->stations() as $station): ?>
                <option value="<?= e($station['id']) ?>"<?= $station['id'] === $stationContext->activeId() ? ' selected' : '' ?>><?= e($station['name'] . ($station['is_active'] ? '' : ' — ' . t('stations.inactive'))) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
<?php elseif ($active !== null): ?>
    <span class="station-switch station-switch--single" title="<?= e(t('nav.active_station')) ?>"><i data-lucide="map-pin"></i><?= e($active['name']) ?></span>
<?php endif; ?>
