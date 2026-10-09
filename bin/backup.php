<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Sauvegarde quotidienne (tâche planifiée) : php bin/backup.php [--no-upload]
 * mysqldump compressé dans storage/backups (ou BACKUP_DIR), rotation à 30 jours (BACKUP_RETENTION_DAYS),
 * puis copie vers le serveur central si la station y est reliée et qu'il est joignable.
 * Code de sortie : 0 = sauvegarde faite (copiée ou non), 1 = sauvegarde échouée, 2 = faite mais copie au central échouée.
 */

use Petra\Maintenance\Backup;
use Petra\Maintenance\BackupTransfer;
use Petra\Sync\NativeTransport;
use Petra\Sync\SyncConfig;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$backup = new Backup($config['db'], (string) $config['backup']['dir'], (int) $config['backup']['retention_days'], $config['backup']['mysql_bin']);
try {
    $path = $backup->create();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
printf("Sauvegarde : %s (%s Ko)\n", $path, number_format(filesize($path) / 1024, 0, ',', ' '));
foreach ($backup->rotate() as $deleted) {
    echo "Rotation   : $deleted supprimée\n";
}

if (in_array('--no-upload', $argv, true)) {
    exit(0);
}
try {
    $status = (new BackupTransfer((string) $config['backup']['dir']))->send($path, SyncConfig::fromConfig($config), new NativeTransport());
} catch (Throwable $e) {
    fwrite(STDERR, 'Copie au central échouée : ' . $e->getMessage() . "\n");
    exit(2);
}
echo match ($status) {
    'sent' => "Central    : copie envoyée\n",
    'offline' => "Central    : injoignable, la sauvegarde reste sur ce serveur (elle sera remplacée par celle de demain)\n",
    default => "Central    : station non reliée, pas de copie\n",
};
exit(0);
