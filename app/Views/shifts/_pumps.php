<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * Choix des distributeurs confiés au poste, avec les index initiaux de leurs pistolets.
 * Utilisé par la fenêtre d'ouverture (shifts/_form) et par l'écran complet (shifts/open).
 *
 * @var list<array{key: string, code: string, name: string, products: list<string>, nozzles: list<array<string, mixed>>}> $pumps
 * @var bool $recap vrai dans la fenêtre : les champs alimentent l'étape de vérification
 */
$oldOpening = (array) old('opening', []);
$oldPumps = (array) old('pump_ids', []);
$single = count($pumps) === 1;
?>
<h3 class="shift-form__title"><?= e(t('shifts.pumps_title')) ?></h3>
<p class="muted small"><?= e(t($single ? 'shifts.pumps_single_hint' : 'shifts.pumps_hint')) ?></p>
<?php // Champ vide toujours envoyé : sans case cochée, le serveur exige un choix au lieu de prendre tous les distributeurs. ?>
<input type="hidden" name="pump_ids[]" value="">
<div class="pump-list">
    <?php foreach ($pumps as $pump): ?>
        <?php $checked = $single || in_array($pump['key'], $oldPumps, true); ?>
        <section class="pump-block<?= $checked ? ' is-selected' : '' ?>" data-pump-block data-pump="<?= e($pump['code']) ?>">
            <label class="pump-block__head">
                <input type="checkbox" name="pump_ids[]" value="<?= e($pump['key']) ?>" data-pump-check data-error-key="pump_ids"<?= $checked ? ' checked' : '' ?><?= $recap ? ' data-recap="' . e(t('fields.pump') . ' ' . $pump['code']) . '"' : '' ?>>
                <span class="pump-block__code"><?= e($pump['code']) ?></span>
                <span class="pump-block__name"><?= e($pump['name']) ?></span>
                <span class="badge badge--neutral"><?= e(t(count($pump['products']) > 1 ? 'pumps.multi' : 'pumps.mono') . ' · ' . implode(', ', $pump['products'])) ?></span>
            </label>
            <div class="nozzle-grid">
                <?php foreach ($pump['nozzles'] as $nozzle): ?>
                    <?php $error = field_error('opening_' . $nozzle['nozzle_id']); ?>
                    <div class="nozzle-card">
                        <div class="nozzle-card__head"><strong><?= e($nozzle['code']) ?></strong><span><?= e($nozzle['product']) ?></span></div>
                        <?php if ($nozzle['last_index'] !== null): ?>
                            <div class="nozzle-card__value" title="<?= e(t('shifts.carried_over')) ?>"<?= $recap ? ' data-recap-static="' . e(t('fields.opening_index') . ' ' . $nozzle['code']) . '" data-recap-value="' . e(format_number($nozzle['last_index'], 2)) . '"' : '' ?>><i data-lucide="lock"></i><?= e(format_number($nozzle['last_index'], 2)) ?></div>
                            <div class="muted small"><?= e(t('shifts.carried_over')) ?></div>
                        <?php else: ?>
                            <input name="opening[<?= e($nozzle['nozzle_id']) ?>]" class="input input--xl" inputmode="decimal" autocomplete="off" data-pump-input data-error-key="opening_<?= e($nozzle['nozzle_id']) ?>"
                                   value="<?= e($oldOpening[$nozzle['nozzle_id']] ?? '') ?>" aria-label="<?= e(t('fields.opening_index') . ' ' . $nozzle['code']) ?>"<?= $error ? ' aria-invalid="true"' : '' ?><?= $recap ? ' data-recap="' . e(t('fields.opening_index') . ' ' . $nozzle['code']) . '"' : '' ?>>
                            <div class="muted small"><?= e(t('shifts.first_index')) ?></div>
                            <?php if ($error): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
                        <?php endif; ?>
                        <div class="muted small"><?= e(t('fields.unit_price')) ?> : <?= e(format_money($nozzle['price']['unit_price'], $nozzle['price']['currency'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php if (field_error('pump_ids')): ?><span class="error"><?= e(field_error('pump_ids')) ?></span><?php endif; ?>
