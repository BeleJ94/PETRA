<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\Container;
use Petra\Core\Database;
use Petra\Core\Migrator;
use Petra\Core\ValidationException;
use Petra\Core\Validator;
use PHPUnit\Framework\TestCase;

final class MiscUnitTest extends TestCase
{
    public function testMigrationFilesAreNumberedAndOrdered(): void
    {
        $dir = dirname(__DIR__, 3) . '/database/migrations';
        $files = scandir($dir) ?: [];
        foreach ($files as $file) {
            if ($file[0] !== '.') {
                self::assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.sql$/', $file, "Nom de migration non conforme : $file");
            }
        }
        $sql = array_values(array_filter($files, static fn ($f) => str_ends_with($f, '.sql')));
        $numbers = array_map(static fn ($f) => (int) substr($f, 0, 4), $sql);
        self::assertSame($numbers === [] ? [] : range(1, count($numbers)), $numbers, 'Numérotation continue sans doublon');
    }

    public function testSqlSplitterIgnoresCommentsAndTrailingSeparators(): void
    {
        $statements = Migrator::splitStatements("-- commentaire\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);\n-- fin\n");
        self::assertSame(['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)'], $statements);
    }

    public function testContainerAutowiresAndSharesInstances(): void
    {
        $c = new Container();
        $a = $c->get(\SplObjectStorage::class);
        self::assertSame($a, $c->get(\SplObjectStorage::class));

        $c->factory('answer', static fn () => new \ArrayObject([42]));
        self::assertSame(42, $c->get('answer')[0]);
    }

    /** Une page sans requête SQL ne doit pas dépendre de MariaDB (station hors ligne, base arrêtée). */
    public function testDatabaseConnectsLazily(): void
    {
        $db = new Database(['host' => '127.0.0.1', 'port' => 1, 'name' => 'nope', 'user' => 'nobody', 'pass' => '']);
        self::assertFalse($db->inTransaction());
        $this->expectException(\PDOException::class);
        $db->fetchValue('SELECT 1');
    }

    /** Volumes DECIMAL(12,2) : la validation rend une chaîne normalisée, jamais un float. */
    public function testDecimalRuleReturnsNormalisedString(): void
    {
        $data = Validator::validate(
            ['a' => '1500', 'b' => '12,5', 'c' => ' 0.07 ', 'd' => '123456789.99'],
            ['a' => 'decimal:2', 'b' => 'decimal:2|min:0', 'c' => 'required|decimal:2', 'd' => 'decimal:2']
        );
        self::assertSame(['a' => '1500.00', 'b' => '12.50', 'c' => '0.07', 'd' => '123456789.99'], $data);
        // L'espace des milliers (simple, insécable ou fine) est accepté à la saisie.
        self::assertSame(
            ['a' => '1000.00', 'b' => '9950.50', 'c' => '1234567.80'],
            Validator::validate(['a' => '1 000', 'b' => "9\u{00A0}950,5", 'c' => "1\u{202F}234\u{202F}567.8"], ['a' => 'decimal:2', 'b' => 'decimal:2', 'c' => 'decimal:2'])
        );
    }

    /** @return list<array{string}> */
    public static function invalidDecimals(): array
    {
        return [['12.345'], ['1e3'], ['abc'], ['1.'], ['.5'], ['1 000 000,123'], ['1,000.50']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecimals')]
    public function testDecimalRuleRejectsInvalidInput(string $value): void
    {
        \Petra\Core\I18n::setCurrent(new \Petra\Core\I18n(dirname(__DIR__, 3) . '/lang'));
        $this->expectException(ValidationException::class);
        Validator::validate(['v' => $value], ['v' => 'decimal:2']);
    }
}
