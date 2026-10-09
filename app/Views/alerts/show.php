<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array $alert  @var list<array<string, mixed>> $history  @var bool $canResolve */
$tones = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'];
$statusTones = ['open' => 'danger', 'acknowledged' => 'warning', 'resolved' => 'success'];
$id = $alert['id'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="siren"></i></span>
        <div>
            <h1><?= e(t('alerts.sheet')) ?></h1>
            <div class="page-title__meta">
                <span><i data-lucide="map-pin"></i><?= e($alert['station_name']) ?></span>
                <span><i data-lucide="calendar"></i><?= e(format_date($alert['created_at'], true)) ?></span>
                <span class="badge badge--<?= e($tones[$alert['severity']] ?? 'neutral') ?>"><?= e(label('severity', $alert['severity'])) ?></span>
                <span class="badge badge--<?= e($statusTones[$alert['status']]) ?>"><?= e(label('alert_status', $alert['status'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/alerts')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <?php if ($canResolve && $alert['status'] === 'open'): ?>
            <form method="post" action="<?= e(url("/alerts/$id/acknowledge")) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="/alerts/<?= e($id) ?>">
                <button type="submit" class="btn btn--lg"><i data-lucide="eye"></i><?= e(t('alerts.acknowledge')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($canResolve && $alert['status'] !== 'resolved'): ?>
            <button type="button" class="btn btn--primary btn--lg" data-open="#resolve-alert"><i data-lucide="check"></i><?= e(t('alerts.resolve')) ?></button>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <p class="alert-message"><?= e($alert['message']) ?></p>
    <dl class="dl">
        <?php if ($alert['amount'] !== null): ?><dt><?= e(t('fields.amount')) ?></dt><dd><?= e(format_money((int) $alert['amount'], (string) $alert['currency'])) ?></dd><?php endif; ?>
        <?php if ($alert['volume_l'] !== null): ?><dt><?= e(t('fields.volume_l')) ?></dt><dd><?= e(format_number($alert['volume_l'], 2)) ?></dd><?php endif; ?>
        <?php if ($alert['threshold_value'] !== null): ?><dt><?= e(t('alerts.threshold')) ?></dt><dd><?= e($alert['threshold_value']) ?></dd><?php endif; ?>
        <?php if ($alert['user_name']): ?><dt><?= e(t('fields.user')) ?></dt><dd><?= e($alert['user_name']) ?></dd><?php endif; ?>
        <?php if ($alert['shift_id']): ?><dt><?= e(t('fields.shift')) ?></dt><dd><a href="<?= e(url('/shifts/' . $alert['shift_id'])) ?>"><?= e(t('alerts.see_shift')) ?></a></dd><?php endif; ?>
        <?php if ($alert['acknowledged_at']): ?><dt><?= e(t('alerts.acknowledged_by')) ?></dt><dd><?= e($alert['acknowledged_by_name']) ?> · <?= e(format_date($alert['acknowledged_at'], true)) ?></dd><?php endif; ?>
        <?php if ($alert['resolved_at']): ?><dt><?= e(t('alerts.resolved_by')) ?></dt><dd><?= e($alert['resolved_by_name']) ?> · <?= e(format_date($alert['resolved_at'], true)) ?></dd><?php endif; ?>
        <?php if ($alert['resolution_note']): ?><dt><?= e(t('fields.resolution_note')) ?></dt><dd><?= e($alert['resolution_note']) ?></dd><?php endif; ?>
    </dl>
</section>

<section class="card">
    <div class="card__title"><h2><?= e(t('alerts.history')) ?></h2></div>
    <?php if ($history === []): ?>
        <p class="empty"><?= e(t('alerts.history_none')) ?></p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $entry): ?>
                <li><time><?= e(format_date($entry['created_at'], true)) ?></time><strong><?= e(label('action', $entry['action'])) ?></strong> — <?= e($entry['user_name']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($canResolve && $alert['status'] !== 'resolved'): ?>
    <template id="resolve-alert">
        <form method="post" action="<?= e(url("/alerts/$id/resolve")) ?>" class="grid tablet-form">
            <?= csrf_field() ?>
            <h2><?= e(t('alerts.resolve')) ?></h2>
            <label class="field"><?= e(t('fields.resolution_note')) ?><input name="resolution_note" class="input" maxlength="255" required></label>
        </form>
    </template>
<?php endif; ?>
