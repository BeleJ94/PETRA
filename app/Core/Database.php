<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use PDO;
use Throwable;

/**
 * Accès PDO : requêtes préparées uniquement (pas d'émulation), transactions imbricables.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(private readonly array $config)
    {
    }

    /** Connexion ouverte au premier accès : une page sans requête ne dépend pas de MariaDB. */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['host'],
                $this->config['port'],
                $this->config['name']
            );
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // Dates stockées en heure locale de la station (fuseau PHP) : NOW() et PHP restent cohérents.
            $offset = (new \DateTimeImmutable())->format('P');
            $this->pdo->exec("SET time_zone = '$offset', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            // Variables lues par les déclencheurs de sync_queue (migration 0016).
            $this->queueing(!empty($this->config['sync_queue']));
            $stationId = (string) ($this->config['station_id'] ?? '');
            $this->pdo->exec('SET @petra_station_id = ' . (preg_match('/^[0-9a-f-]{36}$/', $stationId) ? "_ascii'$stationId' COLLATE ascii_bin" : 'NULL'));
        }

        return $this->pdo;
    }

    /**
     * Active ou suspend la mise en file de synchronisation des écritures de cette connexion.
     * Suspendue sur le serveur central et pendant l'application de données reçues.
     */
    public function queueing(bool $enabled): void
    {
        $this->pdo()->exec('SET @petra_sync_apply = ' . ($enabled ? 'NULL' : '1'));
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Exécute une requête d'écriture et retourne le nombre de lignes affectées. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Exécute $callback dans une transaction. Les appels imbriqués rejoignent la transaction englobante.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;
        try {
            $result = $callback($this);
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $this->pdo()->commit();
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0 && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }
}
