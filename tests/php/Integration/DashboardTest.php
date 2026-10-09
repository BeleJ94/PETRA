<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Petra\Core\HttpException;
use Petra\Core\Uuid;
use Petra\Services\DashboardService;
use Petra\Services\ShiftService;

/**
 * Tableaux de bord : les agrégations SQL doivent donner exactement les chiffres des postes
 * (calculés par ShiftCalculator à la clôture), par station et en consolidé, sans fuite entre stations.
 * Jeu d'essai : voir TwoStationsTestCase.
 */
final class DashboardTest extends TwoStationsTestCase
{
    private function dashboard(string $user, array $query, string $day = '06'): array
    {
        return $this->service($user, DashboardService::class)->build($query, self::at($day, '18:00'));
    }

    public function testStationDayMatchesTheShiftFigures(): void
    {
        $d = $this->dashboard($this->managerA, ['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-05']);

        self::assertFalse($d['consolidated']);
        self::assertSame('Station A', $d['stationName']);
        $t = $d['totals'];
        self::assertSame([730000, 14000], [$t['revenue_cdf'], $t['sold_cl']], 'CA et litres (centilitres)');
        self::assertSame([485000, 10000, 230000, 715000], [$t['receipts_cdf'], $t['receipts_usd'], $t['receipts_usd_cdf'], $t['receipts_total_cdf']], 'Recettes CDF, USD (cents), contre-valeur, total');
        self::assertSame([-15000, -15000, 0], [$t['cash_variance'], $t['shortage'], $t['surplus']]);
        self::assertSame([0, 0, 1], [$t['shifts_open'], $t['shifts_closed'], $t['shifts_validated']]);
        // L'agrégation SQL retrouve, à l'unité près, l'attendu figé par ShiftCalculator à la clôture.
        self::assertSame((int) $this->value('SELECT expected_amount FROM shifts WHERE id = :id', ['id' => $this->shift1]), $t['revenue_cdf']);

        self::assertSame([
            ['product' => 'Essence', 'sold_l' => '100.00', 'amount_cdf' => 500000],
            ['product' => 'Gaz', 'sold_l' => '40.00', 'amount_cdf' => 230000],
        ], $d['products']);
        self::assertSame(['rate' => 2300, 'effective_at' => '2026-10-05 05:00:00'], $d['rate']);

        // Stock : dernier jaugeage de chaque cuve ; écart du jaugeage de contrôle (mesuré 9 800 L, théorique 10 000 − 105).
        $tanks = array_column($d['tanks'], null, 'code');
        self::assertSame(['9800.00', 326, false], [$tanks['C-ESS']['volume_l'], $tanks['C-ESS']['fill_permille'], $tanks['C-ESS']['is_low']]);
        self::assertSame([null, null], [$tanks['C-GAZ']['volume_l'], $tanks['C-GAZ']['fill_permille']], 'Cuve jamais jaugée');
        self::assertSame(-9500, $t['stock_variance_cl']);
        self::assertSame((int) $this->value('SELECT SUM(variance_amount) FROM tank_dips WHERE station_id = :s', ['s' => $this->a]), $t['stock_variance_cdf']);
        self::assertLessThan(0, $t['stock_variance_cdf']);

        // Alertes ouvertes de la station, les plus graves d'abord.
        self::assertSame((int) $this->value("SELECT COUNT(*) FROM alerts WHERE station_id = :s AND status = 'open'", ['s' => $this->a]), $t['alerts_open']);
        self::assertGreaterThan(0, $t['alerts_open']);
        self::assertNotSame('info', $d['alerts'][0]['severity']);
        self::assertSame([$this->a], array_values(array_unique(array_column($d['alerts'], 'station_id'))));
    }

