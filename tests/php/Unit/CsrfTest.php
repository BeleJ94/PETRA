<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\Csrf;
use Petra\Core\HttpException;
use Petra\Core\Request;
use Petra\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableWithinSessionAndValidated(): void
    {
        $csrf = new Csrf(Session::memory());
        $token = $csrf->token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));
        self::assertFalse($csrf->isValid('x' . substr($token, 1)));
        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(''));
    }

    public function testAcceptsFormFieldOrAjaxHeader(): void
    {
        $csrf = new Csrf(Session::memory());
        $token = $csrf->token();

        $csrf->validateRequest(new Request('POST', '/x', [], ['_csrf' => $token]));
        $csrf->validateRequest(new Request('POST', '/x', [], [], ['x-csrf-token' => $token]));
        $this->addToAssertionCount(2);

        $this->expectExceptionObject(HttpException::csrf());
        $csrf->validateRequest(new Request('POST', '/x', [], ['_csrf' => 'forged']));
    }

    public function testRotationInvalidatesPreviousToken(): void
    {
        $csrf = new Csrf(Session::memory());
        $old = $csrf->token();
        $csrf->rotate();

        self::assertNotSame($old, $csrf->token());
        self::assertFalse($csrf->isValid($old));
    }
}
