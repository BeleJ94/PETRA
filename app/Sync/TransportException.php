<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

use RuntimeException;

/** Le serveur central n'a pas pu être joint : la station est hors ligne, la file attend. */
final class TransportException extends RuntimeException
{
}
