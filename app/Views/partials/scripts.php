<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** Données de configuration et catalogue i18n passés au JS sans script inline (CSP stricte). */
$ptConfig = [
    'base' => \Petra\Core\Url::basePath(),
    'locale' => \Petra\Core\I18n::current()->locale(),
    'stations' => array_map(static fn (array $s) => ['id' => (string) $s['id'], 'name' => $s['name']], $navStations ?? []),
    'assets' => [
        'exceljs' => asset('vendor/exceljs.min.js'),
        'pdfmake' => asset('vendor/pdfmake.min.js'),
        'vfs' => asset('vendor/vfs_fonts.js'),
    ],
];
?>
<script type="application/json" id="pt-config"><?= json_for_html($ptConfig) ?></script>
<script type="application/json" id="pt-i18n"><?= json_for_html(\Petra\Core\I18n::current()->jsCatalogue()) ?></script>
<script src="<?= e(asset('vendor/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/dataTables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/pt-core.js')) ?>"></script>
<script src="<?= e(asset('js/pt-tables.js')) ?>"></script>
<script src="<?= e(asset('js/pt-export.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
