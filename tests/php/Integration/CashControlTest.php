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
use Petra\Core\ValidationException;
use Petra\Services\CashControl;
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Tests\DatabaseTestCase;

/** Contrôle de caisse d'un poste, de la remise à l'écart, l'alerte et la justification. */
final class CashControlTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $attendant;
    private string $product;
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
        $this->nozzle = $this->createNozzle($this->station, $this->createTank($this->station, $this->product, 'C-ESS'), 'P-ESS');
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->product, 5000, self::DAY . ' 05:00:00', $this->owner);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    private function cash(string $userId): CashControl
    {
        return $this->service($userId, CashControl::class);
    }

    private function shifts(string $userId): ShiftService
    {
        return $this->service($userId, ShiftService::class);
    }

    /** Poste ouvert à 06h, index 0. */
    private function open(): string
    {
        return $this->shifts($this->manager)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$this->nozzle => '0']], self::at('06:00:00'));
    }

    /** Poste clôturé à 12h après $litres litres vendus à 5 000 FC. */
    private function closed(string $litres = '100'): string
    {
        $shift = $this->open();
        $this->shifts($this->attendant)->close($shift, ['final' => [$this->nozzle => $litres]], self::at('12:00:00'));

        return $shift;
    }

    private function shift(string $id): array
    {
        return $this->row('SELECT * FROM shifts WHERE id = :id', ['id' => $id]);
    }

    private function expectErrors(callable $call, string ...$keys): ValidationException
    {
        try {
            $call();
        } catch (ValidationException $e) {
            self::assertSame($keys, array_keys($e->errors));

            return $e;
        }
        self::fail('Validation attendue : ' . implode(', ', $keys));
    }

    // --- Écart ---------------------------------------------------------------------------

    /** Théorique 500 000, remis 485 000 → écart −15 000 CDF, alerte de déficit. */
    public function testShortageIsComputedFrozenAndRaisesAnAlert(): void
    {
        $shift = $this->closed('100');
        $this->cash($this->manager)->addHandover($shift, ['amount' => '485 000', 'currency' => 'CDF'], self::at('12:10:00'));

        $cash = $this->cash($this->manager)->forShift($this->shifts($this->manager)->find($shift));
        self::assertSame([500000, 500000, 485000, -15000], [$cash['theoretical_cdf'], $cash['expected_cdf'], $cash['remitted_cdf'], $cash['variance_cdf']]);

        $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));

        $row = $this->shift($shift);
        self::assertSame(['validated', 500000, 485000, -15000, 'CDF'], [$row['status'], (int) $row['expected_amount'], (int) $row['received_amount'], (int) $row['variance_amount'], $row['currency']]);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'cash_shortage'");
        self::assertSame(
            [$this->station, $shift, $this->attendant, -15000, 'CDF', '5000', 'critical', 'open'],
            [$alert['station_id'], $alert['shift_id'], $alert['user_id'], (int) $alert['amount'], $alert['currency'], $alert['threshold_value'], $alert['severity'], $alert['status']],
            'Écart chiffré, rattaché à la station, au poste et au pompiste'
        );
        self::assertStringContainsString("15\u{202F}000 FC", $alert['message']);
        $log = json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'shift' AND action = 'validate'"), true);
        self::assertSame([500000, 485000, -15000, 'cash_shortage'], [$log['expected_amount'], $log['received_amount'], $log['variance_amount'], $log['alert']]);
    }

    public function testNoAlertWithinTheStationToleranceAndSurplusBeyondIt(): void
    {
        // Déficit de 5 000 FC : égal au seuil par défaut, toléré.
        $shift = $this->closed('100');
        $this->cash($this->manager)->addHandover($shift, ['amount' => '495000', 'currency' => 'CDF'], self::at('12:10:00'));
        $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));
        self::assertSame(-5000, (int) $this->shift($shift)['variance_amount']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type LIKE 'cash_%'"));

        // Poste suivant : seuil d'excédent abaissé à 1 000 FC sur cette station, 2 500 FC de trop.
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (UUID(), :s, 'cash_surplus_tolerance_cdf', '1000')", ['s' => $this->station]);
        $next = $this->shifts($this->manager)->open($this->station, ['attendant_id' => $this->attendant], self::at('13:00:00'));
        $this->shifts($this->attendant)->close($next, ['final' => [$this->nozzle => '150']], self::at('18:00:00'));
        $this->cash($this->manager)->addHandover($next, ['amount' => '252500', 'currency' => 'CDF'], self::at('18:10:00'));
        $this->shifts($this->manager)->validate($next, [], self::at('18:30:00'));

        self::assertSame(2500, (int) $this->shift($next)['variance_amount']);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'cash_surplus'");
        self::assertSame([$next, 2500, '1000', 'warning'], [$alert['shift_id'], (int) $alert['amount'], $alert['threshold_value'], $alert['severity']]);
    }

    // --- Crédit et dépenses ---------------------------------------------------------------

    public function testCreditSalesAndJustifiedExpensesReduceTheExpectedCash(): void
    {
        try {
            $shift = $this->closed('100');
            $cash = $this->cash($this->manager);
            $cash->addCreditSale($shift, ['nozzle_id' => $this->nozzle, 'customer_name' => 'Mining Co', 'voucher_no' => 'B-0042', 'volume_l' => '20'], self::at('12:05:00'));
            $cash->addExpense($shift, ['category' => 'generator_fuel', 'description' => 'Gasoil du groupe', 'amount' => '15000', 'currency' => 'CDF'], self::at('12:06:00'));
            $cash->addExpense($shift, ['category' => 'transport', 'description' => 'Course banque', 'amount' => '5', 'currency' => 'USD'], self::at('12:07:00'));
            $cash->addHandover($shift, ['amount' => '373500', 'currency' => 'CDF'], self::at('12:10:00'));

            $result = $cash->forShift($this->shifts($this->manager)->find($shift));

            self::assertSame(500000, $result['theoretical_cdf']);
            self::assertSame(100000, $result['credit_cdf'], '20 L × 5 000 FC');
            self::assertSame(26500, $result['expenses_cdf'], '15 000 FC + 5 $ × 2 300');
            self::assertSame(373500, $result['expected_cdf']);
            self::assertSame(0, $result['variance_cdf']);
            self::assertSame(373500, (int) $this->shift($shift)['expected_amount'], 'Caisse attendue tenue à jour sur le poste clôturé');

            $credit = $this->row('SELECT * FROM credit_sales');
            self::assertSame(['Mining Co', 'B-0042', '20.00', 100000, 'CDF', $this->manager], [$credit['customer_name'], $credit['voucher_no'], $credit['volume_l'], (int) $credit['amount'], $credit['currency'], $credit['created_by']]);
            self::assertSame([500, 'USD', 11500], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($this->row("SELECT amount, currency, amount_cdf FROM cash_expenses WHERE currency = 'USD'"))));
            self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type IN ('credit_sale', 'cash_expense', 'cash_handover')"));

            // Le crédit ne peut pas dépasser les litres vendus ; une dépense doit être justifiée.
            $this->expectErrors(fn () => $cash->addCreditSale($shift, ['nozzle_id' => $this->nozzle, 'customer_name' => 'Autre', 'volume_l' => '80.01'], self::at('12:20:00')), 'volume_l');
            $this->expectErrors(fn () => $cash->addExpense($shift, ['category' => 'other', 'description' => '', 'amount' => '1000', 'currency' => 'CDF']), 'description');
            $this->expectErrors(fn () => $cash->addExpense($shift, ['category' => 'cadeau', 'description' => 'Divers', 'amount' => '1000', 'currency' => 'CDF']), 'category');
            $this->expectErrors(fn () => $cash->addExpense($shift, ['category' => 'other', 'description' => 'Divers', 'amount' => '10,50', 'currency' => 'CDF']), 'amount');
            $this->expectErrors(fn () => $cash->addHandover($shift, ['amount' => '-500', 'currency' => 'CDF']), 'amount');
            $this->expectErrors(fn () => $cash->addHandover($shift, ['amount' => 'rien', 'currency' => 'CDF']), 'amount');
            $this->expectErrors(fn () => $cash->addHandover($shift, ['amount' => '100', 'currency' => 'EUR']), 'currency');
            self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM credit_sales'));
            self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM cash_expenses'));
        } finally {
            $this->restoreReferenceData();
        }
    }

    // --- Deux tranches, francs et dollars ------------------------------------------------------

    /**
     * Poste à deux tranches : 100 L à 5 000 FC au taux 2 300, puis changement de taux à 10h (2 320) et 60 L.
     * Remises : 400 000 FC + 40 $ dans la tranche 1, 250 000 FC + 20 $ dans la tranche 2.
     */
    public function testMixedCdfAndUsdOverTwoSegmentsEndToEnd(): void
    {
        $shift = $this->open();
        $this->service($this->owner, PricingService::class)->publishRate($this->station, ['rate' => '2320', 'readings' => [$shift => [$this->nozzle => '100']]], self::at('10:00:00'));
        $this->shifts($this->attendant)->close($shift, ['final' => [$this->nozzle => '160']], self::at('12:00:00'));
        $segments = array_column($this->db->fetchAll('SELECT id, sequence FROM shift_segments WHERE shift_id = :s', ['s' => $shift]), 'id', 'sequence');
        $cash = $this->cash($this->manager);

        $cash->addHandover($shift, ['amount' => '400000', 'currency' => 'CDF', 'segment_id' => $segments[1]], self::at('12:05:00'));
        $cash->addHandover($shift, ['amount' => '40', 'currency' => 'USD', 'segment_id' => $segments[1]], self::at('12:06:00'));
        $cash->addHandover($shift, ['amount' => '250000', 'currency' => 'CDF', 'segment_id' => $segments[2]], self::at('12:07:00'));
        $cash->addHandover($shift, ['amount' => '20,00', 'currency' => 'USD', 'segment_id' => $segments[2]], self::at('12:08:00'));

        $result = $cash->forShift($this->shifts($this->manager)->find($shift));

        self::assertSame(800000, $result['theoretical_cdf'], '100 L + 60 L à 5 000 FC');
        self::assertSame([500000, 300000], array_column($result['segments'], 'theoretical_cdf'));
        self::assertSame([92000, 46400], array_column($result['segments'], 'remitted_usd_cdf'), '40 $ × 2 300 et 20 $ × 2 320');
        self::assertSame(6000, $result['remitted_usd']);
        self::assertSame(650000 + 92000 + 46400, $result['remitted_cdf']);
        self::assertSame(-11600, $result['variance_cdf']);

        $rows = $this->db->fetchAll("SELECT h.amount, h.amount_cdf, x.rate FROM cash_handovers h JOIN exchange_rates x ON x.id = h.exchange_rate_id WHERE h.currency = 'USD' ORDER BY h.handed_at");
        self::assertSame([[4000, 92000, 2300], [2000, 46400, 2320]], array_map(static fn (array $r): array => array_map('intval', array_values($r)), $rows), 'Chaque remise garde son taux et sa contre-valeur');

        $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));
        self::assertSame([800000, 788400, -11600], array_map('intval', array_values($this->row('SELECT expected_amount, received_amount, variance_amount FROM shifts WHERE id = :id', ['id' => $shift]))));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'cash_shortage'"));
    }

    public function testUnallocatedDollarsFollowTheStationRule(): void
    {
        $shift = $this->open();
        $this->service($this->owner, PricingService::class)->publishRate($this->station, ['rate' => '2320', 'readings' => [$shift => [$this->nozzle => '100']]], self::at('10:00:00'));
        $cash = $this->cash($this->manager);

        // Règle par défaut : taux de la dernière tranche.
        $cash->addHandover($shift, ['amount' => '100', 'currency' => 'USD'], self::at('11:00:00'));
        foreach (['first' => 230000, 'lowest' => 230000, 'highest' => 232000] as $rule => $expected) {
            $this->db->execute(
                "INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (UUID(), :s, 'usd_unallocated_rate', :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['s' => $this->station, 'v' => $rule]
            );
            $id = $this->cash($this->manager)->addHandover($shift, ['amount' => '100', 'currency' => 'USD'], self::at('11:0' . strlen($rule) . ':00'));
            self::assertSame($expected, (int) $this->value('SELECT amount_cdf FROM cash_handovers WHERE id = :id', ['id' => $id]), $rule);
        }
        self::assertSame(232000, (int) $this->value('SELECT amount_cdf FROM cash_handovers ORDER BY handed_at LIMIT 1'), 'Règle par défaut : dernière tranche');
        self::assertSame('station_rule:last', json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'cash_handover' ORDER BY created_at, id LIMIT 1"), true)['allocation']);

        // Une tranche d'un autre poste n'est pas acceptée.
        $this->expectErrors(fn () => $cash->addHandover($shift, ['amount' => '10', 'currency' => 'USD', 'segment_id' => '0190a7c2-6f3e-7b1a-9c4d-2e5f8a1b3c7d']), 'segment_id');
    }

    // --- Justification ------------------------------------------------------------------------

    public function testAJustificationIsANewLinkedRowAndNothingIsErased(): void
    {
        $shift = $this->closed('100');
        $this->cash($this->manager)->addHandover($shift, ['amount' => '485000', 'currency' => 'CDF'], self::at('12:10:00'));
        $this->expectErrors(fn () => $this->cash($this->manager)->justify($shift, ['amount' => '15000', 'reason' => 'Trop tôt']), 'shift');
        $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));
        $before = $this->shift($shift);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'cash_shortage'");

        $first = $this->cash($this->manager)->justify($shift, ['amount' => '10 000', 'reason' => 'Billet de 10 000 FC refusé car faux']);

        $row = $this->row('SELECT * FROM variance_justifications WHERE id = :id', ['id' => $first]);
        self::assertSame([$this->station, $shift, $alert['id'], -10000, 'CDF', $this->manager], [$row['station_id'], $row['shift_id'], $row['alert_id'], (int) $row['amount'], $row['currency'], $row['created_by']]);
        self::assertSame($before, $this->shift($shift), 'Le poste et son écart constaté ne changent pas');
        self::assertSame($alert, $this->row("SELECT * FROM alerts WHERE alert_type = 'cash_shortage'"), 'L\'alerte reste telle quelle');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM cash_handovers'), 'Aucune remise réécrite');

        $cash = $this->cash($this->manager)->forShift($this->shifts($this->manager)->find($shift));
        self::assertSame([-15000, -10000, -5000], [$cash['variance_cdf'], $cash['justified_cdf'], $cash['residual_cdf']]);

        // On ne justifie pas plus que ce qui reste à expliquer.
        $error = $this->expectErrors(fn () => $this->cash($this->manager)->justify($shift, ['amount' => '5001', 'reason' => 'Erreur de rendu monnaie']), 'amount');
        self::assertStringContainsString("5\u{202F}000 FC", $error->errors['amount']);
        $this->expectErrors(fn () => $this->cash($this->manager)->justify($shift, ['amount' => '1000', 'reason' => 'ok']), 'reason');
        $this->cash($this->manager)->justify($shift, ['amount' => '5000', 'reason' => 'Erreur de rendu monnaie']);
        $this->expectErrors(fn () => $this->cash($this->manager)->justify($shift, ['amount' => '1', 'reason' => 'Plus rien à expliquer']), 'amount');

        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM variance_justifications'));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'variance_justification'"));
        foreach (['UPDATE variance_justifications SET amount = 0', 'DELETE FROM variance_justifications', 'UPDATE cash_handovers SET amount = 500000, amount_cdf = 500000', 'DELETE FROM cash_expenses'] as $sql) {
            if (str_contains($sql, 'cash_expenses')) {
                $this->db->execute(
                    "INSERT INTO cash_expenses (id, station_id, category, description, amount, currency, amount_cdf, spent_at, created_by) VALUES (UUID(), :s, 'other', 'x', 1, 'CDF', 1, NOW(), :u)",
                    ['s' => $this->station, 'u' => $this->owner]
                );
            }
            try {
                $this->db->execute($sql);
                self::fail("Accepté : $sql");
            } catch (PDOException $e) {
                self::assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
    }

    public function testASurplusIsJustifiedWithAPositiveAmount(): void
    {
        $shift = $this->closed('100');
        $this->cash($this->manager)->addHandover($shift, ['amount' => '520000', 'currency' => 'CDF'], self::at('12:10:00'));
        $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));

        $id = $this->cash($this->manager)->justify($shift, ['amount' => '20000', 'reason' => 'Client revenu payer un ancien bon']);

        self::assertSame(20000, (int) $this->value('SELECT amount FROM variance_justifications WHERE id = :id', ['id' => $id]));
        self::assertSame(0, $this->cash($this->manager)->forShift($this->shifts($this->manager)->find($shift))['residual_cdf']);
    }

    // --- Verrouillage et droits ------------------------------------------------------------------

    public function testCashIsFrozenOnceTheShiftIsValidated(): void
    {
        try {
            $shift = $this->closed('100');
            $this->cash($this->manager)->addHandover($shift, ['amount' => '500000', 'currency' => 'CDF'], self::at('12:10:00'));
            $this->shifts($this->manager)->validate($shift, [], self::at('12:30:00'));
            $cash = $this->cash($this->manager);

            $this->expectErrors(fn () => $cash->addHandover($shift, ['amount' => '1000', 'currency' => 'CDF']), 'shift');
            $this->expectErrors(fn () => $cash->addCreditSale($shift, ['nozzle_id' => $this->nozzle, 'customer_name' => 'Tardif', 'volume_l' => '1']), 'shift');
            $this->expectErrors(fn () => $cash->addExpense($shift, ['category' => 'other', 'description' => 'Tardive', 'amount' => '1000', 'currency' => 'CDF']), 'shift');
            self::assertSame(0, (int) $this->shift($shift)['variance_amount']);
            self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM cash_handovers'));
        } finally {
            $this->restoreReferenceData();
        }
    }

    public function testCashOfAnotherStationOrAnotherAttendantIsOutOfReach(): void
    {
        $shift = $this->closed('100');
        $other = $this->createStation($this->org, 'B');
        $managerB = $this->createUser($this->org, 'manager', [$other]);
        $colleague = $this->createUser($this->org, 'attendant', [$this->station]);

        foreach ([$managerB, $colleague] as $user) {
            foreach ([
                fn () => $this->cash($user)->addHandover($shift, ['amount' => '500000', 'currency' => 'CDF']),
                fn () => $this->cash($user)->justify($shift, ['amount' => '1', 'reason' => 'Intrusion']),
                fn () => $this->cash($user)->forShift($this->shifts($user)->find($shift)),
            ] as $call) {
                try {
                    $call();
                    self::fail('Caisse accessible');
                } catch (HttpException $e) {
                    self::assertSame(403, $e->status);
                }
            }
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM cash_handovers'));
    }
}
