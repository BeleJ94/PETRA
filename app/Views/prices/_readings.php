<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var list<array{shift_id: string, attendant: string, sequence: int, nozzles: list<array<string, string>>}> $open */
$oldReadings = old('readings', []);
?>
<?php if ($open !== []): ?>
    <div class="alert alert--info"><i data-lucide="gauge"></i><div><?= e(t('pricing.readings_notice')) ?></div></div>
    <table class="table" data-no-dt>
        <thead>
        <tr>
            <th><?= e(t('fields.attendant')) ?></th>
            <th><?= e(t('fields.nozzle')) ?></th>
            <th><?= e(t('fields.product')) ?></th>
            <th class="num"><?= e(t('fields.opening_index')) ?></th>
            <th><?= e(t('fields.intermediate_index')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($open as $shift): ?>
            <?php foreach ($shift['nozzles'] as $nozzle): ?>
                <?php $error = field_error('reading_' . $nozzle['nozzle_id']); ?>
                <tr>
                    <td><?= e($shift['attendant']) ?></td>
                    <td><strong><?= e($nozzle['code']) ?></strong> <span class="muted"><?= e($nozzle['name']) ?></span></td>
                    <td><?= e($nozzle['product']) ?></td>
                    <td class="num"><?= e(format_number($nozzle['opening_index'], 2)) ?></td>
                    <td>
                        <input name="readings[<?= e($shift['shift_id']) ?>][<?= e($nozzle['nozzle_id']) ?>]" class="input" required inputmode="decimal"
                               value="<?= e($oldReadings[$shift['shift_id']][$nozzle['nozzle_id']] ?? '') ?>"
                               aria-label="<?= e(t('fields.intermediate_index') . ' ' . $nozzle['code']) ?>"<?= $error ? ' aria-invalid="true"' : '' ?>>
                        <?php if ($error): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
