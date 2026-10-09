<?php /* Petra — développé par BELEJ-CONSULTING. Pages sans menu : erreurs, connexion. */ ?>
<!doctype html>
<html lang="<?= e(\Petra\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body class="minimal">
<main class="auth">
    <?= $content ?>
</main>
<?= $view->partial('partials/footer') ?>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
