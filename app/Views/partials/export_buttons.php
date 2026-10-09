<?php /* Petra — développé par BELEJ-CONSULTING. */ ?>
<?php /** @var string $table identifiant data-table ciblé  @var string $exportTitle */ ?>
<button type="button" class="btn" data-export="excel" data-export-table="<?= e($table) ?>" data-export-title="<?= e($exportTitle) ?>"><i data-lucide="file-spreadsheet"></i><?= e(t('common.export_excel')) ?></button>
<button type="button" class="btn" data-export="pdf" data-export-table="<?= e($table) ?>" data-export-title="<?= e($exportTitle) ?>"><i data-lucide="file-text"></i><?= e(t('common.export_pdf')) ?></button>
