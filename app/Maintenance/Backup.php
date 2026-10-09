<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Maintenance;

use DateTimeImmutable;
use RuntimeException;

/**
 * Sauvegardes de la base : mysqldump compressé (gzip) dans un dossier hors racine web, rotation,
 * vérification et restauration. Le mot de passe passe par l'environnement du processus (MYSQL_PWD),
 * jamais par la ligne de commande.
 */
final class Backup
{
    public const NAME = '/^petra-([A-Za-z0-9_]+)-(\d{8})-(\d{6})\.sql\.gz$/';
    /** Dernière ligne écrite par mysqldump quand la sauvegarde est allée au bout. */
    private const TRAILER = '-- Dump completed';

    /**
     * @param array{host: string, port: int, name: string, user: string, pass: string} $db
     * @param string|null $binDir dossier de mysqldump et mysql ; null : dossier XAMPP s'il existe, sinon le PATH
     */
    public function __construct(private readonly array $db, private readonly string $dir, private readonly int $retentionDays = 30, private readonly ?string $binDir = null)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Sauvegarde la base et retourne le chemin du fichier .sql.gz, vérifié. */
    public function create(?DateTimeImmutable $now = null): string
    {
        $this->ensureDir($this->dir);
        $path = $this->dir . DIRECTORY_SEPARATOR . 'petra-' . self::safeName((string) $this->db['name']) . '-' . ($now ?? new DateTimeImmutable())->format('Ymd-His') . '.sql.gz';
        $tmp = $path . '.tmp';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Impossible d'écrire dans $this->dir");
        }
        try {
            // Transaction unique : image cohérente sans bloquer la station pendant la sauvegarde.
            $error = $this->run(
                [$this->binary('mysqldump'), ...$this->connection(), '--single-transaction', '--routines', '--triggers', '--hex-blob', '--default-character-set=utf8mb4', (string) $this->db['name']],
                null,
                static function (string $chunk) use ($gz): void {
                    gzwrite($gz, $chunk);
                }
            );
        } finally {
            gzclose($gz);
        }
        if ($error !== null || !$this->verify($tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Sauvegarde échouée' . ($error === null ? ' : fichier incomplet' : " : $error"));
        }
        rename($tmp, $path);

        return $path;
    }

    /** Le fichier se décompresse jusqu'au bout et se termine par la ligne de fin de mysqldump. */
    public function verify(string $path): bool
    {
        $gz = is_file($path) ? @gzopen($path, 'rb') : false;
        if ($gz === false) {
            return false;
        }
        $tail = '';
        $bytes = 0;
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1 << 16);
            if ($chunk === false) {
                gzclose($gz);

                return false;
            }
            $bytes += strlen($chunk);
            $tail = substr($tail . $chunk, -200);
        }
        gzclose($gz);

        return $bytes > 0 && str_contains($tail, self::TRAILER);
    }

    /**
     * Supprime les sauvegardes plus vieilles que la durée de conservation (d'après la date de leur nom).
     * La plus récente est toujours gardée, même si elle est ancienne.
     *
     * @return list<string> noms supprimés
     */
    public function rotate(?DateTimeImmutable $now = null): array
    {
        return self::rotateDir($this->dir, $this->retentionDays, $now ?? new DateTimeImmutable());
    }

    /** @return list<string> */
    public static function rotateDir(string $dir, int $retentionDays, DateTimeImmutable $now): array
    {
        $files = self::listDir($dir);
        $limit = $now->modify('-' . max(1, $retentionDays) . ' days')->format('Ymd-His');
        $deleted = [];
        foreach (array_slice($files, 1) as $file) {
            if ($file['stamp'] < $limit && @unlink($file['path'])) {
                $deleted[] = $file['name'];
            }
        }

        return $deleted;
    }

    /** @return list<array{name: string, path: string, stamp: string, size: int}> sauvegardes, la plus récente d'abord */
    public function list(): array
    {
        return self::listDir($this->dir);
    }

    /** @return list<array{name: string, path: string, stamp: string, size: int}> */
    public static function listDir(string $dir): array
    {
        $files = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $name) {
            if (preg_match(self::NAME, $name, $m)) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                $files[] = ['name' => $name, 'path' => $path, 'stamp' => $m[2] . '-' . $m[3], 'size' => (int) filesize($path)];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return $files;
    }

    /**
     * Restaure une sauvegarde dans la base configurée, ou dans $target (créée au besoin) pour un essai.
     * Le fichier remplace toutes les tables qu'il contient.
     */
    public function restore(string $path, ?string $target = null): void
    {
        if (!$this->verify($path)) {
            throw new RuntimeException('Sauvegarde illisible ou incomplète : ' . basename($path));
        }
        $database = self::safeName($target ?? (string) $this->db['name']);
        $mysql = $this->binary('mysql');
        $error = $this->run([$mysql, ...$this->connection(), '-e', "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"]);
        if ($error !== null) {
            throw new RuntimeException("Création de la base $database impossible : $error");
        }
        $gz = gzopen($path, 'rb');
        try {
            $error = $this->run([$mysql, ...$this->connection(), '--default-character-set=utf8mb4', $database], static function () use ($gz): ?string {
                if (gzeof($gz)) {
                    return null;
                }
                $chunk = gzread($gz, 1 << 16);

                return $chunk === false ? null : $chunk;
            });
        } finally {
            gzclose($gz);
        }
        if ($error !== null) {
            throw new RuntimeException("Restauration échouée : $error");
        }
    }

    /** @return list<string> */
    private function connection(): array
    {
        return ['--host=' . $this->db['host'], '--port=' . (int) $this->db['port'], '--user=' . $this->db['user']];
    }

    /** Chemin de mysqldump ou mysql : MYSQL_BIN_DIR, sinon l'installation XAMPP, sinon le PATH. */
    private function binary(string $name): string
    {
        $exe = $name . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
        foreach (array_filter([$this->binDir, 'C:\\xampp\\mysql\\bin']) as $dir) {
            $path = rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($path)) {
                return $path;
            }
        }

        return $name;
    }

    /**
     * Exécute une commande sans passer par un shell.
     *
     * @param list<string> $command
     * @param (callable(): ?string)|null $input fournit l'entrée par morceaux, null à la fin
     * @param (callable(string): void)|null $output reçoit la sortie par morceaux
     * @return string|null message d'erreur, null si la commande a réussi
     */
    private function run(array $command, ?callable $input = null, ?callable $output = null): ?string
    {
        $errors = tempnam(sys_get_temp_dir(), 'petra-err-');
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            null,
            ['MYSQL_PWD' => (string) $this->db['pass']] + getenv(),
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            @unlink($errors);

            return 'commande introuvable : ' . $command[0] . ' (renseignez MYSQL_BIN_DIR dans .env)';
        }
        while ($input !== null && ($chunk = $input()) !== null) {
            if ($chunk !== '' && @fwrite($pipes[0], $chunk) === false) {
                break;
            }
        }
        fclose($pipes[0]);
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1 << 16);
            if ($chunk !== false && $chunk !== '' && $output !== null) {
                $output($chunk);
            }
        }
        fclose($pipes[1]);
        $code = proc_close($process);
        $message = trim((string) file_get_contents($errors));
        @unlink($errors);

        return $code === 0 ? null : ($message !== '' ? $message : "code de sortie $code");
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Dossier de sauvegarde impossible à créer : $dir");
        }
    }

    private static function safeName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException("Nom de base invalide : $name");
        }

        return $name;
    }
}
