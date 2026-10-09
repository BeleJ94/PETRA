<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use DateTimeImmutable;
use Petra\Core\App;
use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Core\Session;
use Petra\Core\ValidationException;
use Petra\Security\Auth;
use Petra\Security\PasswordHasher;
use Petra\Services\CashControl;
use Petra\Services\DashboardService;
use Petra\Services\DeliveryService;
use Petra\Services\PricingService;
use Petra\Services\ReportService;
use Petra\Services\ShiftService;
use Petra\Services\StockControl;
use Petra\Sync\HttpTransport;
use Petra\Sync\SyncClient;
use Petra\Sync\SyncConfig;
use Petra\Sync\TransportException;
use Tests\DatabaseTestCase;
use Tests\Seeds\RecetteSeed;

/**
 * Recette fonctionnelle : six journées types jouées de bout en bout sur petra_test, à partir du jeu
 * d'essai RecetteSeed. Les opérations passent par les services de l'application à des heures fixées
 * (une journée ne se joue pas en temps réel) ; les contrôles se font sur la base, sur les écrans
 * (tableau de bord, rapports, alertes) et, pour les refus, par les formulaires eux-mêmes.
 *
 * Dans chaque scénario, tout écart est vérifié jusqu'à son rattachement : station, poste, utilisateur.
 */
final class RecetteTest extends DatabaseTestCase
{
    private const DAY = RecetteSeed::DAY;
    private const TOKEN = 'jeton-recette-kolwezi-0123456789abcdef';

