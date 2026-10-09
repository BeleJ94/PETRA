<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Maintenance;

use DateTimeImmutable;
use Petra\Core\HttpException;
use Petra\Sync\HttpTransport;
use Petra\Sync\SyncConfig;
use Petra\Sync\TransportException;
use RuntimeException;

/**
 * Copie d'une sauvegarde de station vers le serveur central, par morceaux, sur l'API de synchronisation
 * (HTTPS, jeton de la station). Le central range les fichiers par station et vérifie leur empreinte.
 */
final class BackupTransfer
{
    /** Morceaux de 512 Kio : ils passent sous les limites d'envoi par défaut de PHP (post_max_size). */
    public const CHUNK = 524288;

    public function __construct(private readonly string $dir, private readonly int $retentionDays = 30)
    {
    }

    // --- Côté station ----------------------------------------------------------------------------------

    /**
     * @return string 'sent', 'offline' (central injoignable : la sauvegarde reste locale) ou 'skipped' (station non reliée)
     */
    public function send(string $path, SyncConfig $config, HttpTransport $transport): string
    {
        if (!$config->isSyncedStation() || !$config->urlIsAcceptable()) {
            return 'skipped';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Sauvegarde illisible : $path");
        }
        $hash = hash_file('sha256', $path);
        try {
            for ($index = 0; ; $index++) {
                $chunk = (string) fread($handle, self::CHUNK);
                $last = feof($handle);
                $response = $transport->post(
                    $config->url . '/api/sync/backup',
                    ['Authorization' => 'Bearer ' . $config->token, 'X-Station-Id' => $config->stationId],
                    json_encode(['station_id' => $config->stationId, 'name' => basename($path), 'index' => $index, 'data' => base64_encode($chunk), 'last' => $last, 'sha256' => $last ? $hash : null], JSON_THROW_ON_ERROR)
                );
                if ($response['status'] !== 200) {
                    throw new RuntimeException('Réponse du central : HTTP ' . $response['status']);
                }
                if ($last) {
                    return 'sent';
                }
            }
        } catch (TransportException) {
            return 'offline';
        } finally {
            fclose($handle);
        }
    }

    // --- Côté central ----------------------------------------------------------------------------------

    /**
     * Reçoit un morceau. Le dernier déclenche la vérification de l'empreinte, puis la rotation des
     * sauvegardes de cette station.
     *
     * @return array{stored: bool, complete: bool}
     */
    public function receive(string $stationId, mixed $name, mixed $index, mixed $data, bool $last, mixed $sha256, ?DateTimeImmutable $now = null): array
    {
        $bytes = is_string($data) ? base64_decode($data, true) : false;
        if (!is_string($name) || !preg_match(Backup::NAME, $name) || !is_int($index) || $index < 0 || $bytes === false || strlen($bytes) > self::CHUNK) {
            throw new HttpException(400, 'errors.sync_bad_request');
        }
        $dir = $this->stationDir($stationId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Dossier de sauvegarde impossible à créer : $dir");
        }
        $part = $dir . DIRECTORY_SEPARATOR . $name . '.part';
        // Les morceaux arrivent dans l'ordre : le premier recommence le fichier, les suivants le prolongent.
        $expected = $index === 0 ? 0 : (is_file($part) ? (int) filesize($part) : -1);
        if ($expected !== $index * self::CHUNK) {
            @unlink($part);
            throw new HttpException(409, 'errors.sync_bad_request');
        }
        file_put_contents($part, $bytes, $index === 0 ? LOCK_EX : FILE_APPEND | LOCK_EX);
        if (!$last) {
            return ['stored' => true, 'complete' => false];
        }
        if (!is_string($sha256) || !hash_equals(hash_file('sha256', $part), strtolower($sha256))) {
            @unlink($part);
            throw new HttpException(422, 'errors.sync_bad_request');
        }
        rename($part, $dir . DIRECTORY_SEPARATOR . $name);
        Backup::rotateDir($dir, $this->retentionDays, $now ?? new DateTimeImmutable());

        return ['stored' => true, 'complete' => true];
    }

    /** Dossier des sauvegardes reçues d'une station (identifiant déjà contrôlé par le jeton). */
    public function stationDir(string $stationId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'stations' . DIRECTORY_SEPARATOR . preg_replace('/[^0-9a-f-]/', '', strtolower($stationId));
    }
}
