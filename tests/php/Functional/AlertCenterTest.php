<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Session;
use Petra\Security\Auth;
use Tests\DatabaseTestCase;

/** Centre d'alertes : par station et consolidé, statuts ouverte / vue / traitée, badge de l'en-tête. */
final class AlertCenterTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $stationA;
    private string $stationB;
    private string $owner;
    private string $managerA;
    private Session $ownerSession;
    private Session $managerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->stationA = $this->createStation($this->org, 'A');
        $this->stationB = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->managerA = $this->createUser($this->org, 'manager', [$this->stationA]);
        $this->ownerSession = $this->sessionFor($this->owner);
        $this->managerSession = $this->sessionFor($this->managerA);
    }

    private function state(string $id): array
    {
        return $this->row('SELECT status, acknowledged_by, resolved_by, resolution_note FROM alerts WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array{action: string, before: mixed, after: mixed, by: ?string, station: ?string}> */
    private function history(string $id): array
    {
        return array_map(static fn (array $r): array => [
            'action' => $r['action'], 'before' => json_decode((string) $r['before_data'], true)['status'] ?? null,
            'after' => json_decode((string) $r['after_data'], true)['status'] ?? null, 'by' => $r['created_by'], 'station' => $r['station_id'],
        ], $this->db->fetchAll("SELECT * FROM audit_log WHERE entity_type = 'alert' AND entity_id = :id ORDER BY created_at, id", ['id' => $id]));
    }

    private function badge(Session $session): ?string
    {
        $body = $this->http($session, 'GET', '/')->body;
        if (!str_contains($body, 'class="btn btn--ghost alert-bell"')) {
            return null;
        }

        return preg_match('#<span class="alert-bell__badge">([^<]+)</span>#', $body, $m) ? $m[1] : '0';
    }

    // --- Statuts ---------------------------------------------------------------------------

    public function testAlertGoesFromOpenToSeenToHandledAndEachChangeIsLogged(): void
    {
        $alert = $this->createAlert($this->stationA, 'Écart de caisse du matin');
        self::assertSame('open', $this->state($alert)['status']);

        $seen = $this->http($this->managerSession, 'POST', "/alerts/$alert/acknowledge", ['back' => "/alerts/$alert"]);
        self::assertSame("/alerts/$alert", $seen->headers['Location']);
        self::assertSame(['status' => 'acknowledged', 'acknowledged_by' => $this->managerA, 'resolved_by' => null, 'resolution_note' => null], $this->state($alert));
        self::assertNotNull($this->value('SELECT acknowledged_at FROM alerts WHERE id = :id', ['id' => $alert]));

        // Marquer vue une seconde fois ne change rien et n'ajoute rien au journal.
        $this->http($this->managerSession, 'POST', "/alerts/$alert/acknowledge");
        self::assertCount(1, $this->history($alert));

        $this->http($this->ownerSession, 'POST', "/alerts/$alert/resolve", ['resolution_note' => 'Recompté avec le pompiste']);
        self::assertSame(['status' => 'resolved', 'acknowledged_by' => $this->managerA, 'resolved_by' => $this->owner, 'resolution_note' => 'Recompté avec le pompiste'], $this->state($alert));

        self::assertSame([
            ['action' => 'acknowledge', 'before' => 'open', 'after' => 'acknowledged', 'by' => $this->managerA, 'station' => $this->stationA],
            ['action' => 'resolve', 'before' => 'acknowledged', 'after' => 'resolved', 'by' => $this->owner, 'station' => $this->stationA],
        ], $this->history($alert));

        // Une alerte traitée ne revient pas en arrière.
        $this->http($this->managerSession, 'POST', "/alerts/$alert/acknowledge");
        $this->http($this->managerSession, 'POST', "/alerts/$alert/resolve", ['resolution_note' => 'Autre']);
        self::assertSame('Recompté avec le pompiste', $this->state($alert)['resolution_note']);
        self::assertCount(2, $this->history($alert));
    }

    public function testAnAlertCanBeHandledWithoutBeingMarkedSeenFirst(): void
    {
        $alert = $this->createAlert($this->stationA, 'Stock bas');

        $this->http($this->managerSession, 'POST', "/alerts/$alert/resolve");

        self::assertSame(['status' => 'resolved', 'acknowledged_by' => null, 'resolved_by' => $this->managerA, 'resolution_note' => null], $this->state($alert));
        self::assertSame([['action' => 'resolve', 'before' => 'open', 'after' => 'resolved', 'by' => $this->managerA, 'station' => $this->stationA]], $this->history($alert));
    }

    // --- Badge de l'en-tête -------------------------------------------------------------------

    public function testHeaderBadgeCountsOpenAlertsOfTheDisplayedStations(): void
    {
        self::assertSame('0', $this->badge($this->managerSession), 'Cloche présente, sans pastille');

        $first = $this->createAlert($this->stationA, 'Alerte A1');
        $this->createAlert($this->stationA, 'Alerte A2');
        $this->createAlert($this->stationB, 'Alerte B1');

        self::assertSame('2', $this->badge($this->managerSession), 'Le gérant ne compte que sa station');
        self::assertSame('3', $this->badge($this->ownerSession), 'Le propriétaire voit le total');
        $this->ownerSession->set(Auth::SESSION_STATION, $this->stationB);
        self::assertSame('1', $this->badge($this->ownerSession), 'Station active : son compte seulement');
        $this->ownerSession->remove(Auth::SESSION_STATION);

        // Vue ou traitée : l'alerte ne compte plus comme ouverte.
        $this->http($this->managerSession, 'POST', "/alerts/$first/acknowledge");
        self::assertSame('1', $this->badge($this->managerSession));
        self::assertSame('2', $this->badge($this->ownerSession));

        for ($i = 0; $i < 100; $i++) {
            $this->createAlert($this->stationB, "Rafale $i");
        }
        self::assertSame('99+', $this->badge($this->ownerSession));
        self::assertStringContainsString('href="/alerts"', $this->http($this->ownerSession, 'GET', '/')->body);

        // Le pompiste n'a pas le droit de voir les alertes : pas de cloche, pas de compte.
        $attendant = $this->sessionFor($this->createUser($this->org, 'attendant', [$this->stationA]));
        self::assertNull($this->badge($attendant));
        self::assertSame(403, $this->http($attendant, 'GET', '/alerts')->status);
    }

    // --- Par station et consolidé ----------------------------------------------------------------

    public function testOwnerSeesAConsolidatedViewThenOneStation(): void
    {
        $this->createAlert($this->stationA, 'Écart station A');
        $seen = $this->createAlert($this->stationA, 'Stock bas station A');
        $this->db->execute("UPDATE alerts SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = :u WHERE id = :id", ['u' => $this->owner, 'id' => $seen]);
        $critical = $this->createAlert($this->stationB, 'Déficit station B');
        $this->db->execute("UPDATE alerts SET severity = 'critical' WHERE id = :id", ['id' => $critical]);
        $done = $this->createAlert($this->stationB, 'Ancienne alerte B');
        $this->db->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u WHERE id = :id", ['u' => $this->owner, 'id' => $done]);

        $page = $this->http($this->ownerSession, 'GET', '/alerts');
        self::assertSame(200, $page->status);
        self::assertSame(2, substr_count($page->body, 'class="station-alerts__card'), 'Une carte par station');
        self::assertMatchesRegularExpression('#Station A</strong>\s*<span><b>1</b> ouverte\(s\) · <b>1</b> vue\(s\)</span>#', $page->body);
        self::assertMatchesRegularExpression('#Station B</strong>\s*<span><b>1</b> ouverte\(s\) · <b>0</b> vue\(s\)</span>\s*<span class="badge badge--danger">1 critique\(s\)</span>#', $page->body);
        foreach (['Écart station A', 'Stock bas station A', 'Déficit station B'] as $message) {
            self::assertStringContainsString($message, $page->body);
        }
        self::assertStringNotContainsString('Ancienne alerte B</a>', $page->body, 'Les alertes traitées ne sont plus « à traiter »');
        self::assertStringContainsString('data-table="alerts"', $page->body);

        // Une carte mène à la station : la vue devient celle de la station seule.
        $this->http($this->ownerSession, 'POST', '/context/station', ['station_id' => $this->stationB, 'back' => '/alerts']);
        $station = $this->http($this->ownerSession, 'GET', '/alerts');
        self::assertStringNotContainsString('station-alerts__card', $station->body);
        self::assertStringContainsString('Déficit station B', $station->body);
        self::assertStringNotContainsString('Écart station A', $station->body);

        $data = json_decode($this->http($this->ownerSession, 'GET', '/alerts/data?draw=3', [], self::AJAX)->body, true);
        self::assertSame([3, 2], [$data['draw'], $data['recordsTotal']]);
        self::assertSame(['Station B'], array_values(array_unique(array_column($data['data'], 'station'))));
        self::assertSame(['Ancienne alerte B'], array_column(json_decode($this->http($this->ownerSession, 'GET', '/alerts/data?status=resolved', [], self::AJAX)->body, true)['data'], 'message'));
        self::assertSame(['Déficit station B'], array_column(json_decode($this->http($this->ownerSession, 'GET', '/alerts/data?severity=critical', [], self::AJAX)->body, true)['data'], 'message'));
        self::assertSame(1, json_decode($this->http($this->ownerSession, 'GET', '/alerts/data?search[value]=Ancienne', [], self::AJAX)->body, true)['recordsFiltered']);

        // Un gérant d'une seule station n'a pas de vue consolidée.
        $manager = $this->http($this->managerSession, 'GET', '/alerts');
        self::assertStringNotContainsString('station-alerts__card', $manager->body);
        self::assertStringNotContainsString('Déficit station B', $manager->body);
        self::assertSame(2, json_decode($this->http($this->managerSession, 'GET', '/alerts/data', [], self::AJAX)->body, true)['recordsTotal']);
    }

    public function testAlertSheetShowsItsHistory(): void
    {
        $alert = $this->createAlert($this->stationA, 'Écart de caisse du soir');
        $empty = $this->http($this->managerSession, 'GET', "/alerts/$alert");
        self::assertSame(200, $empty->status);
        foreach (['Écart de caisse du soir', "-12\u{202F}500 FC", 'Ouverte', "/alerts/$alert/acknowledge", 'data-open="#resolve-alert"', 'Aucun changement de statut'] as $text) {
            self::assertStringContainsString($text, $empty->body);
        }

        $this->http($this->managerSession, 'POST', "/alerts/$alert/acknowledge");
        $this->http($this->ownerSession, 'POST', "/alerts/$alert/resolve", ['resolution_note' => 'Billet retrouvé']);

        $sheet = $this->http($this->ownerSession, 'GET', "/alerts/$alert");
        foreach (['Traitée', 'Marquée vue', 'Manager Test', 'Owner Test', 'Billet retrouvé'] as $text) {
            self::assertStringContainsString($text, $sheet->body);
        }
        self::assertStringNotContainsString("/alerts/$alert/acknowledge", $sheet->body);
        self::assertStringNotContainsString('#resolve-alert', $sheet->body);
    }

    // --- Droits -----------------------------------------------------------------------------------

    public function testAlertsOfAnotherStationCannotBeSeenOrChanged(): void
    {
        $alertB = $this->createAlert($this->stationB, 'Écart station B');

        foreach ([['GET', "/alerts/$alertB"], ['POST', "/alerts/$alertB/acknowledge"], ['POST', "/alerts/$alertB/resolve"]] as [$method, $path]) {
            self::assertSame(403, $this->http($this->managerSession, $method, $path)->status, "$method $path");
        }
        self::assertSame('open', $this->state($alertB)['status']);
        self::assertSame([], $this->history($alertB));

        // Station désactivée : le propriétaire peut encore solder ses alertes.
        $this->db->execute('UPDATE stations SET is_active = 0 WHERE id = :id', ['id' => $this->stationB]);
        $this->http($this->ownerSession, 'POST', "/alerts/$alertB/acknowledge");
        $this->http($this->ownerSession, 'POST', "/alerts/$alertB/resolve", ['resolution_note' => 'Station fermée']);
        self::assertSame('resolved', $this->state($alertB)['status']);
    }

    public function testViewingRequiresOnePermissionAndHandlingAnother(): void
    {
        $alert = $this->createAlert($this->stationA, 'Écart');
        $this->db->execute("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = 'manager' AND p.code = 'alerts.resolve'");
        try {
            $page = $this->http($this->managerSession, 'GET', '/alerts');
            self::assertSame(200, $page->status);
            self::assertStringContainsString('Écart', $page->body);
            self::assertStringNotContainsString("/alerts/$alert/acknowledge", $page->body);
            self::assertStringNotContainsString('#resolve-alert', $this->http($this->managerSession, 'GET', "/alerts/$alert")->body);
            self::assertSame(403, $this->http($this->managerSession, 'POST', "/alerts/$alert/acknowledge")->status);
            self::assertSame(403, $this->http($this->managerSession, 'POST', "/alerts/$alert/resolve")->status);
            self::assertSame('open', $this->state($alert)['status']);
        } finally {
            $this->restoreReferenceData();
        }
    }
}
