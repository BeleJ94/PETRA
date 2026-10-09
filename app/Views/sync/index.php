<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var array{state: string, pending: int, conflicts: int, last_at: ?string, last_error: ?string}|null $summary  @var list<array<string, mixed>> $history  @var bool $central */
$tones = ['success' => 'success', 'failed' => 'danger', 'offline' => 'warning', 'running' => 'info'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="refresh-cw"></i></span>
        <h1><?= e(t('nav.sync')) ?></h1>
    </div>
</div>

<?php if ($summary !== null): ?>
    <div class="card sync-link sync-link--<?= e($summary['state']) ?>" role="img" aria-label="<?= e(label('sync_state', $summary['state'])) ?>">
        <div class="sync-link__end"><span class="sync-link__icon"><i data-lucide="fuel"></i></span><strong><?= e(t('sync.end_station')) ?></strong></div>
        <div class="sync-link__wire"><span></span><em><i data-lucide="<?= e(['offline' => 'cloud-off', 'pending' => 'cloud-upload', 'synced' => 'cloud-check'][$summary['state']]) ?>"></i><?= e($summary['state'] === 'pending' ? t('sync.state_pending_count', ['n' => $summary['pending']]) : label('sync_state', $summary['state'])) ?></em><span></span></div>
        <div class="sync-link__end"><span class="sync-link__icon"><i data-lucide="server"></i></span><strong><?= e(t('sync.end_central')) ?></strong></div>
    </div>
    <div class="kpis">
        <div class="kpi"><div class="kpi__label"><?= e(t('sync.state')) ?></div><div class="kpi__value"><?= e(label('sync_state', $summary['state'])) ?></div>
            <?php if ($summary['last_error']): ?><div class="kpi__hint"><?= e($summary['last_error']) ?></div><?php endif; ?></div>
        <div class="kpi"><div class="kpi__label"><?= e(t('sync.pending')) ?></div><div class="kpi__value"><?= e($summary['pending']) ?></div></div>
        <div class="kpi<?= $summary['conflicts'] > 0 ? ' kpi--alert' : '' ?>"><div class="kpi__label"><?= e(t('sync.conflicts')) ?></div><div class="kpi__value"><?= e($summary['conflicts']) ?></div></div>
        <div class="kpi"><div class="kpi__label"><?= e(t('sync.last_success')) ?></div><div class="kpi__value kpi__value--small"><?= e($summary['last_at'] === null ? t('sync.never') : format_date($summary['last_at'], true)) ?></div></div>
    </div>
<?php elseif (!$central): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('sync') ?><p><?= e(t('sync.standalone')) ?></p></div></div>
<?php endif; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t($central ? 'sync.history_central' : 'sync.history')) ?></h2></div>
    <?php if ($history === []): ?>
        <p class="empty"><?= e(t('sync.history_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th><?= e(t('fields.created_at')) ?></th><th><?= e(t('fields.station')) ?></th><th><?= e(t('sync.direction')) ?></th><th><?= e(t('fields.status')) ?></th>
                <th class="num"><?= e(t('sync.items_sent')) ?></th><th class="num"><?= e(t('sync.items_accepted')) ?></th><th class="num"><?= e(t('sync.items_rejected')) ?></th><th class="num"><?= e(t('sync.conflicts')) ?></th>
                <th><?= e(t('fields.summary')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <?php $details = json_decode((string) $row['details'], true) ?: []; ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($row['started_at'], true)) ?></td>
                    <td><?= e($row['station']) ?></td>
                    <td><?= e(label('sync_direction', ($central ? 'central_' : '') . $row['direction'])) ?></td>
                    <td><span class="badge badge--<?= e($tones[$row['status']] ?? 'neutral') ?>"><?= e(label('sync_status', $row['status'])) ?></span></td>
                    <td class="num"><?= e($row['items_sent']) ?></td>
                    <td class="num"><?= e($row['items_accepted']) ?></td>
                    <td class="num"><?= e($row['items_rejected']) ?></td>
                    <td class="num"><?= (int) $row['items_conflicted'] > 0 ? '<strong class="overdue">' . e($row['items_conflicted']) . '</strong>' : '0' ?></td>
                    <td>
                        <?= e($row['error_message']) ?>
                        <?php foreach (array_slice($details, 0, 5) as $detail): ?>
                            <div class="small muted"><?= e(trim(($detail['table'] ?? '') . ' ' . ($detail['message'] ?? ''))) ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
