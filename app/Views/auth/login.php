<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var string $username  @var string|null $error */ ?>
<div class="login">
    <aside class="login__hero" aria-hidden="true">
        <div class="login__brand"><img class="login__logo" src="<?= e(asset('img/logo.svg')) ?>" alt=""> Petra</div>
        <div class="login__pitch">
            <h2><?= e(t('auth.hero_title')) ?></h2>
            <p><?= e(t('auth.hero_text')) ?></p>
        </div>
        <div class="login__footnote"><?= e(t('app.credit')) ?></div>
    </aside>

    <section class="login__panel">
        <div class="login__top">
            <div class="login__brand login__brand--compact"><img class="login__logo" src="<?= e(asset('img/logo.svg')) ?>" alt=""> Petra</div>
        </div>

        <div class="login__form">
            <h1><?= e(t('auth.title')) ?></h1>
            <p class="muted"><?= e(t('auth.subtitle')) ?></p>
            <?php if (!empty($error)): ?>
                <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div><?= e($error) ?></div></div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/login')) ?>" class="grid">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username"><?= e(t('fields.username')) ?></label>
                    <div class="input-icon">
                        <i data-lucide="user"></i>
                        <input id="username" name="username" type="text" class="input" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus
                               value="<?= e($username) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="password"><?= e(t('fields.password')) ?></label>
                    <div class="input-icon">
                        <i data-lucide="lock"></i>
                        <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
                        <button type="button" class="input-icon__toggle" data-toggle-password="password"
                                aria-label="<?= e(t('auth.show_password')) ?>" aria-pressed="false"
                                data-label-show="<?= e(t('auth.show_password')) ?>" data-label-hide="<?= e(t('auth.hide_password')) ?>">
                            <i data-lucide="eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary btn--block login__submit">
                    <?= e(t('auth.submit')) ?> <i data-lucide="arrow-right"></i>
                </button>
            </form>
            <p class="login__secure muted"><i data-lucide="shield-check"></i><?= e(t('auth.secure_note')) ?></p>
        </div>
    </section>
</div>
