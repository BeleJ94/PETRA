<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array $shift  @var array $cash  @var list<array<string, mixed>> $segments
 * @var list<array<string, mixed>> $handovers  @var list<array<string, mixed>> $credits  @var list<array<string, mixed>> $expenses
 * @var list<array<string, mixed>> $justifications  @var string $usdRule  @var list<string> $categories
 * @var bool $canHandover  @var bool $canCredit  @var bool $canExpense  @var bool $canJustify
 */
$id = $shift['id'];
$cdf = static fn (int $amount): string => format_money($amount, 'CDF');
$variance = $cash['variance_cdf'];
$tone = $variance < 0 ? 'danger' : ($variance > 0 ? 'warning' : 'success');
$many = count($segments) > 1;
$last = $segments[count($segments) - 1] ?? ['nozzles' => []];
$segmentSelect = static function (bool $unallocated) use ($segments, $many, $usdRule): string {
    if (!$many) {
        return '';
    }
    $html = '<label class="field">' . e(t('fields.segment')) . '<select name="segment_id" class="input">';
    $html .= '<option value="">' . e($unallocated ? t('cash.unallocated', ['rule' => label('usd_rule', $usdRule)]) : t('cash.last_segment')) . '</option>';
    foreach ($segments as $segment) {
        $html .= '<option value="' . e($segment['segment_id']) . '">' . e(t('shifts.segment', ['n' => $segment['sequence']]) . ' — 1 $ = ' . format_money($segment['rate'], 'CDF')) . '</option>';
    }

    return $html . '</select></label>';
};
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="wallet"></i></span>
        <div>
            <h1><?= e(t('cash.title')) ?></h1>
            <div class="page-title__meta">
                <span><?= e($shift['station']) ?></span><span><?= e(format_date($shift['business_date'])) ?><?= $shift['label'] ? ' — ' . e($shift['label']) : '' ?></span>
                <span><i data-lucide="user"></i><?= e($shift['attendant']) ?></span>
                <span class="badge badge--<?= e(['open' => 'info', 'closed' => 'warning', 'validated' => 'success'][$shift['status']]) ?>"><?= e(label('shift_status', $shift['status'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url("/shifts/$id")) ?>"><i data-lucide="arrow-left"></i><?= e(t('cash.back_to_shift')) ?></a>
        <?php if ($canCredit): ?><button type="button" class="btn btn--lg" data-open="#new-credit"><i data-lucide="receipt"></i><?= e(t('cash.credit_action')) ?></button><?php endif; ?>
        <?php if ($canExpense): ?><button type="button" class="btn btn--lg" data-open="#new-expense"><i data-lucide="hand-coins"></i><?= e(t('cash.expense_action')) ?></button><?php endif; ?>
        <?php if ($canHandover): ?><button type="button" class="btn btn--primary btn--lg" data-open="#new-handover"><i data-lucide="banknote"></i><?= e(t('cash.handover_action')) ?></button><?php endif; ?>
    </div>
</div>

<?php if (!$cash['complete']): ?>
    <div class="alert alert--info"><i data-lucide="info"></i><div><?= e(t('cash.partial')) ?></div></div>
<?php endif; ?>

<section class="card cash-statement">
    <dl class="cash-lines">
        <dt><?= e(t('cash.theoretical')) ?></dt><dd><?= e($cdf($cash['theoretical_cdf'])) ?></dd>
        <dt><?= e(t('cash.credit')) ?></dt><dd>− <?= e($cdf($cash['credit_cdf'])) ?></dd>
        <dt><?= e(t('cash.expenses')) ?></dt><dd>− <?= e($cdf($cash['expenses_cdf'])) ?></dd>
        <dt class="cash-lines__total"><?= e(t('cash.expected')) ?></dt><dd class="cash-lines__total"><?= e($cdf($cash['expected_cdf'])) ?></dd>
        <dt><?= e(t('cash.remitted_cdf')) ?></dt><dd><?= e($cdf($cash['remitted_cdf_cash'])) ?></dd>
        <dt><?= e(t('cash.remitted_usd', ['usd' => format_money($cash['remitted_usd'], 'USD')])) ?></dt><dd><?= e($cdf($cash['remitted_usd_cdf'])) ?></dd>
        <dt class="cash-lines__total"><?= e(t('cash.remitted')) ?></dt><dd class="cash-lines__total"><?= e($cdf($cash['remitted_cdf'])) ?></dd>
    </dl>
    <?php
    // Position de l'écart sur la jauge : la zone tolérée occupe la moitié centrale, d'un seuil à l'autre.
    $limit = $variance < 0 ? (int) ($tolerances['cash_shortage_tolerance_cdf'] ?? 0) : (int) ($tolerances['cash_surplus_tolerance_cdf'] ?? 0);
    $magnitude = abs($variance);
    $offset = $limit > 0 ? ($magnitude <= $limit ? intdiv($magnitude * 25, $limit) : 25 + min(23, intdiv(($magnitude - $limit) * 23, $limit * 4))) : ($magnitude > 0 ? 48 : 0);
    $needle = 50 + ($variance < 0 ? -$offset : $offset);
    ?>
    <div class="cash-variance cash-variance--<?= e($tone) ?>">
        <span><?= e(t($variance < 0 ? 'cash.shortage' : ($variance > 0 ? 'cash.surplus' : 'cash.balanced'))) ?></span>
        <strong><?= e($cdf($variance)) ?></strong>
        <div class="cash-gauge" role="img" aria-label="<?= e(t('cash.gauge', ['shortage' => $cdf((int) ($tolerances['cash_shortage_tolerance_cdf'] ?? 0)), 'surplus' => $cdf((int) ($tolerances['cash_surplus_tolerance_cdf'] ?? 0))])) ?>">
            <span class="cash-gauge__zone cash-gauge__zone--shortage"></span><span class="cash-gauge__zone cash-gauge__zone--ok"></span><span class="cash-gauge__zone cash-gauge__zone--surplus"></span>
            <span class="cash-gauge__needle" style="left: <?= e($needle) ?>%"></span>
        </div>
        <div class="cash-gauge__legend"><span><?= e(t('cash.shortage')) ?></span><span><?= e(t('cash.tolerated')) ?></span><span><?= e(t('cash.surplus')) ?></span></div>        <?php if ($justifications !== []): ?>
            <small><?= e(t('cash.residual', ['justified' => $cdf($cash['justified_cdf']), 'residual' => $cdf($cash['residual_cdf'])])) ?></small>
        <?php endif; ?>
    </div>
</section>

<?php if ($many): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('cash.by_segment')) ?></h2></div>
    <div class="table-wrap"><table class="table" data-no-dt>
        <thead><tr><th><?= e(t('fields.segment')) ?></th><th class="num"><?= e(t('fields.rate')) ?></th><th class="num"><?= e(t('cash.theoretical')) ?></th><th class="num"><?= e(t('cash.credit')) ?></th><th class="num"><?= e(t('cash.expenses')) ?></th><th class="num"><?= e(t('cash.remitted_cdf')) ?></th><th class="num">USD</th><th class="num"><?= e(t('cash.usd_value')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($cash['segments'] as $segment): ?>
            <tr>
                <td><?= e(t('shifts.segment', ['n' => $segment['sequence']])) ?></td>
                <td class="num"><?= e($cdf($segment['rate'])) ?></td>
                <td class="num"><?= e($cdf($segment['theoretical_cdf'])) ?></td>
                <td class="num"><?= e($cdf($segment['credit_cdf'])) ?></td>
                <td class="num"><?= e($cdf($segment['expenses_cdf'])) ?></td>
                <td class="num"><?= e($cdf($segment['remitted_cdf_cash'])) ?></td>
                <td class="num"><?= e(format_money($segment['remitted_usd'], 'USD')) ?></td>
                <td class="num"><?= e($cdf($segment['remitted_usd_cdf'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t('cash.handovers_title')) ?></h2></div>
    <?php if ($handovers === []): ?><p class="empty"><?= e(t('cash.handovers_none')) ?></p><?php else: ?>
    <table class="table" data-no-dt>
        <thead><tr><th><?= e(t('fields.created_at')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th><?= e(t('fields.segment')) ?></th><th class="num"><?= e(t('cash.value_cdf')) ?></th><th><?= e(t('cash.received_by')) ?></th><th><?= e(t('fields.note')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($handovers as $row): ?>
            <tr>
                <td class="nowrap"><?= e(format_date($row['handed_at'], true)) ?></td>
                <td class="num"><strong><?= e(format_money((int) $row['amount'], $row['currency'])) ?></strong></td>
                <td><?= e(t('shifts.segment', ['n' => $row['sequence']])) ?><?= $row['rate'] !== null ? ' · ' . e($cdf((int) $row['rate'])) : '' ?></td>
                <td class="num"><?= e($cdf((int) $row['amount_cdf'])) ?></td>
                <td><?= e($row['received_by_name']) ?></td>
                <td><?= e($row['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<div class="grid grid--2">
    <section class="card">
        <div class="card__title"><h2><?= e(t('cash.credits_title')) ?></h2></div>
        <?php if ($credits === []): ?><p class="empty"><?= e(t('cash.credits_none')) ?></p><?php else: ?>
        <table class="table" data-no-dt>
            <thead><tr><th><?= e(t('fields.customer_name')) ?></th><th><?= e(t('fields.nozzle')) ?></th><th class="num"><?= e(t('fields.volume_l')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($credits as $row): ?>
                <tr>
                    <td><strong><?= e($row['customer_name']) ?></strong><?= $row['voucher_no'] ? '<br><span class="muted">' . e(t('cash.voucher', ['no' => $row['voucher_no']])) . '</span>' : '' ?></td>
                    <td><?= e($row['nozzle']) ?> <span class="muted"><?= e($row['product']) ?></span></td>
                    <td class="num"><?= e(format_number($row['volume_l'], 2)) ?></td>
                    <td class="num"><?= e(format_money((int) $row['amount'], $row['currency'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('cash.expenses_title')) ?></h2></div>
        <?php if ($expenses === []): ?><p class="empty"><?= e(t('cash.expenses_none')) ?></p><?php else: ?>
        <table class="table" data-no-dt>
            <thead><tr><th><?= e(t('fields.category')) ?></th><th><?= e(t('fields.description')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th class="num"><?= e(t('cash.value_cdf')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($expenses as $row): ?>
                <tr>
                    <td><?= e(label('expense_category', $row['category'])) ?></td>
                    <td><?= e($row['description']) ?><br><span class="muted"><?= e($row['author']) ?></span></td>
                    <td class="num"><?= e(format_money((int) $row['amount'], $row['currency'])) ?></td>
                    <td class="num"><?= e($cdf((int) $row['amount_cdf'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<?php if ($shift['status'] === 'validated'): ?>
<section class="card">
    <div class="card__title">
        <h2><?= e(t('cash.justifications_title')) ?></h2>
        <?php if ($canJustify && $cash['residual_cdf'] !== 0): ?><button type="button" class="btn btn--primary" data-open="#new-justification"><i data-lucide="message-square-plus"></i><?= e(t('cash.justify_action')) ?></button><?php endif; ?>
    </div>
    <?php if ($justifications === []): ?><p class="empty"><?= e(t($variance === 0 ? 'cash.nothing_to_justify' : 'cash.justifications_none')) ?></p><?php else: ?>
    <table class="table" data-no-dt>
        <thead><tr><th><?= e(t('fields.created_at')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th><?= e(t('fields.reason')) ?></th><th><?= e(t('fields.author')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($justifications as $row): ?>
            <tr><td class="nowrap"><?= e(format_date($row['created_at'], true)) ?></td><td class="num"><?= e($cdf((int) $row['amount'])) ?></td><td><?= e($row['reason']) ?></td><td><?= e($row['author']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php $currency = '<label class="field">' . e(t('fields.currency')) . '<select name="currency" class="input" required><option value="CDF">CDF (FC)</option><option value="USD">USD ($)</option></select></label>'; ?>
<?php if ($canHandover): ?>
<template id="new-handover">
    <form method="post" action="<?= e(url("/shifts/$id/handovers")) ?>" class="grid tablet-form">
        <?= csrf_field() ?>
        <h2><?= e(t('cash.handover_action')) ?></h2>
        <label class="field"><?= e(t('fields.amount')) ?><input name="amount" class="input input--xl" required inputmode="decimal" autocomplete="off"></label>
        <p class="muted small"><?= e(t('cash.zero_hint')) ?></p>
        <?= $currency ?>
        <?= $segmentSelect(true) ?>
        <label class="field"><?= e(t('fields.note')) ?><input name="note" class="input" maxlength="255"></label>
    </form>
</template>
<?php endif; ?>
<?php if ($canCredit): ?>
<template id="new-credit">
    <form method="post" action="<?= e(url("/shifts/$id/credit-sales")) ?>" class="grid tablet-form">
        <?= csrf_field() ?>
        <h2><?= e(t('cash.credit_action')) ?></h2>
        <label class="field"><?= e(t('fields.customer_name')) ?><input name="customer_name" class="input" required maxlength="120"></label>
        <label class="field"><?= e(t('fields.voucher_no')) ?><input name="voucher_no" class="input" maxlength="60"></label>
        <label class="field"><?= e(t('fields.nozzle')) ?>
            <select name="nozzle_id" class="input" required>
                <?php foreach ($last['nozzles'] as $nozzle): ?><option value="<?= e($nozzle['nozzle_id']) ?>"><?= e($nozzle['code'] . ' — ' . $nozzle['product']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="field"><?= e(t('fields.volume_l')) ?><input name="volume_l" class="input input--xl" required inputmode="decimal" autocomplete="off"></label>
        <?= $segmentSelect(false) ?>
    </form>
</template>
<?php endif; ?>
<?php if ($canExpense): ?>
<template id="new-expense">
    <form method="post" action="<?= e(url("/shifts/$id/expenses")) ?>" class="grid tablet-form">
        <?= csrf_field() ?>
        <h2><?= e(t('cash.expense_action')) ?></h2>
        <label class="field"><?= e(t('fields.category')) ?>
            <select name="category" class="input" required>
                <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?= e(label('expense_category', $category)) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="field"><?= e(t('fields.description')) ?><input name="description" class="input" required minlength="3" maxlength="255"></label>
        <label class="field"><?= e(t('fields.amount')) ?><input name="amount" class="input input--xl" required inputmode="decimal" autocomplete="off"></label>
        <?= $currency ?>
        <?= $segmentSelect(false) ?>
    </form>
</template>
<?php endif; ?>
<?php if ($canJustify): ?>
<template id="new-justification">
    <form method="post" action="<?= e(url("/shifts/$id/justifications")) ?>" class="grid tablet-form">
        <?= csrf_field() ?>
        <h2><?= e(t('cash.justify_action')) ?></h2>
        <p class="muted small"><?= e(t('cash.justify_hint', ['residual' => $cdf(abs($cash['residual_cdf']))])) ?></p>
        <label class="field"><?= e(t('cash.justified_amount')) ?><input name="amount" class="input input--xl" required inputmode="numeric" autocomplete="off" value="<?= e(abs($cash['residual_cdf'])) ?>"></label>
        <label class="field"><?= e(t('fields.reason')) ?><input name="reason" class="input" required minlength="5" maxlength="255"></label>
    </form>
</template>
<?php endif; ?>
