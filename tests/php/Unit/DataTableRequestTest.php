<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\DataTableRequest;
use PHPUnit\Framework\TestCase;

final class DataTableRequestTest extends TestCase
{
    private const SORTABLE = ['code' => 'e.code', 'name' => 'e.name'];

    public function testParsesDataTablesParameters(): void
    {
        $dt = DataTableRequest::fromQuery([
            'draw' => '3', 'start' => '50', 'length' => '25',
            'search' => ['value' => '  chariot  '],
            'columns' => [['data' => 'code'], ['data' => 'name']],
            'order' => [['column' => '1', 'dir' => 'desc']],
        ], self::SORTABLE, ['e.code', 'ASC']);

        self::assertSame(3, $dt->draw);
        self::assertSame(50, $dt->start);
        self::assertSame(25, $dt->length);
        self::assertSame('chariot', $dt->search);
        self::assertSame('e.name DESC', $dt->orderSql());
    }

    public function testOrderingIsWhitelisted(): void
    {
        $dt = DataTableRequest::fromQuery([
            'columns' => [['data' => 'password_hash'], ['data' => '(SELECT 1)']],
            'order' => [['column' => '0', 'dir' => 'asc'], ['column' => '1', 'dir' => 'desc; DROP TABLE users']],
        ], self::SORTABLE, ['e.code', 'ASC']);

        self::assertSame('e.code ASC', $dt->orderSql(), 'Colonnes inconnues ignorées : tri par défaut');
    }

    public function testPaginationIsBounded(): void
    {
        self::assertSame(DataTableRequest::MAX_PAGE, DataTableRequest::fromQuery(['length' => '100000'], [], ['x', 'ASC'])->length);
        self::assertSame(1, DataTableRequest::fromQuery(['length' => '0'], [], ['x', 'ASC'])->length);
        self::assertSame(DataTableRequest::MAX_EXPORT, DataTableRequest::fromQuery(['length' => '-1'], [], ['x', 'ASC'])->length);
        self::assertSame(0, DataTableRequest::fromQuery(['start' => '-10'], [], ['x', 'ASC'])->start);
        self::assertSame(100, mb_strlen(DataTableRequest::fromQuery(['search' => ['value' => str_repeat('é', 300)]], [], ['x', 'ASC'])->search));
    }

    public function testResponseShape(): void
    {
        $dt = DataTableRequest::fromQuery(['draw' => '7'], [], ['x', 'ASC']);
        self::assertSame(['draw' => 7, 'recordsTotal' => 10, 'recordsFiltered' => 2, 'data' => [['id' => 1]]], $dt->response(10, 2, [['id' => 1]]));
    }
}
