<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\Router;
use Petra\Core\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratesLowercaseVersion7Identifiers(): void
    {
        $uuid = Uuid::v7();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        self::assertTrue(Uuid::isValid($uuid));
    }

    public function testIdentifiersAreUniqueAndCarryTheCurrentTime(): void
    {
        $ids = array_map(static fn () => Uuid::v7(), range(1, 2000));
        self::assertCount(2000, array_unique($ids));

        $ms = (int) hexdec(substr(str_replace('-', '', $ids[0]), 0, 12));
        self::assertEqualsWithDelta(microtime(true) * 1000, $ms, 5000);
    }

    public function testValidationRejectsAnythingElse(): void
    {
        foreach (['', '42', strtoupper(Uuid::v7()), Uuid::v7() . '0', null, 12, ['x']] as $value) {
            self::assertFalse(Uuid::isValid($value));
        }
    }

    public function testGeneratedIdentifiersAreAcceptedByTheRouter(): void
    {
        $router = new Router();
        $router->get('/stations/{id}', ['C', 'show']);
        $uuid = Uuid::v7();
        self::assertSame(['id' => $uuid], $router->match('GET', '/stations/' . $uuid)['params']);
    }
}
