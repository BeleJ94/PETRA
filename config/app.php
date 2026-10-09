<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Core\Env;

$mode = Env::get('APP_MODE', 'station');
if (!in_array($mode, ['station', 'central'], true)) {
    throw new RuntimeException("APP_MODE invalide : $mode (station ou central attendu)");
}

return [
    'name' => 'Petra',
    'vendor' => 'BELEJ-CONSULTING',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG'),
    'base_path' => Env::get('APP_BASE_PATH', ''),
    'default_locale' => 'fr',
    'locales' => ['fr'],
    // Stations en RDC (UTC+2, sans heure d'été)
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'),
    // Devise de référence des écarts ; l'USD est converti au taux de la tranche.
    'currency' => strtoupper((string) Env::get('APP_CURRENCY', 'CDF')),
    // station : serveur local d'une station (hors ligne possible) ; central : VPS consolidé.
    'mode' => $mode,
    'station_id' => Env::get('STATION_ID', ''),
    'sync' => [
        'url' => Env::get('SYNC_URL', ''),
        'token' => Env::get('SYNC_TOKEN', ''),
    ],
    'session' => [
        'name' => 'petra_session',
        'lifetime' => 60 * 60 * 8,
        'secure' => Env::bool('SESSION_SECURE'),
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
    ],
    // Sauvegardes (bin/backup.php) : dossier hors racine web, durée de conservation, dossier de mysqldump et mysql.
    'backup' => [
        'dir' => Env::get('BACKUP_DIR', '') ?: dirname(__DIR__) . '/storage/backups',
        'retention_days' => max(1, (int) Env::get('BACKUP_RETENTION_DAYS', '30')),
        'mysql_bin' => Env::get('MYSQL_BIN_DIR', '') ?: null,
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'petra'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
        // File de synchronisation : alimentée sur un serveur de station, jamais sur le central.
        'sync_queue' => $mode === 'station',
        'station_id' => $mode === 'station' ? (string) Env::get('STATION_ID', '') : '',
    ],
];
