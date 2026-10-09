<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\I18n;
use Petra\Core\Session;
use Petra\Core\View;
use Petra\Security\Auth;

/** Dépendances communes aux contrôleurs, injectées en un seul objet. */
final class ControllerContext
{
    public function __construct(
        public readonly View $view,
        public readonly Auth $auth,
        public readonly Session $session,
        public readonly I18n $i18n,
    ) {
    }
}
