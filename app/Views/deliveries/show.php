<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $delivery  @var array<string, mixed> $figures */
$n = static fn (mixed $v, int $d = 2): string => $v === null ? '—' : format_number((string) $v, $d);
$gap = static function (?string $value, bool $exceeds) use ($n): string {
    $tone = !$exceeds ? 'success' : (str_starts_with((string) $value, '-') ? 'danger' : 'warning');

    return '<div class="cash-variance cash-variance--' . $tone . '"><strong>' . e($n($value)) . ' L</strong></div>';
};
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="truck"></i></span>
        <div>
            <h1><?= e($delivery['supplier_name']) ?></h1>
            <div class="page-title__meta">
                <?php if ($delivery['delivery_note_no']): ?><span class="chip"><?= e(t('deliveries.note_no', ['no' => $delivery['delivery_note_no']])) ?></span><?php endif; ?>
                <span><i data-lucide="map-pin"></i><?= e($delivery['station']) ?></span>
                <span><i data-lucide="cylinder"></i><?= e($delivery['tank'] . ' — ' . $delivery['product']) ?></span>
                <span><i data-lucide="calendar"></i><?= e(format_date($delivery['delivered_at'], true)) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions"><a class="btn" href="<?= e(url('/deliveries')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a></div>
</div>

<div class="grid grid--2">
    <section class="card">
        <div class="card__title"><h2><?= e(t('deliveries.gap_announced')) ?></h2></div>
        <dl class="cash-lines">
            <dt><?= e(t('fields.ordered_volume_l')) ?></dt><dd><?= e($n($delivery['ordered_volume_l'])) ?></dd>
            <dt><?= e(t('fields.invoiced_volume_l')) ?></dt><dd><?= e($n($delivery['invoiced_volume_l'])) ?></dd>
            <dt><?= e(t('fields.received_volume_l')) ?></dt><dd><?= e($n($delivery['received_volume_l'])) ?></dd>
        </dl>
        <?= $gap((string) $delivery['announced_variance_l'], $figures['announced_exceeds']) ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('deliveries.gap_tank')) ?></h2></div>
        <dl class="cash-lines">
            <dt><?= e(t('deliveries.dip_before', ['cm' => $n($delivery['before_cm'], 1)])) ?></dt><dd><?= e($n($delivery['before_l'])) ?></dd>
            <dt><?= e(t('deliveries.dip_after', ['cm' => $n($delivery['after_cm'], 1)])) ?></dt><dd><?= e($n($delivery['after_l'])) ?></dd>
            <dt class="cash-lines__total"><?= e(t('deliveries.tank_variation')) ?></dt><dd class="cash-lines__total"><?= e($n($figures['tank_variation_l'])) ?></dd>
        </dl>
        <?= $gap((string) $delivery['tank_variance_l'], $figures['tank_exceeds']) ?>
    </section>
</div>

<section class="card">
    <dl class="dl">
        <dt><?= e(t('fields.received_by')) ?></dt><dd><?= e($delivery['receiver']) ?></dd>
        <dt><?= e(t('deliveries.entered_by')) ?></dt><dd><?= e($delivery['author']) ?></dd>
        <?php if ($delivery['truck_plate']): ?><dt><?= e(t('fields.truck_plate')) ?></dt><dd><?= e($delivery['truck_plate']) ?></dd><?php endif; ?>
        <?php if ($delivery['driver_name']): ?><dt><?= e(t('fields.driver_name')) ?></dt><dd><?= e($delivery['driver_name']) ?></dd><?php endif; ?>
        <dt><?= e(t('deliveries.tolerance')) ?></dt><dd><?= e($n($figures['tolerance_l'])) ?> L</dd>
        <?php if ($delivery['note']): ?><dt><?= e(t('fields.observations')) ?></dt><dd><?= e($delivery['note']) ?></dd><?php endif; ?>
    </dl>
</section>

<?php if ($delivery['photo_path'] !== null): ?>
    <section class="card">
        <div class="card__title"><h2><?= e(t('fields.photo')) ?></h2></div>
        <a href="<?= e(url('/deliveries/' . $delivery['id'] . '/photo')) ?>" target="_blank" rel="noopener">
            <img class="delivery-photo" src="<?= e(url('/deliveries/' . $delivery['id'] . '/photo')) ?>" alt="<?= e(t('deliveries.photo_alt', ['supplier' => $delivery['supplier_name']])) ?>">
        </a>
    </section>
<?php endif; ?>
