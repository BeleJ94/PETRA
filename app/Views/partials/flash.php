<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php if (!empty($flashSuccess)): ?>
    <div class="alert alert--success" role="status"><i data-lucide="check-circle-2"></i><div><?= e($flashSuccess) ?></div></div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
    <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div>
        <?= e($flashError) ?>
        <?php if (\Petra\Core\FormState::errors() !== []): ?>
            <ul class="alert__list">
                <?php foreach (\Petra\Core\FormState::errors() as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
