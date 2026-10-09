<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array|null $station  @var list<array<string, mixed>> $products  @var array|null $rate
 * @var int $openShifts  @var bool $canPrices  @var bool $canRates
 */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="badge-dollar-sign"></i></span>
        <div>
            <h1><?= e(t('nav.prices')) ?></h1>
            <?php if ($station !== null): ?><div class="page-title__meta"><span><i data-lucide="map-pin"></i><?= e($station['name']) ?></span></div><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($station === null): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('prices') ?><p><?= e(t('pricing.pick_station')) ?></p></div></div>
<?php else: ?>
    <?php if ($openShifts > 0 && ($canPrices || $canRates)): ?>
        <div class="alert alert--info"><i data-lucide="info"></i><div><?= e(t('pricing.open_shifts_notice', ['count' => $openShifts])) ?></div></div>
    <?php endif; ?>

    <div class="grid grid--main-side">
        <section class="card">
            <div class="card__title"><h2><?= e(t('pricing.current_prices')) ?></h2></div>
            <?php if ($products === []): ?>
                <p class="empty"><?= e(t('products.none')) ?></p>
            <?php else: ?>
                <table class="table" data-no-dt>
                    <thead><tr><th><?= e(t('fields.product')) ?></th><th class="num"><?= e(t('fields.unit_price')) ?></th><th><?= e(t('fields.effective_at')) ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><strong><?= e($product['name']) ?></strong> <span class="muted"><?= e($product['code']) ?></span></td>
                            <td class="num">
                                <?php if ($product['price'] === null): ?>
                                    <span class="badge badge--warning"><?= e(t('pricing.no_price')) ?></span>
                                <?php else: ?>
                                    <strong><?= e(format_money($product['price']['unit_price'], $product['price']['currency'])) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= e(format_date($product['price']['effective_at'] ?? null, true)) ?></td>
                            <td>
                                <?php if ($canPrices): ?>
                                    <a class="btn btn--sm" href="<?= e(url('/prices/products/' . $product['id'] . '/new')) ?>"><i data-lucide="pencil"></i><?= e(t('pricing.change')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= e(t('pricing.current_rate')) ?></h2></div>
            <?php if ($rate === null): ?>
                <p><span class="badge badge--warning"><?= e(t('pricing.no_rate')) ?></span></p>
            <?php else: ?>
                <p class="kpi__value">1 $ = <?= e(format_money($rate['rate'], 'CDF')) ?></p>
                <p class="muted small"><?= e(t('pricing.since', ['date' => format_date($rate['effective_at'], true)])) ?></p>
            <?php endif; ?>
            <?php if ($canRates): ?>
                <div class="form-actions"><a class="btn btn--primary" href="<?= e(url('/rates/new')) ?>"><i data-lucide="pencil"></i><?= e(t('pricing.change_rate')) ?></a></div>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__title">
        <h2><?= e(t('pricing.price_history')) ?></h2>
        <div class="page-header__actions"><?= $view->partial('partials/export_buttons', ['table' => 'prices', 'exportTitle' => t('pricing.price_history')]) ?></div>
    </div>
    <table class="display" style="width:100%" data-table="prices" data-source="<?= e(url('/prices/data')) ?>"></table>
</section>

<section class="card">
    <div class="card__title">
        <h2><?= e(t('pricing.rate_history')) ?></h2>
        <div class="page-header__actions"><?= $view->partial('partials/export_buttons', ['table' => 'rates', 'exportTitle' => t('pricing.rate_history')]) ?></div>
    </div>
    <table class="display" style="width:100%" data-table="rates" data-source="<?= e(url('/rates/data')) ?>"></table>
</section>
