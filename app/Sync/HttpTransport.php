<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

/** Envoi d'une requête au serveur central. Une coupure réseau lève TransportException. */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws TransportException serveur injoignable (hors ligne, délai dépassé, TLS refusé)
     */
    public function post(string $url, array $headers, string $body): array;
}
