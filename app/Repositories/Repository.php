<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Repositories;

use Petra\Core\DataTableRequest;
use Petra\Core\Database;
use Petra\Core\Uuid;
use Petra\Security\StationContext;

/**
 * Base des repositories. Le filtrage par station est garanti ici, au niveau des données :
 * - une liste inclut toujours $this->stations->clause() ;
 * - une ligne chargée par identifiant passe par scopedRow() ;
 * - une écriture obtient sa station par $this->stations->writable().
 */
abstract class Repository
{
    public function __construct(protected readonly Database $db, protected readonly StationContext $stations)
    {
    }

    public function stations(): StationContext
    {
        return $this->stations;
    }

    /**
     * Ligne d'une table métier par identifiant. Une ligne d'une station non attribuée lève un 403
     * (journalisé, avec alerte) : changer l'identifiant dans l'URL ne donne accès à rien.
     *
     * @param string $table nom de table fixé dans le code (jamais une saisie utilisateur)
     */
    protected function scopedRow(string $table, string $id): ?array
    {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            throw new \InvalidArgumentException("Table invalide : $table");
        }
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->db->fetchOne("SELECT * FROM `$table` WHERE id = :id", ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $this->stations->assertAllows(isset($row['station_id']) ? (string) $row['station_id'] : null);

        return $row;
    }

    /**
     * Recherche plein texte simple : (col1 LIKE :q0 OR col2 LIKE :q1 ...).
     * Placeholders distincts (préparations natives, sans émulation).
     *
     * @param list<string> $columns expressions fixées dans le code
     * @return array{0: string, 1: array<string, string>}
     */
    protected function searchClause(array $columns, string $term): array
    {
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $parts = [];
        $params = [];
        foreach ($columns as $i => $column) {
            $parts[] = "$column LIKE :q$i";
            $params["q$i"] = $like;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Pagination DataTables générique.
     *
     * @param string       $select        colonnes SELECT
     * @param string       $from          FROM + JOIN
     * @param list<string> $baseWhere     conditions de périmètre (déterminent recordsTotal)
     * @param list<string> $filterWhere   filtres utilisateur (déterminent recordsFiltered)
     * @param list<string> $searchColumns colonnes de recherche globale
     */
    protected function paginate(
        DataTableRequest $dt,
        string $select,
        string $from,
        array $baseWhere,
        array $baseParams,
        array $filterWhere,
        array $filterParams,
        array $searchColumns,
    ): array {
        $baseSql = implode(' AND ', $baseWhere ?: ['1=1']);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM $from WHERE $baseSql", $baseParams);

        $where = array_merge($baseWhere, $filterWhere);
        $params = array_merge($baseParams, $filterParams);
        if ($dt->search !== '' && $searchColumns !== []) {
            [$searchSql, $searchParams] = $this->searchClause($searchColumns, $dt->search);
            $where[] = $searchSql;
            $params += $searchParams;
        }
        $whereSql = implode(' AND ', $where ?: ['1=1']);
        $filtered = $where === $baseWhere
            ? $total
            : (int) $this->db->fetchValue("SELECT COUNT(*) FROM $from WHERE $whereSql", $params);

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            $select,
            $from,
            $whereSql,
            $dt->orderSql(),
            $dt->length,
            $dt->start
        );

        return $dt->response($total, $filtered, $this->db->fetchAll($sql, $params));
    }
}
