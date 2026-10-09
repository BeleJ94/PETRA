<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var list<array<string, mixed>> $users  @var int $minLength */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="users"></i></span>
        <h1><?= e(t('nav.users')) ?></h1>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('fields.name')) ?></th>
                <th><?= e(t('fields.username')) ?></th>
                <th><?= e(t('fields.role')) ?></th>
                <th><?= e(t('fields.stations')) ?></th>
                <th><?= e(t('fields.last_login_at')) ?></th>
                <th><?= e(t('fields.status')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= e($user['last_name']) ?></strong> <?= e($user['first_name']) ?></td>
                    <td><?= e($user['username']) ?></td>
                    <td><?= e($user['role_name']) ?></td>
                    <td><?= e((int) $user['all_stations'] === 1 ? t('common.all_stations') : $user['stations']) ?></td>
                    <td class="nowrap"><?= e(format_date($user['last_login_at'], true)) ?></td>
                    <td>
                        <?php if ((int) $user['is_active'] !== 1): ?>
                            <span class="badge badge--neutral"><?= e(t('users.inactive')) ?></span>
                        <?php elseif ((int) $user['is_locked'] === 1): ?>
                            <span class="badge badge--danger"><?= e(t('users.locked')) ?></span>
                        <?php else: ?>
                            <span class="badge badge--success"><?= e(t('users.active')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn--sm" data-open="#reset-<?= e($user['id']) ?>"><i data-lucide="key-round"></i><?= e(t('users.reset_password')) ?></button>
                        <template id="reset-<?= e($user['id']) ?>">
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>" class="grid" autocomplete="off">
                                <?= csrf_field() ?>
                                <h2><?= e(t('users.reset_title', ['user' => $user['username']])) ?></h2>
                                <p class="muted small"><?= e(t('users.reset_hint')) ?></p>
                                <div class="field">
                                    <label><?= e(t('fields.password')) ?>
                                        <input name="password" type="password" class="input" required minlength="<?= e($minLength) ?>" autocomplete="new-password">
                                    </label>
                                    <span class="hint"><?= e(t('users.password_rule', ['n' => $minLength])) ?></span>
                                </div>
                                <div class="field">
                                    <label><?= e(t('fields.password_confirmation')) ?>
                                        <input name="password_confirmation" type="password" class="input" required minlength="<?= e($minLength) ?>" autocomplete="new-password">
                                    </label>
                                </div>
                            </form>
                        </template>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