    public function testPeriodFiltersAndDailySeries(): void
    {
        // Jour : le 06/10 seulement.
        $day = $this->dashboard($this->managerA, ['period' => 'day']);
        self::assertSame(['day', '2026-10-06', '2026-10-06'], [$day['period']->type, $day['period']->from, $day['period']->to]);
        self::assertSame([250000, 5000, 2000], [$day['totals']['revenue_cdf'], $day['totals']['sold_cl'], $day['totals']['cash_variance']]);
        self::assertSame(252000, $day['totals']['receipts_cdf']);

        // Semaine du lundi 05/10 au dimanche 11/10 : les deux postes.
        $week = $this->dashboard($this->managerA, ['period' => 'week']);
        self::assertSame(['2026-10-05', '2026-10-11'], [$week['period']->from, $week['period']->to]);
        self::assertSame([980000, 19000, -13000, -15000, 2000], [$week['totals']['revenue_cdf'], $week['totals']['sold_cl'], $week['totals']['cash_variance'], $week['totals']['shortage'], $week['totals']['surplus']]);
        self::assertSame([730000, 250000, 0, 0, 0, 0, 0], $week['charts']['days']['amounts']);
        self::assertSame('2026-10-05', $week['charts']['days']['labels'][0]);
        self::assertSame([['Essence', '150.00', 750000], ['Gaz', '40.00', 230000]], array_map('array_values', $week['products']));

        // Mois : identique ici ; période personnalisée hors activité : tout à zéro, sans erreur.
        self::assertSame(980000, $this->dashboard($this->managerA, ['period' => 'month'])['totals']['revenue_cdf']);
        $empty = $this->dashboard($this->managerA, ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        self::assertSame([0, 0, 0, 0], [$empty['totals']['revenue_cdf'], $empty['totals']['sold_cl'], $empty['totals']['receipts_total_cdf'], $empty['totals']['cash_variance']]);
        self::assertSame([], $empty['products']);
        self::assertSame([], $empty['attendantRanking']);
        self::assertCount(30, $empty['days']);
    }

    public function testAttendantRankingPutsTheBiggestShortageFirst(): void
    {
        $ranking = $this->dashboard($this->managerA, ['period' => 'week'])['attendantRanking'];

        self::assertSame([$this->attendant1, $this->attendant2], array_column($ranking, 'attendant_id'));
        self::assertSame([1, 730000, -15000, -15000, 0], [$ranking[0]['shifts'], $ranking[0]['expected'], $ranking[0]['variance'], $ranking[0]['shortage'], $ranking[0]['surplus']]);
        self::assertSame([1, 250000, 2000, 0, 2000], [$ranking[1]['shifts'], $ranking[1]['expected'], $ranking[1]['variance'], $ranking[1]['shortage'], $ranking[1]['surplus']]);
        self::assertSame('Station A', $ranking[0]['station']);
    }

    public function testOwnerSeesTheConsolidatedViewWithComparisonAndRankings(): void
    {
        $this->db->queueing(false);
        $this->db->execute("UPDATE stations SET last_sync_at = '2026-10-06 17:45:00' WHERE id = :id", ['id' => $this->b]);
        $this->db->queueing(true);

        $d = $this->dashboard($this->owner, ['period' => 'week']);

        self::assertTrue($d['consolidated']);
        self::assertSame('all', $d['selected']);
        self::assertSame([1780000, 39000, 1537000, 10000, -13000], [$d['totals']['revenue_cdf'], $d['totals']['sold_cl'], $d['totals']['receipts_cdf'], $d['totals']['receipts_usd'], $d['totals']['cash_variance']]);
        self::assertSame(3, $d['totals']['shifts_validated']);

        // Comparatif : une ligne par station, la meilleure recette en tête ; les totaux sont la somme des lignes.
        $rows = array_column($d['comparison'], null, 'station');
        self::assertSame(['Station A', 'Station B'], array_column($d['comparison'], 'station'));
        self::assertSame([980000, 19000, 737000, 10000, -13000, 2300, null], [
            $rows['Station A']['revenue_cdf'], $rows['Station A']['sold_cl'], $rows['Station A']['receipts_cdf'], $rows['Station A']['receipts_usd'],
            $rows['Station A']['cash_variance'], $rows['Station A']['rate'], $rows['Station A']['last_sync_at'],
        ]);
        self::assertSame([800000, 20000, 800000, 0, 0, 2350, '2026-10-06 17:45:00'], [
            $rows['Station B']['revenue_cdf'], $rows['Station B']['sold_cl'], $rows['Station B']['receipts_cdf'], $rows['Station B']['receipts_usd'],
            $rows['Station B']['cash_variance'], $rows['Station B']['rate'], $rows['Station B']['last_sync_at'],
        ]);
        self::assertNull($d['rate'], 'Pas de taux unique en consolidé : il figure par station');

        // Classement des écarts par station (caisse + stock valorisé) : la station A, déficitaire, en tête.
        self::assertSame(['Station A', 'Station B'], array_column($d['stationRanking'], 'station'));
        self::assertSame($rows['Station A']['cash_variance'] + $rows['Station A']['stock_variance_cdf'], $d['stationRanking'][0]['variance_cdf']);
        self::assertSame(0, $d['stationRanking'][1]['variance_cdf']);
        // Par pompiste, toutes stations confondues.
        self::assertSame([-15000, 0, 2000], array_column($d['attendantRanking'], 'variance'));
        self::assertSame(['Station A', 'Station B', 'Station A'], array_column($d['attendantRanking'], 'station'));
        self::assertSame(['Gasoil', 'Essence', 'Gaz'], array_column($d['products'], 'product'));
        self::assertCount(3, $d['tanks']);

        // Filtre de station : le propriétaire isole une station.
        $onlyB = $this->dashboard($this->owner, ['period' => 'week', 'station' => $this->b]);
        self::assertFalse($onlyB['consolidated']);
        self::assertSame([$this->b, 800000, 2350], [$onlyB['selected'], $onlyB['totals']['revenue_cdf'], $onlyB['rate']['rate']]);
        self::assertSame(['Gasoil'], array_column($onlyB['products'], 'product'));
    }

    public function testAManagerNeverSeesAnotherStation(): void
    {
        // Par défaut comme avec « toutes les stations » : uniquement la sienne.
        foreach ([[], ['station' => 'all'], ['station' => $this->a]] as $filter) {
            $d = $this->dashboard($this->managerA, ['period' => 'week'] + $filter);
            self::assertSame(980000, $d['totals']['revenue_cdf']);
            self::assertSame(['Station A'], array_column($d['comparison'], 'station'));
            self::assertNotContains('Gasoil', array_column($d['products'], 'product'));
            self::assertSame(['Station A'], array_values(array_unique(array_column($d['tanks'], 'station'))));
        }

        // Demander la station B est refusé, par le service comme par la page.
        try {
            $this->dashboard($this->managerA, ['period' => 'week', 'station' => $this->b]);
            self::fail('Station B lue par le gérant de A');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $session = $this->sessionFor($this->managerA);
        self::assertSame(403, $this->http($session, 'GET', '/?period=week&station=' . $this->b)->status);
        self::assertSame(403, $this->http($session, 'GET', '/?station=' . Uuid::v7())->status);
    }

    public function testCorrectedAndUnreadLinesAreNotCounted(): void
    {
        // Correction d'un relevé final (ajout seul) : 105 → 104, soit 99 L vendus. L'ancienne ligne ne compte plus.
        $reading = $this->row("SELECT * FROM meter_readings WHERE shift_id = :s AND nozzle_id = :n AND reading_type = 'closing'", ['s' => $this->shift1, 'n' => $this->nozzleEss]);
        $this->db->execute(
            "INSERT INTO meter_readings (id, station_id, shift_id, segment_id, nozzle_id, reading_type, index_value, price_id, read_at, created_by, corrects_id, correction_reason, approved_by, approved_at)
             VALUES (:id, :s, :sh, :seg, :n, 'closing', '104.00', :p, :at, :u, :c, 'Erreur de lecture', :u2, :at2)",
            ['id' => Uuid::v7(), 's' => $this->a, 'sh' => $this->shift1, 'seg' => $reading['segment_id'], 'n' => $this->nozzleEss, 'p' => $reading['price_id'],
             'at' => $reading['read_at'], 'u' => $this->managerA, 'c' => $reading['id'], 'u2' => $this->owner, 'at2' => '2026-10-05 14:00:00']
        );
        // Remise corrigée : 485 000 → 480 000 FC.
        $handover = $this->row("SELECT * FROM cash_handovers WHERE shift_id = :s AND currency = 'CDF'", ['s' => $this->shift1]);
        $this->db->execute(
            "INSERT INTO cash_handovers (id, station_id, shift_id, segment_id, amount, currency, amount_cdf, received_by, handed_at, created_by, corrects_id, correction_reason, approved_by, approved_at)
             VALUES (:id, :s, :sh, :seg, 480000, 'CDF', 480000, :r, :at, :u, :c, 'Recomptage', :u2, :at2)",
            ['id' => Uuid::v7(), 's' => $this->a, 'sh' => $this->shift1, 'seg' => $handover['segment_id'], 'r' => $this->managerA, 'at' => $handover['handed_at'],
             'u' => $this->managerA, 'c' => $handover['id'], 'u2' => $this->owner, 'at2' => '2026-10-05 14:00:00']
        );
        // Poste ouvert le 06/10 après-midi, sans relevé final : rien à compter tant que la tranche n'est pas relevée.
        $this->service($this->managerA, ShiftService::class)->open($this->a, ['attendant_id' => $this->attendant1], self::at('06', '14:00'));

        $d = $this->dashboard($this->managerA, ['period' => 'week']);

        self::assertSame(495000 + 230000 + 250000, $d['totals']['revenue_cdf']);
        self::assertSame(9900 + 4000 + 5000, $d['totals']['sold_cl']);
        self::assertSame(480000 + 252000, $d['totals']['receipts_cdf']);
        self::assertSame([1, 2], [$d['totals']['shifts_open'], $d['totals']['shifts_validated']]);
    }

    public function testDeliveriesAndLowStockThreshold(): void
    {
        $insert = function (string $id, string $received, string $variance, string $at, ?string $corrects = null): void {
            $this->db->execute(
                'INSERT INTO deliveries (id, station_id, tank_id, supplier_name, invoiced_volume_l, received_volume_l, announced_variance_l, delivered_at, received_by, created_by, corrects_id, correction_reason, approved_by, approved_at)
                 VALUES (:id, :s, :t, :sup, 5000, :r, :v, :at, :u, :u2, :c, :reason, :ap, :apat)',
                ['id' => $id, 's' => $this->a, 't' => $this->tankA, 'sup' => 'SEP Congo', 'r' => $received, 'v' => $variance, 'at' => $at, 'u' => $this->managerA, 'u2' => $this->managerA,
                 'c' => $corrects, 'reason' => $corrects ? 'Erreur de saisie' : null, 'ap' => $corrects ? $this->owner : null, 'apat' => $corrects ? $at : null]
            );
        };
        $first = Uuid::v7();
        $insert($first, '4900.00', '-100.00', '2026-10-06 09:00:00');
        $insert(Uuid::v7(), '4950.00', '-50.00', '2026-10-06 09:00:00', $first);
        $insert(Uuid::v7(), '5000.00', '0.00', '2026-10-04 09:00:00');
        // Seuil de stock bas propre à la station A : 10 000 L (la cuve essence est à 9 800 L).
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (:id, :s, 'low_stock_l', '10000.00')", ['id' => Uuid::v7(), 's' => $this->a]);

        $d = $this->dashboard($this->owner, ['period' => 'week']);

        self::assertSame([1, 495000, -5000], [$d['totals']['deliveries'], $d['totals']['received_cl'], $d['totals']['delivery_variance_cl']], 'La livraison corrigée compte une fois, celle du 04/10 est hors période');
        self::assertSame(['4950.00', '5000.00'], array_column($d['deliveries'], 'received_volume_l'), 'Derniers ravitaillements : la ligne corrigée est masquée');
        $tanks = array_column($d['tanks'], null, 'code');
        self::assertTrue($tanks['C-ESS']['is_low']);
        self::assertSame('10000.00', $tanks['C-ESS']['low_stock_l']);
        self::assertSame('3000.00', $tanks['C-DSL']['low_stock_l'], 'Seuil par défaut pour la station B');
    }

    public function testDashboardPages(): void
    {
        $range = 'period=custom&from=2026-10-05&to=2026-10-06';

        // Gérant : tableau de bord de sa station, sans comparatif.
        $page = $this->http($this->sessionFor($this->managerA), 'GET', "/?$range");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Tableau de bord — Station A', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="revenue">980\x{202F}000 FC<#u', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="receipts-usd">100,00 \$<#u', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="rate">1 \$ = 2\x{202F}300 FC<#u', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="cash-variance">-13\x{202F}000 FC<#u', $page->body);
        // L'essentiel sur la page : état, quatre chiffres, chaîne de contrôle, deux graphiques, cuves ; le reste en fenêtres.
        foreach (['class="dash-status', 'Chaîne de contrôle', 'Ventes par produit', 'Ventes et encaissements par jour', 'Stock par cuve', 'Derniers ravitaillements', 'Écarts par pompiste',
                  'chart-products', 'chart-days', 'class="fuel-tank"', 'class="sparkline"', 'data-live', '/dashboard/detail?kind=sales', '/dashboard/detail?kind=variance',
                  'vendor/chart.umd.min.js', 'js/pt-live.js', 'js/dashboard.js', 'id="dashboard-data"', 'Petra — développé par BELEJ-CONSULTING'] as $text) {
            self::assertStringContainsString($text, $page->body, $text);
        }
        self::assertSame(4, substr_count($page->body, '<button type="button" class="dash-kpi'), 'Quatre chiffres clés, pas plus');
        self::assertStringNotContainsString('<table', $page->body, 'Aucun tableau dans la page : ils s\'ouvrent en fenêtre');
        self::assertStringNotContainsString('Comparatif des stations', $page->body);
        self::assertStringNotContainsString('Station B', $page->body);
        self::assertStringNotContainsString('Gasoil', $page->body);
        self::assertStringNotContainsString('data-station-filter', $page->body, 'Une seule station : pas de filtre de station');

        // Propriétaire : vue consolidée.
        $owner = $this->sessionFor($this->owner);
        $page = $this->http($owner, 'GET', "/?$range&station=all");
        self::assertStringContainsString('Tableau de bord consolidé', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="revenue">1\x{202F}780\x{202F}000 FC<#u', $page->body);
        foreach (['Vos stations', 'Comparatif des stations', 'class="station-card', 'Meilleure recette', 'data-station-filter', 'Station A', 'Station B', 'Gasoil', '/dashboard/detail?kind=stations'] as $text) {
            self::assertStringContainsString($text, $page->body, $text);
        }
        self::assertSame(2, substr_count($page->body, '<a class="station-card'), 'Une carte par station');
        $data = json_decode(html_entity_decode((string) preg_replace('#^.*<script type="application/json" id="dashboard-data">(.*?)</script>.*$#s', '$1', $page->body)), true);
        self::assertSame(['Station A', 'Station B'], $data['stations']['labels']);
        self::assertSame([980000, 800000], $data['stations']['revenue']);
        self::assertSame("Chiffre d'affaires", $data['labels']['revenue']);

        // Sans filtre : la journée en cours, sans erreur.
        self::assertSame(200, $this->http($owner, 'GET', '/')->status);
        self::assertSame(200, $this->http($owner, 'GET', '/?period=nimporte&from=x&to=2026-13-45')->status);

        // Pompiste : pas de droit dashboard.view, donc ni recettes ni écarts.
        $page = $this->http($this->sessionFor($this->attendant1), 'GET', "/?$range");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString("Chiffre d'affaires", $page->body);
        self::assertStringNotContainsString('dashboard-data', $page->body);
        self::assertStringContainsString('Voir mes postes', $page->body);
    }

    public function testInsightComparesWithThePreviousPeriodAndListsWhatToDo(): void
    {
        // Le 06/10 comparé au 05/10 : 250 000 FC contre 730 000 FC, 50 L contre 140 L.
        $d = $this->dashboard($this->managerA, ['period' => 'day']);
        self::assertSame(['2026-10-05', '2026-10-05', 730000, 14000, 715000, -15000], [$d['previous']['from'], $d['previous']['to'], $d['previous']['revenue_cdf'], $d['previous']['sold_cl'], $d['previous']['receipts_total_cdf'], $d['previous']['cash_variance']]);
        self::assertSame(['revenue' => -65, 'sold' => -64, 'received' => -64, 'variance' => 17000], $d['deltas']);
        // Tendance : les sept jours qui finissent le 06/10.
        self::assertSame([0, 0, 0, 0, 0, 730000, 250000], $d['trend']['revenue']);
        self::assertSame([0, 0, 0, 0, 0, 14000, 5000], $d['trend']['sold']);
        self::assertSame([0, 0, 0, 0, 0, 715000, 252000], $d['trend']['received']);
        self::assertSame([0, 0, 0, 0, 0, -15000, 2000], $d['trend']['variance']);
        // Sans période précédente, pas de pourcentage inventé.
        self::assertNull($this->dashboard($this->managerA, ['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-05'])['deltas']['revenue']);

        // À faire : les alertes critiques d'abord (déficit de caisse, écart de stock), rien d'autre ici.
        self::assertSame(['alert'], array_column($d['todos'], 'kind'));
        self::assertSame(2, $d['todos'][0]['count']);
        self::assertSame('/alerts', $d['todos'][0]['url']);

        // Un poste clôturé qui attend, puis une cuve sous son seuil : deux cartes de plus, chacune avec sa destination.
        $shift = $this->service($this->managerA, ShiftService::class)->open($this->a, ['attendant_id' => $this->attendant1, 'label' => 'Après-midi'], self::at('06', '14:00'));
        $this->service($this->attendant1, ShiftService::class)->close($shift, ['final' => [$this->nozzleEss => '160', (string) $this->value("SELECT id FROM nozzles WHERE code = 'P-GAZ'") => '40']], self::at('06', '18:00'));
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (:id, :s, 'low_stock_l', '10000.00')", ['id' => Uuid::v7(), 's' => $this->a]);
        $d = $this->dashboard($this->managerA, ['period' => 'day']);
        self::assertSame(['alert', 'shift', 'stock'], array_column($d['todos'], 'kind'));
        self::assertSame([3, 1, '/shifts?date=2026-10-06', '/stock?station=' . $this->a], [$d['todoCount'], $d['todos'][1]['count'], $d['todos'][1]['url'], $d['todos'][2]['url']]);
        self::assertStringContainsString('Après-midi', $d['todos'][1]['text']);
        // Le gérant de B n'a rien de tout cela.
        $managerB = (string) $this->value("SELECT id FROM users WHERE username = 'gerant.b'");
        self::assertSame([], $this->dashboard($managerB, ['period' => 'day'])['todos']);

        // La page l'annonce.
        $page = $this->http($this->sessionFor($this->managerA), 'GET', '/?period=custom&from=2026-10-06&to=2026-10-06')->body;
        self::assertStringContainsString('data-status="attention"', $page);
        self::assertStringContainsString('3 point(s) demandent votre attention', $page);
        self::assertSame(3, substr_count($page, 'class="dash-todo '));
        self::assertStringContainsString('data-status="ok"', $this->http($this->sessionFor($managerB), 'GET', '/?period=custom&from=2026-09-01&to=2026-09-01')->body);
    }

    public function testEveryBlockOpensItsDetailAsACompactTable(): void
    {
        $session = $this->sessionFor($this->owner);
        $range = '&period=custom&from=2026-10-05&to=2026-10-06&station=all';
        $expected = [
            'sales' => ['Ventes de la période', ['05/10/2026', "1\u{202F}530\u{202F}000 FC", 'Gasoil', 'Essence']],
            'receipts' => ['Argent encaissé', ['05/10/2026', "1\u{202F}285\u{202F}000 FC", '100,00 $', "1\u{202F}515\u{202F}000 FC"]],
            'variance' => ['Écarts de caisse, poste par poste', ['Station A', 'Station B', "-15\u{202F}000 FC", "+2\u{202F}000 FC"]],
            'attendants' => ['Classement des écarts par pompiste', ['Station A', "-15\u{202F}000 FC"]],
            'stations' => ['Comparatif des stations', ['Station A', 'Station B', "980\u{202F}000 FC", "800\u{202F}000 FC", 'Total', "1\u{202F}780\u{202F}000 FC"]],
            'deliveries' => ['Derniers ravitaillements', ['Aucun ravitaillement sur la période.']],
        ];
        foreach ($expected as $kind => [$title, $texts]) {
            $response = $this->http($session, 'GET', "/dashboard/detail?kind=$kind$range");
            self::assertSame(200, $response->status, $kind);
            self::assertStringNotContainsString('<html', $response->body, "$kind : fragment, sans gabarit");
            self::assertStringContainsString($title, $response->body, $kind);
            foreach ($texts as $text) {
                self::assertStringContainsString($text, $response->body, "$kind : $text");
            }
            if ($kind !== 'deliveries') {
                self::assertStringContainsString('class="table table--compact"', $response->body, $kind);
            }
        }
        // Le manquant figure en tête des écarts de caisse (le plus gros d'abord).
        $variance = $this->http($session, 'GET', "/dashboard/detail?kind=variance$range")->body;
        self::assertLessThan(strpos($variance, "+2\u{202F}000 FC"), strpos($variance, "-15\u{202F}000 FC"));

        // Cloisonnement : le gérant de A n'obtient que A ; demander B est refusé ; un détail inconnu n'existe pas.
        $manager = $this->sessionFor($this->managerA);
        $own = $this->http($manager, 'GET', '/dashboard/detail?kind=variance&period=custom&from=2026-10-05&to=2026-10-06')->body;
        self::assertStringNotContainsString('Station B', $own);
        self::assertStringNotContainsString("800\u{202F}000", $own);
        self::assertSame(403, $this->http($manager, 'GET', '/dashboard/detail?kind=sales&station=' . $this->b)->status);
        self::assertSame(404, $this->http($manager, 'GET', '/dashboard/detail?kind=secrets')->status);
        self::assertSame(403, $this->http($this->sessionFor($this->attendant1), 'GET', '/dashboard/detail?kind=sales')->status);
    }

    public function testAlertsOpenAsATableInAWindow(): void
    {
        $owner = $this->sessionFor($this->owner);
        $pending = $this->db->fetchAll("SELECT a.message, a.station_id FROM alerts a WHERE a.status <> 'resolved'");
        self::assertNotSame([], $pending);

        // Le tableau de bord ouvre la liste en fenêtre : bouton d'en-tête et carte « à traiter ».
        $page = $this->http($owner, 'GET', '/?period=custom&from=2026-10-06&to=2026-10-06&station=all')->body;
        self::assertStringContainsString('/dashboard/detail?kind=alerts', $page);
        self::assertMatchesRegularExpression('/data-todo="alert"[^>]*data-modal-url="[^"]*kind=alerts/', $page);

        // Toutes les alertes non traitées, quelle que soit la période affichée ; les alertes traitées n'y sont pas.
        $response = $this->http($owner, 'GET', '/dashboard/detail?kind=alerts&period=custom&from=2020-01-01&to=2020-01-01&station=all');
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<html', $response->body);
        foreach (['Alertes à traiter', 'Gravité', 'Statut', 'class="table table--compact"', 'href="' . url('/alerts') . '"'] as $text) {
            self::assertStringContainsString($text, $response->body, $text);
        }
        self::assertSame(count($pending), substr_count($response->body, '<tr><td') , 'une ligne par alerte non traitée');
        self::assertStringNotContainsString('2020', strip_tags(explode('</div>', explode('shift-modal__meta', $response->body)[1])[0]), 'la période ne filtre pas les alertes');

        // Cloisonnement : le gérant de A ne voit que les alertes de A.
        $own = $this->http($this->sessionFor($this->managerA), 'GET', '/dashboard/detail?kind=alerts')->body;
        $inA = count(array_filter($pending, fn (array $a): bool => $a['station_id'] === $this->a));
        self::assertSame($inA, substr_count($own, '<tr><td'));
        self::assertStringNotContainsString('Station B', $own);
        self::assertSame(403, $this->http($this->sessionFor($this->managerA), 'GET', '/dashboard/detail?kind=alerts&station=' . $this->b)->status);
    }
}