    private RecetteSeed $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = RecetteSeed::install($this->db, self::passwordHash());
    }

    protected function tearDown(): void
    {
        putenv('STATION_ID');
        putenv('SYNC_URL');
        putenv('SYNC_TOKEN');
    }

    // --- Outils ------------------------------------------------------------------------------------

    private function id(string $key): string
    {
        return $this->seed->id[$key];
    }

    private static function at(string $time, string $day = self::DAY): DateTimeImmutable
    {
        return new DateTimeImmutable("$day $time:00");
    }

    /**
     * Poste complet : ouverture par le gérant, relevés finaux par le pompiste, remises reçues par le gérant, validation.
     *
     * @param array<string, array{0: ?string, 1: string}> $nozzles clé de pistolet => [index d'ouverture (null : reprise), index final]
     * @param list<array<string, string>> $handovers remises (amount, currency, segment_id facultatif)
     */
    private function shift(string $manager, string $attendant, string $station, string $from, string $to, array $nozzles, array $handovers, ?string $label = null): string
    {
        $opening = [];
        $final = [];
        foreach ($nozzles as $key => [$open, $close]) {
            if ($open !== null) {
                $opening[$this->id($key)] = $open;
            }
            $final[$this->id($key)] = $close;
        }
        $id = $this->service($this->id($manager), ShiftService::class)->open(
            $this->id($station),
            ['attendant_id' => $this->id($attendant), 'label' => $label] + ($opening === [] ? [] : ['opening' => $opening]),
            self::at($from)
        );
        $this->service($this->id($attendant), ShiftService::class)->close($id, ['final' => $final], self::at($to));
        foreach ($handovers as $i => $handover) {
            $this->service($this->id($manager), CashControl::class)->addHandover($id, $handover, self::at($to)->modify('+' . (5 + $i) . ' minutes'));
        }
        $this->service($this->id($manager), ShiftService::class)->validate($id, [], self::at($to)->modify('+20 minutes'));

        return $id;
    }

    /** @return array{0: int, 1: int, 2: int} attendu, reçu, écart du poste (CDF) */
    private function figures(string $shiftId): array
    {
        $row = $this->row('SELECT status, expected_amount, received_amount, variance_amount FROM shifts WHERE id = :id', ['id' => $shiftId]);
        self::assertSame('validated', $row['status']);

        return [(int) $row['expected_amount'], (int) $row['received_amount'], (int) $row['variance_amount']];
    }

    /**
     * Un écart de caisse est figé sur le poste et signalé par une alerte rattachée à la station,
     * au poste et au pompiste — et à eux seuls.
     */
    private function assertCashVariance(string $shiftId, string $station, string $attendant, int $variance): void
    {
        $shift = $this->row('SELECT station_id, attendant_id, variance_amount FROM shifts WHERE id = :id', ['id' => $shiftId]);
        self::assertSame([$this->id($station), $this->id($attendant), $variance], [$shift['station_id'], $shift['attendant_id'], (int) $shift['variance_amount']]);

        $alerts = $this->db->fetchAll("SELECT alert_type, station_id, shift_id, user_id, amount, severity FROM alerts WHERE shift_id = :s AND alert_type LIKE 'cash\\_%'", ['s' => $shiftId]);
        self::assertCount(1, $alerts, 'Une alerte de caisse, et une seule, pour ce poste');
        self::assertSame(
            [$variance < 0 ? 'cash_shortage' : 'cash_surplus', $this->id($station), $shiftId, $this->id($attendant), abs($variance)],
            [$alerts[0]['alert_type'], $alerts[0]['station_id'], $alerts[0]['shift_id'], $alerts[0]['user_id'], abs((int) $alerts[0]['amount'])]
        );
        // Journal : la validation du poste porte l'écart, la station et le valideur.
        $audit = $this->row("SELECT station_id, after_data FROM audit_log WHERE entity_type = 'shift' AND entity_id = :id AND action = 'validate'", ['id' => $shiftId]);
        self::assertSame($this->id($station), $audit['station_id']);
        self::assertStringContainsString((string) $variance, (string) $audit['after_data']);
    }

    private function assertNoCashAlert(string $shiftId): void
    {
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE shift_id = :s AND alert_type LIKE 'cash\\_%'", ['s' => $shiftId]));
    }

    private function dashboard(string $user, array $query = [], string $time = '20:00'): array
    {
        return $this->service($this->id($user), DashboardService::class)->build($query + ['period' => 'custom', 'from' => self::DAY, 'to' => self::DAY], self::at($time));
    }

    private function report(string $user, array $query, string $time = '20:00'): array
    {
        return $this->service($this->id($user), ReportService::class)->build($query + ['date' => self::DAY], self::at($time));
    }

    /** Lignes d'une section de rapport, en valeurs natives. */
    private static function rows(array $report, string $key): array
    {
        foreach ($report['sections'] as $section) {
            if ($section['key'] === $key) {
                return array_map(static fn (array $row): array => array_map(static fn (mixed $c): mixed => is_array($c) ? $c['v'] : $c, $row), $section['rows']);
            }
        }
        self::fail("Section absente : $key");
    }

    // --- (a) Journée normale, deux postes, une station ----------------------------------------------

    public function testANormalDayWithTwoShiftsOnOneStation(): void
    {
        // Matin : Essence 400 L, Mazout 300 L. Après-midi : Essence 250 L, Mazout 200 L. Caisse remise au franc près.
        $morning = $this->shift('manager_k', 'attendant_k1', 'kolwezi', '06:00', '12:00', ['nozzle_k_ess' => ['0', '400'], 'nozzle_k_maz' => ['0', '300']], [['amount' => '2 070 000', 'currency' => 'CDF']], 'Matin');
        $afternoon = $this->shift('manager_k', 'attendant_k2', 'kolwezi', '12:00', '18:00', ['nozzle_k_ess' => [null, '650'], 'nozzle_k_maz' => [null, '500']], [['amount' => '1 330 000', 'currency' => 'CDF']], 'Après-midi');
        // Jaugeage de clôture : la cuve d'essence a baissé de 650 L exactement.
        $dip = $this->service($this->id('manager_k'), StockControl::class)->recordDip($this->id('tank_k_ess'), ['depth_cm' => '193,5', 'dip_type' => 'closing'], self::at('18:30'));

        // Chaque poste : attendu = litres × prix, reçu = attendu, écart nul.
        self::assertSame([2070000, 2070000, 0], $this->figures($morning));
        self::assertSame([1330000, 1330000, 0], $this->figures($afternoon));
        $this->assertNoCashAlert($morning);
        $this->assertNoCashAlert($afternoon);
        // Le second poste reprend les index du premier : aucun litre perdu ni compté deux fois entre les postes.
        self::assertSame(['400.00', '300.00'], array_column($this->db->fetchAll(
            "SELECT mr.index_value FROM meter_readings mr JOIN nozzles n ON n.id = mr.nozzle_id WHERE mr.shift_id = :s AND mr.reading_type = 'opening' ORDER BY n.code",
            ['s' => $afternoon]
        ), 'index_value'));
        self::assertSame(['19350.00', '19350.00', '0.00'], [$dip['volume_l'], $dip['theoretical_l'], $dip['variance_l']]);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type IN ('cash_shortage', 'cash_surplus', 'stock_variance', 'delivery_variance')"), 'Journée sans écart : aucune alerte d\'écart');

        // Tableau de bord du gérant.
        $d = $this->dashboard('manager_k');
        self::assertSame([3400000, 115000, 3400000, 0, 0], [$d['totals']['revenue_cdf'], $d['totals']['sold_cl'], $d['totals']['receipts_cdf'], $d['totals']['receipts_usd'], $d['totals']['cash_variance']]);
        self::assertSame([['Essence', '650.00', 1950000], ['Mazout', '500.00', 1450000]], array_map('array_values', $d['products']));
        self::assertSame([0, 0, 2], [$d['totals']['shifts_open'], $d['totals']['shifts_closed'], $d['totals']['shifts_validated']]);

        // Rapport journalier : les dix questions.
        $r = $this->report('manager_k', ['type' => 'daily']);
        self::assertSame(['Station Kolwezi', '02/11/2026', 'Patrick Ilunga'], [$r['station'], $r['period'], $r['author']]);
        self::assertSame([['Essence', '650.00', 1950000], ['Mazout', '500.00', 1450000]], self::rows($r, 'products'));
        self::assertSame(
            [['Grâce Mwamba (Matin)', 'Validé', 2070000, 2070000, '0.00', 0, 2070000, 0], ['Daniel Kasongo (Après-midi)', 'Validé', 1330000, 1330000, '0.00', 0, 1330000, 0]],
            self::rows($r, 'shifts')
        );
        self::assertSame([2800], array_column(self::rows($r, 'rates'), 0));
        $stock = array_column(self::rows($r, 'stock'), null, 0);
        self::assertSame('19350.00', $stock['C-ESS'][6]);
        self::assertSame('14500.00', $stock['C-MAZ'][6], 'Mazout : 15 000 L jaugés le matin − 500 L débités');
        $operations = self::rows($r, 'operations');
        self::assertSame(
            ['Jaugeage' => 3, 'Ouverture de poste' => 2, 'Clôture de poste' => 2, 'Remise de caisse reçue' => 2, 'Validation de poste' => 2],
            array_count_values(array_column($operations, 1))
        );
        self::assertSame(['Patrick Ilunga', 'Grâce Mwamba', 'Daniel Kasongo'], array_values(array_unique(array_column($operations, 3))), 'Chaque opération a son responsable');

        // Écrans.
        $session = $this->sessionFor($this->id('manager_k'));
        $page = $this->http($session, 'GET', '/?period=custom&from=' . self::DAY . '&to=' . self::DAY);
        self::assertMatchesRegularExpression('#data-kpi="revenue">3\x{202F}400\x{202F}000 FC<#u', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="cash-variance">0 FC<#u', $page->body);
        self::assertSame(200, $this->http($session, 'GET', "/shifts/$morning")->status);
        self::assertStringContainsString('1–2. Litres vendus par produit', $this->http($session, 'GET', '/reports?type=daily&date=' . self::DAY)->body);
    }

    // --- (b) Changement de taux à 14 h pendant le poste 2, paiement mixte ----------------------------

    public function testBRateChangeAtTwoPmDuringShiftTwoWithMixedPayment(): void
    {
        $ess = $this->id('nozzle_k_ess');
        $maz = $this->id('nozzle_k_maz');
        $this->shift('manager_k', 'attendant_k1', 'kolwezi', '06:00', '12:00', ['nozzle_k_ess' => ['0', '400'], 'nozzle_k_maz' => ['0', '300']], [['amount' => '2070000', 'currency' => 'CDF']], 'Matin');
        $shift = $this->service($this->id('manager_k'), ShiftService::class)->open($this->id('kolwezi'), ['attendant_id' => $this->id('attendant_k2'), 'label' => 'Après-midi'], self::at('12:00'));

        // 14 h : le propriétaire veut passer le taux de 2 800 à 2 850. Sans relevé intermédiaire, c'est refusé.
        $pricing = $this->service($this->id('owner'), PricingService::class);
        try {
            $pricing->publishRate($this->id('kolwezi'), ['rate' => '2850'], self::at('14:00'));
            self::fail('Taux changé pendant un poste sans relevé intermédiaire');
        } catch (ValidationException $e) {
            self::assertArrayHasKey("reading_$ess", $e->errors);
            self::assertArrayHasKey("reading_$maz", $e->errors);
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates WHERE station_id = :s', ['s' => $this->id('kolwezi')]));
        // Avec les index relevés à 14 h (Essence 500, Mazout 380) : le poste est découpé en deux tranches.
        $rate = $pricing->publishRate($this->id('kolwezi'), ['rate' => '2850', 'readings' => [$shift => [$ess => '500', $maz => '380']]], self::at('14:00'));

        $segments = $this->db->fetchAll(
            'SELECT sg.id, sg.sequence, sg.reason, sg.started_at, x.rate FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id WHERE sg.shift_id = :s ORDER BY sg.sequence',
            ['s' => $shift]
        );
        self::assertSame([['1', 'shift_open', self::DAY . ' 12:00:00', '2800'], ['2', 'rate_change', self::DAY . ' 14:00:00', '2850']], array_map(static fn (array $s): array => [(string) $s['sequence'], $s['reason'], $s['started_at'], (string) $s['rate']], $segments));
        self::assertSame($rate, $this->value('SELECT exchange_rate_id FROM shift_segments WHERE id = :id', ['id' => $segments[1]['id']]));

        // 18 h : clôture (Essence 650, Mazout 500), puis paiement mixte :
        //   1 000 000 FC, 50 $ encaissés avant 14 h (taux 2 800) et 50 $ après (taux 2 850).
        $this->service($this->id('attendant_k2'), ShiftService::class)->close($shift, ['final' => [$ess => '650', $maz => '500']], self::at('18:00'));
        $cash = $this->service($this->id('manager_k'), CashControl::class);
        $cash->addHandover($shift, ['amount' => '1 000 000', 'currency' => 'CDF'], self::at('18:05'));
        $cash->addHandover($shift, ['amount' => '50', 'currency' => 'USD', 'segment_id' => $segments[0]['id']], self::at('18:06'));
        $cash->addHandover($shift, ['amount' => '50', 'currency' => 'USD', 'segment_id' => $segments[1]['id']], self::at('18:07'));
        $this->service($this->id('manager_k'), ShiftService::class)->validate($shift, [], self::at('18:20'));

        // Attendu : tranche 1 = 100 L × 3 000 + 80 L × 2 900 = 532 000 ; tranche 2 = 150 × 3 000 + 120 × 2 900 = 798 000.
        // Reçu : 1 000 000 + 50 $ × 2 800 + 50 $ × 2 850 = 1 282 500. Écart : −47 500 FC.
        self::assertSame([1330000, 1282500, -47500], $this->figures($shift));
        self::assertSame(
            [['CDF', 1000000, 1000000, null], ['USD', 5000, 140000, 2800], ['USD', 5000, 142500, 2850]],
            array_map(static fn (array $h): array => [$h['currency'], (int) $h['amount'], (int) $h['amount_cdf'], $h['rate'] === null ? null : (int) $h['rate']], $this->db->fetchAll(
                'SELECT h.currency, h.amount, h.amount_cdf, x.rate FROM cash_handovers h LEFT JOIN exchange_rates x ON x.id = h.exchange_rate_id WHERE h.shift_id = :s ORDER BY h.handed_at',
                ['s' => $shift]
            )),
            'Chaque dollar est converti au taux de sa tranche'
        );
        // L'écart est en CDF, rattaché à Kolwezi, au poste de l'après-midi et à Daniel Kasongo.
        $this->assertCashVariance($shift, 'kolwezi', 'attendant_k2', -47500);
        self::assertSame('CDF', $this->value('SELECT currency FROM shifts WHERE id = :id', ['id' => $shift]));

        // Rapports : deux taux utilisés, ventes par tranche, recettes par devise.
        $r = $this->report('manager_k', ['type' => 'shift', 'shift' => $shift]);
        self::assertSame([[2800, '1'], [2850, '1']], array_map(static fn (array $row): array => [$row[0], $row[2]], self::rows($r, 'rates')));
        self::assertSame(
            [['1', 'P-ESS', '400.00', '500.00', '100.00', 300000], ['1', 'P-MAZ', '300.00', '380.00', '80.00', 232000], ['2', 'P-ESS', '500.00', '650.00', '150.00', 450000], ['2', 'P-MAZ', '380.00', '500.00', '120.00', 348000]],
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[3], $row[4], $row[6], $row[8]], self::rows($r, 'readings'))
        );
        $d = $this->dashboard('manager_k');
        self::assertSame([3400000, 3070000, 10000, 282500, -47500, 2850], [$d['totals']['revenue_cdf'], $d['totals']['receipts_cdf'], $d['totals']['receipts_usd'], $d['totals']['receipts_usd_cdf'], $d['totals']['cash_variance'], $d['rate']['rate']]);
        self::assertSame([['Daniel Kasongo', -47500], ['Grâce Mwamba', 0]], array_map(static fn (array $a): array => [$a['attendant'], $a['variance']], $d['attendantRanking']));
        // Les opérations d'avant 14 h gardent le taux de 2 800 : rien n'est recalculé après coup.
        self::assertSame(2800, (int) $this->value('SELECT x.rate FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id JOIN shifts sh ON sh.id = sg.shift_id WHERE sh.label = :l', ['l' => 'Matin']));
    }

    // --- (c) Essai de pompe de 20 L et livraison avec écart -------------------------------------------

    public function testCPumpTestOfTwentyLitresAndDeliveryWithAVariance(): void
    {
        $shift = $this->service($this->id('manager_k'), ShiftService::class)->open($this->id('kolwezi'), ['attendant_id' => $this->id('attendant_k1'), 'opening' => [$this->id('nozzle_k_ess') => '0', $this->id('nozzle_k_maz') => '0']], self::at('06:00'));
        // 08 h : essai de pompe de 20 L sur l'essence, déclaré par le pompiste.
        $this->service($this->id('attendant_k1'), ShiftService::class)->addOutput($shift, ['nozzle_id' => $this->id('nozzle_k_ess'), 'output_type' => 'test', 'volume_l' => '20', 'reason' => 'Essai de pompe du matin'], self::at('08:00'));
        $this->service($this->id('attendant_k1'), ShiftService::class)->close($shift, ['final' => [$this->id('nozzle_k_ess') => '520', $this->id('nozzle_k_maz') => '0']], self::at('12:00'));
        $this->service($this->id('manager_k'), CashControl::class)->addHandover($shift, ['amount' => '1500000', 'currency' => 'CDF'], self::at('12:05'));
        $this->service($this->id('manager_k'), ShiftService::class)->validate($shift, [], self::at('12:20'));

        // 520 L débités, dont 20 L d'essai : 500 L vendus. Les 20 L ne sont pas réclamés au pompiste.
        self::assertSame([1500000, 1500000, 0], $this->figures($shift));
        $this->assertNoCashAlert($shift);
        $output = $this->row('SELECT station_id, shift_id, created_by, output_type, volume_l FROM non_sale_outputs');
        self::assertSame([$this->id('kolwezi'), $shift, $this->id('attendant_k1'), 'test', '20.00'], array_values($output));
        // … mais ils sont bien sortis de la cuve : stock théorique = 20 000 − 520.
        $position = $this->service($this->id('manager_k'), StockControl::class)->position($this->id('tank_k_ess'), self::DAY . ' 12:30:00');
        self::assertSame(['500.00', '20.00', '19480.00'], [$position['sold_l'], $position['non_sale_l'], $position['theoretical_l']]);

        // 13 h : livraison d'essence. Bon de 10 000 L, 9 850 L reçus ; la cuve passe de 194,8 cm à 293,3 cm.
        $delivery = $this->service($this->id('manager_k'), DeliveryService::class)->receive($this->id('kolwezi'), [
            'tank_id' => $this->id('tank_k_ess'), 'supplier_id' => $this->id('supplier_k'), 'delivery_note_no' => 'BL-2026-1102', 'truck_plate' => '4521AB/07', 'driver_name' => 'Paul Kalala',
            'ordered_volume_l' => '10000', 'invoiced_volume_l' => '10000', 'received_volume_l' => '9850', 'before_cm' => '194,8', 'after_cm' => '293,3',
            'received_by' => $this->id('manager_k'), 'note' => 'Manque constaté au dépotage',
        ], null, self::at('13:00'));

        self::assertTrue($delivery['alert']);
        $row = $this->row('SELECT station_id, tank_id, received_by, invoiced_volume_l, received_volume_l, announced_variance_l, tank_variance_l FROM deliveries WHERE id = :id', ['id' => $delivery['id']]);
        self::assertSame(
            [$this->id('kolwezi'), $this->id('tank_k_ess'), $this->id('manager_k'), '10000.00', '9850.00', '-150.00', '0.00'],
            array_values($row),
            'Il manque 150 L sur le bon ; la cuve, elle, a bien monté de ce qui a été reçu'
        );
        // L'écart de livraison (150 L > tolérance de 100 L) est signalé et rattaché à la station, à la livraison et au réceptionnaire.
        $alert = $this->row("SELECT station_id, shift_id, user_id, entity_type, entity_id, volume_l, severity, status FROM alerts WHERE alert_type = 'delivery_variance'");
        self::assertSame([$this->id('kolwezi'), null, $this->id('manager_k'), 'delivery', $delivery['id'], 'open'], [$alert['station_id'], $alert['shift_id'], $alert['user_id'], $alert['entity_type'], $alert['entity_id'], $alert['status']]);
        self::assertSame('150.00', ltrim((string) $alert['volume_l'], '-'));
        // Les deux jaugeages du dépotage : avant (aucun écart de stock : l'essai est compté), après.
        self::assertSame(
            [['before_delivery', '19480.00', '0.00'], ['after_delivery', '29330.00', '0.00']],
            array_map('array_values', $this->db->fetchAll("SELECT dip_type, volume_l, variance_l FROM tank_dips WHERE tank_id = :t AND dip_type LIKE '%delivery' ORDER BY measured_at, dip_type DESC", ['t' => $this->id('tank_k_ess')]))
        );
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'stock_variance'"), 'Aucun écart de stock : seul le bon du fournisseur est en cause');

        // Rapport journalier : questions 7 (stock), 8 (ravitaillements), 9 (écarts) et 10 (responsables).
        $r = $this->report('manager_k', ['type' => 'daily']);
        self::assertSame('29330.00', array_column(self::rows($r, 'stock'), null, 0)['C-ESS'][6]);
        self::assertSame([['13:00', 'C-ESS', 'SEP Congo', 'BL-2026-1102', '10000.00', '9850.00', '-150.00', 'Patrick Ilunga']], array_map(static fn (array $d): array => [substr($d[0], 11), $d[1], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8]], self::rows($r, 'deliveries')));
        $anomalies = array_values(array_filter(self::rows($r, 'anomalies'), static fn (array $a): bool => $a[1] === 'Écart de livraison'));
        self::assertSame([['-150.00', 'Patrick Ilunga']], array_map(static fn (array $a): array => [$a[3], $a[4]], $anomalies));
        $operations = array_column(self::rows($r, 'operations'), 3, 1);
        self::assertSame(['Grâce Mwamba', 'Patrick Ilunga'], [$operations['Sortie non vendue'], $operations['Réception de livraison']]);
        $line = self::rows($this->report('manager_k', ['type' => 'shift', 'shift' => $shift]), 'readings')[0];
        self::assertSame(['P-ESS', '0.00', '520.00', '20.00', '500.00', 1500000], [$line[1], $line[3], $line[4], $line[5], $line[6], $line[8]], 'Rapport de poste : 20 L non vendus, 500 L vendus');
        $d = $this->dashboard('manager_k');
        self::assertSame([1500000, 50000, 1, 985000, -15000], [$d['totals']['revenue_cdf'], $d['totals']['sold_cl'], $d['totals']['deliveries'], $d['totals']['received_cl'], $d['totals']['delivery_variance_cl']]);
        self::assertStringContainsString('SEP Congo', $this->http($this->sessionFor($this->id('manager_k')), 'GET', '/alerts')->body);
    }

    // --- (d) Deux stations le même jour : isolation des droits et consolidé ---------------------------

    public function testDTwoStationsOnTheSameDayStayIsolatedAndConsolidateExactly(): void
    {
        // Kolwezi : 2 070 000 FC attendus, 2 050 000 remis → manquant de 20 000 FC (Grâce Mwamba).
        $k = $this->shift('manager_k', 'attendant_k1', 'kolwezi', '06:00', '12:00', ['nozzle_k_ess' => ['0', '400'], 'nozzle_k_maz' => ['0', '300']], [['amount' => '2050000', 'currency' => 'CDF']]);
        // Likasi : 200 L × 3 100 = 620 000 FC attendus, 630 000 remis → excédent de 10 000 FC (Moïse Tshibangu).
        $l = $this->shift('manager_l', 'attendant_l', 'likasi', '06:30', '12:30', ['nozzle_l_ess' => ['0', '200']], [['amount' => '630000', 'currency' => 'CDF']]);

        self::assertSame([2070000, 2050000, -20000], $this->figures($k));
        self::assertSame([620000, 630000, 10000], $this->figures($l));
        // Chaque écart chez lui : bonne station, bon poste, bon pompiste.
        $this->assertCashVariance($k, 'kolwezi', 'attendant_k1', -20000);
        $this->assertCashVariance($l, 'likasi', 'attendant_l', 10000);
        self::assertSame(
            [[$this->id('kolwezi'), 1], [$this->id('likasi'), 1]],
            array_map(static fn (array $r): array => [$r['station_id'], (int) $r['n']], $this->db->fetchAll("SELECT station_id, COUNT(*) n FROM alerts WHERE alert_type LIKE 'cash\\_%' GROUP BY station_id ORDER BY station_id = :k DESC", ['k' => $this->id('kolwezi')]))
        );
        // Aucune ligne métier d'une station ne porte l'identifiant de l'autre.
        foreach (['meter_readings', 'shift_segments', 'cash_handovers', 'alerts'] as $table) {
            self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM `$table` t JOIN shifts sh ON sh.id = t.shift_id WHERE t.station_id <> sh.station_id"), $table);
        }

        // Isolation : chaque gérant ne voit, ne lit et ne modifie que sa station.
        foreach ([['manager_k', $k, $l, 'likasi', 'Station Likasi', 2070000], ['manager_l', $l, $k, 'kolwezi', 'Station Kolwezi', 620000]] as [$manager, $own, $other, $otherStation, $otherName, $revenue]) {
            $session = $this->sessionFor($this->id($manager));
            self::assertSame(200, $this->http($session, 'GET', "/shifts/$own")->status);
            foreach (["/shifts/$other", "/shifts/$other/cash", "/reports?type=shift&shift=$other", '/reports?type=daily&station=' . $this->id($otherStation), '/?station=' . $this->id($otherStation)] as $path) {
                self::assertSame(403, $this->http($session, 'GET', $path)->status, "$manager → $path");
            }
            self::assertSame(403, $this->http($session, 'POST', "/shifts/$other/handovers", ['amount' => '1000', 'currency' => 'CDF'])->status);
            self::assertSame(403, $this->http($session, 'POST', "/shifts/$other/justifications", ['amount' => '1000', 'reason' => 'Intrusion'])->status);
            $d = $this->dashboard($manager, ['station' => 'all']);
            self::assertSame($revenue, $d['totals']['revenue_cdf']);
            self::assertFalse($d['consolidated']);
            $page = $this->http($session, 'GET', '/?period=custom&from=' . self::DAY . '&to=' . self::DAY . '&station=all')->body . $this->http($session, 'GET', '/alerts')->body
                . $this->http($session, 'GET', '/reports?type=daily&date=' . self::DAY . '&station=all')->body;
            self::assertStringNotContainsString($otherName, $page, "$manager ne doit rien voir de $otherName");
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM cash_handovers WHERE shift_id = :s', ['s' => $l]), 'Aucune remise injectée dans le poste de l\'autre station');
        // Les tentatives sont journalisées au nom de leur auteur.
        self::assertSame(
            [['Patrick Ilunga', $this->id('kolwezi')], ['Sarah Kabwe', $this->id('likasi')]],
            array_map('array_values', $this->db->fetchAll("SELECT DISTINCT user_name, station_id FROM audit_log WHERE action = 'access_denied' ORDER BY user_name"))
        );
        // Un pompiste ne voit que son poste, et pas le tableau de bord.
        $attendant = $this->sessionFor($this->id('attendant_k2'));
        self::assertSame(403, $this->http($attendant, 'GET', "/shifts/$k")->status);
        self::assertSame(403, $this->http($attendant, 'GET', '/reports')->status);
        self::assertStringNotContainsString("Chiffre d'affaires", $this->http($attendant, 'GET', '/')->body);

        // Consolidé du propriétaire : somme exacte des deux stations.
        $d = $this->dashboard('owner', ['station' => 'all']);
        self::assertTrue($d['consolidated']);
        self::assertSame([2690000, 90000, 2680000, -10000, -20000, 10000], [$d['totals']['revenue_cdf'], $d['totals']['sold_cl'], $d['totals']['receipts_cdf'], $d['totals']['cash_variance'], $d['totals']['shortage'], $d['totals']['surplus']]);
        $rows = array_column($d['comparison'], null, 'station');
        self::assertSame([2070000, 70000, 2050000, -20000, 2800], [$rows['Station Kolwezi']['revenue_cdf'], $rows['Station Kolwezi']['sold_cl'], $rows['Station Kolwezi']['receipts_cdf'], $rows['Station Kolwezi']['cash_variance'], $rows['Station Kolwezi']['rate']]);
        self::assertSame([620000, 20000, 630000, 10000, 2820], [$rows['Station Likasi']['revenue_cdf'], $rows['Station Likasi']['sold_cl'], $rows['Station Likasi']['receipts_cdf'], $rows['Station Likasi']['cash_variance'], $rows['Station Likasi']['rate']]);
        self::assertSame(array_sum(array_column($d['comparison'], 'revenue_cdf')), $d['totals']['revenue_cdf']);
        self::assertSame(['Station Kolwezi', 'Station Likasi'], array_column($d['stationRanking'], 'station'), 'Le plus gros manquant en tête');
        self::assertSame([['Grâce Mwamba', 'Station Kolwezi', -20000], ['Moïse Tshibangu', 'Station Likasi', 10000]], array_map(static fn (array $a): array => [$a['attendant'], $a['station'], $a['variance']], $d['attendantRanking']));

        $r = $this->report('owner', ['type' => 'daily', 'station' => 'all']);
        self::assertSame('Toutes les stations', $r['station']);
        self::assertSame(
            [['Station Kolwezi', 'Grâce Mwamba', 2070000, 2050000, -20000], ['Station Likasi', 'Moïse Tshibangu', 620000, 630000, 10000]],
            array_map(static fn (array $s): array => [$s[0], $s[1], $s[3], $s[4], $s[8]], self::rows($r, 'shifts'))
        );
        $anomalies = array_values(array_filter(self::rows($r, 'anomalies'), static fn (array $a): bool => $a[2] === 'Écart de caisse'));
        self::assertSame([['Station Kolwezi', -20000, 'Grâce Mwamba'], ['Station Likasi', 10000, 'Moïse Tshibangu']], array_map(static fn (array $a): array => [$a[0], $a[4], $a[5]], $anomalies));
        $page = $this->http($this->sessionFor($this->id('owner')), 'GET', '/?period=custom&from=' . self::DAY . '&to=' . self::DAY . '&station=all')->body;
        self::assertMatchesRegularExpression('#data-kpi="revenue">2\x{202F}690\x{202F}000 FC<#u', $page);
        self::assertMatchesRegularExpression('#data-kpi="cash-variance">-10\x{202F}000 FC<#u', $page);
    }

    // --- (e) Saisie hors ligne, synchronisation, renvoi en double --------------------------------------

    /** Le central connaît l'organisation, les stations et les comptes du jeu d'essai (mêmes identifiants). */
    private function central(): Database
    {
        $central = central_test_database();
        $central->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($central->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = (string) array_values($row)[0];
            if (!in_array($table, ['schema_migrations', 'roles', 'permissions', 'role_permissions'], true)) {
                $central->pdo()->exec("TRUNCATE TABLE `$table`");
            }
        }
        $central->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['organizations', 'stations', 'users', 'user_stations'] as $table) {
            foreach ($this->db->fetchAll("SELECT * FROM `$table`") as $row) {
                $columns = array_keys($row);
                $central->execute("INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')', $row);
            }
        }
        $central->execute('UPDATE stations SET sync_token_hash = :h WHERE id = :id', ['h' => hash('sha256', self::TOKEN), 'id' => $this->id('kolwezi')]);

        return $central;
    }

    /** Réseau factice vers l'application centrale : peut être coupé, ou perdre une réponse après traitement. */
    private function network(Database $central): object
    {
        $config = ['mode' => 'central', 'station_id' => '', 'sync' => ['url' => '', 'token' => '']] + self::config();

        return new class (static fn (Request $r): Response => App::create($config, Session::memory(), $central, $r)->handle($r)) implements HttpTransport {
            public bool $online = true;
            public int $dropResponses = 0;

            public function __construct(private readonly \Closure $central)
            {
            }

            public function post(string $url, array $headers, string $body): array
            {
                if (!$this->online) {
                    throw new TransportException('Could not resolve host: central.test');
                }
                $response = ($this->central)(new Request('POST', (string) parse_url($url, PHP_URL_PATH), [], json_decode($body, true), array_change_key_case($headers) + ['content-type' => 'application/json']));
                if ($this->dropResponses > 0) {
                    $this->dropResponses--;
                    throw new TransportException('Connection reset by peer');
                }

                return ['status' => $response->status, 'body' => $response->body];
            }
        };
    }

    public function testEOfflineEntryThenSyncThenDuplicateResend(): void
    {
        $central = $this->central();
        $network = $this->network($central);
        $station = $this->id('kolwezi');
        putenv("STATION_ID=$station");
        putenv('SYNC_URL=https://central.test');
        putenv('SYNC_TOKEN=' . self::TOKEN);
        $client = fn (): SyncClient => new SyncClient($this->db, new SyncConfig('station', $station, 'https://central.test', self::TOKEN), $network, 25);
        $header = function (): string {
            preg_match('#<span class="sync-state sync-state--(\w+)"[^>]*>\s*<i[^>]*></i>\s*<span>([^<]+)</span>#', $this->http($this->sessionFor($this->id('manager_k')), 'GET', '/')->body, $m);

            return $m[2] ?? '?';
        };
        // L'installation de la station (produits, cuves, prix…) est déjà au central.
        self::assertSame('success', $client()->run()['push']['status']);
        self::assertSame('Synchronisé', $header());

        // Internet coupé : la station travaille quand même. Poste complet, avec un manquant de 20 000 FC.
        $network->online = false;
        $shift = $this->shift('manager_k', 'attendant_k1', 'kolwezi', '06:00', '12:00', ['nozzle_k_ess' => ['0', '400'], 'nozzle_k_maz' => ['0', '300']], [['amount' => '2050000', 'currency' => 'CDF']], 'Matin');
        self::assertSame([2070000, 2050000, -20000], $this->figures($shift));
        // La file de cette station (un serveur de station ne porte que la sienne).
        $pending = (int) $this->value("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending' AND station_id = :s", ['s' => $station]);
        self::assertGreaterThan(10, $pending);
        self::assertSame("En attente ($pending)", $header());
        $run = $client()->run();
        self::assertSame(['offline', 0], [$run['push']['status'], $run['push']['accepted']]);
        self::assertSame('Hors ligne', $header());
        self::assertSame($pending, (int) $this->value("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending' AND station_id = :s", ['s' => $station]), 'Rien n\'est perdu hors ligne');
        self::assertSame(0, (int) $central->fetchValue('SELECT COUNT(*) FROM shifts'));

        // Connexion revenue, mais la réponse du central se perd : il a tout reçu, la station ne le sait pas.
        $network->online = true;
        $network->dropResponses = 1;
        $lost = $client()->push();
        self::assertSame('offline', $lost['status']);
        self::assertGreaterThan(0, (int) $central->fetchValue('SELECT COUNT(*) FROM meter_readings'), 'Le central a bien reçu le premier lot');
        $tables = ['shifts', 'shift_segments', 'meter_readings', 'cash_handovers', 'alerts', 'audit_log'];

        // Renvoi : tout repart, y compris ce que le central a déjà. Aucun doublon.
        $resend = $client()->run();
        self::assertSame(['success', 0, 0], [$resend['push']['status'], $resend['push']['rejected'], $resend['push']['conflicts']]);
        self::assertSame($pending, $resend['push']['sent'] + $lost['accepted']);
        self::assertSame('Synchronisé', $header());
        $counts = [];
        foreach ($tables as $table) {
            $sql = "SELECT * FROM `$table` WHERE station_id = :s ORDER BY id";
            self::assertSame($this->db->fetchAll($sql, ['s' => $station]), $central->fetchAll($sql, ['s' => $station]), "$table : mêmes lignes à la station et au central");
            $counts[$table] = (int) $central->fetchValue("SELECT COUNT(*) FROM `$table`");
        }
        // Double envoi complet (file remise en attente, comme après une restauration) : le central ne double rien.
        $this->db->execute("UPDATE sync_queue SET status = 'pending', sent_at = NULL WHERE station_id = :s", ['s' => $station]);
        $again = $client()->push();
        self::assertSame(['success', 0, 0], [$again['status'], $again['rejected'], $again['conflicts']]);
        self::assertGreaterThan($pending, $again['sent']);
        foreach ($tables as $table) {
            self::assertSame($counts[$table], (int) $central->fetchValue("SELECT COUNT(*) FROM `$table`"), "$table : aucun doublon après renvoi");
        }
        self::assertSame(1, (int) $central->fetchValue('SELECT COUNT(*) FROM shifts'));
        self::assertSame(1, (int) $central->fetchValue('SELECT COUNT(*) FROM cash_handovers'));

        // Au central, l'écart saisi hors ligne arrive rattaché à la même station, au même poste, au même pompiste.
        $row = $central->fetchOne('SELECT station_id, attendant_id, expected_amount, received_amount, variance_amount, validated_by FROM shifts WHERE id = :id', ['id' => $shift]);
        self::assertSame([$station, $this->id('attendant_k1'), 2070000, 2050000, -20000, $this->id('manager_k')], [$row['station_id'], $row['attendant_id'], (int) $row['expected_amount'], (int) $row['received_amount'], (int) $row['variance_amount'], $row['validated_by']]);
        $alert = $central->fetchOne("SELECT station_id, shift_id, user_id, amount FROM alerts WHERE alert_type = 'cash_shortage'");
        self::assertSame([$station, $shift, $this->id('attendant_k1'), 20000], [$alert['station_id'], $alert['shift_id'], $alert['user_id'], abs((int) $alert['amount'])]);
        // Le propriétaire le voit dans son consolidé, sur le serveur central.
        $owner = $this->id('owner');
        $hash = (string) $central->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $owner]);
        $request = new Request('GET', '/', ['period' => 'custom', 'from' => self::DAY, 'to' => self::DAY, 'station' => 'all']);
        $page = App::create(
            ['mode' => 'central', 'station_id' => '', 'sync' => ['url' => '', 'token' => '']] + self::config(),
            Session::memory([Auth::SESSION_USER => $owner, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash), '_csrf_token' => self::CSRF]),
            $central,
            $request
        )->handle($request);
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('#data-kpi="revenue">2\x{202F}070\x{202F}000 FC<#u', $page->body);
        self::assertMatchesRegularExpression('#data-kpi="cash-variance">-20\x{202F}000 FC<#u', $page->body);
        self::assertStringContainsString('Grâce Mwamba', $page->body);
        self::assertStringNotContainsString('Jamais</span></td>', (string) preg_replace('#.*Station Kolwezi</a></td>(.*?)</tr>.*#s', '$1', $page->body), 'Dernière synchronisation de Kolwezi renseignée');
    }

    // --- (f) Tentative de modification d'une opération validée ------------------------------------------

    public function testFModifyingAValidatedOperationFailsAndIsLogged(): void
    {
        $shift = $this->shift('manager_k', 'attendant_k1', 'kolwezi', '06:00', '12:00', ['nozzle_k_ess' => ['0', '400'], 'nozzle_k_maz' => ['0', '300']], [['amount' => '2050000', 'currency' => 'CDF']], 'Matin');
        $this->assertCashVariance($shift, 'kolwezi', 'attendant_k1', -20000);
        $snapshot = fn (): array => [
            $this->row('SELECT status, expected_amount, received_amount, variance_amount, validated_at, validated_by FROM shifts WHERE id = :id', ['id' => $shift]),
            $this->db->fetchAll('SELECT * FROM meter_readings WHERE shift_id = :s ORDER BY id', ['s' => $shift]),
            $this->db->fetchAll('SELECT * FROM cash_handovers WHERE shift_id = :s ORDER BY id', ['s' => $shift]),
            (int) $this->value('SELECT COUNT(*) FROM non_sale_outputs') + (int) $this->value('SELECT COUNT(*) FROM cash_expenses') + (int) $this->value('SELECT COUNT(*) FROM credit_sales'),
        ];
        $before = $snapshot();
        $denied = fn (): array => $this->db->fetchAll("SELECT station_id, created_by, user_name, entity_type, entity_id FROM audit_log WHERE action = 'modification_denied' ORDER BY created_at, id");

        // 1. Par les écrans : le gérant veut « arranger » la caisse du poste validé pour effacer le manquant.
        $manager = $this->sessionFor($this->id('manager_k'));
        $referer = ['referer' => "http://localhost/shifts/$shift/cash"];
        foreach ([
            ["/shifts/$shift/handovers", ['amount' => '20000', 'currency' => 'CDF', 'note' => 'Complément']],
            ["/shifts/$shift/expenses", ['category' => 'divers', 'description' => 'Dépense après coup', 'amount' => '20000', 'currency' => 'CDF']],
            ["/shifts/$shift/credit-sales", ['nozzle_id' => $this->id('nozzle_k_ess'), 'customer_name' => 'Client inventé', 'volume_l' => '6']],
        ] as [$path, $body]) {
            $response = $this->http($manager, 'POST', $path, $body, $referer);
            self::assertSame(302, $response->status, $path);
            self::assertStringContainsString('validé', $this->http($manager, 'GET', "/shifts/$shift/cash")->body, 'Le refus est expliqué à l\'écran');
        }
        // Le pompiste, lui, tente de refaire ses relevés et d'ajouter un essai de pompe.
        $attendant = $this->sessionFor($this->id('attendant_k1'));
        $this->http($attendant, 'POST', "/shifts/$shift/close", ['final' => [$this->id('nozzle_k_ess') => '393', $this->id('nozzle_k_maz') => '300']], ['referer' => "http://localhost/shifts/$shift"]);
        $this->http($attendant, 'POST', "/shifts/$shift/outputs", ['nozzle_id' => $this->id('nozzle_k_ess'), 'output_type' => 'test', 'volume_l' => '7', 'reason' => 'Essai oublié'], ['referer' => "http://localhost/shifts/$shift"]);
        // Une seconde validation ne refait pas le calcul.
        $this->http($manager, 'POST', "/shifts/$shift/validate", [], ['referer' => "http://localhost/shifts/$shift"]);

        self::assertSame($before, $snapshot(), 'Rien n\'a changé : ni les relevés, ni la caisse, ni l\'écart');

        // Chaque tentative est journalisée : qui, sur quel poste, dans quelle station.
        $log = $denied();
        self::assertCount(5, $log);
        self::assertSame(
            [[$this->id('manager_k'), 3], [$this->id('attendant_k1'), 2]],
            array_map(static fn (string $user) => [$user, count(array_filter($log, static fn (array $l): bool => $l['created_by'] === $user))], [$this->id('manager_k'), $this->id('attendant_k1')])
        );
        foreach ($log as $entry) {
            self::assertSame([$this->id('kolwezi'), 'shift', $shift], [$entry['station_id'], $entry['entity_type'], $entry['entity_id']]);
        }
        self::assertSame(['Grâce Mwamba', 'Patrick Ilunga'], array_values(array_unique(array_column($this->db->fetchAll("SELECT user_name FROM audit_log WHERE action = 'modification_denied' ORDER BY user_name"), 'user_name'))));
        // … et signalée : une alerte par auteur, rattachée à la station, au poste et à l'utilisateur.
        $alerts = $this->db->fetchAll("SELECT station_id, shift_id, user_id, severity, status FROM alerts WHERE alert_type = 'modification_denied' ORDER BY user_id = :m DESC", ['m' => $this->id('manager_k')]);
        self::assertSame(
            [[$this->id('kolwezi'), $shift, $this->id('manager_k'), 'warning', 'open'], [$this->id('kolwezi'), $shift, $this->id('attendant_k1'), 'warning', 'open']],
            array_map('array_values', $alerts)
        );
        // Le propriétaire le lit dans le journal d'audit et dans le centre d'alertes.
        $owner = $this->sessionFor($this->id('owner'));
        $audit = $this->http($owner, 'GET', '/audit/data?draw=1&start=0&length=50&action=modification_denied', [], ['accept' => 'application/json']);
        self::assertSame(200, $audit->status);
        self::assertSame(5, json_decode($audit->body, true)['recordsFiltered']);
        self::assertStringContainsString('Modification refusée', $this->http($owner, 'GET', '/audit')->body);
        self::assertStringContainsString('Patrick Ilunga', $this->http($owner, 'GET', '/alerts')->body);

        // 2. Par les services (tout autre chemin applicatif) : même refus.
        foreach ([
            fn () => $this->service($this->id('manager_k'), CashControl::class)->addHandover($shift, ['amount' => '20000', 'currency' => 'CDF']),
            fn () => $this->service($this->id('attendant_k1'), ShiftService::class)->close($shift, ['final' => [$this->id('nozzle_k_ess') => '393']]),
            fn () => $this->service($this->id('owner'), ShiftService::class)->addOutput($shift, ['nozzle_id' => $this->id('nozzle_k_ess'), 'output_type' => 'test', 'volume_l' => '7', 'reason' => 'x']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Opération validée modifiée');
            } catch (ValidationException | HttpException) {
            }
        }
        self::assertCount(8, $denied());

        // 3. Directement en base (hors application) : MariaDB refuse toute réécriture ou suppression.
        foreach ([
            'UPDATE cash_handovers SET amount = 2070000, amount_cdf = 2070000 WHERE shift_id = :s',
            'DELETE FROM cash_handovers WHERE shift_id = :s',
            'UPDATE meter_readings SET index_value = 393 WHERE shift_id = :s',
            'DELETE FROM meter_readings WHERE shift_id = :s',
            'UPDATE shifts SET variance_amount = 0, received_amount = expected_amount WHERE id = :s',
            "UPDATE shifts SET status = 'open' WHERE id = :s",
            'DELETE FROM shifts WHERE id = :s',
            "UPDATE audit_log SET summary = 'effacé' WHERE entity_id = :s",
            'DELETE FROM audit_log WHERE entity_id = :s',
        ] as $sql) {
            try {
                $this->db->execute($sql, ['s' => $shift]);
                self::fail("Réécriture acceptée par la base : $sql");
            } catch (\PDOException $e) {
                self::assertContains((string) $e->getCode(), ['45000', '23000'], $sql);
            }
        }
        self::assertSame($before, $snapshot());
        $this->assertCashVariance($shift, 'kolwezi', 'attendant_k1', -20000);

        // La seule voie prévue reste ouverte : justifier l'écart, par une nouvelle ligne, sans toucher au poste.
        $this->service($this->id('manager_k'), CashControl::class)->justify($shift, ['amount' => '20000', 'reason' => 'Billets refusés par la banque']);
        // La justification porte le signe de l'écart qu'elle explique (manquant : négatif).
        self::assertSame([$this->id('kolwezi'), $shift, $this->id('manager_k'), -20000], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($this->row('SELECT station_id, shift_id, created_by, amount FROM variance_justifications'))));
        self::assertSame(-20000, $this->figures($shift)[2], 'L\'écart constaté reste au dossier');
    }
}
