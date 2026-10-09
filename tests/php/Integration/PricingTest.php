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
use Petra\Domain\Money;
use Petra\Services\PriceResolver;
use Petra\Services\PricingService;
use Petra\Services\RateResolver;
use Tests\DatabaseTestCase;

/**
 * Principes 4 et 5 : prix et taux versionnés, chaque calcul utilise la version en vigueur à l'instant
 * de l'opération ; un changement pendant un poste impose un relevé intermédiaire (nouvelle tranche).
 */
final class PricingTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';

    private string $org;
    private string $station;
    private string $other;
    private string $owner;
    private string $essence;
    private string $mazout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->other = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->essence = $this->createProduct($this->station, 'ESS', 'Essence');
        $this->mazout = $this->createProduct($this->station, 'MAZ', 'Mazout');
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    private function pricing(): PricingService
    {
        return $this->service($this->owner, PricingService::class);
    }

    private function rates(): RateResolver
    {
        return $this->service($this->owner, RateResolver::class);
    }

    private function prices(): PriceResolver
    {
        return $this->service($this->owner, PriceResolver::class);
    }

    // --- Taux : version en vigueur à une date donnée ------------------------------------

    /** Taux 2 300 à 8h, 2 320 à 14h : une opération de 10h reste à 2 300. */
    public function testAnOperationKeepsTheRateInForceAtItsTime(): void
    {
        $this->pricing()->publishRate($this->station, ['rate' => '2300'], self::at('08:00:00'));
        $this->pricing()->publishRate($this->station, ['rate' => '2320'], self::at('14:00:00'));
        $rates = $this->rates();

        self::assertSame(2300, $rates->at($this->station, self::at('10:00:00'))['rate'], 'Opération de 10h');
        self::assertSame(2300, $rates->at($this->station, self::DAY . ' 08:00:00')['rate'], 'La version prend effet à son instant exact');
        self::assertSame(2300, $rates->at($this->station, self::DAY . ' 13:59:59')['rate']);
        self::assertSame(2320, $rates->at($this->station, self::DAY . ' 14:00:00')['rate']);
        self::assertSame(2320, $rates->at($this->station, self::at('18:30:00'))['rate']);
        self::assertSame(2320, $rates->at($this->station)['rate'], 'Sans date : le taux actuel');
        self::assertNull($rates->at($this->station, self::DAY . ' 07:59:59'), 'Aucun taux avant la première version');

        // 100 $ remis à 10h valent 230 000 FC, même calculés après le changement de 14h.
        self::assertSame(230000, $rates->toCdf($this->station, 10000, self::at('10:00:00')));
        self::assertSame(232000, $rates->toCdf($this->station, 10000, self::at('15:00:00')));

        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM exchange_rates WHERE station_id = :s', ['s' => $this->station]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM exchange_rates WHERE station_id = :s', ['s' => $this->other]), 'Le taux est propre à la station');
        self::assertNull($rates->at($this->other, self::at('10:00:00')));
    }

    public function testTheRateCanChangeSeveralTimesADay(): void
    {
        foreach (['07:00:00' => 2290, '09:30:00' => 2300, '11:15:00' => 2310, '14:00:00' => 2320, '16:45:00' => 2315] as $time => $rate) {
            $this->pricing()->publishRate($this->station, ['rate' => (string) $rate], self::at($time));
        }

        self::assertSame(5, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));
        self::assertSame(
            [2290, 2300, 2310, 2320, 2315],
            array_map(fn (string $t): int => $this->rates()->at($this->station, self::at($t))['rate'], ['08:00:00', '10:00:00', '12:00:00', '15:00:00', '17:00:00'])
        );
    }

    public function testResolverRefusesToGuessWhenNoVersionExists(): void
    {
        foreach ([fn () => $this->rates()->require($this->station), fn () => $this->prices()->require($this->essence)] as $call) {
            try {
                $call();
                self::fail('Une valeur a été inventée');
            } catch (HttpException $e) {
                self::assertSame(409, $e->status);
            }
        }
    }

    public function testUsdConversionRoundsToTheNearestFranc(): void
    {
        self::assertSame(2300, Money::usdCentsToCdf(100, 2300));
        self::assertSame(23, Money::usdCentsToCdf(1, 2300));
        self::assertSame(12, Money::usdCentsToCdf(1, 1150), '11,5 FC arrondi à 12');
        self::assertSame(11, Money::usdCentsToCdf(1, 1149));
        self::assertSame(-12, Money::usdCentsToCdf(-1, 1150), 'Arrondi symétrique pour un écart négatif');
        self::assertSame(0, Money::usdCentsToCdf(0, 2300));
        self::assertSame(230000000000, Money::usdCentsToCdf(10000000000, 2300), 'Grands montants sans perte de précision');
    }

    // --- Prix ---------------------------------------------------------------------------

    public function testPriceVersionsPerProductAndCurrency(): void
    {
        $first = $this->pricing()->publishPrice($this->essence, ['unit_price' => '3450', 'currency' => 'CDF'], self::at('06:00:00'));
        $this->pricing()->publishPrice($this->mazout, ['unit_price' => '1,25', 'currency' => 'USD', 'note' => 'Prix en dollars'], self::at('06:00:00'));
        $second = $this->pricing()->publishPrice($this->essence, ['unit_price' => '3 500', 'currency' => 'CDF'], self::at('12:00:00'));

        $prices = $this->prices();
        self::assertEquals(['id' => $first, 'unit_price' => 3450, 'currency' => 'CDF'], array_intersect_key($prices->at($this->essence, self::at('11:59:59')), ['id' => 1, 'unit_price' => 1, 'currency' => 1]));
        self::assertSame($second, $prices->at($this->essence, self::at('12:00:00'))['id']);
        self::assertSame(3500, $prices->at($this->essence)['unit_price']);
        self::assertSame(['unit_price' => 125, 'currency' => 'USD'], array_intersect_key($prices->at($this->mazout, self::at('20:00:00')), ['unit_price' => 1, 'currency' => 1]), 'USD en cents');
        self::assertNull($prices->at($this->essence, self::at('05:00:00')));

        $row = $this->row('SELECT * FROM prices WHERE id = :id', ['id' => $second]);
        self::assertSame($this->owner, $row['created_by'], 'Auteur');
        self::assertSame(self::DAY . ' 12:00:00', $row['effective_at'], 'Date d\'effet');
        self::assertSame($this->station, $row['station_id']);
    }

    public function testAChangeIsANewVersionNeverAnUpdate(): void
    {
        $first = $this->pricing()->publishPrice($this->essence, ['unit_price' => '3450', 'currency' => 'CDF'], self::at('06:00:00'));
        $before = $this->row('SELECT * FROM prices WHERE id = :id', ['id' => $first]);

        $this->pricing()->publishPrice($this->essence, ['unit_price' => '3500', 'currency' => 'CDF'], self::at('12:00:00'));

        self::assertSame($before, $this->row('SELECT * FROM prices WHERE id = :id', ['id' => $first]), 'La version précédente est intacte');
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM prices WHERE product_id = :p', ['p' => $this->essence]));
        foreach (['UPDATE prices SET unit_price = 1', 'DELETE FROM prices', 'UPDATE exchange_rates SET rate = 1', 'DELETE FROM exchange_rates'] as $sql) {
            $this->createRate($this->station, 2300, self::DAY . ' 0' . random_int(1, 5) . ':' . random_int(10, 59) . ':' . random_int(10, 59), $this->owner);
            try {
                $this->db->execute($sql);
                self::fail("Accepté : $sql");
            } catch (PDOException $e) {
                self::assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
    }

    public function testEveryChangeRaisesAnAlertAndIsAuditedWithOldAndNewValues(): void
    {
        $this->pricing()->publishRate($this->station, ['rate' => '2300'], self::at('08:00:00'));
        $rateId = $this->pricing()->publishRate($this->station, ['rate' => '2320', 'note' => 'Marché'], self::at('14:00:00'));
        $this->pricing()->publishPrice($this->essence, ['unit_price' => '3450', 'currency' => 'CDF'], self::at('08:00:00'));
        $priceId = $this->pricing()->publishPrice($this->essence, ['unit_price' => '3500', 'currency' => 'CDF'], self::at('14:00:00'));

        $log = $this->row("SELECT * FROM audit_log WHERE entity_type = 'exchange_rate' AND entity_id = :id", ['id' => $rateId]);
        self::assertSame(['rate' => 2300, 'effective_at' => self::DAY . ' 08:00:00'], json_decode((string) $log['before_data'], true));
        self::assertSame(2320, json_decode((string) $log['after_data'], true)['rate']);
        self::assertSame([$this->owner, $this->station, 'publish'], [$log['created_by'], $log['station_id'], $log['action']]);

        $log = $this->row("SELECT * FROM audit_log WHERE entity_type = 'price' AND entity_id = :id", ['id' => $priceId]);
        self::assertSame(3450, json_decode((string) $log['before_data'], true)['unit_price']);
        self::assertSame(['unit_price' => 3500, 'effective_at' => self::DAY . ' 14:00:00'], array_intersect_key(json_decode((string) $log['after_data'], true), ['unit_price' => 1, 'effective_at' => 1]));
        self::assertStringContainsString("3\u{202F}450 FC → 3\u{202F}500 FC", $log['summary']);

        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'rate_change' AND entity_id = :id", ['id' => $rateId]);
        self::assertStringContainsString("2\u{202F}300 FC → 2\u{202F}320 FC", $alert['message']);
        self::assertSame([$this->station, $this->owner, 2320, 'CDF', 'info', 'open'], [$alert['station_id'], $alert['user_id'], (int) $alert['amount'], $alert['currency'], $alert['severity'], $alert['status']]);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'price_change' AND entity_id = :id", ['id' => $priceId]);
        self::assertStringContainsString("Essence : 3\u{202F}450 FC → 3\u{202F}500 FC", $alert['message']);

        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type IN ('rate_change', 'price_change')"), 'Une alerte par modification, première version comprise');
        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'publish'"));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidPrices(): array
    {
        return [
            'franc avec décimales' => [['unit_price' => '3450,50', 'currency' => 'CDF'], 'unit_price'],
            'nul' => [['unit_price' => '0', 'currency' => 'CDF'], 'unit_price'],
            'négatif' => [['unit_price' => '-10', 'currency' => 'CDF'], 'unit_price'],
            'trois décimales' => [['unit_price' => '1.255', 'currency' => 'USD'], 'unit_price'],
            'texte' => [['unit_price' => 'cher', 'currency' => 'CDF'], 'unit_price'],
            'devise inconnue' => [['unit_price' => '10', 'currency' => 'EUR'], 'currency'],
            'valeur déjà en vigueur' => [['unit_price' => '3450', 'currency' => 'CDF'], 'unit_price'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPrices')]
    public function testInvalidPricesAreRejected(array $input, string $field): void
    {
        $this->createPrice($this->station, $this->essence, 3450, self::DAY . ' 06:00:00', $this->owner);
        try {
            $this->pricing()->publishPrice($this->essence, $input, self::at('12:00:00'));
            self::fail('Prix accepté');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM prices'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM alerts'));
    }

    public function testAVersionCannotBeInsertedBeforeAnExistingOne(): void
    {
        $this->pricing()->publishRate($this->station, ['rate' => '2300'], self::at('14:00:00'));

        foreach (['08:00:00', '14:00:00'] as $time) {
            try {
                $this->pricing()->publishRate($this->station, ['rate' => '2250'], self::at($time));
                self::fail('Le passé a été réécrit');
            } catch (ValidationException $e) {
                self::assertSame(['effective_at'], array_keys($e->errors));
            }
        }
        foreach (['0', '-5', '23,5', ''] as $rate) {
            try {
                $this->pricing()->publishRate($this->station, ['rate' => $rate], self::at('15:00:00'));
                self::fail("Taux accepté : $rate");
            } catch (ValidationException $e) {
                self::assertSame(['rate'], array_keys($e->errors));
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));
    }

    // --- Poste ouvert : relevé intermédiaire et nouvelle tranche -----------------------------

    /**
     * Station avec un poste ouvert à 06h : pistolet Essence (index 1 000) et pistolet Mazout (index 5 000).
     *
     * @return array{shift: string, segment: string, essence: string, mazout: string, rate: string, priceEss: string, priceMaz: string, attendant: string}
     */
    private function openStation(): array
    {
        $rate = $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $priceEss = $this->createPrice($this->station, $this->essence, 3450, self::DAY . ' 05:00:00', $this->owner);
        $priceMaz = $this->createPrice($this->station, $this->mazout, 3440, self::DAY . ' 05:00:00', $this->owner);
        $nozzleEss = $this->createNozzle($this->station, $this->createTank($this->station, $this->essence, 'C-ESS'), 'P-ESS');
        $nozzleMaz = $this->createNozzle($this->station, $this->createTank($this->station, $this->mazout, 'C-MAZ'), 'P-MAZ');
        $attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $shift = $this->openShift($this->station, $attendant, $rate, [$nozzleEss => ['1000.00', $priceEss], $nozzleMaz => ['5000.00', $priceMaz]], self::DAY . ' 06:00:00', $this->owner);

        return $shift + ['essence' => $nozzleEss, 'mazout' => $nozzleMaz, 'rate' => $rate, 'priceEss' => $priceEss, 'priceMaz' => $priceMaz, 'attendant' => $attendant];
    }

    /** @return list<array<string, mixed>> relevés d'une tranche, par pistolet puis par type */
    private function readings(string $segmentId): array
    {
        return $this->db->fetchAll(
            'SELECT n.code, mr.reading_type, mr.index_value, mr.price_id, mr.read_at, mr.created_by FROM meter_readings mr JOIN nozzles n ON n.id = mr.nozzle_id
              WHERE mr.segment_id = :s ORDER BY n.code, mr.reading_type',
            ['s' => $segmentId]
        );
    }

    public function testRateChangeDuringAShiftRequiresIntermediateReadings(): void
    {
        $s = $this->openStation();

        foreach ([null, [], [$s['shift'] => [$s['essence'] => '1250']], [$s['shift'] => [$s['essence'] => '1250', $s['mazout'] => '']]] as $readings) {
            try {
                $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => $readings], self::at('14:00:00'));
                self::fail('Taux modifié sans relevé intermédiaire');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('reading_' . $s['mazout'], $e->errors);
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'), 'Rien n\'est enregistré');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shift_segments'));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM meter_readings'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM alerts'));
    }

    public function testRateChangeClosesTheCurrentSegmentAndOpensANewOne(): void
    {
        $s = $this->openStation();

        $newRate = $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => [$s['shift'] => [$s['essence'] => '1250,5', $s['mazout'] => '5400']]], self::at('14:00:00'));

        $segments = $this->db->fetchAll('SELECT * FROM shift_segments WHERE shift_id = :s ORDER BY sequence', ['s' => $s['shift']]);
        self::assertCount(2, $segments);
        self::assertSame([$s['segment'], 1, $s['rate']], [$segments[0]['id'], (int) $segments[0]['sequence'], $segments[0]['exchange_rate_id']], 'La première tranche garde le taux de 2 300');
        self::assertSame([2, 'rate_change', self::DAY . ' 14:00:00', $newRate, $this->owner], [
            (int) $segments[1]['sequence'], $segments[1]['reason'], $segments[1]['started_at'], $segments[1]['exchange_rate_id'], $segments[1]['created_by'],
        ]);

        // Tranche 1 : ouverture inchangée + fermeture aux index relevés, au prix de la tranche.
        self::assertSame([
            ['code' => 'P-ESS', 'reading_type' => 'opening', 'index_value' => '1000.00', 'price_id' => $s['priceEss'], 'read_at' => self::DAY . ' 06:00:00', 'created_by' => $this->owner],
            ['code' => 'P-ESS', 'reading_type' => 'closing', 'index_value' => '1250.50', 'price_id' => $s['priceEss'], 'read_at' => self::DAY . ' 14:00:00', 'created_by' => $this->owner],
            ['code' => 'P-MAZ', 'reading_type' => 'opening', 'index_value' => '5000.00', 'price_id' => $s['priceMaz'], 'read_at' => self::DAY . ' 06:00:00', 'created_by' => $this->owner],
            ['code' => 'P-MAZ', 'reading_type' => 'closing', 'index_value' => '5400.00', 'price_id' => $s['priceMaz'], 'read_at' => self::DAY . ' 14:00:00', 'created_by' => $this->owner],
        ], $this->readings($s['segment']));
        // Tranche 2 : ouverte aux mêmes index, prix inchangés.
        self::assertSame([
            ['code' => 'P-ESS', 'reading_type' => 'opening', 'index_value' => '1250.50', 'price_id' => $s['priceEss'], 'read_at' => self::DAY . ' 14:00:00', 'created_by' => $this->owner],
            ['code' => 'P-MAZ', 'reading_type' => 'opening', 'index_value' => '5400.00', 'price_id' => $s['priceMaz'], 'read_at' => self::DAY . ' 14:00:00', 'created_by' => $this->owner],
        ], $this->readings($segments[1]['id']));

        // Les dollars remis dans chaque tranche se convertissent au taux de leur tranche.
        $rates = $this->rates();
        self::assertSame(2300, $rates->at($this->station, self::at('10:00:00'))['rate']);
        self::assertSame($s['rate'], $rates->at($this->station, self::at('10:00:00'))['id']);
        self::assertSame($newRate, $rates->at($this->station, self::at('14:00:01'))['id']);
        self::assertSame(1, json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'exchange_rate'"), true)['segments_opened']);
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $s['shift']]), 'Le poste reste ouvert');
    }

    public function testPriceChangeOpensASegmentAtTheNewPriceForThatProductOnly(): void
    {
        $s = $this->openStation();

        $newPrice = $this->pricing()->publishPrice(
            $this->essence,
            ['unit_price' => '3500', 'currency' => 'CDF', 'readings' => [$s['shift'] => [$s['essence'] => '1300', $s['mazout'] => '5200']]],
            self::at('11:00:00')
        );

        $second = $this->row('SELECT * FROM shift_segments WHERE shift_id = :s AND sequence = 2', ['s' => $s['shift']]);
        self::assertSame(['price_change', $s['rate']], [$second['reason'], $second['exchange_rate_id']], 'Le taux ne change pas');
        self::assertSame(
            ['P-ESS' => $newPrice, 'P-MAZ' => $s['priceMaz']],
            array_column($this->readings($second['id']), 'price_id', 'code'),
            'Nouveau prix pour l\'essence, prix inchangé pour le mazout'
        );
        self::assertSame(['P-ESS' => $s['priceEss'], 'P-MAZ' => $s['priceMaz']], array_column(array_filter($this->readings($s['segment']), static fn (array $r): bool => $r['reading_type'] === 'closing'), 'price_id', 'code'));

        // Volume vendu à l'ancien prix : 1 300 − 1 000 = 300 L à 3 450 FC ; la suite se vendra à 3 500 FC.
        self::assertSame(3450, $this->prices()->at($this->essence, self::at('10:59:59'))['unit_price']);
        self::assertSame(3500, $this->prices()->at($this->essence, self::at('11:00:00'))['unit_price']);
    }

    public function testAShiftThatDoesNotSellTheProductIsNotSplit(): void
    {
        $s = $this->openStation();
        $gasoil = $this->createProduct($this->station, 'GPL', 'Pétrole lampant');
        $this->createPrice($this->station, $gasoil, 2000, self::DAY . ' 05:00:00', $this->owner);

        $this->pricing()->publishPrice($gasoil, ['unit_price' => '2100', 'currency' => 'CDF'], self::at('11:00:00'));

        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $s['shift']]));
        self::assertSame(2100, $this->prices()->at($gasoil, self::at('12:00:00'))['unit_price']);
    }

    public function testOnlyTheShiftOfThatStationIsSplit(): void
    {
        $s = $this->openStation();
        // Poste ouvert sur l'autre station : il n'est pas concerné par le taux de celle-ci.
        $rateB = $this->createRate($this->other, 2300, self::DAY . ' 05:00:00', $this->owner);
        $productB = $this->createProduct($this->other);
        $priceB = $this->createPrice($this->other, $productB, 3450, self::DAY . ' 05:00:00', $this->owner);
        $nozzleB = $this->createNozzle($this->other, $this->createTank($this->other, $productB), 'P-B');
        $shiftB = $this->openShift($this->other, $this->createUser($this->org, 'attendant', [$this->other]), $rateB, [$nozzleB => ['10.00', $priceB]], self::DAY . ' 06:00:00', $this->owner);

        $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => [
            $s['shift'] => [$s['essence'] => '1100', $s['mazout'] => '5100'],
        ]], self::at('14:00:00'));

        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $s['shift']]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $shiftB['shift']]));
        self::assertSame(2300, $this->rates()->at($this->other, self::at('15:00:00'))['rate']);
    }

    public function testIntermediateReadingsCannotGoBelowTheOpeningIndex(): void
    {
        $s = $this->openStation();

        foreach (['999.99', 'abc', '-1', '1000.001'] as $bad) {
            try {
                $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => [$s['shift'] => [$s['essence'] => $bad, $s['mazout'] => '5000']]], self::at('14:00:00'));
                self::fail("Index accepté : $bad");
            } catch (ValidationException $e) {
                self::assertSame(['reading_' . $s['essence']], array_keys($e->errors), $bad);
            }
        }
        // Un index égal à l'ouverture est valable : rien n'a été vendu sur ce pistolet.
        $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => [$s['shift'] => [$s['essence'] => '1000', $s['mazout'] => '5000']]], self::at('14:00:00'));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM shift_segments'));
    }

    public function testASecondChangeSplitsFromTheLatestSegment(): void
    {
        $s = $this->openStation();
        $this->pricing()->publishRate($this->station, ['rate' => '2320', 'readings' => [$s['shift'] => [$s['essence'] => '1200', $s['mazout'] => '5200']]], self::at('10:00:00'));

        try {
            $this->pricing()->publishRate($this->station, ['rate' => '2330', 'readings' => [$s['shift'] => [$s['essence'] => '1100', $s['mazout'] => '5300']]], self::at('15:00:00'));
            self::fail('Index inférieur à celui de la tranche en cours');
        } catch (ValidationException $e) {
            self::assertStringContainsString('1200.00', $e->errors['reading_' . $s['essence']]);
        }
        $this->pricing()->publishRate($this->station, ['rate' => '2330', 'readings' => [$s['shift'] => [$s['essence'] => '1350', $s['mazout'] => '5300']]], self::at('15:00:00'));

        self::assertSame([1, 2, 3], array_map('intval', array_column($this->db->fetchAll('SELECT sequence FROM shift_segments WHERE shift_id = :s ORDER BY sequence', ['s' => $s['shift']]), 'sequence')));
        self::assertSame([2300, 2320, 2330], array_map('intval', array_column($this->db->fetchAll(
            'SELECT x.rate FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id WHERE sg.shift_id = :s ORDER BY sg.sequence',
            ['s' => $s['shift']]
        ), 'rate')));
    }

    // --- Cloisonnement -----------------------------------------------------------------------

    public function testAManagerCannotPublishOrResolveForAnotherStation(): void
    {
        $manager = $this->createUser($this->org, 'manager', [$this->other]);
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->essence, 3450, self::DAY . ' 05:00:00', $this->owner);

        foreach ([
            fn () => $this->service($manager, PricingService::class)->publishRate($this->station, ['rate' => '1'], self::at('14:00:00')),
            fn () => $this->service($manager, PricingService::class)->publishPrice($this->essence, ['unit_price' => '1', 'currency' => 'CDF'], self::at('14:00:00')),
            fn () => $this->service($manager, RateResolver::class)->at($this->station),
            fn () => $this->service($manager, PriceResolver::class)->at($this->essence),
        ] as $call) {
            try {
                $call();
                self::fail('Accès accepté');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM exchange_rates'));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM prices'));
    }
}
