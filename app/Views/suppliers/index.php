<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * Répertoire des fournisseurs.
 *
 * @var list<array<string, mixed>> $suppliers  @var bool $several  @var bool $canManage  @var bool $canCreate  @var bool $needsStation
 */
$active = count(array_filter($suppliers, static fn (array $s): bool => (bool) $s['is_active']));
?>
<div data-live>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="contact"></i></span>
        <div>
            <h1><?= e(t('nav.suppliers')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('suppliers.count', ['active' => $active, 'total' => count($suppliers)])) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/deliveries')) ?>"><i data-lucide="truck"></i><?= e(t('nav.deliveries')) ?></a>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn--primary btn--lg" data-modal-url="<?= e(url('/suppliers/form')) ?>" data-modal-width="640"><i data-lucide="plus"></i><?= e(t('suppliers.new')) ?></button>
        <?php endif; ?>
    </div>
</div>

<?php if ($needsStation): ?>
    <div class="alert alert--info"><i data-lucide="info"></i><div><?= e(t('suppliers.pick_station')) ?></div></div>
<?php endif; ?>

<?php if ($suppliers === []): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('deliveries') ?><p><?= e(t('suppliers.none')) ?></p></div></div>
<?php else: ?>
<section class="card">
    <div class="table-wrap"><table class="table table--compact" data-suppliers>
        <thead><tr>
            <th><?= e(t('fields.supplier_name')) ?></th>
            <?php if ($several): ?><th><?= e(t('fields.station')) ?></th><?php endif; ?>
            <th><?= e(t('fields.contact_name')) ?></th><th><?= e(t('fields.phone')) ?></th>
            <th class="num"><?= e(t('suppliers.col_deliveries')) ?></th><th class="num"><?= e(t('suppliers.col_received')) ?></th>
            <th><?= e(t('suppliers.col_last')) ?></th><th><?= e(t('fields.status')) ?></th>
            <?php if ($canManage): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($suppliers as $supplier): ?>
            <tr data-supplier="<?= e($supplier['name']) ?>"<?= $supplier['is_active'] ? '' : ' class="muted"' ?>>
                <td><strong><?= e($supplier['name']) ?></strong><?php if ($supplier['address'] !== null): ?><br><span class="muted small"><?= e($supplier['address']) ?></span><?php endif; ?></td>
                <?php if ($several): ?><td><?= e($supplier['station']) ?></td><?php endif; ?>
                <td><?= e($supplier['contact_name'] ?? '—') ?></td>
                <td class="nowrap"><?= e($supplier['phone'] ?? '—') ?><?php if ($supplier['email'] !== null): ?><br><span class="muted small"><?= e($supplier['email']) ?></span><?php endif; ?></td>
                <td class="num"><?= e($supplier['delivery_count']) ?></td>
                <td class="num nowrap"><?= e(format_number($supplier['received_l'], 0)) ?> L</td>
                <td class="nowrap"><?= $supplier['last_delivery_at'] === null ? '—' : e(format_date($supplier['last_delivery_at'])) ?></td>
                <td><span class="badge badge--<?= $supplier['is_active'] ? 'success' : 'neutral' ?>"><?= e(t($supplier['is_active'] ? 'suppliers.active' : 'suppliers.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                    <td class="num"><button type="button" class="btn btn--sm" data-modal-url="<?= e(url('/suppliers/' . $supplier['id'] . '/form')) ?>" data-modal-width="640"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></button></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
</div>
