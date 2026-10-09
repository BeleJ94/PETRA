<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Sync;

/**
 * Transport HTTP sans dépendance : cURL s'il est disponible, sinon les flux PHP natifs.
 * Le certificat du central est toujours vérifié.
 */
final class NativeTransport implements HttpTransport
{
    public function __construct(private readonly int $timeout = 30)
    {
    }

    public function post(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'] as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return function_exists('curl_init') ? $this->curl($url, $lines, $body) : $this->stream($url, $lines, $body);
    }

    /** @param list<string> $headers */
    private function curl(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false) {
            throw new TransportException($error !== '' ? $error : 'Serveur central injoignable');
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /** @param list<string> $headers */
    private function stream(string $url, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $this->timeout, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new TransportException(error_get_last()['message'] ?? 'Serveur central injoignable');
        }
        $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int) $m[1] : 0;

        return ['status' => $status, 'body' => $response];
    }
}
