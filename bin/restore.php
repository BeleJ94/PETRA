<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Restauration d'une sauvegarde. Procédure complète : docs/DEPLOIEMENT.md, « Restaurer une sauvegarde ».
 *
 *   php bin/restore.php                         liste les sauvegardes disponibles
 *   php bin/restore.php FICHIER --to=petra_essai restaure dans une base d'essai (créée au besoin) : sans risque
 *   php bin/restore.php FICHIER --yes            restaure dans la base de .env, après une sauvegarde de sécurité
 *
 * FICHIER : nom d'une sauvegarde du dossier de sauvegarde, chemin complet, ou « latest » pour la plus récente.
 * Sans --yes, la restauration dans la base de .env demande de taper RESTAURER.
 */

use Petra\Maintenance\Backup;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$backup = new Backup($config['db'], (string) $config['backup']['dir'], (int) $config['backup']['retention_days'], $config['backup']['mysql_bin']);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$target = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--to=')) {
        $target = substr($arg, 5);
    }
}

if ($args === []) {
    $files = $backup->list();
    echo $files === [] ? "Aucune sauvegarde dans {$backup->dir()}\n" : "Sauvegardes dans {$backup->dir()} :\n";
    foreach ($files as $file) {
        printf("  %s  %8s Ko\n", $file['name'], number_format($file['size'] / 1024, 0, ',', ' '));
    }
    echo "\nEssai sans risque : php bin/restore.php latest --to=petra_essai\n";
    exit(0);
}

$path = $args[0] === 'latest' ? ($backup->list()[0]['path'] ?? '') : (is_file($args[0]) ? $args[0] : $backup->dir() . DIRECTORY_SEPARATOR . basename($args[0]));
if (!is_file($path)) {
    fwrite(STDERR, "Sauvegarde introuvable : {$args[0]}\n");
    exit(1);
}
if (!$backup->verify($path)) {
    fwrite(STDERR, "Sauvegarde illisible ou incomplète : $path\nRien n'a été modifié.\n");
    exit(1);
}

$database = $target ?? (string) $config['db']['name'];
if ($target === null || $target === $config['db']['name']) {
    echo "ATTENTION : la base « $database » va être remplacée par le contenu de " . basename($path) . ".\n";
    echo "Tout ce qui a été saisi après cette sauvegarde sera perdu sur ce serveur.\n";
    if (!in_array('--yes', $argv, true)) {
        echo 'Tapez RESTAURER pour continuer : ';
        if (trim((string) fgets(STDIN)) !== 'RESTAURER') {
            echo "Abandon. Rien n'a été modifié.\n";
            exit(1);
        }
    }
    // Filet de sécurité : l'état actuel est sauvegardé avant d'être remplacé.
    try {
        echo 'Sauvegarde de sécurité : ' . $backup->create() . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Sauvegarde de sécurité impossible (" . $e->getMessage() . "). Restauration annulée.\n");
        exit(1);
    }
}

try {
    $backup->restore($path, $target);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Restauration terminée dans la base « $database ».\n";
echo $target === null
    ? "Vérifiez l'application, puis lancez : php bin/migrate.php (migrations parues depuis la sauvegarde) et php bin/sync.php.\n"
    : "Base d'essai : contrôlez-la, puis supprimez-la (DROP DATABASE `$database`).\n";
exit(0);
