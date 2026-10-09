<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="layout-dashboard"></i></span>
        <h1><?= e(t('nav.home')) ?></h1>
    </div>
</div>

<div class="card welcome">
    <?= illustration('attendant', 'lg') ?>
    <div class="welcome__body">
        <p class="welcome__lead"><?= e(t('home.empty')) ?></p>
        <ol class="welcome__steps">
            <li><?= e(t('home.step_check')) ?></li>
            <li><?= e(t('home.step_declare')) ?></li>
            <li><?= e(t('home.step_close')) ?></li>
        </ol>
        <a class="btn btn--primary btn--lg" href="<?= e(url('/shifts')) ?>"><i data-lucide="gauge"></i><?= e(t('home.go_shifts')) ?></a>
    </div>
</div>
