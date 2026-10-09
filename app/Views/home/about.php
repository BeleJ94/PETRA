<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var string $appMode  @var string $phpVersion  @var string $timezone */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="info"></i></span>
        <h1><?= e(t('nav.about')) ?></h1>
    </div>
</div>

<div class="about-banner" role="img" aria-label="Petra"><img class="about-banner__logo" src="<?= e(asset('img/logo.svg')) ?>" alt=""><div><strong>Petra</strong><span><?= e(t('app.credit')) ?></span></div></div>

<div class="card">
    <h2>Petra</h2>
    <p><?= e(t('about.description')) ?></p>
    <p><strong><?= e(t('app.credit')) ?></strong></p>
</div>

<div class="card">
    <div class="card__title"><h2><?= e(t('about.installation')) ?></h2></div>
    <dl class="dl">
        <dt><?= e(t('about.mode')) ?></dt>
        <dd><?= e(label('mode', $appMode)) ?></dd>
        <dt><?= e(t('about.timezone')) ?></dt>
        <dd><?= e($timezone) ?></dd>
        <dt><?= e(t('about.php')) ?></dt>
        <dd><?= e($phpVersion) ?></dd>
    </dl>
</div>
