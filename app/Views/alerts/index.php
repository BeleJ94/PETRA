<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php
/**
 * @var list<array<string, mixed>> $pending alertes ouvertes ou vues
 * @var list<array{id: string, name: string, open: int, acknowledged: int, critical: int}> $stations vue consolidée
 * @var bool $canResolve
 */
$tones = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'];
$statusTones = ['open' => 'danger', 'acknowledged' => 'warning', 'resolved' => 'success'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="siren"></i></span>
        <h1><?= e(t('nav.alerts')) ?></h1>
    </div>
</div>

<?php if ($stations !== []): ?>
    <div class="station-alerts">
        <?php foreach ($stations as $station): ?>
            <form method="post" action="<?= e(url('/context/station')) ?>" class="station-alerts__card<?= $station['critical'] > 0 ? ' station-alerts__card--critical' : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="station_id" value="<?= e($station['id']) ?>">
                <input type="hidden" name="back" value="/alerts">
                <button type="submit" title="<?= e(t('alerts.open_station', ['station' => $station['name']])) ?>">
                    <strong><?= e($station['name']) ?></strong>
                    <span><b><?= e($station['open']) ?></b> <?= e(t('alerts.count_open')) ?> · <b><?= e($station['acknowledged']) ?></b> <?= e(t('alerts.count_acknowledged')) ?></span>
                    <?php if ($station['critical'] > 0): ?><span class="badge badge--danger"><?= e(t('alerts.count_critical', ['n' => $station['critical']])) ?></span><?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t('alerts.pending_title')) ?></h2></div>
    <?php if ($pending === []): ?>
        <div class="empty-state empty-state--ok empty-state--illus"><?= illustration('all-clear') ?><p><?= e(t('alerts.none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" data-no-dt>
                <thead>
                <tr>
                    <th><?= e(t('fields.created_at')) ?></th>
                    <th><?= e(t('fields.station')) ?></th>
                    <th><?= e(t('fields.severity')) ?></th>
                    <th><?= e(t('fields.message')) ?></th>
                    <th><?= e(t('fields.user')) ?></th>
                    <th><?= e(t('fields.status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $alert): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($alert['created_at'], true)) ?></td>
                        <td><?= e($alert['station_name']) ?></td>
                        <td><span class="badge badge--<?= e($tones[$alert['severity']] ?? 'neutral') ?>"><?= e(label('severity', $alert['severity'])) ?></span></td>
                        <td>
                            <a href="<?= e(url('/alerts/' . $alert['id'])) ?>"><?= e($alert['message']) ?></a>
                            <?php if ($alert['amount'] !== null): ?><br><strong><?= e(format_money((int) $alert['amount'], (string) $alert['currency'])) ?></strong><?php endif; ?>
                        </td>
                        <td><?= e($alert['user_name']) ?></td>
                        <td><span class="badge badge--<?= e($statusTones[$alert['status']]) ?>"><?= e(label('alert_status', $alert['status'])) ?></span></td>
                        <td class="nowrap">
                            <?php if ($canResolve): ?>
                                <?php if ($alert['status'] === 'open'): ?>
                                    <form method="post" action="<?= e(url('/alerts/' . $alert['id'] . '/acknowledge')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="back" value="/alerts">
                                        <button type="submit" class="btn btn--sm"><i data-lucide="eye"></i><?= e(t('alerts.acknowledge')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('/alerts/' . $alert['id'] . '/resolve')) ?>" class="inline-form" data-confirm="<?= e(t('alerts.resolve_confirm')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--sm"><i data-lucide="check"></i><?= e(t('alerts.resolve')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__title">
        <h2><?= e(t('alerts.all_title')) ?></h2>
        <div class="page-header__actions"><?= $view->partial('partials/export_buttons', ['table' => 'alerts', 'exportTitle' => t('nav.alerts')]) ?></div>
    </div>
    <form class="filters" data-filters-for="alerts">
        <div class="field">
            <label for="filter-status"><?= e(t('fields.status')) ?></label>
            <select id="filter-status" name="status" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\Petra\Repositories\AlertRepository::STATUSES as $value): ?>
                    <option value="<?= e($value) ?>"><?= e(label('alert_status', $value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-severity"><?= e(t('fields.severity')) ?></label>
            <select id="filter-severity" name="severity" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\Petra\Repositories\AlertRepository::SEVERITIES as $value): ?>
                    <option value="<?= e($value) ?>"><?= e(label('severity', $value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <table class="display" style="width:100%" data-table="alerts" data-source="<?= e(url('/alerts/data')) ?>" data-row-link="<?= e(url('/alerts/{id}')) ?>"></table>
</section>
