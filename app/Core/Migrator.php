<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use RuntimeException;

/**
 * Applique dans l'ordre les fichiers database/migrations/NNNN_nom.sql non encore joués.
 * Le schéma n'évolue QUE par ce mécanisme (table schema_migrations).
 */
final class Migrator
{
    private const FILE_PATTERN = '/^(\d{4})_[a-z0-9_]+\.sql$/';

    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> noms des migrations appliquées lors de cet appel */
    public function migrate(?callable $output = null): array
    {
        $this->ensureTable();
        $applied = array_flip($this->applied());
        $done = [];
        foreach ($this->files() as $name => $path) {
            if (isset($applied[$name])) {
                continue;
            }
            $output && $output("→ $name");
            // Le DDL MariaDB valide implicitement : exécution instruction par instruction.
            foreach (self::splitStatements((string) file_get_contents($path)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->execute('INSERT INTO schema_migrations (migration) VALUES (:m)', ['m' => $name]);
            $done[] = $name;
        }

        return $done;
    }

    /** @return array<string, string> nom => chemin, triés par numéro */
    public function files(): array
    {
        $files = [];
        $numbers = [];
        foreach (scandir($this->dir) ?: [] as $file) {
            if (!preg_match(self::FILE_PATTERN, $file, $m)) {
                continue;
            }
            if (isset($numbers[$m[1]])) {
                throw new RuntimeException("Numéro de migration dupliqué : {$m[1]}");
            }
            $numbers[$m[1]] = true;
            $files[$file] = $this->dir . '/' . $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();

        return array_column($this->db->fetchAll('SELECT migration FROM schema_migrations ORDER BY migration'), 'migration');
    }

    /** Supprime toutes les tables (réservé aux bases de test). */
    public function dropAll(): void
    {
        $dbName = (string) $this->db->fetchValue('SELECT DATABASE()');
        if (!str_ends_with($dbName, '_test')) {
            throw new RuntimeException("dropAll() refusé hors base de test ($dbName)");
        }
        $tables = $this->db->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $row) {
            $table = (string) array_values($row)[0];
            $this->db->pdo()->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", str_replace("\r\n", "\n", $sql)),
            static fn (string $l): bool => !str_starts_with(ltrim($l), '--')
        );
        $statements = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $statements), static fn (string $s): bool => $s !== ''));
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
