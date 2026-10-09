<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array $station  @var list<array{label: string, start: string, end: string}> $schedule  @var array<string, string> $thresholds
 * @var list<string> $timezones  @var list<array<string, mixed>> $products  @var list<array<string, mixed>> $tanks
 * @var bool $canSettings  @var bool $canCatalog
 */
$id = $station['id'];
$active = (int) $station['is_active'] === 1;
/** @var string $shiftMode  @var list<array<string, mixed>> $pumps */
$activePumps = array_values(array_filter($pumps, static fn (array $p): bool => (bool) $p['is_active']));
$slots = array_pad($schedule, \Petra\Domain\StationSettings::MAX_SLOTS, ['label' => '', 'start' => '', 'end' => '']);
$units = ['stock_tolerance_pct' => '%', 'stock_tolerance_l' => 'L', 'low_stock_l' => 'L', 'cash_shortage_tolerance_cdf' => 'FC', 'cash_surplus_tolerance_cdf' => 'FC', 'delivery_variance_l' => 'L'];
$activeProducts = array_values(array_filter($products, static fn (array $p): bool => (int) $p['is_active'] === 1));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="fuel"></i></span>
        <div>
            <h1><?= e($station['name']) ?></h1>
            <div class="page-title__meta">
                <span class="chip"><?= e($station['code']) ?></span>
                <?php if ($station['city']): ?><span><i data-lucide="map-pin"></i><?= e($station['city']) ?></span><?php endif; ?>
                <span><i data-lucide="clock"></i><?= e($station['timezone']) ?></span>
                <span class="badge badge--<?= $active ? 'success' : 'neutral' ?>"><?= e(t($active ? 'stations.active' : 'stations.inactive')) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/stations')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <a class="btn" href="<?= e(url("/stations/$id/edit")) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
        <?php if ($active): ?>
            <form method="post" action="<?= e(url("/stations/$id/deactivate")) ?>" data-confirm="<?= e(t('stations.deactivate_confirm', ['station' => $station['name']])) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--danger"><i data-lucide="power"></i><?= e(t('stations.deactivate')) ?></button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= e(url("/stations/$id/activate")) ?>" data-confirm="<?= e(t('stations.activate_confirm', ['station' => $station['name']])) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary"><i data-lucide="power"></i><?= e(t('stations.activate')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canSettings): ?>
