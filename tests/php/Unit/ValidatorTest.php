<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\I18n;
use Petra\Core\ValidationException;
use Petra\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::setCurrent(new I18n(dirname(__DIR__, 3) . '/lang'));
    }

    public function testCastsAndTrimsValidData(): void
    {
        $data = Validator::validate(
            ['code' => '  CH-01 ', 'hour_meter' => '1500', 'unit_cost' => '12,5', 'active' => 'on', 'due' => '2026-10-01', 'at' => '2026-10-01T08:30'],
            ['code' => 'required|string|max:30', 'hour_meter' => 'int|min:0', 'unit_cost' => 'numeric', 'active' => 'bool', 'due' => 'date', 'at' => 'datetime', 'notes' => 'string']
        );

        self::assertSame(['code' => 'CH-01', 'hour_meter' => 1500, 'unit_cost' => 12.5, 'active' => true, 'due' => '2026-10-01', 'at' => '2026-10-01 08:30:00', 'notes' => null], $data);
    }

    public function testCollectsTranslatedErrorsPerField(): void
    {
        try {
            Validator::validate(
                ['code' => '', 'hour_meter' => '12a', 'status' => 'broken', 'email' => 'nope', 'due' => '2026-02-30', 'name' => str_repeat('x', 11)],
                ['code' => 'required', 'hour_meter' => 'int', 'status' => 'in:available,breakdown', 'email' => 'email', 'due' => 'date', 'name' => 'max:10']
            );
            self::fail('ValidationException attendue');
        } catch (ValidationException $e) {
            self::assertSame(['code', 'hour_meter', 'status', 'email', 'due', 'name'], array_keys($e->errors));
            self::assertSame('Le champ « Code » est obligatoire.', $e->errors['code']);
            self::assertStringContainsString('10', $e->errors['name']);
        }
    }

    public function testNumericBounds(): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate(['quantity' => '-1'], ['quantity' => 'numeric|min:0']);
    }

    public function testRejectsArraysWhereScalarsAreExpected(): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate(['status' => ['available']], ['status' => 'in:available']);
    }

    public function testFailBuildsFieldError(): void
    {
        $e = Validator::fail('code', 'unique');
        self::assertSame(['code' => 'Cette valeur de « Code » est déjà utilisée.'], $e->errors);
    }
}
