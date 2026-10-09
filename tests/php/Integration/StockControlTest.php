<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDOException;
use Petra\Core\HttpException;
use Petra\Core\Uuid;
use Petra\Core\ValidationException;
use Petra\Services\ShiftService;
use Petra\Services\StockControl;
use Tests\DatabaseTestCase;

/** Suivi du stock d'une cuve : jaugeage, stock théorique, écart en litres et en valeur, alertes. */
final class StockControlTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $attendant;
    private string $product;
    private string $tank;
    private string $nozzle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station]);
        $this->attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $this->product = $this->createProduct($this->station, 'ESS', 'Essence');
        $this->tank = $this->createTank($this->station, $this->product, 'C-ESS', '30000.00');
        $this->nozzle = $this->createNozzle($this->station, $this->tank, 'P-ESS');
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->product, 3450, self::DAY . ' 05:00:00', $this->owner);
        // Barème : 0 cm → 0 L, 100 cm → 10 000 L, 200 cm → 24 000 L, 250 cm → 30 000 L.
        foreach ([['0.0', '0.00'], ['100.0', '10000.00'], ['200.0', '24000.00'], ['250.0', '30000.00']] as [$depth, $volume]) {
            $this->db->execute(
                'INSERT INTO tank_calibrations (id, station_id, tank_id, depth_cm, volume_l) VALUES (:id, :s, :t, :d, :v)',
                ['id' => Uuid::v7(), 's' => $this->station, 't' => $this->tank, 'd' => $depth, 'v' => $volume]
            );
        }
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    private function stock(?string $userId = null): StockControl
    {
        return $this->service($userId ?? $this->manager, StockControl::class);
    }

    private function dip(string $depth, string $time, array $extra = []): array
    {
        return $this->stock()->recordDip($this->tank, ['depth_cm' => $depth, 'dip_type' => 'control'] + $extra, self::at($time));
    }

    private function deliver(string $volume, string $time): void
    {
        $this->db->execute(
            "INSERT INTO deliveries (id, station_id, tank_id, supplier_name, invoiced_volume_l, received_volume_l, delivered_at, received_by, created_by)
             VALUES (:id, :s, :t, 'SEP Congo', :v, :v2, :at, :u, :u2)",
            ['id' => Uuid::v7(), 's' => $this->station, 't' => $this->tank, 'v' => $volume, 'v2' => $volume, 'at' => self::DAY . ' ' . $time, 'u' => $this->manager, 'u2' => $this->manager]
        );
    }

    /** Poste complet : ouvert à $open, $litres débités, $outputs sorties non vendues, clôturé à $close. */
    private function sell(string $from, string $to, string $open, string $close, array $outputs = []): void
    {
        $shifts = $this->service($this->manager, ShiftService::class);
        $shift = $shifts->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$this->nozzle => $from]], self::at($open));
        foreach ($outputs as [$type, $volume]) {
            $this->service($this->attendant, ShiftService::class)->addOutput($shift, ['nozzle_id' => $this->nozzle, 'output_type' => $type, 'volume_l' => $volume, 'reason' => 'Test'], self::at($open));
        }
        $this->service($this->attendant, ShiftService::class)->close($shift, ['final' => [$this->nozzle => $to]], self::at($close));
    }

    // --- Jaugeage : cm → litres -------------------------------------------------------------

    public function testDipIsConvertedByLinearInterpolationOnTheChart(): void
    {
        $first = $this->dip('150', '06:00:00', ['water_cm' => '0,5', 'note' => 'Mise en service']);

        self::assertSame('17000.00', $first['volume_l'], 'Entre 100 cm (10 000 L) et 200 cm (24 000 L)');
        self::assertSame([null, null, null], [$first['theoretical_l'], $first['variance_l'], $first['variance_amount']], 'Premier jaugeage : stock initial, sans écart');
        $row = $this->row('SELECT * FROM tank_dips WHERE id = :id', ['id' => $first['id']]);
        self::assertSame(['150.0', '17000.00', '0.5', 'control', $this->station, $this->manager, self::DAY . ' 06:00:00'], [
            $row['depth_cm'], $row['volume_l'], $row['water_cm'], $row['dip_type'], $row['station_id'], $row['created_by'], $row['measured_at'],
        ]);
        self::assertSame('2500.00', $this->dip('25', '06:10:00')['volume_l']);
        self::assertSame('27000.00', $this->dip('225,0', '06:20:00')['volume_l']);
    }

    public function testDipOutsideTheChartOrWithoutChartIsRefused(): void
    {
        foreach (['250.1' => 'hors du barème', '-5' => 'supérieur ou égal', 'haut' => 'nombre', '12.55' => 'décimales'] as $depth => $message) {
            try {
                $this->dip((string) $depth, '06:00:00');
                self::fail("Hauteur acceptée : $depth");
            } catch (ValidationException $e) {
                self::assertStringContainsString($message, $e->errors['depth_cm'], (string) $depth);
            }
        }
        $empty = $this->createTank($this->station, $this->product, 'C-VIDE');
        try {
            $this->stock()->recordDip($empty, ['depth_cm' => '10', 'dip_type' => 'control'], self::at('06:00:00'));
            self::fail('Jaugeage sans barème accepté');
        } catch (ValidationException $e) {
            self::assertStringContainsString('pas de barème', $e->errors['depth_cm']);
        }
        try {
            $this->stock()->recordDip($this->tank, ['depth_cm' => '10', 'dip_type' => 'au jugé'], self::at('06:00:00'));
            self::fail('Type inconnu accepté');
        } catch (ValidationException $e) {
            self::assertSame(['dip_type'], array_keys($e->errors));
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
    }

    // --- Stock théorique et écart ------------------------------------------------------------

    /** Théorique = initial + livraisons − litres vendus − sorties non vendues. */
    public function testTheoreticalStockAndVarianceInLitresAndValue(): void
    {
        $this->dip('100', '06:00:00');                                  // stock initial : 10 000 L
        $this->deliver('8000', '07:00:00');                             // + 8 000 L
        $this->sell('1000', '4540', '08:00:00', '12:00:00', [['test', '30'], ['internal_use', '10']]);  // 3 540 L débités dont 40 non vendus

        $position = $this->stock()->position($this->tank, self::DAY . ' 12:30:00');
        self::assertSame(
            ['10000.00', '8000.00', '3500.00', '40.00', '14460.00'],
            [$position['initial_l'], $position['deliveries_l'], $position['sold_l'], $position['non_sale_l'], $position['theoretical_l']],
            '10 000 + 8 000 − 3 500 − 40'
        );

        // Jaugé 14 400 L (131,4… cm) : on jauge à 131,4 cm → 10 000 + 14 000 × 31,4 / 100 = 14 396 L.
        $dip = $this->dip('131,4', '12:30:00');

        self::assertSame(['14396.00', '14460.00', '-64.00'], [$dip['volume_l'], $dip['theoretical_l'], $dip['variance_l']]);
        self::assertSame(-220800, $dip['variance_amount'], '64 L manquants × 3 450 FC');
        $row = $this->row('SELECT theoretical_l, variance_l, variance_amount, currency FROM tank_dips WHERE id = :id', ['id' => $dip['id']]);
        self::assertSame(['14460.00', '-64.00', -220800, 'CDF'], [$row['theoretical_l'], $row['variance_l'], (int) $row['variance_amount'], $row['currency']], 'Écart figé sur le jaugeage');

        // Le jaugeage devient le nouveau stock initial : le théorique repart du physique.
        $after = $this->stock()->position($this->tank, self::DAY . ' 13:00:00');
        self::assertSame(['14396.00', '0.00', '0.00', '14396.00'], [$after['initial_l'], $after['deliveries_l'], $after['sold_l'], $after['theoretical_l']]);
    }

    public function testFuelReturnedToTheTankComesBackIntoTheStock(): void
    {
        $this->dip('100', '06:00:00');
        $this->sell('0', '500', '08:00:00', '12:00:00', [['test', '20'], ['tank_return', '15']]);

        $position = $this->stock()->position($this->tank, self::DAY . ' 12:30:00');

        self::assertSame(['465.00', '35.00', '15.00'], [$position['sold_l'], $position['non_sale_l'], $position['tank_return_l']]);
        self::assertSame('9515.00', $position['theoretical_l'], '10 000 − 500 débités + 15 reversés');
    }

    public function testSalesOfAnOpenShiftAreCountedOnlyOnceRead(): void
    {
        $this->dip('100', '06:00:00');
        $shift = $this->service($this->manager, ShiftService::class)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$this->nozzle => '0']], self::at('08:00:00'));

        $open = $this->stock()->position($this->tank, self::DAY . ' 10:00:00');
        self::assertSame(['10000.00', true], [$open['theoretical_l'], $open['unread_sales']]);
        self::assertSame($shift, $this->value('SELECT shift_id FROM tank_dips WHERE id = :id', ['id' => $this->dip('100', '10:00:00', ['dip_type' => 'control'])['id']]), 'Jaugeage rattaché au poste en cours');

        $this->service($this->attendant, ShiftService::class)->close($shift, ['final' => [$this->nozzle => '1200']], self::at('12:00:00'));
        $closed = $this->stock()->position($this->tank, self::DAY . ' 12:30:00');
        self::assertSame(['8800.00', false], [$closed['theoretical_l'], $closed['unread_sales']]);
    }

    public function testValueUsesThePriceInForceAtTheTimeOfTheDip(): void
    {
        $this->dip('100', '06:00:00');
        $this->createPrice($this->station, $this->product, 3500, self::DAY . ' 09:00:00', $this->owner);

        self::assertSame(-345000, $this->dip('99', '08:00:00')['variance_amount'], '100 L manquants au prix de 8h : 3 450 FC');
        self::assertSame(-350000, $this->dip('98', '10:00:00')['variance_amount'], '100 L manquants au prix de 10h : 3 500 FC');

        // Prix en dollars : converti en CDF au taux en vigueur.
        $this->createPrice($this->station, $this->product, 150, self::DAY . ' 11:00:00', $this->owner, 'USD');
        self::assertSame(-345000, $this->dip('97', '12:00:00')['variance_amount'], '100 L × 1,50 $ × 2 300');
    }

    // --- Alertes ---------------------------------------------------------------------------------

    public function testVarianceBeyondToleranceRaisesAnAlert(): void
    {
        $this->dip('100', '06:00:00');

        // 50 L manquants : égal à la tolérance par défaut (50 L), accepté.
        self::assertSame([], $this->dip('99,5', '07:00:00')['alerts']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'stock_variance'"));

        // 60 L manquants : hors tolérance.
        $dip = $this->dip('98,9', '08:00:00');
        self::assertSame(['-60.00', ['stock_variance']], [$dip['variance_l'], $dip['alerts']]);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'stock_variance'");
        self::assertSame(
            [$this->station, 'tank', $this->tank, '-60.00', -207000, 'CDF', '50.00', 'critical', $this->manager],
            [$alert['station_id'], $alert['entity_type'], $alert['entity_id'], $alert['volume_l'], (int) $alert['amount'], $alert['currency'], $alert['threshold_value'], $alert['severity'], $alert['user_id']]
        );
        self::assertStringContainsString('il manque 60,00 L', $alert['message']);

        // Excédent hors tolérance : avertissement.
        $dip = $this->dip('100', '09:00:00');
        self::assertSame('110.00', $dip['variance_l']);
        self::assertSame('warning', $this->value("SELECT severity FROM alerts WHERE alert_type = 'stock_variance' ORDER BY created_at DESC, id DESC LIMIT 1"));
        self::assertSame(['stock_variance'], json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'tank_dip' AND entity_id = :id", ['id' => $dip['id']]), true)['alerts']);
    }

    public function testToleranceGrowsWithTheVolumeSoldAndIsSetPerStation(): void
    {
        $this->dip('200', '06:00:00');                                  // 24 000 L
        $this->sell('0', '20000', '07:00:00', '12:00:00');              // 20 000 L débités : 0,5 % = 100 L

        // Théorique 4 000 L ; jaugé 39,1 cm → 3 910 L : 90 L manquants, sous les 100 L tolérés.
        $dip = $this->dip('39,1', '12:30:00');
        self::assertSame(['-90.00', []], [$dip['variance_l'], $dip['alerts']]);
        self::assertSame('100.00', json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_id = :id", ['id' => $dip['id']]), true)['tolerance_l']);

        // Station plus stricte : 0 % et 20 L.
        foreach (['stock_tolerance_pct' => '0', 'stock_tolerance_l' => '20'] as $key => $value) {
            $this->db->execute('INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (UUID(), :s, :k, :v)', ['s' => $this->station, 'k' => $key, 'v' => $value]);
        }
        self::assertSame(['stock_variance'], $this->dip('38,8', '13:00:00')['alerts'], '30 L manquants pour 20 L tolérés');
    }

    public function testLowStockRaisesOneAlertPerTankUntilItIsHandled(): void
    {
        $this->dip('100', '06:00:00');
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'low_stock'"));

        // Seuil par défaut : 3 000 L. 29,9 cm → 2 990 L.
        $this->sell('0', '7010', '07:00:00', '12:00:00');
        $dip = $this->dip('29,9', '12:30:00');
        self::assertSame(['2990.00', ['low_stock']], [$dip['volume_l'], $dip['alerts']]);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'low_stock'");
        self::assertSame([$this->tank, '2990.00', '3000.00', 'warning', 'open'], [$alert['entity_id'], $alert['volume_l'], $alert['threshold_value'], $alert['severity'], $alert['status']]);
        self::assertStringContainsString('C-ESS', $alert['message']);

        // Toujours bas au jaugeage suivant : pas de seconde alerte tant que la première est ouverte.
        self::assertSame([], $this->dip('29,9', '13:00:00')['alerts']);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'low_stock'"));

        // 30 cm → 3 000 L : au seuil exact, le stock n'est plus bas.
        $this->db->execute("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = :u WHERE alert_type = 'low_stock'", ['u' => $this->manager]);
        self::assertNotContains('low_stock', $this->dip('30', '14:00:00')['alerts']);
        self::assertContains('low_stock', $this->dip('29', '15:00:00')['alerts'], 'Une fois traitée, une nouvelle alerte peut être levée');
    }

    // --- Ajout seul, écran, cloisonnement ---------------------------------------------------------

    public function testDipsAreAppendOnlyAndInChronologicalOrder(): void
    {
        $dip = $this->dip('100', '08:00:00');

        foreach (['07:00:00', '08:00:00'] as $time) {
            try {
                $this->dip('90', $time);
                self::fail('Jaugeage antidaté accepté');
            } catch (ValidationException $e) {
                self::assertSame(['depth_cm'], array_keys($e->errors));
            }
        }
        foreach (["UPDATE tank_dips SET volume_l = 0 WHERE id = '{$dip['id']}'", 'DELETE FROM tank_dips'] as $sql) {
            try {
                $this->db->execute($sql);
                self::fail("Accepté : $sql");
            } catch (PDOException $e) {
                self::assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
    }

    public function testStockScreenAndHistory(): void
    {
        $this->dip('100', '06:00:00');
        $this->deliver('5000', '07:00:00');
        $session = $this->sessionFor($this->manager);

        $page = $this->http($session, 'GET', '/stock');
        self::assertSame(200, $page->status);
        // La page : une carte par cuve, avec l'essentiel ; le reste s'ouvre en fenêtres.
        foreach (['C-ESS', "15\u{202F}000 L", '>50 %</text>', 'class="fuel-tank"', 'class="shift-card stock-card"', 'data-live', 'js/pt-live.js', 'Historique général', 'Plus de détails', 'Jauger'] as $text) {
            self::assertStringContainsString($text, $page->body, $text);
        }
        self::assertMatchesRegularExpression('#data-drill="[^"]*stock/dips/data#', $page->body, 'Le tableau complet s\'ouvre en fenêtre');
        self::assertMatchesRegularExpression('#data-stock="total">15\x{202F}000 L<#u', $page->body);
        self::assertStringNotContainsString('<script>', $page->body, 'Aucun calcul dans la page');

        // Fenêtre « Détail du stock » : d'où vient le stock théorique.
        $detail = $this->http($session, 'GET', "/stock/tanks/{$this->tank}/detail");
        self::assertSame(200, $detail->status);
        self::assertStringNotContainsString('<html', $detail->body, 'Fragment, sans gabarit');
        self::assertStringNotContainsString('class="stock-lines"', $page->body, 'Le détail du calcul n\'encombre plus la carte');
        foreach (['Stock théorique', 'Livraisons', "+ 5\u{202F}000,00", "15\u{202F}000,00 L", '(50 %)', 'Seuil de stock bas', 'Historique de la cuve', "/stock/tanks/{$this->tank}/history"] as $text) {
            self::assertStringContainsString($text, $detail->body, $text);
        }
        // Fenêtre de jaugeage : gros champ, schéma, repères pour l'étape de vérification.
        $form = $this->http($session, 'GET', "/stock/tanks/{$this->tank}/form");
        foreach (['data-ajax-form', "action=\"/stock/tanks/{$this->tank}/dips\"", 'input--xl', 'name="depth_cm"', 'name="_csrf"', 'data-recap=', 'dip-guide.svg'] as $text) {
            self::assertStringContainsString($text, $form->body, $text);
        }
        // Fenêtre « Historique de la cuve ».
        $history = $this->http($session, 'GET', "/stock/tanks/{$this->tank}/history");
        self::assertSame(200, $history->status);
        self::assertStringContainsString('Jaugeages de la cuve C-ESS', $history->body);
        self::assertStringContainsString("10\u{202F}000,00 L", $history->body);

        // Envoi sans rechargement : une hauteur hors barème revient en erreur JSON, sans rien enregistrer.
        $json = ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'];
        $before = (int) $this->value('SELECT COUNT(*) FROM tank_dips');
        $refused = $this->http($session, 'POST', "/stock/tanks/{$this->tank}/dips", ['depth_cm' => '999', 'dip_type' => 'control'], $json);
        self::assertSame(422, $refused->status);
        self::assertArrayHasKey('depth_cm', json_decode($refused->body, true)['errors']);
        self::assertSame($before, (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
        // Une cuve d'une autre station : ni détail, ni historique, ni jaugeage.
        $other = $this->createTank($this->createStation($this->org, 'Z'), $this->createProduct($this->createStation($this->org, 'Y')));
        foreach (['detail', 'history', 'form'] as $fragment) {
            self::assertSame(403, $this->http($session, 'GET', "/stock/tanks/$other/$fragment")->status, $fragment);
        }

        $post = $this->http($session, 'POST', "/stock/tanks/{$this->tank}/dips", ['depth_cm' => '120', 'dip_type' => 'after_delivery'], ['referer' => 'http://localhost/stock']);
        self::assertSame('/stock', $post->headers['Location']);
        $row = $this->row('SELECT volume_l, variance_l FROM tank_dips ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertSame('12800.00', $row['volume_l']);
        $bad = $this->http($session, 'POST', "/stock/tanks/{$this->tank}/dips", ['depth_cm' => '999', 'dip_type' => 'control'], ['referer' => 'http://localhost/stock']);
        self::assertSame('/stock', $bad->headers['Location']);
        self::assertStringContainsString('hors du barème', $this->http($session, 'GET', '/stock')->body);

        $data = json_decode($this->http($session, 'GET', '/stock/dips/data?draw=4', [], self::AJAX)->body, true);
        self::assertSame([4, 2], [$data['draw'], $data['recordsTotal']]);
        self::assertSame(['C-ESS', 'after_delivery', '120.0', '15000.00'], [$data['data'][0]['tank'], $data['data'][0]['dip_type'], $data['data'][0]['depth_cm'], $data['data'][0]['theoretical_l']]);

        // Le pompiste n'a ni l'écran ni la saisie.
        $attendant = $this->sessionFor($this->attendant);
        self::assertSame(403, $this->http($attendant, 'GET', '/stock')->status);
        self::assertSame(403, $this->http($attendant, 'POST', "/stock/tanks/{$this->tank}/dips", ['depth_cm' => '1', 'dip_type' => 'control'])->status);
        self::assertStringNotContainsString('href="/stock"', $this->http($attendant, 'GET', '/')->body);
    }

    public function testAManagerOfAnotherStationCannotReadOrGaugeThisTank(): void
    {
        $this->dip('100', '06:00:00');
        $other = $this->createStation($this->org, 'B');
        $managerB = $this->createUser($this->org, 'manager', [$other]);

        foreach ([
            fn () => $this->stock($managerB)->recordDip($this->tank, ['depth_cm' => '1', 'dip_type' => 'control'], self::at('07:00:00')),
            fn () => $this->stock($managerB)->position($this->tank, self::DAY . ' 07:00:00'),
        ] as $call) {
            try {
                $call();
                self::fail('Cuve d\'une autre station accessible');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame([], $this->stock($managerB)->overview());
        $session = $this->sessionFor($managerB);
        self::assertSame(403, $this->http($session, 'POST', "/stock/tanks/{$this->tank}/dips", ['depth_cm' => '1', 'dip_type' => 'control'])->status);
        self::assertSame(0, json_decode($this->http($session, 'GET', '/stock/dips/data', [], self::AJAX)->body, true)['recordsTotal']);
        self::assertStringNotContainsString('C-ESS', $this->http($session, 'GET', '/stock')->body);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
    }

    public function testStockPageCanBeFilteredByStation(): void
    {
        // Une seule station : pas de filtre à afficher.
        $this->dip('100', '06:00:00');
        self::assertStringNotContainsString('station-filter', $this->http($this->sessionFor($this->manager), 'GET', '/stock')->body);

        // Le propriétaire a deux stations, chacune avec une cuve.
        $second = $this->createStation($this->org, 'B');
        $this->createTank($second, $this->createProduct($second, 'MAZ', 'Mazout'), 'C-MAZ', '20000.00');
        $owner = $this->sessionFor($this->createUser($this->org, 'owner', [], 'proprio.stock'));

        $all = $this->http($owner, 'GET', '/stock')->body;
        self::assertSame(2, substr_count($all, 'class="shift-card stock-card'));
        self::assertStringContainsString('class="station-filter"', $all);
        self::assertStringContainsString('href="/stock?station=' . $second . '"', $all);
        self::assertMatchesRegularExpression('#class="chip is-active" href="/stock" data-day-link aria-current="true">\s*Toutes les stations<span class="chip__count">2<#u', $all);

        // Filtré sur la seconde station : sa cuve seule, et les chiffres de tête recalculés.
        $filtered = $this->http($owner, 'GET', '/stock?station=' . $second)->body;
        self::assertSame(1, substr_count($filtered, 'class="shift-card stock-card'));
        self::assertStringContainsString('data-tank="C-MAZ"', $filtered);
        self::assertStringNotContainsString('data-tank="C-ESS"', $filtered);
        self::assertMatchesRegularExpression('#class="chip is-active" href="/stock\?station=' . $second . '"#', $filtered);
        self::assertMatchesRegularExpression('#data-stock="total">0 L<#u', $filtered, 'Cuve jamais jaugée : pas de stock connu');
        self::assertMatchesRegularExpression('#data-stock="total">10\x{202F}000 L<#u', $this->http($owner, 'GET', '/stock?station=' . $this->station)->body);

        // Une station hors de son périmètre est refusée ; une valeur illisible est ignorée.
        self::assertSame(403, $this->http($this->sessionFor($this->manager), 'GET', '/stock?station=' . $second)->status);
        self::assertSame(2, substr_count($this->http($owner, 'GET', '/stock?station=')->body, 'class="shift-card stock-card'));
    }
}
