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

/** Écran « Prix et taux » : consultation, publication, relevés intermédiaires, droits. */
final class PricingScreenTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $station;
    private string $other;
    private string $owner;
    private string $product;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->other = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->product = $this->createProduct($this->station);
        $this->session = $this->sessionFor($this->owner);
        $this->session->set(Auth::SESSION_STATION, $this->station);
    }

    private function post(string $path, array $body, string $referer): \Petra\Core\Response
    {
        return $this->http($this->session, 'POST', $path, $body, ['referer' => 'http://localhost' . $referer]);
    }

    public function testThePageAsksForAStationThenShowsCurrentValues(): void
    {
        $all = $this->sessionFor($this->owner);
        $page = $this->http($all, 'GET', '/prices');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Choisissez une station', $page->body);
        self::assertStringNotContainsString('/rates/new', $page->body);

        $this->createRate($this->station, 2300, date('Y-m-d H:i:s', time() - 3600), $this->owner);
        $this->createPrice($this->station, $this->product, 3450, date('Y-m-d H:i:s', time() - 3600), $this->owner);
        $page = $this->http($this->session, 'GET', '/prices');
        self::assertStringContainsString("1 $ = 2\u{202F}300 FC", $page->body);
        self::assertStringContainsString("3\u{202F}450 FC", $page->body);
        self::assertStringContainsString('/prices/products/' . $this->product . '/new', $page->body);
        self::assertStringContainsString('data-table="prices"', $page->body);
        self::assertStringContainsString('data-table="rates"', $page->body);
    }

    public function testOwnerPublishesARateAndAPriceFromTheForms(): void
    {
        $this->createRate($this->station, 2250, date('Y-m-d H:i:s', time() - 3600), $this->owner);
        $form = $this->http($this->session, 'GET', '/rates/new');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('data-confirm="', $form->body, 'Confirmation SweetAlert2');

        // Valeur identique à celle en vigueur : refusée, le formulaire revient avec l'erreur.
        self::assertSame('/rates/new', $this->post('/rates', ['rate' => '2250'], '/rates/new')->headers['Location']);
        self::assertStringContainsString('déjà en vigueur', $this->http($this->session, 'GET', '/rates/new')->body);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));

        self::assertSame('/prices', $this->post('/rates', ['rate' => '2300', 'note' => 'Ouverture'], '/rates/new')->headers['Location']);
        $path = '/prices/products/' . $this->product;
        self::assertSame(200, $this->http($this->session, 'GET', $path . '/new')->status);
        self::assertSame('/prices', $this->post($path, ['unit_price' => '3 450', 'currency' => 'CDF'], $path . '/new')->headers['Location']);

        self::assertSame(['rate' => 2300, 'note' => 'Ouverture', 'created_by' => $this->owner], $this->row('SELECT rate, note, created_by FROM exchange_rates ORDER BY effective_at DESC LIMIT 1'));
        self::assertSame(['unit_price' => 3450, 'currency' => 'CDF', 'station_id' => $this->station], $this->row('SELECT unit_price, currency, station_id FROM prices'));
        self::assertStringContainsString("1 $ = 2\u{202F}300 FC", $this->http($this->session, 'GET', '/prices')->body);
    }

    public function testHistoryIsServedPageByPageAndLimitedToTheActiveStation(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->createRate($this->station, 2300 + $i, sprintf('2026-10-01 %02d:00:00', $i), $this->owner);
        }
        $this->createRate($this->other, 9999, '2026-10-01 08:00:00', $this->owner);
        $this->createPrice($this->station, $this->product, 3450, '2026-10-01 06:00:00', $this->owner);
        $this->createPrice($this->other, $this->createProduct($this->other), 1111, '2026-10-01 06:00:00', $this->owner);

        $rates = json_decode($this->http($this->session, 'GET', '/rates/data?draw=2&start=0&length=15', [], self::AJAX)->body, true);
        self::assertSame([2, 20, 15], [$rates['draw'], $rates['recordsTotal'], count($rates['data'])]);
        self::assertSame(2319, (int) $rates['data'][0]['rate'], 'Version la plus récente en tête');
        self::assertSame('Owner Test', $rates['data'][0]['author']);
        self::assertNotContains(9999, array_map('intval', array_column($rates['data'], 'rate')));

        $prices = json_decode($this->http($this->session, 'GET', '/prices/data', [], self::AJAX)->body, true);
        self::assertSame([3450], array_map('intval', array_column($prices['data'], 'unit_price')));

        // Sans station active : le propriétaire voit l'historique de toutes ses stations.
        $all = json_decode($this->http($this->sessionFor($this->owner), 'GET', '/prices/data', [], self::AJAX)->body, true);
        self::assertSame(2, $all['recordsTotal']);
    }

    public function testFormAsksForIntermediateReadingsWhenAShiftIsOpen(): void
    {
        $at = date('Y-m-d H:i:s', time() - 7200);
        $rate = $this->createRate($this->station, 2300, $at, $this->owner);
        $price = $this->createPrice($this->station, $this->product, 3450, $at, $this->owner);
        $nozzle = $this->createNozzle($this->station, $this->createTank($this->station, $this->product), 'P-ESS');
        $attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $shift = $this->openShift($this->station, $attendant, $rate, [$nozzle => ['1000.00', $price]], $at, $this->owner);

        self::assertStringContainsString('1 poste(s) ouvert(s)', $this->http($this->session, 'GET', '/prices')->body);
        $form = $this->http($this->session, 'GET', '/rates/new');
        self::assertStringContainsString('name="readings[' . $shift['shift'] . '][' . $nozzle . ']"', $form->body);
        self::assertStringContainsString('Attendant Test', $form->body);
        self::assertStringContainsString("1\u{202F}000,00", $form->body, 'Index d\'ouverture affiché');

        // Sans index : refus, message nommant le pistolet, rien d'enregistré.
        self::assertSame('/rates/new', $this->post('/rates', ['rate' => '2320'], '/rates/new')->headers['Location']);
        $again = $this->http($this->session, 'GET', '/rates/new');
        self::assertStringContainsString('Index intermédiaire manquant pour le pistolet P-ESS', $again->body);
        self::assertStringContainsString('value="2320"', $again->body, 'La saisie est conservée');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));

        self::assertSame('/prices', $this->post('/rates', ['rate' => '2320', 'readings' => [$shift['shift'] => [$nozzle => '1 240,5']]], '/rates/new')->headers['Location']);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $shift['shift']]));
        self::assertSame('1240.50', $this->value("SELECT index_value FROM meter_readings WHERE reading_type = 'closing'"));

        // Le formulaire de prix demande les mêmes relevés, à partir du nouvel index d'ouverture.
        $priceForm = $this->http($this->session, 'GET', '/prices/products/' . $this->product . '/new');
        self::assertStringContainsString("1\u{202F}240,50", $priceForm->body);
    }

    public function testRolesSeeButOnlyTheOwnerChanges(): void
    {
        $this->createRate($this->station, 2300, date('Y-m-d H:i:s', time() - 3600), $this->owner);
        $manager = $this->sessionFor($this->createUser($this->org, 'manager', [$this->station]));
        $attendant = $this->sessionFor($this->createUser($this->org, 'attendant', [$this->station]));

        foreach ([$manager, $attendant] as $session) {
            $page = $this->http($session, 'GET', '/prices');
            self::assertSame(200, $page->status);
            self::assertStringContainsString("2\u{202F}300 FC", $page->body);
            self::assertStringNotContainsString('/rates/new', $page->body);
            self::assertSame(403, $this->http($session, 'GET', '/rates/new')->status);
            self::assertSame(403, $this->http($session, 'POST', '/rates', ['rate' => '1'])->status);
            self::assertSame(403, $this->http($session, 'POST', '/prices/products/' . $this->product, ['unit_price' => '1', 'currency' => 'CDF'])->status);
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM prices'));
    }

    public function testNothingIsPublishedOnADeactivatedStationOrWithoutActiveStation(): void
    {
        self::assertSame(409, $this->http($this->sessionFor($this->owner), 'POST', '/rates', ['rate' => '2300'])->status, 'Aucune station choisie');

        $this->db->execute('UPDATE stations SET is_active = 0 WHERE id = :id', ['id' => $this->station]);
        self::assertSame(409, $this->post('/rates', ['rate' => '2300'], '/rates/new')->status);
        self::assertSame(409, $this->post('/prices/products/' . $this->product, ['unit_price' => '3450', 'currency' => 'CDF'], '/prices')->status);
        self::assertStringNotContainsString('/rates/new', $this->http($this->session, 'GET', '/prices')->body);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM exchange_rates') + (int) $this->value('SELECT COUNT(*) FROM prices'));
    }
}
