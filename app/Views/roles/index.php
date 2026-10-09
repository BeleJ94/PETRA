<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var list<array<string, string>> $roles  @var list<array<string, string>> $permissions  @var array<string, list<string>> $matrix */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="shield-check"></i></span>
        <div>
            <h1><?= e(t('nav.roles')) ?></h1>
            <div class="page-title__meta"><?= e(t('roles.intro')) ?></div>
        </div>
    </div>
</div>

<div class="grid grid--3">
    <?php foreach ($roles as $role): ?>
        <?php $granted = $matrix[$role['id']] ?? []; $locked = $role['code'] === \Petra\Security\CurrentUser::ROLE_OWNER; ?>
        <form method="post" action="<?= e(url('/roles/' . $role['id'] . '/permissions')) ?>" class="card role-card">
            <?= csrf_field() ?>
            <div class="card__title">
                <h2><?= e($role['name']) ?></h2>
                <span class="chip"><?= e(count($granted)) ?> / <?= e(count($permissions)) ?></span>
            </div>
            <?php if ($locked): ?>
                <p class="muted small"><?= e(t('roles.owner_locked')) ?></p>
            <?php endif; ?>
            <?php foreach ($permissions as $permission): ?>
                <label class="check" title="<?= e($permission['code']) ?>">
                    <input type="checkbox" name="permissions[]" value="<?= e($permission['code']) ?>"<?= in_array($permission['code'], $granted, true) ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                    <span><?= e($permission['description']) ?></span>
                </label>
            <?php endforeach; ?>
            <?php if (!$locked): ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
                </div>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
</div>
