<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

/**
 * Paramètres DataTables (server-side) validés : pagination bornée, recherche tronquée,
 * tri limité aux colonnes déclarées par le repository (liste blanche).
 */
final class DataTableRequest
{
    public const MAX_PAGE = 200;
    public const MAX_EXPORT = 5000;

    /** @param list<array{0: string, 1: 'ASC'|'DESC'}> $order expressions SQL issues de la liste blanche */
    private function __construct(
        public readonly int $draw,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly array $order,
    ) {
    }

    /**
     * @param array<string, mixed>  $query     paramètres GET envoyés par DataTables
     * @param array<string, string> $sortable  nom de colonne DataTables => expression SQL
     * @param array{0: string, 1: 'ASC'|'DESC'} $defaultOrder
     */
    public static function fromQuery(array $query, array $sortable, array $defaultOrder): self
    {
        $length = (int) ($query['length'] ?? 25);
        // -1 = export : toutes les lignes filtrées, dans une limite raisonnable.
        $length = $length === -1 ? self::MAX_EXPORT : max(1, min(self::MAX_PAGE, $length));

        $search = $query['search']['value'] ?? '';
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';

        $order = [];
        foreach (array_slice(is_array($query['order'] ?? null) ? $query['order'] : [], 0, 3) as $item) {
            $index = (int) ($item['column'] ?? -1);
            $name = $query['columns'][$index]['data'] ?? ($item['name'] ?? null);
            if (is_string($name) && isset($sortable[$name])) {
                $dir = strtolower((string) ($item['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
                $order[] = [$sortable[$name], $dir];
            }
        }

        return new self(
            max(0, (int) ($query['draw'] ?? 0)),
            max(0, (int) ($query['start'] ?? 0)),
            $length,
            $search,
            $order === [] ? [$defaultOrder] : $order,
        );
    }

    public function orderSql(): string
    {
        return implode(', ', array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], $this->order));
    }

    /** @param list<array<string, mixed>> $rows */
    public function response(int $total, int $filtered, array $rows): array
    {
        return [
            'draw' => $this->draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ];
    }
}
