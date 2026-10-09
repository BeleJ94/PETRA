<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

// Routeur pour le serveur PHP intégré (développement) : php -S 127.0.0.1:8081 -t public bin/dev-router.php
// Ce serveur sert l'application à la racine, quel que soit APP_BASE_PATH dans .env (prévu pour Apache).
putenv('APP_BASE_PATH=');
$file = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file)) {
    return false;
}
require __DIR__ . '/../public/index.php';
