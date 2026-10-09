<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Applique les migrations en attente : php bin/migrate.php [--status]
 */

use Petra\Core\Database;
use Petra\Core\Migrator;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$migrator = new Migrator(new Database($config['db']), dirname(__DIR__) . '/database/migrations');

if (in_array('--status', $argv, true)) {
    $applied = array_flip($migrator->applied());
    foreach (array_keys($migrator->files()) as $name) {
        echo (isset($applied[$name]) ? '[x] ' : '[ ] ') . $name . PHP_EOL;
    }
    exit(0);
}

$done = $migrator->migrate(static fn (string $line) => print($line . PHP_EOL));
echo $done === [] ? "Base à jour, aucune migration en attente.\n" : count($done) . " migration(s) appliquée(s).\n";