<div class="grid grid--2">
    <form method="post" action="<?= e(url("/stations/$id/schedule")) ?>" class="card">
        <?= csrf_field() ?>
        <div class="card__title"><h2><?= e(t('stations.schedule_title')) ?></h2></div>
        <div class="field">
            <label for="timezone"><?= e(t('fields.timezone')) ?></label>
            <select id="timezone" name="timezone" class="input">
                <?php foreach ($timezones as $timezone): ?>
                    <option value="<?= e($timezone) ?>"<?= $timezone === $station['timezone'] ? ' selected' : '' ?>><?= e($timezone) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="shift_mode"><?= e(t('fields.shift_mode')) ?></label>
            <select id="shift_mode" name="shift_mode" class="input">
                <?php foreach (\Petra\Domain\StationSettings::MODES as $mode): ?>
                    <option value="<?= e($mode) ?>"<?= $mode === $shiftMode ? ' selected' : '' ?>><?= e(label('shift_mode', $mode)) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint"><?= e(t('stations.mode_hint')) ?></span>
        </div>
        <table class="table schedule" data-no-dt>
            <thead><tr><th><?= e(t('fields.shift_label')) ?></th><th><?= e(t('fields.start')) ?></th><th><?= e(t('fields.end')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($slots as $i => $slot): ?>
                <tr>
                    <td><input name="slots[<?= e($i) ?>][label]" class="input" maxlength="16" value="<?= e($slot['label']) ?>" aria-label="<?= e(t('fields.shift_label')) ?>"></td>
                    <td><input name="slots[<?= e($i) ?>][start]" type="time" class="input" value="<?= e($slot['start']) ?>" aria-label="<?= e(t('fields.start')) ?>"></td>
                    <td><input name="slots[<?= e($i) ?>][end]" type="time" class="input" value="<?= e($slot['end']) ?>" aria-label="<?= e(t('fields.end')) ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted small"><?= e(t('stations.schedule_hint')) ?></p>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button></div>
    </form>

    <form method="post" action="<?= e(url("/stations/$id/thresholds")) ?>" class="card">
        <?= csrf_field() ?>
        <div class="card__title"><h2><?= e(t('stations.thresholds_title')) ?></h2></div>
        <div class="form-grid">
            <?php foreach ($thresholds as $key => $value): ?>
                <div class="field">
                    <label for="th-<?= e($key) ?>"><?= e(t('fields.' . $key)) ?> (<?= e($units[$key]) ?>)</label>
                    <input id="th-<?= e($key) ?>" name="<?= e($key) ?>" class="input" inputmode="decimal" required value="<?= e($value) ?>">
                </div>
            <?php endforeach; ?>
            <?php foreach (\Petra\Domain\StationSettings::delays($delays ?? []) as $key => $value): ?>
                <div class="field">
                    <label for="th-<?= e($key) ?>"><?= e(t('fields.' . $key)) ?> (h)</label>
                    <input type="number" id="th-<?= e($key) ?>" name="<?= e($key) ?>" class="input" min="1" step="1" inputmode="numeric" value="<?= e(old($key, $value)) ?>">
                    <?php if ($error = field_error($key)): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="field">
            <label for="usd-rule"><?= e(t('fields.usd_unallocated_rate')) ?></label>
            <select id="usd-rule" name="usd_unallocated_rate" class="input">
                <?php foreach (\Petra\Domain\CashCalculator::USD_RULES as $rule): ?>
                    <option value="<?= e($rule) ?>"<?= $rule === $usdRule ? ' selected' : '' ?>><?= e(label('usd_rule', $rule)) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint"><?= e(t('stations.usd_rule_hint')) ?></span>
        </div>
        <p class="muted small"><?= e(t('stations.thresholds_hint')) ?></p>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button></div>
    </form>
</div>
<?php endif; ?>

<?php if ($canCatalog): ?>
<section class="card">
    <div class="card__title">
        <h2><?= e(t('products.title')) ?></h2>
        <button type="button" class="btn btn--sm" data-open="#new-product"><i data-lucide="plus"></i><?= e(t('products.new')) ?></button>
    </div>
    <?php if ($products === []): ?>
        <p class="empty"><?= e(t('products.none')) ?></p>
    <?php else: ?>
        <table class="table" data-no-dt>
            <thead><tr><th><?= e(t('fields.code')) ?></th><th><?= e(t('fields.name')) ?></th><th class="num"><?= e(t('fields.tank_count')) ?></th><th><?= e(t('fields.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <?php $on = (int) $product['is_active'] === 1; ?>
                <tr>
                    <td><strong><?= e($product['code']) ?></strong></td>
                    <td><?= e($product['name']) ?></td>
                    <td class="num"><?= e($product['tank_count']) ?></td>
                    <td><span class="badge badge--<?= $on ? 'success' : 'neutral' ?>"><?= e(t($on ? 'stations.active' : 'stations.inactive')) ?></span></td>
                    <td>
                        <form method="post" action="<?= e(url('/products/' . $product['id'] . '/active')) ?>" data-confirm="<?= e(t($on ? 'products.deactivate_confirm' : 'products.activate_confirm', ['product' => $product['name']])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="is_active" value="<?= $on ? '0' : '1' ?>">
                            <button type="submit" class="btn btn--sm"><?= e(t($on ? 'stations.deactivate' : 'stations.activate')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <template id="new-product">
        <form method="post" action="<?= e(url("/stations/$id/products")) ?>" class="grid">
            <?= csrf_field() ?>
            <h2><?= e(t('products.new')) ?></h2>
            <label class="field"><?= e(t('fields.code')) ?><input name="code" class="input" required maxlength="20" placeholder="ESS"></label>
            <label class="field"><?= e(t('fields.name')) ?><input name="name" class="input" required maxlength="80" placeholder="Essence"></label>
        </form>
    </template>
</section>

<section class="card">
    <div class="card__title">
        <h2><?= e(t('tanks.title')) ?></h2>
        <div class="page-header__actions">
            <?= $view->partial('partials/export_buttons', ['table' => 'tanks', 'exportTitle' => t('tanks.title') . ' — ' . $station['name']]) ?>
            <?php if ($activeProducts !== []): ?>
                <button type="button" class="btn btn--sm btn--primary" data-open="#new-tank"><i data-lucide="plus"></i><?= e(t('tanks.new')) ?></button>
            <?php endif; ?>
        </div>
    </div>
    <table class="display" style="width:100%" data-table="tanks" data-source="<?= e(url("/stations/$id/tanks/data")) ?>" data-row-link="<?= e(url('/tanks/{id}')) ?>"></table>
    <template id="new-tank">
        <form method="post" action="<?= e(url("/stations/$id/tanks")) ?>" class="grid">
            <?= csrf_field() ?>
            <h2><?= e(t('tanks.new')) ?></h2>
            <label class="field"><?= e(t('fields.product')) ?>
                <select name="product_id" class="input" required>
                    <?php foreach ($activeProducts as $product): ?><option value="<?= e($product['id']) ?>"><?= e($product['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.code')) ?><input name="code" class="input" required maxlength="20" placeholder="C-ESS"></label>
            <label class="field"><?= e(t('fields.name')) ?><input name="name" class="input" required maxlength="80"></label>
            <label class="field"><?= e(t('fields.capacity_l')) ?><input name="capacity_l" class="input" required inputmode="decimal" placeholder="30000"></label>
        </form>
    </template>
</section>

<section class="card" id="pumps">
    <div class="card__title">
        <h2><?= e(t('pumps.title')) ?></h2>
        <div class="page-header__actions">
            <button type="button" class="btn btn--sm btn--primary" data-open="#new-pump"><i data-lucide="plus"></i><?= e(t('pumps.new')) ?></button>
        </div>
    </div>
    <p class="muted small"><?= e(t('pumps.hint')) ?></p>
    <?php if ($pumps === []): ?>
        <p class="muted"><?= e(t('pumps.none')) ?></p>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact" data-no-dt data-pumps>
        <thead><tr>
            <th><?= e(t('fields.code')) ?></th><th><?= e(t('fields.name')) ?></th><th class="num"><?= e(t('pumps.col_nozzles')) ?></th>
            <th><?= e(t('pumps.col_products')) ?></th><th><?= e(t('pumps.col_type')) ?></th><th><?= e(t('fields.status')) ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($pumps as $pump): ?>
            <?php $kind = $pump['nozzle_count'] === 0 ? 'empty' : ($pump['product_count'] > 1 ? 'multi' : 'mono'); ?>
            <tr data-pump="<?= e($pump['code']) ?>"<?= $pump['is_active'] ? '' : ' class="muted"' ?>>
                <td><span class="chip"><?= e($pump['code']) ?></span></td>
                <td><strong><?= e($pump['name']) ?></strong></td>
                <td class="num"><?= e($pump['nozzle_count']) ?><?php if ($pump['nozzle_codes'] !== null): ?><br><span class="muted small"><?= e($pump['nozzle_codes']) ?></span><?php endif; ?></td>
                <td><?= e($pump['products'] ?? '—') ?></td>
                <td><span class="badge badge--<?= $kind === 'multi' ? 'info' : 'neutral' ?>"><?= e(label('pump_kind', $kind)) ?></span></td>
                <td><span class="badge badge--<?= $pump['is_active'] ? 'success' : 'neutral' ?>"><?= e(t($pump['is_active'] ? 'pumps.active' : 'pumps.inactive')) ?></span></td>
                <td class="num">
                    <button type="button" class="btn btn--sm" data-open="#edit-pump-<?= e($pump['id']) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></button>
                    <template id="edit-pump-<?= e($pump['id']) ?>">
                        <form method="post" action="<?= e(url('/pumps/' . $pump['id'])) ?>" class="grid">
                            <?= csrf_field() ?>
                            <h2><?= e(t('pumps.edit', ['code' => $pump['code']])) ?></h2>
                            <label class="field"><?= e(t('fields.code')) ?><input name="code" class="input" required maxlength="20" value="<?= e($pump['code']) ?>"></label>
                            <label class="field"><?= e(t('fields.name')) ?><input name="name" class="input" required maxlength="80" value="<?= e($pump['name']) ?>"></label>
                            <label class="field"><?= e(t('fields.status')) ?>
                                <select name="is_active" class="input">
                                    <option value="1"<?= $pump['is_active'] ? ' selected' : '' ?>><?= e(t('pumps.active')) ?></option>
                                    <option value="0"<?= $pump['is_active'] ? '' : ' selected' ?>><?= e(t('pumps.inactive')) ?></option>
                                </select>
                            </label>
                        </form>
                    </template>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <template id="new-pump">
        <form method="post" action="<?= e(url("/stations/$id/pumps")) ?>" class="grid">
            <?= csrf_field() ?>
            <h2><?= e(t('pumps.new')) ?></h2>
            <label class="field"><?= e(t('fields.code')) ?><input name="code" class="input" required maxlength="20" placeholder="D1"></label>
            <label class="field"><?= e(t('fields.name')) ?><input name="name" class="input" required maxlength="80" placeholder="Distributeur 1"></label>
        </form>
    </template>
</section>

<section class="card">
    <div class="card__title">
        <h2><?= e(t('nozzles.title')) ?></h2>
        <div class="page-header__actions">
            <?= $view->partial('partials/export_buttons', ['table' => 'nozzles', 'exportTitle' => t('nozzles.title') . ' — ' . $station['name']]) ?>
            <?php if ($tanks !== [] && $activePumps !== []): ?>
                <button type="button" class="btn btn--sm btn--primary" data-open="#new-nozzle"><i data-lucide="plus"></i><?= e(t('nozzles.new')) ?></button>
            <?php endif; ?>
        </div>
    </div>
    <table class="display" style="width:100%" data-table="nozzles" data-source="<?= e(url("/stations/$id/nozzles/data")) ?>" data-row-link="<?= e(url('/nozzles/{id}')) ?>"></table>
    <template id="new-nozzle">
        <form method="post" action="<?= e(url("/stations/$id/nozzles")) ?>" class="grid">
            <?= csrf_field() ?>
            <h2><?= e(t('nozzles.new')) ?></h2>
            <label class="field"><?= e(t('fields.pump')) ?>
                <select name="pump_id" class="input" required>
                    <?php foreach ($activePumps as $pump): ?><option value="<?= e($pump['id']) ?>"><?= e($pump['code'] . ' — ' . $pump['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.tank')) ?>
                <select name="tank_id" class="input" required>
                    <?php foreach ($tanks as $tank): ?><option value="<?= e($tank['id']) ?>"><?= e($tank['code'] . ' — ' . $tank['product']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.code')) ?><input name="code" class="input" required maxlength="20" placeholder="P-ESS-1"></label>
            <label class="field"><?= e(t('fields.name')) ?><input name="name" class="input" required maxlength="80"></label>

        </form>
    </template>
</section>
<?php endif; ?>
