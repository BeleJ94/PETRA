<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * À lancer sur le serveur central : php bin/sync-token.php [CODE_DE_STATION]
 * Sans argument : liste les stations. Avec un code : génère un nouveau jeton de synchronisation pour cette
 * station et affiche les trois lignes à copier dans son .env. Seule l'empreinte du jeton est conservée ici ;
 * il n'est affiché qu'une fois, et en générer un nouveau révoque le précédent.
 */

use Petra\Core\Database;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
if ($config['mode'] !== 'central') {
    fwrite(STDERR, "Cette commande se lance sur le serveur central (APP_MODE=central).\n");
    exit(1);
}
$db = new Database($config['db']);

$code = $argv[1] ?? null;
if ($code === null) {
    foreach ($db->fetchAll('SELECT s.code, s.name, s.id, s.sync_token_hash IS NOT NULL AS has_token, s.last_sync_at FROM stations s ORDER BY s.code') as $station) {
        printf("%-12s %-40s %s  jeton : %s  dernière synchro : %s\n", $station['code'], $station['name'], $station['id'], $station['has_token'] ? 'oui' : 'non', $station['last_sync_at'] ?? 'jamais');
    }
    exit(0);
}

$stations = $db->fetchAll('SELECT id, code, name FROM stations WHERE code = :c', ['c' => $code]);
if (count($stations) !== 1) {
    fwrite(STDERR, count($stations) === 0 ? "Station introuvable : $code\n" : "Plusieurs stations portent le code $code.\n");
    exit(1);
}
$token = bin2hex(random_bytes(32));
$db->execute('UPDATE stations SET sync_token_hash = :h WHERE id = :id', ['h' => hash('sha256', $token), 'id' => $stations[0]['id']]);

echo "Jeton généré pour {$stations[0]['name']}. À copier dans le .env du serveur de cette station :\n\n";
echo "APP_MODE=station\n";
echo "STATION_ID={$stations[0]['id']}\n";
echo "SYNC_TOKEN=$token\n";
echo "\nSYNC_URL : adresse HTTPS de ce serveur central (http://localhost/… pour un essai local).\n";
