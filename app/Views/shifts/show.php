<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array $shift  @var array $summary  @var list<array<string, mixed>> $outputs
 * @var bool $canRecord  @var bool $canValidate  @var list<string> $outputTypes  @var array $cash contrôle de caisse
 */
$tones = ['open' => 'info', 'closed' => 'warning', 'validated' => 'success'];
$id = $shift['id'];
$last = $summary['segments'][count($summary['segments']) - 1] ?? ['nozzles' => []];
$hasRollover = false;
$n2 = static fn (?string $v): string => $v === null ? '—' : format_number($v, 2);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="gauge"></i></span>
        <div>
            <h1><?= e($title) ?><?= $shift['label'] ? ' — ' . e($shift['label']) : '' ?></h1>
            <div class="page-title__meta">
                <span><i data-lucide="map-pin"></i><?= e($shift['station']) ?></span>
                <span><i data-lucide="user"></i><?= e($shift['attendant']) ?></span>
                <span class="badge badge--<?= e($tones[$shift['status']]) ?>"><?= e(label('shift_status', $shift['status'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/shifts')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <a class="btn btn--lg" href="<?= e(url("/shifts/$id/cash")) ?>"><i data-lucide="wallet"></i><?= e(t('cash.title')) ?></a>
        <?php if ($canRecord): ?>
            <button type="button" class="btn btn--lg" data-open="#new-output"><i data-lucide="droplet"></i><?= e(t('shifts.output_action')) ?></button>
            <a class="btn btn--primary btn--lg" href="<?= e(url("/shifts/$id/close")) ?>"><i data-lucide="flag"></i><?= e(t('shifts.close_action')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="kpis">
    <div class="kpi"><div class="kpi__label"><?= e(t('shifts.delivered')) ?></div><div class="kpi__value"><?= e(format_number($summary['delivered_l'], 2)) ?> L</div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('shifts.non_sale')) ?></div><div class="kpi__value"><?= e(format_number($summary['non_sale_l'], 2)) ?> L</div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('shifts.sold')) ?></div><div class="kpi__value"><?= e(format_number($summary['sold_l'], 2)) ?> L</div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('shifts.expected')) ?></div><div class="kpi__value"><?= e(format_money($summary['expected_cdf'], 'CDF')) ?></div>
        <?php if (!$summary['complete']): ?><div class="kpi__hint"><?= e(t('shifts.partial')) ?></div><?php endif; ?></div>
</div>

<?php foreach ($summary['segments'] as $segment): ?>
    <section class="card">
        <div class="card__title">
            <h2><?= e(t('shifts.segment', ['n' => $segment['sequence']])) ?> <span class="muted small"><?= e(label('segment_reason', $segment['reason'])) ?> · <?= e(format_date($segment['started_at'], true)) ?></span></h2>
            <span class="chip">1 $ = <?= e(format_money($segment['rate'], 'CDF')) ?></span>
        </div>
        <div class="table-wrap">
            <table class="table" data-no-dt>
                <thead><tr>
                    <th><?= e(t('fields.nozzle')) ?></th><th><?= e(t('fields.product')) ?></th>
                    <th class="num"><?= e(t('fields.opening_index')) ?></th><th class="num"><?= e(t('fields.final_index')) ?></th>
                    <th class="num"><?= e(t('shifts.delivered')) ?></th><th class="num"><?= e(t('shifts.non_sale')) ?></th><th class="num"><?= e(t('shifts.sold')) ?></th>
                    <th class="num"><?= e(t('fields.unit_price')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($segment['nozzles'] as $nozzle): ?>
                    <?php $hasRollover = $hasRollover || $nozzle['is_rollover']; ?>
                    <tr>
                        <td><strong><?= e($nozzle['code']) ?></strong></td>
                        <td><?= e($nozzle['product']) ?></td>
                        <td class="num"><?= e($n2($nozzle['opening'])) ?></td>
                        <td class="num"><?= e($n2($nozzle['closing'])) ?><?php if ($nozzle['is_rollover']): ?> <span class="badge badge--warning"><?= e(t('shifts.rollover_badge')) ?></span><?php endif; ?></td>
                        <td class="num"><?= e($n2($nozzle['delivered_l'])) ?></td>
                        <td class="num"><?= e($n2($nozzle['non_sale_l'])) ?></td>
                        <td class="num"><strong><?= e($n2($nozzle['sold_l'])) ?></strong></td>
                        <td class="num"><?= e(format_money($nozzle['unit_price'], $nozzle['currency'])) ?></td>
                        <td class="num"><?= $nozzle['amount_cdf'] === null ? '—' : e(format_money($nozzle['amount_cdf'], 'CDF')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t('shifts.outputs_title')) ?></h2></div>
    <?php if ($outputs === []): ?>
        <p class="empty"><?= e(t('shifts.outputs_none')) ?></p>
    <?php else: ?>
        <table class="table" data-no-dt>
            <thead><tr><th><?= e(t('fields.created_at')) ?></th><th><?= e(t('fields.nozzle')) ?></th><th><?= e(t('fields.output_type')) ?></th><th class="num"><?= e(t('fields.volume_l')) ?></th><th><?= e(t('fields.note')) ?></th><th><?= e(t('fields.author')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($outputs as $output): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($output['occurred_at'], true)) ?></td>
                    <td><strong><?= e($output['nozzle']) ?></strong> <span class="muted"><?= e($output['product']) ?></span></td>
                    <td><?= e(label('output_type', $output['output_type'])) ?></td>
                    <td class="num"><?= e(format_number($output['volume_l'], 2)) ?></td>
                    <td><?= e($output['reason']) ?></td>
                    <td><?= e($output['author']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($shift['status'] !== 'open'): ?>
    <section class="card">
        <dl class="dl">
            <dt><?= e(t('shifts.closed_by')) ?></dt><dd><?= e($shift['closed_by_name']) ?> · <?= e(format_date($shift['closed_at'], true)) ?></dd>
            <?php if ($shift['status'] === 'validated'): ?>
                <dt><?= e(t('shifts.validated_by')) ?></dt><dd><?= e($shift['validated_by_name']) ?> · <?= e(format_date($shift['validated_at'], true)) ?></dd>
            <?php endif; ?>
            <?php if ($shift['note']): ?><dt><?= e(t('fields.note')) ?></dt><dd><?= e($shift['note']) ?></dd><?php endif; ?>
        </dl>
    </section>
<?php endif; ?>

<?php if ($canValidate): ?>
    <form method="post" action="<?= e(url("/shifts/$id/validate")) ?>" class="card tablet-form" data-confirm="<?= e(t('shifts.validate_confirm')) ?>">
        <?= csrf_field() ?>
        <div class="card__title"><h2><?= e(t('shifts.validate_title')) ?></h2></div>
        <dl class="cash-lines">
            <dt><?= e(t('cash.expected')) ?></dt><dd><?= e(format_money($cash['expected_cdf'], 'CDF')) ?></dd>
            <dt><?= e(t('cash.remitted')) ?></dt><dd><?= e(format_money($cash['remitted_cdf'], 'CDF')) ?></dd>
            <dt class="cash-lines__total"><?= e(t($cash['variance_cdf'] < 0 ? 'cash.shortage' : ($cash['variance_cdf'] > 0 ? 'cash.surplus' : 'cash.balanced'))) ?></dt>
            <dd class="cash-lines__total"><?= e(format_money($cash['variance_cdf'], 'CDF')) ?></dd>
        </dl>
        <p class="muted small"><?= e(t('cash.validate_hint')) ?></p>
        <?php if ($hasRollover): ?>
            <div class="alert alert--error"><i data-lucide="rotate-ccw"></i><div>
                <?= e(t('shifts.rollover_warning')) ?>
                <label class="check"><input type="checkbox" name="confirm_rollover" value="1" required> <strong><?= e(t('shifts.rollover_confirm')) ?></strong></label>
            </div></div>
        <?php endif; ?>
        <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'value' => old('note'), 'attrs' => ['maxlength' => 255]]) ?>
        <div class="form-actions"><button type="submit" class="btn btn--primary btn--lg"><i data-lucide="check-check"></i><?= e(t('shifts.validate_action')) ?></button></div>
    </form>
<?php endif; ?>

<?php if ($canRecord): ?>
    <template id="new-output">
        <form method="post" action="<?= e(url("/shifts/$id/outputs")) ?>" class="grid tablet-form">
            <?= csrf_field() ?>
            <h2><?= e(t('shifts.output_action')) ?></h2>
            <label class="field"><?= e(t('fields.nozzle')) ?>
                <select name="nozzle_id" class="input" required>
                    <?php foreach ($last['nozzles'] as $nozzle): ?><option value="<?= e($nozzle['nozzle_id']) ?>"><?= e($nozzle['code'] . ' — ' . $nozzle['product']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.output_type')) ?>
                <select name="output_type" class="input" required>
                    <?php foreach ($outputTypes as $type): ?><option value="<?= e($type) ?>"><?= e(label('output_type', $type)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><?= e(t('fields.volume_l')) ?><input name="volume_l" class="input input--xl" required inputmode="decimal" autocomplete="off"></label>
            <label class="field"><?= e(t('fields.note')) ?><input name="reason" class="input" required maxlength="255"></label>
        </form>
    </template>
<?php endif; ?>
