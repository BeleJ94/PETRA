<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\HttpException;
use Petra\Repositories\IdentityRepository;
use Petra\Repositories\Repository;
use Petra\Repositories\RoleRepository;
use Petra\Security\StationContext;
use PHPUnit\Framework\TestCase;

final class StationContextTest extends TestCase
{
    private const A = '0190a7c2-6f3e-7b1a-9c4d-00000000000a';
    private const B = '0190a7c2-6f3e-7b1a-9c4d-00000000000b';
    private const C = '0190a7c2-6f3e-7b1a-9c4d-00000000000c';

    private static function stations(string ...$ids): array
    {
        return array_map(static fn (string $id): array => ['id' => $id, 'code' => substr($id, -1), 'name' => 'Station ' . substr($id, -1)], $ids);
    }

    public function testNoneAllowsNothing(): void
    {
        $context = StationContext::none();
        self::assertSame(['1=0', []], $context->clause('station_id'));
        self::assertFalse($context->allows(self::A));
        self::assertFalse($context->allows(null));
        self::assertNull($context->activeId());
    }

    public function testClauseCoversAllowedStationsThenOnlyTheActiveOne(): void
    {
        $all = StationContext::for('org', self::stations(self::A, self::B));
        self::assertSame(['a.station_id IN (:st_0, :st_1)', ['st_0' => self::A, 'st_1' => self::B]], $all->clause('a.station_id'));
        self::assertTrue($all->isMultiStation());
        self::assertNull($all->activeId());

        $active = StationContext::for('org', self::stations(self::A, self::B), self::B);
        self::assertSame(['station_id IN (:x_0)', ['x_0' => self::B]], $active->clause('station_id', 'x'));
        self::assertSame('Station b', $active->active()['name']);
        self::assertTrue($active->allows(self::A), 'Une station attribuée reste accessible par son identifiant');
    }

    public function testAStationThatIsNotAssignedCannotBecomeActive(): void
    {
        $context = StationContext::for('org', self::stations(self::A, self::B), self::C);
        self::assertNull($context->activeId());
        self::assertFalse($context->allows(self::C));
    }

    public function testSingleStationIsAlwaysActive(): void
    {
        $context = StationContext::for('org', self::stations(self::A));
        self::assertSame(self::A, $context->activeId());
        self::assertFalse($context->isMultiStation());
        self::assertSame(self::A, $context->writable());
    }

    public function testWritableRequiresAnAssignedStation(): void
    {
        $context = StationContext::for('org', self::stations(self::A, self::B));
        self::assertSame(self::B, $context->writable(self::B));

        foreach ([[self::C, 403], [null, 409]] as [$station, $status]) {
            try {
                $context->writable($station);
                self::fail('Écriture acceptée');
            } catch (HttpException $e) {
                self::assertSame($status, $e->status);
            }
        }
    }

    public function testClauseRejectsAnythingThatIsNotAColumnName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StationContext::for('org', self::stations(self::A))->clause('station_id; DROP TABLE users');
    }

    /**
     * Le filtrage par station vit dans la couche commune : tout repository hérite de Repository,
     * sauf ceux qui ne touchent aucune donnée rattachée à une station.
     */
    public function testEveryRepositoryGoesThroughTheCommonLayer(): void
    {
        $withoutStationData = [IdentityRepository::class, RoleRepository::class];
        $files = glob(dirname(__DIR__, 3) . '/app/Repositories/*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $class = 'Petra\\Repositories\\' . basename($file, '.php');
            if ($class === Repository::class || in_array($class, $withoutStationData, true)) {
                continue;
            }
            self::assertTrue(is_subclass_of($class, Repository::class), "$class doit hériter de Repository");
        }
    }

    public function testEveryRoutePermissionExistsInTheReferenceData(): void
    {
        // Le référentiel d'origine et les permissions ajoutées par les migrations suivantes.
        $sql = '';
        foreach (array_merge([dirname(__DIR__, 3) . '/database/migrations/0010_seed_roles_permissions.sql'], glob(dirname(__DIR__, 3) . '/database/migrations/*_permission_*.sql') ?: []) as $file) {
            $sql .= (string) file_get_contents($file);
        }
        preg_match_all("/'([a-z]+\\.[a-z_]+)', '/", $sql, $m);
        $router = new \Petra\Core\Router();
        (require dirname(__DIR__, 3) . '/app/routes.php')($router);
        foreach ((fn () => $this->routes)->call($router) as $route) {
            if (isset($route['options']['permission'])) {
                self::assertContains($route['options']['permission'], $m[1], $route['pattern']);
            }
        }
    }
}
