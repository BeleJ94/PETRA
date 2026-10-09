<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * Formulaire d'une action sur un poste, ouvert en fenêtre depuis l'écran des postes (fragment).
 * Les champs marqués data-recap figurent dans l'étape de vérification ; data-context y ajoute les repères
 * calculés par le serveur. L'envoi se fait sans recharger la page (shifts.js).
 *
 * @var string $action open | close | cash | validate
 */
$money = static fn (int $amount): string => format_money($amount, 'CDF');
$context = static fn (array $rows): string => e(json_encode($rows, JSON_UNESCAPED_UNICODE));
$sheet = static fn (string $id): string => '<a class="btn btn--ghost" href="' . e(url('/shifts/' . $id)) . '"><i data-lucide="external-link"></i>' . e(t('shifts.view_sheet')) . '</a>';
?>
<div class="shift-modal shift-form">
<?php if ($action === 'open'): ?>
    <?php /** @var array $station  @var list<array> $staff  @var list<array> $schedule  @var ?string $slot  @var ?string $preset  @var list<array> $nozzles  @var list<array> $pumps  @var string $mode  @var list<string> $blockers */ ?>
    <div class="shift-modal__head">
        <h2><?= e(t('shifts.open_title')) ?></h2>
        <div class="shift-modal__meta"><?= e($station['name'] . ' · ' . format_date(date('Y-m-d H:i:s'), true)) ?></div>
    </div>
    <?php if ($blockers !== []): ?>
        <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div>
            <?= e(t('shifts.cannot_open')) ?>
            <ul class="alert__list"><?php foreach ($blockers as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?></ul>
        </div></div>
    <?php else: ?>
    <form method="post" action="<?= e(url('/shifts')) ?>" data-ajax-form data-recap-title="<?= e(t('shifts.recap_open')) ?>" data-submit-label="<?= e(t('shifts.open_action')) ?>" data-context="<?= $context([[t('fields.station'), $station['name']]]) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.attendant')) ?>
                <select name="attendant_id" class="input" required data-recap="<?= e(t('fields.attendant')) ?>">
                    <option value=""></option>
                    <?php foreach ($staff as $person): ?>
                        <option value="<?= e($person['id']) ?>"><?= e($person['name'] . ' — ' . $person['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($mode === 'fixed'): ?>
            <label class="field"><?= e(t('fields.shift_label')) ?>
                <select name="label" class="input" data-recap="<?= e(t('fields.shift_label')) ?>">
                    <option value=""><?= e(t('shifts.outside_schedule')) ?></option>
                    <?php foreach ($schedule as $item): ?>
                        <option value="<?= e($item['label']) ?>"<?= ($preset ?? $slot) === $item['label'] ? ' selected' : '' ?>><?= e($item['label'] . ' (' . $item['start'] . ' – ' . $item['end'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php else: ?>
            <div class="field"><?= e(t('shifts.opening_time')) ?>
                <div class="nozzle-card__value" data-recap-static="<?= e(t('shifts.opening_time')) ?>" data-recap-value="<?= e(date('H:i')) ?>"><i data-lucide="clock"></i><?= e(date('H:i')) ?></div>
                <span class="hint"><?= e(t('shifts.free_hint')) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?= $view->partial('shifts/_pumps', ['pumps' => $pumps, 'recap' => true]) ?>
        <div class="shift-form__actions">
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="play"></i><?= e(t('shifts.open_action')) ?></button>
        </div>
    </form>
    <?php endif; ?>

<?php elseif ($action === 'close'): ?>
    <?php /** @var array $shift  @var array $segment */ ?>
    <div class="shift-modal__head">
        <h2><?= e(t('shifts.close_title')) ?></h2>
        <div class="shift-modal__meta"><?= e($shift['station'] . ' · ' . $shift['attendant'] . ($shift['label'] ? ' · ' . $shift['label'] : '')) ?></div>
    </div>
    <form method="post" action="<?= e(url('/shifts/' . $shift['id'] . '/close')) ?>" data-ajax-form data-recap-title="<?= e(t('shifts.recap_close')) ?>" data-submit-label="<?= e(t('shifts.close_action')) ?>"
          data-context="<?= $context([[t('reports.col_attendant'), $shift['attendant']]]) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <p class="muted small"><?= e(t('shifts.close_hint')) ?></p>
        <div class="nozzle-grid">
            <?php foreach ($segment['nozzles'] as $nozzle): ?>
                <div class="nozzle-card">
                    <div class="nozzle-card__head"><strong><?= e($nozzle['code']) ?></strong><span><?= e($nozzle['product']) ?></span></div>
                    <div class="muted small" data-recap-static="<?= e(t('fields.opening_index') . ' ' . $nozzle['code']) ?>" data-recap-value="<?= e(format_number($nozzle['opening'], 2)) ?>"><?= e(t('fields.opening_index')) ?> : <strong><?= e(format_number($nozzle['opening'], 2)) ?></strong></div>
                    <?php if ($nozzle['non_sale_l'] !== '0.00'): ?>
                        <div class="muted small"><?= e(t('shifts.non_sale')) ?> : <?= e(format_number($nozzle['non_sale_l'], 2)) ?> L</div>
                    <?php endif; ?>
                    <label class="nozzle-card__label"><?= e(t('fields.final_index')) ?>
                        <input name="final[<?= e($nozzle['nozzle_id']) ?>]" class="input input--xl" required inputmode="decimal" autocomplete="off" data-error-key="final_<?= e($nozzle['nozzle_id']) ?>"
                               data-recap="<?= e(t('fields.final_index') . ' ' . $nozzle['code']) ?>">
                    </label>
                    <label class="check"><input type="checkbox" name="rollover[<?= e($nozzle['nozzle_id']) ?>]" value="1" data-recap="<?= e(t('shifts.rollover_declare') . ' (' . $nozzle['code'] . ')') ?>"> <?= e(t('shifts.rollover_declare')) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="shift-form__actions">
            <?= $sheet($shift['id']) ?>
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="flag"></i><?= e(t('shifts.close_action')) ?></button>
        </div>
    </form>

<?php elseif ($action === 'cash'): ?>
    <?php /** @var array $shift  @var array $cash */ ?>
    <div class="shift-modal__head">
        <h2><?= e(t('shifts.cash_action')) ?></h2>
        <div class="shift-modal__meta"><?= e($shift['station'] . ' · ' . $shift['attendant'] . ($shift['label'] ? ' · ' . $shift['label'] : '')) ?></div>
    </div>
    <div class="shift-modal__figures">
        <div><span><?= e(t('cash.expected')) ?></span><strong><?= e($money($cash['expected_cdf'])) ?></strong></div>
        <div><span><?= e(t('cash.remitted')) ?></span><strong><?= e($money($cash['remitted_cdf'])) ?></strong></div>
    </div>
    <form method="post" action="<?= e(url('/shifts/' . $shift['id'] . '/handovers')) ?>" data-ajax-form data-recap-title="<?= e(t('shifts.recap_cash')) ?>" data-submit-label="<?= e(t('shifts.cash_action')) ?>"
          data-context="<?= $context([[t('reports.col_attendant'), $shift['attendant']], [t('cash.expected'), $money($cash['expected_cdf'])], [t('shifts.already_remitted'), $money($cash['remitted_cdf'])]]) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.amount')) ?>
                <input name="amount" class="input input--xl" required inputmode="decimal" autocomplete="off" data-error-key="amount" data-recap="<?= e(t('shifts.recap_amount')) ?>">
            </label>
            <label class="field"><?= e(t('fields.currency')) ?>
                <select name="currency" class="input" required data-recap="<?= e(t('fields.currency')) ?>"><option value="CDF">CDF (FC)</option><option value="USD">USD ($)</option></select>
            </label>
        </div>
        <p class="muted small"><?= e(t('cash.zero_hint')) ?></p>
        <label class="field"><?= e(t('fields.note')) ?><input name="note" class="input" maxlength="255" data-recap="<?= e(t('fields.note')) ?>"></label>
        <div class="shift-form__actions">
            <?= $sheet($shift['id']) ?>
            <a class="btn btn--ghost" href="<?= e(url('/shifts/' . $shift['id'] . '/cash')) ?>"><i data-lucide="wallet"></i><?= e(t('shifts.full_cash')) ?></a>
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('common.save')) ?></button>
        </div>
    </form>

<?php elseif ($action === 'settle'): ?>
    <?php /** @var array $shift  @var array $cash  @var array $segment */ ?>
    <?php $isOpen = $shift['status'] === 'open'; ?>
    <div class="shift-modal__head">
        <h2><?= e(t('shifts.settle_title')) ?></h2>
        <div class="shift-modal__meta"><?= e($shift['station'] . ' · ' . $shift['attendant'] . ($shift['label'] ? ' · ' . $shift['label'] : '')) ?></div>
    </div>
    <?php if (!$isOpen): ?>
        <div class="shift-modal__figures">
            <div><span><?= e(t('cash.expected')) ?></span><strong><?= e($money($cash['expected_cdf'])) ?></strong></div>
            <div><span><?= e(t('shifts.already_remitted')) ?></span><strong><?= e($money($cash['remitted_cdf'])) ?></strong></div>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/shifts/' . $shift['id'] . '/settle')) ?>" data-ajax-form data-recap-title="<?= e(t('shifts.recap_settle')) ?>" data-submit-label="<?= e(t('shifts.settle_action')) ?>"
          data-recap-warning="<?= e(t('shifts.settle_warning')) ?>"
          data-context="<?= $context(array_merge([[t('reports.col_attendant'), $shift['attendant']]], $isOpen ? [] : [[t('cash.expected'), $money($cash['expected_cdf'])], [t('shifts.already_remitted'), $money($cash['remitted_cdf'])]])) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <?php if ($isOpen): ?>
            <h3 class="shift-form__title"><?= e(t('shifts.settle_step_indexes')) ?></h3>
            <div class="nozzle-grid">
                <?php foreach ($segment['nozzles'] as $nozzle): ?>
                    <div class="nozzle-card">
                        <div class="nozzle-card__head"><strong><?= e($nozzle['code']) ?></strong><span><?= e($nozzle['product']) ?></span></div>
                        <div class="muted small" data-recap-static="<?= e(t('fields.opening_index') . ' ' . $nozzle['code']) ?>" data-recap-value="<?= e(format_number($nozzle['opening'], 2)) ?>"><?= e(t('fields.opening_index')) ?> : <strong><?= e(format_number($nozzle['opening'], 2)) ?></strong></div>
                        <label class="nozzle-card__label"><?= e(t('fields.final_index')) ?>
                            <input name="final[<?= e($nozzle['nozzle_id']) ?>]" class="input input--xl" required inputmode="decimal" autocomplete="off" data-error-key="final_<?= e($nozzle['nozzle_id']) ?>"
                                   data-recap="<?= e(t('fields.final_index') . ' ' . $nozzle['code']) ?>">
                        </label>
                        <label class="check"><input type="checkbox" name="rollover[<?= e($nozzle['nozzle_id']) ?>]" value="1" data-recap="<?= e(t('shifts.rollover_declare') . ' (' . $nozzle['code'] . ')') ?>"> <?= e(t('shifts.rollover_declare')) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h3 class="shift-form__title"><?= e(t('shifts.settle_step_cash')) ?></h3>
        <div class="form-grid">
            <label class="field"><?= e(t('shifts.settle_cdf')) ?>
                <input name="amount_cdf" class="input input--xl" inputmode="numeric" autocomplete="off" data-error-key="amount_cdf" data-recap="<?= e(t('shifts.settle_cdf')) ?>"<?= $cash['remitted_cdf'] === 0 ? ' required' : '' ?>>
            </label>
            <label class="field"><?= e(t('shifts.settle_usd')) ?>
                <input name="amount_usd" class="input input--xl" inputmode="decimal" autocomplete="off" data-error-key="amount" data-recap="<?= e(t('shifts.settle_usd')) ?>">
            </label>
        </div>
        <p class="muted small"><?= e(t('cash.zero_hint')) ?></p>
        <label class="field"><?= e(t('fields.note')) ?><input name="note" class="input" maxlength="255" data-recap="<?= e(t('fields.note')) ?>"></label>
        <div class="shift-form__actions">
            <?= $sheet($shift['id']) ?>
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="check-check"></i><?= e(t('shifts.settle_action')) ?></button>
        </div>
    </form>

<?php else: ?>
    <?php /** @var array $shift  @var array $cash  @var bool $hasRollover */ ?>
    <?php $variance = (int) $cash['variance_cdf']; $verdict = t($variance < 0 ? 'cash.shortage' : ($variance > 0 ? 'cash.surplus' : 'cash.balanced')); ?>
    <div class="shift-modal__head">
        <h2><?= e(t('shifts.validate_title')) ?></h2>
        <div class="shift-modal__meta"><?= e($shift['station'] . ' · ' . $shift['attendant'] . ($shift['label'] ? ' · ' . $shift['label'] : '')) ?></div>
    </div>
    <div class="shift-modal__figures">
        <div><span><?= e(t('cash.expected')) ?></span><strong><?= e($money($cash['expected_cdf'])) ?></strong></div>
        <div><span><?= e(t('cash.remitted')) ?></span><strong><?= e($money($cash['remitted_cdf'])) ?></strong></div>
        <div><span><?= e($verdict) ?></span><strong class="<?= $variance < 0 ? 'overdue' : '' ?>"><?= e(($variance > 0 ? '+' : '') . $money($variance)) ?></strong></div>
    </div>
    <form method="post" action="<?= e(url('/shifts/' . $shift['id'] . '/validate')) ?>" data-ajax-form data-recap-title="<?= e(t('shifts.recap_validate')) ?>" data-submit-label="<?= e(t('shifts.validate_action')) ?>"
          data-recap-warning="<?= e(t('cash.validate_hint')) ?>"
          data-context="<?= $context([[t('reports.col_attendant'), $shift['attendant']], [t('cash.expected'), $money($cash['expected_cdf'])], [t('cash.remitted'), $money($cash['remitted_cdf'])], [$verdict, ($variance > 0 ? '+' : '') . $money($variance)]]) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <p class="muted small"><?= e(t('cash.validate_hint')) ?></p>
        <?php if ($hasRollover): ?>
            <div class="alert alert--error"><i data-lucide="rotate-ccw"></i><div>
                <?= e(t('shifts.rollover_warning')) ?>
                <label class="check"><input type="checkbox" name="confirm_rollover" value="1" required data-error-key="confirm_rollover" data-recap="<?= e(t('shifts.rollover_confirm')) ?>"> <strong><?= e(t('shifts.rollover_confirm')) ?></strong></label>
            </div></div>
        <?php endif; ?>
        <label class="field"><?= e(t('fields.note')) ?><input name="note" class="input" maxlength="255" data-recap="<?= e(t('fields.note')) ?>"></label>
        <div class="shift-form__actions">
            <?= $sheet($shift['id']) ?>
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('shifts.validate_action')) ?></button>
        </div>
    </form>
<?php endif; ?>
</div>
