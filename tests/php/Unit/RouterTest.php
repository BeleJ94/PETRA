<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\HttpException;
use Petra\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private const UUID = '0190a7c2-6f3e-7b1a-9c4d-2e5f8a1b3c7d';

    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        $router = new Router();
        $router->get('/stations/create', ['C', 'create']);
        $router->get('/stations/{id}', ['C', 'show'], ['permission' => 'stations.view']);
        $router->get('/stations/{id}/pumps/{pumpId}', ['C', 'pump']);

        self::assertSame(['C', 'create'], $router->match('GET', '/stations/create')['handler']);
        $match = $router->match('GET', '/stations/' . self::UUID);
        self::assertSame(['id' => self::UUID], $match['params']);
        self::assertSame('stations.view', $match['options']['permission']);
        self::assertTrue($match['options']['auth'], 'Les routes sont authentifiées par défaut');
        self::assertSame(
            ['id' => self::UUID, 'pumpId' => self::UUID],
            $router->match('GET', '/stations/' . self::UUID . '/pumps/' . self::UUID)['params']
        );
    }

    /** @return list<array{string}> */
    public static function invalidIds(): array
    {
        return [['42'], ['1%20OR%201=1'], [strtoupper(self::UUID)], [self::UUID . '0'], ['0190a7c26f3e7b1a9c4d2e5f8a1b3c7d']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIds')]
    public function testIdParametersAcceptOnlyUuids(string $id): void
    {
        $router = new Router();
        $router->get('/stations/{id}', ['C', 'show']);
        $this->expectExceptionObject(HttpException::notFound());
        $router->match('GET', '/stations/' . $id);
    }

    public function testWrongMethodIs405(): void
    {
        $router = new Router();
        $router->post('/stations', ['C', 'store']);
        try {
            $router->match('GET', '/stations');
            self::fail('405 attendu');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
        }
    }

    public function testEveryApplicationRouteTargetsAnExistingAction(): void
    {
        $router = new Router();
        (require dirname(__DIR__, 3) . '/app/routes.php')($router);
        $routes = (fn () => $this->routes)->call($router);

        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertTrue(method_exists($route['handler'][0], $route['handler'][1]), implode('::', $route['handler']));
        }
    }
}
