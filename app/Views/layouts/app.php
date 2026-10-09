<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var \Petra\Security\CurrentUser $currentUser  @var \Petra\Security\StationContext $stationContext  @var string $content  @var string $currentPath  @var string $appMode  @var int|null $openAlertCount  @var array|null $syncSummary */
// Menu : l'accueil, puis les sections dans l'ordre du travail quotidien. Lien : [chemin, icône, libellé, permission ou null, compteur].
$syncPending = ($syncSummary ?? null) === null ? 0 : (int) $syncSummary['pending'] + (int) $syncSummary['conflicts'];
$sections = [
    [null, [
        ['/', 'layout-dashboard', 'nav.home', null, 0],
    ]],
    ['nav.section_operations', [
        ['/shifts', 'gauge', 'nav.shifts', 'shifts.view', (int) ($shiftsToValidate ?? 0)],
        ['/stock', 'cylinder', 'nav.stock', 'reports.view', 0],
        ['/deliveries', 'truck', 'nav.deliveries', 'reports.view', 0],
    ]],
    ['nav.section_control', [
        ['/alerts', 'siren', 'nav.alerts', 'alerts.view', (int) ($openAlertCount ?? 0)],
        ['/reports', 'file-bar-chart', 'nav.reports', 'reports.view', 0],
        ['/audit', 'history', 'nav.audit', 'audit.view', 0],
    ]],
    ['nav.section_settings', [
        ['/prices', 'badge-dollar-sign', 'nav.prices', 'prices.view', 0],
        ['/stations', 'fuel', 'nav.stations', 'stations.manage', 0],
        ['/suppliers', 'contact', 'nav.suppliers', 'suppliers.manage', 0],
        ['/users', 'users', 'nav.users', 'users.manage', 0],
        ['/roles', 'shield-check', 'nav.roles', 'organization.manage', 0],
        ['/sync', 'refresh-cw', 'nav.sync', 'sync.manage', $syncPending],
    ]],
];
// Sous le nom : la station de ce serveur ou de l'utilisateur quand elle est unique, sinon le mode.
$activeStation = $stationContext->active();
$brandLine = $appMode === 'central' ? label('mode', 'central') : ($activeStation['name'] ?? label('mode', $appMode));
// Action la plus fréquente du gérant, à portée de main : affichée seulement quand un poste peut être ouvert.
$canOpenShift = (bool) ($canOpenShift ?? false);
// Pompiste (ni tableau de bord ni ouverture de poste) : sur téléphone, une barre en bas de l'écran remplace le menu.
$bottomNav = !$currentUser->can('dashboard.view') && !$currentUser->can('shifts.open');
$bottomLinks = array_values(array_filter([
    ['/', 'layout-dashboard', 'nav.home', null],
    ['/shifts', 'gauge', 'nav.my_shifts', 'shifts.view'],
    ['/prices', 'badge-dollar-sign', 'nav.prices_short', 'prices.view'],
], static fn (array $l): bool => $l[3] === null || $currentUser->can($l[3])));
?>
<!doctype html>
<html lang="<?= e(\Petra\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body<?= $bottomNav ? ' class="has-bottom-nav"' : '' ?>>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="<?= e(t('nav.main')) ?>">
        <div class="sidebar__brand">
            <img class="sidebar__logo" src="<?= e(asset('img/logo.svg')) ?>" alt="">
            <div class="nav__text sidebar__name">Petra<small title="<?= e($brandLine) ?>"><?= e($brandLine) ?></small></div>
            <button type="button" class="sidebar__collapse" data-sidebar-mini aria-pressed="false" title="<?= e(t('nav.collapse')) ?>" aria-label="<?= e(t('nav.collapse')) ?>"><i data-lucide="panel-left-close"></i></button>
        </div>

        <?php if ($canOpenShift): ?>
            <a class="sidebar__action" href="<?= e(url('/shifts/open')) ?>" data-label="<?= e(t('nav.open_shift')) ?>" aria-label="<?= e(t('nav.open_shift')) ?>">
                <i data-lucide="play"></i><span class="nav__text"><?= e(t('nav.open_shift')) ?></span>
            </a>
        <?php endif; ?>

        <ul class="nav">
            <?php foreach ($sections as [$sectionLabel, $links]): ?>
                <?php $links = array_filter($links, static fn (array $l): bool => $l[3] === null || $currentUser->can($l[3])); ?>
                <?php if ($links !== []): ?>
                    <?php if ($sectionLabel !== null): ?>
                        <li class="nav__section"><span class="nav__text"><?= e(t($sectionLabel)) ?></span></li>
                    <?php endif; ?>
                    <?php foreach ($links as $link): ?>
                        <?php $name = t($link[2]); ?>
                        <li><a href="<?= e(url($link[0])) ?>"<?= nav_current($currentPath, $link[0]) ?> data-label="<?= e($name) ?>" aria-label="<?= e($link[4] > 0 ? $name . ' (' . $link[4] . ')' : $name) ?>">
                            <i data-lucide="<?= e($link[1]) ?>"></i><span class="nav__text"><?= e($name) ?></span>
                            <?php if ($link[4] > 0): ?><span class="nav__badge" data-nav-badge="<?= e(ltrim($link[0], '/')) ?>"><?= e($link[4] > 99 ? '99+' : $link[4]) ?></span><?php endif; ?>
                        </a></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar__foot">
            <div class="sidebar__user" title="<?= e($currentUser->name() . ' — ' . $currentUser->roleName) ?>">
                <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
                <span class="nav__text sidebar__who"><strong><?= e($currentUser->name()) ?></strong><small><?= e($currentUser->roleName) ?></small></span>
            </div>
            <div class="sidebar__links">
                <a href="<?= e(url('/about')) ?>"<?= nav_current($currentPath, '/about') ?> data-label="<?= e(t('nav.about')) ?>" aria-label="<?= e(t('nav.about')) ?>"><i data-lucide="info"></i><span class="nav__text"><?= e(t('nav.about')) ?></span></a>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" data-label="<?= e(t('auth.logout')) ?>" aria-label="<?= e(t('auth.logout')) ?>"><i data-lucide="log-out"></i><span class="nav__text"><?= e(t('auth.logout')) ?></span></button>
                </form>
            </div>
        </div>
    </aside>
    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost topbar__menu" data-toggle-sidebar aria-controls="sidebar" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
                <i data-lucide="menu"></i>
            </button>
            <p class="topbar__title"><?= e($title ?? '') ?></p>
            <?php if (($syncSummary ?? null) !== null): ?>
                <span class="sync-state sync-state--<?= e($syncSummary['state']) ?>" title="<?= e($syncSummary['last_at'] === null ? t('sync.never') : t('sync.last_success') . ' : ' . format_date($syncSummary['last_at'], true)) ?>">
                    <i data-lucide="<?= e(['offline' => 'cloud-off', 'pending' => 'cloud-upload', 'synced' => 'cloud-check'][$syncSummary['state']]) ?>"></i>
                    <span><?= e($syncSummary['state'] === 'pending' ? t('sync.state_pending_count', ['n' => $syncSummary['pending']]) : label('sync_state', $syncSummary['state'])) ?></span>
                </span>
            <?php endif; ?>
            <?php if (($openAlertCount ?? null) !== null): ?>
                <a class="btn btn--ghost alert-bell" href="<?= e(url('/alerts')) ?>" title="<?= e(t('alerts.bell', ['count' => $openAlertCount])) ?>" aria-label="<?= e(t('alerts.bell', ['count' => $openAlertCount])) ?>">
                    <i data-lucide="bell"></i><?php if ($openAlertCount > 0): ?><span class="alert-bell__badge"><?= e($openAlertCount > 99 ? '99+' : $openAlertCount) ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <?= $view->partial('partials/station_switch', ['stationContext' => $stationContext, 'currentPath' => $currentPath]) ?>
            <div class="topbar__user">
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--ghost" title="<?= e(t('auth.logout')) ?>" aria-label="<?= e(t('auth.logout')) ?>"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </header>
        <main id="main" class="content">
            <?= $view->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
            <?= $content ?>
        </main>
        <?= $view->partial('partials/footer') ?>
    </div>
</div>
<?php if ($bottomNav): ?>
    <nav class="bottom-nav" aria-label="<?= e(t('nav.quick')) ?>">
        <?php foreach ($bottomLinks as $link): ?>
            <a href="<?= e(url($link[0])) ?>"<?= nav_current($currentPath, $link[0]) ?>><i data-lucide="<?= e($link[1]) ?>"></i><span><?= e(t($link[2])) ?></span></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? [], 'navStations' => $stationContext->stations()]) ?>
</body>
</html>
