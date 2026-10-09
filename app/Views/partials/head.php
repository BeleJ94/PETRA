<?php
/* Petra — développé par BELEJ-CONSULTING. */
/** @var string|null $title */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1F4E79">
<meta name="author" content="BELEJ-CONSULTING">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(isset($title) ? $title . ' · Petra' : 'Petra') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(asset('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/dataTables.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
