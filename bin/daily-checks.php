<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Contrôles quotidiens (tâche planifiée) : php bin/daily-checks.php
 * Lève une alerte pour chaque poste resté ouvert, chaque cuve en stock bas et chaque station qui n'a pas
 * synchronisé depuis 24 h (délais réglables par station). Sur le central, seul le contrôle de synchronisation tourne.
 * Code de sortie : 0 = contrôles faits (alertes levées ou non), 1 = erreur.
 */

use Petra\Core\Database;
use Petra\Core\I18n;
use Petra\Maintenance\DailyChecks;
use Petra\Sync\SyncConfig;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
I18n::setCurrent(new I18n(dirname(__DIR__) . '/lang', (string) $config['default_locale']));

try {
    $raised = (new DailyChecks(new Database($config['db']), SyncConfig::fromConfig($config)))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($raised as $alert) {
    echo "[{$alert['type']}] {$alert['station']} — {$alert['message']}\n";
}
echo count($raised) . " alerte(s) levée(s).\n";
exit(0);
