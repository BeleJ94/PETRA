<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Petra\Core\Csrf;
use Petra\Core\HttpException;
use Petra\Core\Session;
use Petra\Core\Uuid;
use Petra\Repositories\AlertRepository;
use Petra\Repositories\IdentityRepository;
use Petra\Security\Auth;
use Petra\Security\StationContext;
use Tests\DatabaseTestCase;

/**
 * Principe 1 : un utilisateur n'accède qu'aux stations qui lui sont attribuées.
 * Un gérant de la station A ne peut ni lire ni écrire les données de la station B,
 * même en changeant l'identifiant dans l'URL ou dans le formulaire.
 */
final class StationIsolationTest extends DatabaseTestCase
{
    private string $org;
    private string $stationA;
    private string $stationB;
    private string $managerA;
    private string $alertA;
    private string $alertB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->stationA = $this->createStation($this->org, 'A');
        $this->stationB = $this->createStation($this->org, 'B');
        $this->managerA = $this->createUser($this->org, 'manager', [$this->stationA]);
        $this->alertA = $this->createAlert($this->stationA, 'Écart de caisse station A');
        $this->alertB = $this->createAlert($this->stationB, 'Écart de caisse station B');
    }

    private function denials(): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'access_denied'");
    }

    // --- Lecture ----------------------------------------------------------------

    public function testManagerOnlyReadsHisStationThroughHttp(): void
    {
        $page = $this->http($this->sessionFor($this->managerA), 'GET', '/alerts');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Écart de caisse station A', $page->body);
        self::assertStringNotContainsString('Écart de caisse station B', $page->body);
        self::assertStringNotContainsString($this->alertB, $page->body);
    }

    public function testManagerOnlyReadsHisStationThroughTheRepository(): void
    {
        $alerts = $this->service($this->managerA, AlertRepository::class);

        self::assertSame([$this->alertA], array_column($alerts->listRecent(), 'id'));
        self::assertSame(1, $alerts->countOpen());
        self::assertSame($this->alertA, $alerts->find($this->alertA)['id']);

        try {
            $alerts->find($this->alertB);
            self::fail('La lecture d\'une ligne de la station B devait être refusée');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testChoosingStationBInTheSelectorIsRefused(): void
    {
        $session = $this->sessionFor($this->managerA);

        $response = $this->http($session, 'POST', '/context/station', ['station_id' => $this->stationB, 'back' => '/alerts']);

        self::assertSame(403, $response->status);
        self::assertNull($session->get(Auth::SESSION_STATION), 'La station active ne change pas');
        // Même en forçant la session, la station B n'est pas attribuée : elle est ignorée.
        $session->set(Auth::SESSION_STATION, $this->stationB);
        $page = $this->http($session, 'GET', '/alerts');
        self::assertStringContainsString('Écart de caisse station A', $page->body);
        self::assertStringNotContainsString('Écart de caisse station B', $page->body);
    }

    // --- Écriture ---------------------------------------------------------------

    public function testManagerCannotWriteStationBByChangingTheIdInTheUrl(): void
    {
        $session = $this->sessionFor($this->managerA);

        $refused = $this->http($session, 'POST', '/alerts/' . $this->alertB . '/resolve', ['resolution_note' => 'vu']);

        self::assertSame(403, $refused->status);
        self::assertSame(
            ['status' => 'open', 'resolved_by' => null, 'resolution_note' => null],
            $this->row('SELECT status, resolved_by, resolution_note FROM alerts WHERE id = :id', ['id' => $this->alertB]),
            'La ligne de la station B est intacte'
        );

        // La même action sur sa propre station aboutit.
        $allowed = $this->http($session, 'POST', '/alerts/' . $this->alertA . '/resolve', ['resolution_note' => 'Recompté']);
        self::assertSame(302, $allowed->status);
        self::assertSame(
            ['status' => 'resolved', 'resolved_by' => $this->managerA, 'resolution_note' => 'Recompté'],
            $this->row('SELECT status, resolved_by, resolution_note FROM alerts WHERE id = :id', ['id' => $this->alertA])
        );
    }

    public function testManagerCannotWriteStationBThroughTheRepository(): void
    {
        $alerts = $this->service($this->managerA, AlertRepository::class);

        foreach ([
            fn () => $alerts->resolve($this->alertB, $this->managerA, 'x'),
            fn () => $alerts->raise($this->stationB, 'cash_variance', 'warning', 'Alerte injectée'),
        ] as $write) {
            try {
                $write();
                self::fail('Écriture sur la station B acceptée');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM alerts WHERE station_id = :s', ['s' => $this->stationB]));
        self::assertSame('open', $this->value('SELECT status FROM alerts WHERE id = :id', ['id' => $this->alertB]));
    }

    public function testUnknownOrMalformedIdentifiersAreNotFound(): void
    {
        $session = $this->sessionFor($this->managerA);

        self::assertSame(404, $this->http($session, 'POST', '/alerts/' . Uuid::v7() . '/resolve')->status);
        self::assertSame(404, $this->http($session, 'POST', '/alerts/42/resolve')->status);
        self::assertSame(404, $this->http($session, 'POST', "/alerts/{$this->alertB}'%20OR%201=1/resolve")->status);
        self::assertSame(0, $this->denials());
    }

    // --- Journal et alerte --------------------------------------------------------

    public function testRefusalsAreLoggedAndRaiseOneAlertOnTheUsersStation(): void
    {
        $session = $this->sessionFor($this->managerA);

        $this->http($session, 'POST', '/alerts/' . $this->alertB . '/resolve');
        $this->http($session, 'POST', '/context/station', ['station_id' => $this->stationB]);
        $this->http($session, 'GET', '/users');

        self::assertSame(3, $this->denials(), 'Chaque refus est journalisé');
        $log = $this->row("SELECT * FROM audit_log WHERE action = 'access_denied' AND summary LIKE '%/users%'");
        self::assertSame($this->managerA, $log['created_by']);
        self::assertSame($this->stationA, $log['station_id']);
        self::assertSame('127.0.0.1', $log['ip_address']);
        self::assertSame('users.manage', json_decode((string) $log['after_data'], true)['permission']);

        $alerts = $this->db->fetchAll("SELECT * FROM alerts WHERE alert_type = 'access_denied'");
        self::assertCount(1, $alerts, 'Une rafale de refus ne crée qu\'une alerte');
        self::assertSame($this->stationA, $alerts[0]['station_id']);
        self::assertSame($this->managerA, $alerts[0]['user_id']);
        self::assertSame('open', $alerts[0]['status']);
        self::assertStringContainsString('/alerts/' . $this->alertB . '/resolve', $alerts[0]['message']);
    }

    // --- Périmètres légitimes -------------------------------------------------------

    public function testOwnerSeesEveryStationOfHisOrganizationOnly(): void
    {
        $owner = $this->createUser($this->org, 'owner');
        $otherOrg = $this->createOrganization('Concurrent');
        $foreignStation = $this->createStation($otherOrg, 'X');
        $foreignAlert = $this->createAlert($foreignStation, 'Écart station concurrente');

        $alerts = $this->service($owner, AlertRepository::class);
        $ids = array_column($alerts->listRecent(), 'id');
        sort($ids);
        $expected = [$this->alertA, $this->alertB];
        sort($expected);
        self::assertSame($expected, $ids);

        self::assertSame(403, $this->http($this->sessionFor($owner), 'POST', '/alerts/' . $foreignAlert . '/resolve')->status);
        self::assertSame(403, $this->http($this->sessionFor($owner), 'POST', '/context/station', ['station_id' => $foreignStation])->status);
    }

    public function testActiveStationNarrowsReadsForAMultiStationUser(): void
    {
        $manager = $this->createUser($this->org, 'manager', [$this->stationA, $this->stationB]);
        $session = $this->sessionFor($manager);

        $all = $this->http($session, 'GET', '/alerts');
        self::assertStringContainsString('<select id="station-switch"', $all->body, 'Sélecteur de station dans l\'en-tête');
        self::assertStringContainsString('station A', $all->body);
        self::assertStringContainsString('station B', $all->body);

        $switch = $this->http($session, 'POST', '/context/station', ['station_id' => $this->stationB, 'back' => '/alerts']);
        self::assertSame(302, $switch->status);
        self::assertSame('/alerts', $switch->headers['Location']);
        $onlyB = $this->http($session, 'GET', '/alerts');
        self::assertStringNotContainsString('Écart de caisse station A', $onlyB->body);
        self::assertStringContainsString('Écart de caisse station B', $onlyB->body);

        $this->http($session, 'POST', '/context/station', ['station_id' => '']);
        self::assertStringContainsString('Écart de caisse station A', $this->http($session, 'GET', '/alerts')->body);
        self::assertSame(0, $this->denials());
    }

    public function testSingleStationUserHasNoSelectorAndAUserWithoutStationSeesNothing(): void
    {
        $page = $this->http($this->sessionFor($this->managerA), 'GET', '/alerts');
        self::assertStringNotContainsString('<select id="station-switch"', $page->body);
        self::assertStringContainsString('Station A', $page->body, 'La station unique est affichée dans l\'en-tête');

        $orphan = $this->createUser($this->org, 'manager', []);
        self::assertSame([], $this->service($orphan, AlertRepository::class)->listRecent());
    }

    public function testBackUrlOfTheSelectorStaysInsideTheApplication(): void
    {
        $manager = $this->createUser($this->org, 'manager', [$this->stationA, $this->stationB]);
        foreach (['https://evil.test/', '//evil.test', '/\\evil.test'] as $back) {
            $response = $this->http($this->sessionFor($manager), 'POST', '/context/station', ['station_id' => $this->stationA, 'back' => $back]);
            self::assertSame('/', $response->headers['Location'], $back);
        }
    }

    /** APP_MODE=station : l'installation d'une station ne sert que cette station, même au propriétaire. */
    public function testStationInstallationIsRestrictedToItsOwnStation(): void
    {
        $owner = $this->createUser($this->org, 'owner');
        $session = $this->sessionFor($owner);
        $auth = new Auth($session, new IdentityRepository($this->db), new Csrf($session), self::config()['security'], $this->stationB);

        self::assertSame([$this->stationB], $auth->stations()->stationIds());
        self::assertSame($this->stationB, $auth->stations()->activeId());
        self::assertFalse($auth->stations()->allows($this->stationA));
    }

    public function testAnonymousContextGivesAccessToNothing(): void
    {
        self::assertSame([], $this->service(null, AlertRepository::class, Session::memory())->listRecent());
        self::assertSame([], $this->service(null, StationContext::class, Session::memory())->stationIds());
    }
}
