<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * @var array|null $station  @var list<array{station: array, cards: list<array<string, mixed>>}> $boards  @var array<string, int> $totals
 * @var string $date  @var string $today  @var bool $ownOnly  @var bool $canOpen
 */
use Petra\Domain\Decimal;

$isToday = $date === $today;
$litres = static fn (?int $cl): string => $cl === null ? '—' : format_number(Decimal::unscaled($cl, 2), 2) . ' L';
$money = static fn (?int $amount): string => $amount === null ? '—' : format_money($amount, 'CDF');
$signed = static fn (?int $amount): string => $amount === null ? '—' : ($amount > 0 ? '+' : '') . format_money($amount, 'CDF');
$pictures = ['idle' => 'shift-idle', 'open' => 'shift-open', 'closed' => 'shift-cash', 'cash' => 'shift-cash', 'validated' => 'shift-done'];
$tones = ['idle' => 'neutral', 'open' => 'info', 'closed' => 'warning', 'cash' => 'warning', 'validated' => 'success'];
$progress = ['idle' => 0, 'open' => 1, 'closed' => 2, 'cash' => 3, 'validated' => 4];
$steps = ['shifts.step_open', 'shifts.step_closed', 'shifts.step_cash', 'shifts.step_validated'];
$weekday = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int) date('w', strtotime($date))];
$hasCards = array_sum(array_map(static fn (array $b): int => count($b['cards']), $boards)) > 0;
?>
<div id="shifts-live" data-live>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="gauge"></i></span>
        <div>
            <h1><?= e(t('nav.shifts')) ?></h1>
            <?php if ($station !== null): ?><div class="page-title__meta"><span><i data-lucide="map-pin"></i><?= e($station['name']) ?></span></div><?php endif; ?>
        </div>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn"<?= drill('shifts', '/shifts/data', t('shifts.history'), [], null, '/shifts/{id}', [
            ['label' => label('shift_status', 'open'), 'params' => ['status' => 'open']],
            ['label' => label('shift_status', 'closed'), 'params' => ['status' => 'closed']],
            ['label' => label('shift_status', 'validated'), 'params' => ['status' => 'validated']],
        ]) ?>><i data-lucide="history"></i><?= e(t('shifts.history_all')) ?></button>
        <?php if ($canOpen && $isToday): ?>
            <a class="btn btn--primary btn--lg" href="<?= e(url('/shifts/open')) ?>" data-shift-form="<?= e(url('/shifts/form', ['action' => 'open'])) ?>"><i data-lucide="play"></i><?= e(t('shifts.open_action')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="daybar">
    <?php
    $short = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    $link = static fn (string $day): string => url('/shifts', $day === $today ? [] : ['date' => $day]);
    $previousWeek = date('Y-m-d', strtotime($date . ' -7 days'));
    $nextWeek = min($today, date('Y-m-d', strtotime($date . ' +7 days')));
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    ?>
    <div class="period" role="group" aria-label="<?= e(t('shifts.period')) ?>">
        <div class="period__shortcuts">
            <a class="chip<?= $isToday ? ' is-active' : '' ?>" href="<?= e($link($today)) ?>" data-day-link><?= e(t('shifts.day_today')) ?></a>
            <a class="chip<?= $date === $yesterday ? ' is-active' : '' ?>" href="<?= e($link($yesterday)) ?>" data-day-link><?= e(t('shifts.day_yesterday')) ?></a>
            <label class="period__pick" title="<?= e(t('shifts.pick_date')) ?>">
                <i data-lucide="calendar"></i>
                <input type="date" value="<?= e($date) ?>" max="<?= e($today) ?>" data-day-input data-day-url="<?= e(url('/shifts')) ?>" aria-label="<?= e(t('shifts.pick_date')) ?>">
            </label>
        </div>
        <div class="period__week">
            <a class="period__arrow" href="<?= e($link($previousWeek)) ?>" data-day-link title="<?= e(t('shifts.week_previous')) ?>" aria-label="<?= e(t('shifts.week_previous')) ?>"><i data-lucide="chevron-left"></i></a>
            <?php foreach ($week as $i => $day): ?>
                <?php $label = ucfirst($short[$i]) . ' ' . format_date($day['date']) . ' — ' . str_replace(':count', (string) $day['shifts'], label('shift_week', $day['tone'])); ?>
                <?php if ($day['future']): ?>
                    <span class="period__day is-off" aria-hidden="true"><small><?= e($short[$i]) ?></small><b><?= e((int) substr($day['date'], 8)) ?></b><i class="period__dot"></i></span>
                <?php else: ?>
                    <a class="period__day period__day--<?= e($day['tone']) ?><?= $day['date'] === $date ? ' is-active' : '' ?>" href="<?= e($link($day['date'])) ?>" data-day-link
                       title="<?= e($label) ?>" aria-label="<?= e($label) ?>"<?= $day['date'] === $date ? ' aria-current="date"' : '' ?>>
                        <small><?= e($short[$i]) ?></small><b><?= e((int) substr($day['date'], 8)) ?></b><i class="period__dot"></i>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($week[6]['date'] >= $today): ?>
                <span class="period__arrow is-off" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
            <?php else: ?>
                <a class="period__arrow" href="<?= e($link($nextWeek)) ?>" data-day-link title="<?= e(t('shifts.week_next')) ?>" aria-label="<?= e(t('shifts.week_next')) ?>"><i data-lucide="chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <div class="period__current"><strong><?= e($isToday ? t('shifts.day_today') : ucfirst($weekday)) ?></strong> <?= e(format_date($date)) ?></div>
    </div>
    <?php if ($isToday && $hasCards && $totals['open'] === 0 && !$ownOnly && $canOpen): ?><span class="muted small"><?= e(t('shifts.none_open')) ?></span><?php endif; ?>
    <?php if ($totals['shifts'] > 0): ?>
        <div class="daybar__figures">
            <div><span><?= e(t('shifts.day_sold')) ?></span><strong data-day="sold"><?= e($litres($totals['sold_cl'])) ?></strong></div>
            <div><span><?= e(t('shifts.day_expected')) ?></span><strong data-day="expected"><?= e($money($totals['expected'])) ?></strong></div>
            <div><span><?= e(t('shifts.day_variance')) ?></span><strong data-day="variance" class="<?= $totals['variance'] < 0 ? 'overdue' : '' ?>"><?= e($signed($totals['variance'])) ?></strong></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($station === null && !$ownOnly && count($boards) > 1): ?>
    <p class="muted small"><i data-lucide="info"></i> <?= e(t('shifts.pick_station')) ?></p>
<?php endif; ?>

<?php if (!$hasCards): ?>
    <div class="card"><div class="empty-state empty-state--illus"><?= illustration('shifts') ?><p><?= e(t($ownOnly ? 'shifts.mine_none' : 'shifts.none_open')) ?></p></div></div>
<?php endif; ?>

<?php foreach ($boards as $board): ?>
    <?php if ($board['cards'] === []) { continue; } ?>
    <section class="shift-board" data-station="<?= e($board['station']['id']) ?>">
        <?php if (count($boards) > 1): ?><h2 class="shift-board__station"><i data-lucide="map-pin"></i><?= e($board['station']['name']) ?></h2><?php endif; ?>
        <div class="shift-cards">
            <?php foreach ($board['cards'] as $card): ?>
                <?php
                $shift = $card['shift'];
                $state = $card['state'];
                $stateLabel = label('shift_state', $state === 'idle' && !$isToday ? 'idle_past' : $state);
                $initials = $shift === null ? '' : mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice(explode(' ', (string) $shift['attendant']), 0, 2))));
                ?>
                <article class="shift-card shift-card--<?= e($state) ?>" data-state="<?= e($state) ?>">
                    <?php if ($shift !== null): ?>
                        <button type="button" class="shift-card__open" data-shift-detail="<?= e(url('/shifts/' . $shift['id'] . '/summary')) ?>" aria-label="<?= e(t('shifts.card_detail') . ' — ' . $card['label']) ?>"></button>
                    <?php endif; ?>
                    <div class="shift-card__art">
                        <?= illustration($pictures[$state], 'md') ?>
                        <span class="shift-card__state badge badge--<?= e($tones[$state]) ?>"><?= e($stateLabel) ?></span>
                    </div>
                    <div class="shift-card__body">
                        <div class="shift-card__head">
                            <h3><?= e($card['label']) ?></h3>
                            <span><?= e($card['hours'] ?? ($shift !== null ? substr((string) $shift['opened_at'], 11, 5) . ($shift['closed_at'] ? ' – ' . substr((string) $shift['closed_at'], 11, 5) : '') : '')) ?></span>
                        </div>
                        <div class="shift-card__who">
                            <?php if ($shift !== null): ?>
                                <span class="avatar" aria-hidden="true"><?= e($initials) ?></span><strong><?= e($shift['attendant']) ?></strong>
                                <?php if (!empty($card['pumps']) && $shift['label']): ?><span class="chip chip--pump" title="<?= e(t('pumps.title')) ?>"><i data-lucide="fuel"></i><?= e($card['pumps']) ?></span><?php endif; ?>
                            <?php else: ?>
                                <i data-lucide="user-x"></i><?= e($stateLabel) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($shift !== null): ?>
                            <div class="shift-card__figures">
                                <div><span><?= e(t('shifts.card_sold')) ?></span><strong><?= e($litres($card['sold_cl'])) ?></strong></div>
                                <div><span><?= e(t('shifts.card_expected')) ?></span><strong><?= e($money($card['expected'])) ?></strong></div>
                                <div><span><?= e(t('shifts.card_variance')) ?></span><strong class="<?= ($card['variance'] ?? 0) < 0 ? 'overdue' : '' ?>"><?= e($signed($card['variance'])) ?></strong></div>
                            </div>
                        <?php endif; ?>
                        <ol class="shift-steps" aria-label="<?= e($stateLabel) ?>">
                            <?php foreach ($steps as $i => $step): ?>
                                <li class="<?= $i < $progress[$state] ? 'is-done' : '' ?>" title="<?= e(t($step)) ?>"></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if (!empty($card['hint'])): ?><p class="shift-card__hint"><i data-lucide="info"></i><?= e(t($card['hint'])) ?></p><?php endif; ?>
                        <div class="shift-card__foot">
                            <?php if ($card['action'] !== null): ?>
                                <a class="btn btn--primary" href="<?= e(url($card['action'][1])) ?>" data-shift-form="<?= e(url('/shifts/form', array_filter(['action' => $card['action'][3], 'shift' => $card['action'][4], 'label' => $card['action'][3] === 'open' ? $card['label'] : null]))) ?>"><i data-lucide="<?= e($card['action'][2]) ?>"></i><?= e(t($card['action'][0])) ?></a>
                            <?php elseif ($shift !== null): ?>
                                <button type="button" class="btn" data-shift-detail="<?= e(url('/shifts/' . $shift['id'] . '/summary')) ?>"><i data-lucide="eye"></i><?= e(t('shifts.card_detail')) ?></button>
                            <?php endif; ?>
                            <?php if ($shift !== null && !empty($canSettle) && $state !== 'validated'): ?>
                                <button type="button" class="btn shift-card__settle" data-shift-form="<?= e(url('/shifts/form', ['action' => 'settle', 'shift' => $shift['id']])) ?>"><i data-lucide="check-check"></i><?= e(t('shifts.settle_action')) ?></button>
                            <?php endif; ?>
                            <?php if ($shift !== null): ?>
                                <a class="shift-card__link" href="<?= e(url('/shifts/' . $shift['id'])) ?>"><i data-lucide="file-text"></i><?= e(t('shifts.view_sheet')) ?></a>
                            <?php endif; ?>
                            <button type="button" class="shift-card__link" data-slot-history="<?= e(url('/shifts/history', array_filter(['station' => $board['station']['id'], 'label' => $card['history_label']]))) ?>">
                                <i data-lucide="history"></i><?= e(t('shifts.history_slot')) ?>
                            </button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>
