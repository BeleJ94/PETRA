<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Core\Database;
use Petra\Core\Env;
use Petra\Core\Migrator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Les identifiants viennent de .env ; DB_NAME est forcé à petra_test par phpunit.xml.
Env::load(dirname(__DIR__, 2) . '/.env');
date_default_timezone_set('Africa/Lubumbashi');

/** Recrée le schéma de test une seule fois par exécution, via les migrations (comme en production). */
function test_database(): Database
{
    static $db = null;
    if ($db === null) {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $db = new Database($config['db']);
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->dropAll();
        $migrator->migrate();
    }

    return $db;
}

/**
 * Seconde base, pour les tests de synchronisation : elle joue le serveur central (petra_central_test).
 * Créée au besoin, recréée par les migrations une fois par exécution, sans mise en file (comme un central).
 */
function central_test_database(): Database
{
    static $db = null;
    if ($db === null) {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $name = 'petra_central_test';
        test_database()->pdo()->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new Database(['name' => $name, 'sync_queue' => false, 'station_id' => ''] + $config['db']);
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->dropAll();
        $migrator->migrate();
    }

    return $db;
}
