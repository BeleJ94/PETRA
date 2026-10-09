<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var int $status  @var string $message  @var string|null $debug */ ?>
<?php $picture = match (true) { in_array($status, [401, 403], true) => 'error-lock', $status === 419 => 'error-time', $status >= 500 => 'error-broken', default => 'error-lost' }; ?>
<div class="auth__card auth__card--error">
    <div class="auth__brand"><i data-lucide="fuel"></i> Petra</div>
    <?= illustration($picture, 'md') ?>
    <h1><?= e((string) $status) ?></h1>
    <p><?= e($message) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= e(t('common.home')) ?></a>
    <?php if (!empty($debug)): ?>
        <pre style="margin-top: 16px; font-size: 12px; white-space: pre-wrap; overflow-x: auto;"><?= e($debug) ?></pre>
    <?php endif; ?>
</div>
