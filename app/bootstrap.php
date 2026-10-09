<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/** @var array $config */
$config = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set($config['timezone']);
mb_internal_encoding('UTF-8');

return $config;
