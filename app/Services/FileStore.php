<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use Petra\Core\Env;
use Petra\Core\Uuid;
use Petra\Core\Validator;

/**
 * Fichiers envoyés : rangés hors racine web, dans storage/uploads/{station_id}/. Ils ne sont jamais
 * servis par le serveur web ; un contrôleur les lit après avoir vérifié les droits.
 * Le nom du fichier est généré ici : rien de ce que l'utilisateur envoie n'entre dans le chemin.
 */
final class FileStore
{
    public const MAX_IMAGE_BYTES = 5_242_880;

    /** Types acceptés, d'après le contenu réel du fichier (pas son nom ni le type annoncé). */
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? Env::get('UPLOADS_DIR') ?? dirname(__DIR__, 2) . '/storage/uploads', '/\\');
    }

    /** Un fichier a-t-il été envoyé dans ce champ ? */
    public static function wasSent(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Range une image envoyée sous {station_id}/{folder}/ et retourne son chemin relatif.
     *
     * @param array{tmp_name?: string, error?: int} $file fichier envoyé ($_FILES)
     * @param string $field champ de formulaire, pour le message d'erreur
     */
    public function storeImage(array $file, string $stationId, string $folder, string $field = 'photo'): string
    {
        if (!Uuid::isValid($stationId) || !preg_match('/^[a-z]+$/', $folder)) {
            throw new \InvalidArgumentException('Emplacement de fichier invalide');
        }
        $source = (string) ($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($source)) {
            throw Validator::fail($field, 'upload_failed');
        }
        if ((int) filesize($source) > self::MAX_IMAGE_BYTES) {
            throw Validator::fail($field, 'photo_size', ['max' => intdiv(self::MAX_IMAGE_BYTES, 1_048_576)]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $extension = self::IMAGE_TYPES[$mime] ?? null;
        if ($extension === null || @getimagesize($source) === false) {
            throw Validator::fail($field, 'photo_type');
        }
        $relative = $stationId . '/' . $folder . '/' . Uuid::v7() . '.' . $extension;
        $target = $this->baseDir . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Dossier de stockage inaccessible');
        }
        if (!(is_uploaded_file($source) ? move_uploaded_file($source, $target) : copy($source, $target))) {
            throw new \RuntimeException('Fichier non enregistré');
        }

        return $relative;
    }

    /**
     * Contenu d'un fichier rangé ici. Le chemin doit rester sous le dossier de la station attendue.
     *
     * @return array{body: string, mime: string}|null
     */
    public function read(string $relative, string $stationId): ?array
    {
        if (!preg_match('#^' . preg_quote($stationId, '#') . '/[a-z]+/[0-9a-f-]{36}\.(jpg|png|webp)$#', $relative)) {
            return null;
        }
        $path = realpath($this->baseDir . '/' . $relative);
        $base = realpath($this->baseDir);
        if ($path === false || $base === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return ['body' => (string) file_get_contents($path), 'mime' => (string) array_search(pathinfo($path, PATHINFO_EXTENSION), self::IMAGE_TYPES, true)];
    }

    public function delete(string $relative): void
    {
        $path = $this->baseDir . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
