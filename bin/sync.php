<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Synchronisation station ↔ central (à lancer par cron sur le serveur de la station) : php bin/sync.php
 * Envoie la file sync_queue au central par lots, puis récupère comptes, droits, prix et taux.
 * Code de sortie : 0 = passe réussie, 1 = échec ou configuration absente, 2 = central injoignable (hors ligne).
 */

use Petra\Core\Database;
use Petra\Core\I18n;
use Petra\Sync\NativeTransport;
use Petra\Sync\SyncClient;
use Petra\Sync\SyncConfig;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

I18n::setCurrent(new I18n(dirname(__DIR__) . '/lang', (string) $config['default_locale']));
$sync = SyncConfig::fromConfig($config);
if (!$sync->isSyncedStation()) {
    fwrite(STDERR, "Synchronisation non configurée : APP_MODE=station, STATION_ID, SYNC_URL et SYNC_TOKEN sont requis dans .env.\n");
    exit(1);
}

// Une seule passe à la fois : un cron qui déborde ne doit pas envoyer deux fois la même file en parallèle.
$lock = fopen(dirname(__DIR__) . '/storage/logs/sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Une synchronisation est déjà en cours.\n");
    exit(1);
}

try {
    $result = (new SyncClient(new Database($config['db']), $sync, new NativeTransport()))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}

$push = $result['push'];
printf("Envoi     : %s — %d envoyée(s), %d acceptée(s), %d rejetée(s), %d conflit(s)%s\n", $push['status'], $push['sent'], $push['accepted'], $push['rejected'], $push['conflicts'], $push['error'] ? ' — ' . $push['error'] : '');
if ($result['pull'] !== null) {
    $pull = $result['pull'];
    printf("Réception : %s — %d ligne(s) appliquée(s), %d conflit(s)%s\n", $pull['status'], $pull['applied'], $pull['conflicts'], $pull['error'] ? ' — ' . $pull['error'] : '');
}
if ($push['status'] === 'offline') {
    exit(2);
}
exit($push['status'] === 'success' && ($result['pull']['status'] ?? '') === 'success' ? 0 : 1);
