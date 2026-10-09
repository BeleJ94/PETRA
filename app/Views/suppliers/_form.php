<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Fenêtre « fiche fournisseur » : création ou modification (fragment). @var array<string, mixed>|null $supplier  @var string $stationName */
$value = static fn (string $key): string => (string) ($supplier[$key] ?? '');
$action = $supplier === null ? '/suppliers' : '/suppliers/' . $supplier['id'];
?>
<div class="shift-modal shift-form">
    <div class="shift-modal__head">
        <h2><?= e($supplier === null ? t('suppliers.new') : t('suppliers.edit', ['name' => $supplier['name']])) ?></h2>
        <div class="shift-modal__meta"><?= e($stationName) ?></div>
    </div>
    <form method="post" action="<?= e(url($action)) ?>" data-ajax-form data-recap-title="<?= e(t('suppliers.recap')) ?>" data-submit-label="<?= e(t('common.save')) ?>"
          data-context="<?= e(json_encode([[t('fields.station'), $stationName]], JSON_UNESCAPED_UNICODE)) ?>">
        <?= csrf_field() ?>
        <div class="form-errors alert alert--error" role="alert" hidden></div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.supplier_name')) ?>
                <input name="name" class="input" required maxlength="120" autocomplete="off" value="<?= e($value('name')) ?>" data-error-key="name" data-recap="<?= e(t('fields.supplier_name')) ?>">
            </label>
            <label class="field"><?= e(t('fields.contact_name')) ?>
                <input name="contact_name" class="input" maxlength="120" value="<?= e($value('contact_name')) ?>" data-error-key="contact_name" data-recap="<?= e(t('fields.contact_name')) ?>">
            </label>
        </div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.phone')) ?>
                <input name="phone" class="input" type="tel" maxlength="40" value="<?= e($value('phone')) ?>" data-error-key="phone" data-recap="<?= e(t('fields.phone')) ?>">
            </label>
            <label class="field"><?= e(t('fields.email')) ?>
                <input name="email" class="input" type="email" maxlength="160" value="<?= e($value('email')) ?>" data-error-key="email" data-recap="<?= e(t('fields.email')) ?>">
            </label>
        </div>
        <div class="form-grid">
            <label class="field"><?= e(t('fields.address')) ?>
                <input name="address" class="input" maxlength="255" value="<?= e($value('address')) ?>" data-error-key="address" data-recap="<?= e(t('fields.address')) ?>">
            </label>
            <?php if ($supplier !== null): ?>
                <label class="field"><?= e(t('fields.status')) ?>
                    <select name="is_active" class="input" data-error-key="is_active" data-recap="<?= e(t('fields.status')) ?>">
                        <option value="1"<?= $supplier['is_active'] ? ' selected' : '' ?>><?= e(t('suppliers.active')) ?></option>
                        <option value="0"<?= $supplier['is_active'] ? '' : ' selected' ?>><?= e(t('suppliers.inactive')) ?></option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <p class="muted small"><?= e(t($supplier === null ? 'suppliers.new_hint' : 'suppliers.edit_hint')) ?></p>
        <div class="shift-form__actions">
            <button type="button" class="btn" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('common.save')) ?></button>
        </div>
    </form>
</div>
