<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Uuid;
use Tests\DatabaseTestCase;

/** Permissions fines, réglées par le propriétaire depuis l'écran « Rôles et permissions ». */
final class RolePermissionsTest extends DatabaseTestCase
{
    private string $org;
    private string $station;
    private string $owner;
    private string $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station]);
    }

    /** Le référentiel est partagé entre les tests : on le remet dans l'état de la migration 0010. */
    protected function tearDown(): void
    {
        $this->restoreReferenceData();
    }

    private function roleId(string $code): string
    {
        return (string) $this->value('SELECT id FROM roles WHERE code = :c', ['c' => $code]);
    }

    /** @return list<string> */
    private function granted(string $role): array
    {
        return array_column($this->db->fetchAll(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.code = :r ORDER BY p.code',
            ['r' => $role]
        ), 'code');
    }

    public function testDefaultRolesGiveEachProfileItsScreens(): void
    {
        $attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $expected = [
            [$this->owner, ['/' => 200, '/alerts' => 200, '/users' => 200, '/roles' => 200]],
            [$this->manager, ['/' => 200, '/alerts' => 200, '/users' => 403, '/roles' => 403]],
            [$attendant, ['/' => 200, '/alerts' => 403, '/users' => 403, '/roles' => 403]],
        ];
        foreach ($expected as [$user, $pages]) {
            foreach ($pages as $path => $status) {
                self::assertSame($status, $this->http($this->sessionFor($user), 'GET', $path)->status, "$path pour $user");
            }
        }
        $menu = $this->http($this->sessionFor($attendant), 'GET', '/')->body;
        self::assertStringNotContainsString('href="/alerts"', $menu, 'Le menu ne montre que les écrans autorisés');
        self::assertStringNotContainsString('href="/roles"', $menu);
    }

    public function testOwnerScreenListsRolesAndPermissions(): void
    {
        $page = $this->http($this->sessionFor($this->owner), 'GET', '/roles');

        self::assertSame(200, $page->status);
        foreach (['Propriétaire', 'Gérant', 'Pompiste', 'Valider un poste clôturé', 'Approuver une correction'] as $text) {
            self::assertStringContainsString($text, $page->body);
        }
        self::assertStringContainsString('action="/roles/' . $this->roleId('manager') . '/permissions"', $page->body);
    }

    public function testOwnerGrantsAndRevokesPermissionsWithImmediateEffect(): void
    {
        $managerSession = $this->sessionFor($this->manager);
        self::assertSame(200, $this->http($managerSession, 'GET', '/alerts')->status);

        $response = $this->http($this->sessionFor($this->owner), 'POST', '/roles/' . $this->roleId('manager') . '/permissions', [
            'permissions' => ['dashboard.view', 'shifts.view', 'users.manage', 'inconnue.permission', 'dashboard.view'],
        ]);

        self::assertSame(302, $response->status);
        self::assertSame(['dashboard.view', 'shifts.view', 'users.manage'], $this->granted('manager'));
        self::assertSame(403, $this->http($managerSession, 'GET', '/alerts')->status, 'Permission retirée');
        self::assertSame(200, $this->http($managerSession, 'GET', '/users')->status, 'Permission accordée');
        self::assertCount(27, $this->granted('owner'));

        foreach ($this->db->fetchAll('SELECT id FROM role_permissions') as $row) {
            self::assertTrue(Uuid::isValid($row['id']));
        }
        $log = $this->row("SELECT * FROM audit_log WHERE action = 'permissions'");
        self::assertSame($this->owner, $log['created_by']);
        self::assertContains('alerts.view', json_decode((string) $log['before_data'], true)['permissions']);
        self::assertSame(['dashboard.view', 'shifts.view', 'users.manage'], json_decode((string) $log['after_data'], true)['permissions']);
    }

    public function testEveryPermissionCanBeRemovedFromARole(): void
    {
        $this->http($this->sessionFor($this->owner), 'POST', '/roles/' . $this->roleId('attendant') . '/permissions');

        self::assertSame([], $this->granted('attendant'));
    }

    public function testOwnerRoleCannotBeWeakened(): void
    {
        $response = $this->http($this->sessionFor($this->owner), 'POST', '/roles/' . $this->roleId('owner') . '/permissions', ['permissions' => ['dashboard.view']]);

        self::assertSame(403, $response->status);
        self::assertCount(27, $this->granted('owner'));
    }

    public function testOnlyTheOwnerChangesPermissions(): void
    {
        $before = $this->granted('manager');
        $all = array_column($this->db->fetchAll('SELECT code FROM permissions'), 'code');

        $response = $this->http($this->sessionFor($this->manager), 'POST', '/roles/' . $this->roleId('manager') . '/permissions', ['permissions' => $all]);

        self::assertSame(403, $response->status);
        self::assertSame($before, $this->granted('manager'));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'access_denied'"));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'access_denied' AND user_id = :u", ['u' => $this->manager]));
        self::assertSame(404, $this->http($this->sessionFor($this->owner), 'POST', '/roles/' . Uuid::v7() . '/permissions')->status);
    }
}
