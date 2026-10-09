<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Core\App;
use Petra\Core\Database;
use Petra\Core\Request;
use Petra\Core\Session;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals((string) $config['base_path']);
$session = Session::native($config['session']);

App::create($config, $session, new Database($config['db']), $request)
    ->handle($request)
    ->send();
