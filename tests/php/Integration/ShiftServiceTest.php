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
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Tests\DatabaseTestCase;

/** Cycle d'un poste : ouverture, sorties non vendues, clôture par le pompiste, contre-validation par le gérant. */
final class ShiftServiceTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $attendant;
    private string $essence;
    private string $mazout;
    private string $nozzleEss;
    private string $nozzleMaz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station]);
        $this->attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $this->essence = $this->createProduct($this->station, 'ESS', 'Essence');
        $this->mazout = $this->createProduct($this->station, 'MAZ', 'Mazout');
        $this->nozzleEss = $this->createNozzle($this->station, $this->createTank($this->station, $this->essence, 'C-ESS'), 'P-ESS');
        $this->nozzleMaz = $this->createNozzle($this->station, $this->createTank($this->station, $this->mazout, 'C-MAZ'), 'P-MAZ');
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->essence, 3450, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->mazout, 3440, self::DAY . ' 05:00:00', $this->owner);
    }

    private static function at(string $time, string $day = self::DAY): DateTimeImmutable
    {
        return new DateTimeImmutable($day . ' ' . $time);
    }

    private function as(string $userId): ShiftService
    {
        return $this->service($userId, ShiftService::class);
    }

    /** Ouvre le premier poste de la station (mise en service : index saisis). */
    private function openFirst(string $ess = '1000', string $maz = '5000', string $time = '06:00:00'): string
    {
        return $this->as($this->manager)->open(
            $this->station,
            ['attendant_id' => $this->attendant, 'label' => 'Matin', 'opening' => [$this->nozzleEss => $ess, $this->nozzleMaz => $maz]],
            self::at($time)
        );
    }

    /** @return array<string, string> index par code de pistolet */
    private function readings(string $shiftId, string $type): array
    {
        return array_column($this->db->fetchAll(
            'SELECT n.code, mr.index_value FROM meter_readings mr JOIN nozzles n ON n.id = mr.nozzle_id WHERE mr.shift_id = :s AND mr.reading_type = :t ORDER BY n.code',
            ['s' => $shiftId, 't' => $type]
        ), 'index_value', 'code');
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

    // --- Ouverture ---------------------------------------------------------------------

    public function testOpeningCreatesTheShiftItsFirstSegmentAndOpeningReadings(): void
    {
        $shift = $this->openFirst();

        $row = $this->row('SELECT * FROM shifts WHERE id = :id', ['id' => $shift]);
        self::assertSame(['open', $this->station, $this->attendant, self::DAY, 'Matin', self::DAY . ' 06:00:00', $this->manager], [
            $row['status'], $row['station_id'], $row['attendant_id'], $row['business_date'], $row['label'], $row['opened_at'], $row['opened_by'],
        ]);
        $segment = $this->row('SELECT sg.*, x.rate FROM shift_segments sg JOIN exchange_rates x ON x.id = sg.exchange_rate_id WHERE sg.shift_id = :s', ['s' => $shift]);
        self::assertSame([1, 'shift_open', 2300], [(int) $segment['sequence'], $segment['reason'], (int) $segment['rate']]);
        self::assertSame(['P-ESS' => '1000.00', 'P-MAZ' => '5000.00'], $this->readings($shift, 'opening'));
        self::assertSame([3450, 3440], array_map('intval', array_column($this->db->fetchAll(
            "SELECT pr.unit_price FROM meter_readings mr JOIN prices pr ON pr.id = mr.price_id JOIN nozzles n ON n.id = mr.nozzle_id WHERE mr.shift_id = :s ORDER BY n.code",
            ['s' => $shift]
        ), 'unit_price')), 'Chaque relevé porte le prix en vigueur à l\'ouverture');
        $log = $this->row("SELECT * FROM audit_log WHERE entity_type = 'shift' AND action = 'open'");
        self::assertSame([$this->manager, $this->station, $shift], [$log['created_by'], $log['station_id'], $log['entity_id']]);
    }

    public function testOnlyOneShiftCanBeOpenPerStation(): void
    {
        $this->openFirst();
        $second = $this->createUser($this->org, 'attendant', [$this->station]);

        $error = $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $second], self::at('07:00:00')), 'blocker_0');
        self::assertStringContainsString('déjà tenus par un poste ouvert', $error->errors['blocker_0']);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shifts'));

        // La base interdit qu'un pompiste tienne deux postes ouverts sur la station.
        try {
            $this->openShift($this->station, $this->attendant, (string) $this->value('SELECT id FROM exchange_rates'), [], self::DAY . ' 07:00:00', $this->owner);
            self::fail('Deux postes ouverts pour le même pompiste');
        } catch (PDOException $e) {
            self::assertStringContainsString('uq_shf_one_open', $e->getMessage());
        }

        // Une autre station ouvre son propre poste.
        $other = $this->createStation($this->org, 'B');
        $product = $this->createProduct($other);
        $nozzle = $this->createNozzle($other, $this->createTank($other, $product), 'P-B');
        $this->createRate($other, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($other, $product, 3450, self::DAY . ' 05:00:00', $this->owner);
        $attendantB = $this->createUser($this->org, 'attendant', [$other]);
        $this->as($this->owner)->open($other, ['attendant_id' => $attendantB, 'opening' => [$nozzle => '10']], self::at('06:00:00'));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM shifts WHERE status = 'open'"));
    }

    public function testOpeningIndexIsCarriedOverFromThePreviousFinalIndexAndCannotBeChanged(): void
    {
        $first = $this->openFirst();
        $this->as($this->attendant)->close($first, ['final' => [$this->nozzleEss => '1250.50', $this->nozzleMaz => '5400']], self::at('12:00:00'));

        $context = $this->as($this->manager)->openingContext($this->station, self::at('12:05:00'));
        self::assertSame(['P-ESS' => '1250.50', 'P-MAZ' => '5400.00'], array_column($context['nozzles'], 'last_index', 'code'));
        self::assertSame('Après-midi', $context['slot'], 'Poste proposé d\'après les horaires de la station');

        // Une valeur envoyée pour un pistolet déjà relevé est ignorée.
        $second = $this->as($this->manager)->open(
            $this->station,
            ['attendant_id' => $this->attendant, 'label' => 'Après-midi', 'opening' => [$this->nozzleEss => '1', $this->nozzleMaz => '999999']],
            self::at('12:05:00')
        );

        self::assertSame(['P-ESS' => '1250.50', 'P-MAZ' => '5400.00'], $this->readings($second, 'opening'));
        self::assertSame(['P-ESS' => '1250.50', 'P-MAZ' => '5400.00'], $this->readings($first, 'closing'));
    }

    public function testANewNozzleNeedsItsFirstIndexAndAnExistingOneKeepsItsOwn(): void
    {
        $first = $this->openFirst();
        $this->as($this->attendant)->close($first, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00'));
        $newNozzle = $this->createNozzle($this->station, (string) $this->value("SELECT id FROM tanks WHERE code = 'C-ESS'"), 'P-ESS-2');

        $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant], self::at('12:05:00')), 'opening_' . $newNozzle);
        $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$newNozzle => '-5']], self::at('12:05:00')), 'opening_' . $newNozzle);

        $second = $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant, 'opening' => [$newNozzle => '42,5']], self::at('12:05:00'));
        self::assertSame(['P-ESS' => '1100.00', 'P-ESS-2' => '42.50', 'P-MAZ' => '5100.00'], $this->readings($second, 'opening'));
    }

    public function testOpeningIsBlockedWithoutPriceRateNozzleOrValidAttendant(): void
    {
        $outsider = $this->createUser($this->org, 'attendant', []);
        $opening = ['opening' => [$this->nozzleEss => '1', $this->nozzleMaz => '1']];
        $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $outsider] + $opening, self::at('06:00:00')), 'attendant_id');
        $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant, 'label' => 'Inconnu'] + $opening, self::at('06:00:00')), 'label');

        // Avant la première version de prix et de taux : rien n'est inventé.
        $error = $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant] + $opening, self::at('04:00:00')), 'blocker_0', 'blocker_1', 'blocker_2');
        self::assertStringContainsString('taux', $error->errors['blocker_0']);
        self::assertStringContainsString('Essence', $error->errors['blocker_1']);

        $this->db->execute('UPDATE nozzles SET is_active = 0');
        $error = $this->expectErrors(fn () => $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant], self::at('06:00:00')), 'blocker_0');
        self::assertStringContainsString('aucun pistolet', $error->errors['blocker_0']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM shifts'));
    }

    // --- Sorties non vendues et clôture ----------------------------------------------------

    public function testSoldLitresAreFinalMinusInitialMinusNonSaleOutputs(): void
    {
        $shift = $this->openFirst();
        $service = $this->as($this->attendant);
        $service->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'test', 'volume_l' => '10', 'reason' => 'Essai du matin'], self::at('07:00:00'));
        $service->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'tank_return', 'volume_l' => '2,5', 'reason' => 'Reversé en cuve'], self::at('07:05:00'));
        $service->addOutput($shift, ['nozzle_id' => $this->nozzleMaz, 'output_type' => 'internal_use', 'volume_l' => '20', 'reason' => 'Groupe électrogène'], self::at('09:00:00'));

        $summary = $service->close($shift, ['final' => [$this->nozzleEss => '1 250,50', $this->nozzleMaz => '5400']], self::at('12:00:00'));

        $lines = array_column($summary['segments'][0]['nozzles'], null, 'code');
        self::assertSame(['250.50', '12.50', '238.00', 821100], [$lines['P-ESS']['delivered_l'], $lines['P-ESS']['non_sale_l'], $lines['P-ESS']['sold_l'], $lines['P-ESS']['amount_cdf']]);
        self::assertSame(['400.00', '20.00', '380.00', 1307200], [$lines['P-MAZ']['delivered_l'], $lines['P-MAZ']['non_sale_l'], $lines['P-MAZ']['sold_l'], $lines['P-MAZ']['amount_cdf']]);
        self::assertSame(['618.00', '32.50', 2128300], [$summary['sold_l'], $summary['non_sale_l'], $summary['expected_cdf']]);

        $row = $this->row('SELECT * FROM shifts WHERE id = :id', ['id' => $shift]);
        self::assertSame(['closed', self::DAY . ' 12:00:00', $this->attendant, 2128300, null], [$row['status'], $row['closed_at'], $row['closed_by'], (int) $row['expected_amount'], $row['variance_amount']]);
        self::assertSame(['P-ESS' => '1250.50', 'P-MAZ' => '5400.00'], $this->readings($shift, 'closing'));
        $log = json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'shift' AND action = 'close'"), true);
        self::assertSame(['618.00', 2128300], [$log['sold_l'], $log['expected_amount']]);
        self::assertSame(3, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'non_sale_output'"));

        // Le résumé relu depuis la base donne les mêmes chiffres.
        self::assertSame($summary['expected_cdf'], $this->as($this->manager)->summary($this->as($this->manager)->find($shift))['expected_cdf']);
    }

    public function testFinalIndexBelowOpeningIsRefused(): void
    {
        $shift = $this->openFirst();
        $service = $this->as($this->attendant);

        $error = $this->expectErrors(fn () => $service->close($shift, ['final' => [$this->nozzleEss => '999.99', $this->nozzleMaz => '5400']], self::at('12:00:00')), 'final_' . $this->nozzleEss);
        self::assertStringContainsString('inférieur à son index initial (1000.00)', $error->errors['final_' . $this->nozzleEss]);
        $this->expectErrors(fn () => $service->close($shift, ['final' => [$this->nozzleEss => '1100']], self::at('12:00:00')), 'final_' . $this->nozzleMaz);
        $this->expectErrors(fn () => $service->close($shift, ['final' => [$this->nozzleEss => 'abc', $this->nozzleMaz => '5400.123']], self::at('12:00:00')), 'final_' . $this->nozzleEss, 'final_' . $this->nozzleMaz);
        $this->expectErrors(fn () => $service->close($shift, [], self::at('12:00:00')), 'final_' . $this->nozzleEss, 'final_' . $this->nozzleMaz);

        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
        self::assertSame([], $this->readings($shift, 'closing'), 'Aucun relevé final enregistré');
    }

    public function testNonSaleOutputsAboveTheDeliveredVolumeBlockTheClosing(): void
    {
        $shift = $this->openFirst();
        $service = $this->as($this->attendant);
        $service->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'calibration', 'volume_l' => '50', 'reason' => 'Étalonnage annuel'], self::at('08:00:00'));

        $error = $this->expectErrors(fn () => $service->close($shift, ['final' => [$this->nozzleEss => '1049.99', $this->nozzleMaz => '5000']], self::at('12:00:00')), 'final_' . $this->nozzleEss);
        self::assertStringContainsString('50.00 L', $error->errors['final_' . $this->nozzleEss]);

        $summary = $service->close($shift, ['final' => [$this->nozzleEss => '1050', $this->nozzleMaz => '5000']], self::at('12:00:00'));
        self::assertSame(['0.00', 0], [$summary['sold_l'], $summary['expected_cdf']]);
    }

    public function testOutputsAreValidatedAndOnlyOnAnOpenShift(): void
    {
        $shift = $this->openFirst();
        $service = $this->as($this->attendant);
        $valid = ['nozzle_id' => $this->nozzleEss, 'output_type' => 'test', 'volume_l' => '5', 'reason' => 'Essai'];
        $foreign = $this->createNozzle($this->station, (string) $this->value("SELECT id FROM tanks WHERE code = 'C-ESS'"), 'P-NEW');

        foreach ([['output_type' => 'vol'], ['volume_l' => '0'], ['volume_l' => '-3'], ['volume_l' => '1.234'], ['reason' => ''], ['nozzle_id' => $foreign]] as $override) {
            $this->expectErrors(fn () => $service->addOutput($shift, $override + $valid, self::at('07:00:00')), (string) array_key_first($override));
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM non_sale_outputs'));

        $service->close($shift, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00'));
        $this->expectErrors(fn () => $service->addOutput($shift, $valid, self::at('12:30:00')), 'shift');
        $this->expectErrors(fn () => $service->close($shift, ['final' => [$this->nozzleEss => '1200', $this->nozzleMaz => '5200']], self::at('12:30:00')), 'shift');
        self::assertSame(['P-ESS' => '1100.00', 'P-MAZ' => '5100.00'], $this->readings($shift, 'closing'), 'Les index clôturés ne sont pas réécrits');
    }

    // --- Passage à zéro ------------------------------------------------------------------------

    public function testDeclaredRolloverIsAcceptedThenMustBeConfirmedByTheManager(): void
    {
        $shift = $this->openFirst('999900', '5000');
        $attendant = $this->as($this->attendant);

        // Sans déclaration : refus. Déclaration incohérente (index reparti plus haut) : refus.
        $this->expectErrors(fn () => $attendant->close($shift, ['final' => [$this->nozzleEss => '150.25', $this->nozzleMaz => '5100']], self::at('12:00:00')), 'final_' . $this->nozzleEss);
        $this->expectErrors(fn () => $attendant->close($shift, ['final' => [$this->nozzleEss => '150.25', $this->nozzleMaz => '5100'], 'rollover' => [$this->nozzleMaz => '1', $this->nozzleEss => '1']], self::at('12:00:00')), 'final_' . $this->nozzleMaz);

        $summary = $attendant->close($shift, ['final' => [$this->nozzleEss => '150.25', $this->nozzleMaz => '5100'], 'rollover' => [$this->nozzleEss => '1']], self::at('12:00:00'));

        $line = array_column($summary['segments'][0]['nozzles'], null, 'code')['P-ESS'];
        self::assertSame(['250.25', true], [$line['sold_l'], $line['is_rollover']], '100 L jusqu\'au retour à zéro puis 150,25 L');
        self::assertSame(1, (int) $this->value("SELECT is_rollover FROM meter_readings WHERE shift_id = :s AND reading_type = 'closing' AND nozzle_id = :n", ['s' => $shift, 'n' => $this->nozzleEss]));
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'meter_rollover'");
        self::assertSame([$this->station, $shift, $this->attendant, 'warning'], [$alert['station_id'], $alert['shift_id'], $alert['user_id'], $alert['severity']]);
        self::assertStringContainsString('P-ESS', $alert['message']);

        // Le gérant doit confirmer explicitement.
        $manager = $this->as($this->manager);
        $error = $this->expectErrors(fn () => $manager->validate($shift, [], self::at('12:30:00')), 'confirm_rollover');
        self::assertStringContainsString('P-ESS', $error->errors['confirm_rollover']);
        self::assertSame('closed', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));

        $manager->validate($shift, ['confirm_rollover' => '1', 'note' => 'Compteur vérifié'], self::at('12:30:00'));
        self::assertSame(['validated', $this->manager, 'Compteur vérifié'], array_values($this->row('SELECT status, validated_by, note FROM shifts WHERE id = :id', ['id' => $shift])));
        self::assertSame(['P-ESS'], json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'shift' AND action = 'validate'"), true)['rollover_confirmed']);

        // Le poste suivant repart de l'index après passage à zéro.
        $next = $this->as($this->manager)->open($this->station, ['attendant_id' => $this->attendant], self::at('12:35:00'));
        self::assertSame(['P-ESS' => '150.25', 'P-MAZ' => '5100.00'], $this->readings($next, 'opening'));
    }

    // --- Contre-validation -------------------------------------------------------------------------

    public function testAttendantEntersAndTheManagerCounterValidates(): void
    {
        $shift = $this->openFirst();
        $this->expectErrors(fn () => $this->as($this->manager)->validate($shift, [], self::at('11:00:00')), 'shift');

        $this->as($this->attendant)->close($shift, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00'));
        // Le pompiste ne valide pas son propre poste, même avec le droit de valider.
        $this->grant('attendant', 'shifts.validate');
        try {
            $this->expectErrors(fn () => $this->as($this->attendant)->validate($shift, [], self::at('12:10:00')), 'shift');
        } finally {
            $this->restoreReferenceData();
        }

        $this->as($this->manager)->validate($shift, [], self::at('12:30:00'));

        $row = $this->row('SELECT status, validated_at, validated_by, closed_by FROM shifts WHERE id = :id', ['id' => $shift]);
        self::assertSame(['validated', self::DAY . ' 12:30:00', $this->manager, $this->attendant], array_values($row));
        $this->expectErrors(fn () => $this->as($this->manager)->validate($shift, [], self::at('12:40:00')), 'shift');
        try {
            $this->db->execute('UPDATE shifts SET expected_amount = 0 WHERE id = :id', ['id' => $shift]);
            self::fail('Poste validé modifié');
        } catch (PDOException $e) {
            self::assertStringContainsString('poste valide', $e->getMessage());
        }
    }

    public function testAManagerWhoClosedTheShiftHimselfCannotValidateIt(): void
    {
        $shift = $this->openFirst();
        $this->as($this->manager)->close($shift, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00'));

        $this->expectErrors(fn () => $this->as($this->manager)->validate($shift, [], self::at('12:30:00')), 'shift');
        $this->as($this->owner)->validate($shift, [], self::at('12:30:00'));
        self::assertSame($this->owner, $this->value('SELECT validated_by FROM shifts WHERE id = :id', ['id' => $shift]));
    }

    // --- Tranches : changement de prix pendant le poste -----------------------------------------------

    public function testLitresAndAmountsAreComputedPerSegmentAfterAPriceChange(): void
    {
        $shift = $this->openFirst();
        $this->as($this->attendant)->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'test', 'volume_l' => '10', 'reason' => 'Essai tranche 1'], self::at('07:00:00'));
        // 10h : l'essence passe à 3 500 FC, index intermédiaires relevés.
        $this->service($this->owner, PricingService::class)->publishPrice(
            $this->essence,
            ['unit_price' => '3500', 'currency' => 'CDF', 'readings' => [$shift => [$this->nozzleEss => '1300', $this->nozzleMaz => '5200']]],
            self::at('10:00:00')
        );
        $this->as($this->attendant)->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'test', 'volume_l' => '5', 'reason' => 'Essai tranche 2'], self::at('11:00:00'));

        $summary = $this->as($this->attendant)->close($shift, ['final' => [$this->nozzleEss => '1400', $this->nozzleMaz => '5300']], self::at('12:00:00'));

        self::assertCount(2, $summary['segments']);
        $first = array_column($summary['segments'][0]['nozzles'], null, 'code');
        $second = array_column($summary['segments'][1]['nozzles'], null, 'code');
        self::assertSame(['290.00', 3450, 1000500], [$first['P-ESS']['sold_l'], $first['P-ESS']['unit_price'], $first['P-ESS']['amount_cdf']], '(1 300 − 1 000) − 10 à l\'ancien prix');
        self::assertSame(['95.00', 3500, 332500], [$second['P-ESS']['sold_l'], $second['P-ESS']['unit_price'], $second['P-ESS']['amount_cdf']], '(1 400 − 1 300) − 5 au nouveau prix');
        self::assertSame(['200.00', '100.00'], [$first['P-MAZ']['sold_l'], $second['P-MAZ']['sold_l']]);
        self::assertSame(1000500 + 332500 + 300 * 3440, $summary['expected_cdf']);
        self::assertSame(['Essence' => '385.00', 'Mazout' => '300.00'], array_column($summary['products'], 'sold_l', 'product'));
    }

    // --- Droits ------------------------------------------------------------------------------------

    public function testAnAttendantOnlyWorksOnHisOwnShift(): void
    {
        $shift = $this->openFirst();
        $colleague = $this->createUser($this->org, 'attendant', [$this->station]);

        foreach ([
            fn () => $this->as($colleague)->find($shift),
            fn () => $this->as($colleague)->close($shift, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00')),
            fn () => $this->as($colleague)->addOutput($shift, ['nozzle_id' => $this->nozzleEss, 'output_type' => 'test', 'volume_l' => '500', 'reason' => 'x'], self::at('07:00:00')),
        ] as $call) {
            try {
                $call();
                self::fail('Poste d\'un collègue accessible');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame($shift, $this->as($this->attendant)->find($shift)['id']);
        self::assertSame($shift, $this->as($this->manager)->find($shift)['id']);
    }

    public function testShiftsOfAnotherStationAreOutOfReach(): void
    {
        $shift = $this->openFirst();
        $other = $this->createStation($this->org, 'B');
        $managerB = $this->createUser($this->org, 'manager', [$other]);

        foreach ([
            fn () => $this->as($managerB)->find($shift),
            fn () => $this->as($managerB)->close($shift, ['final' => [$this->nozzleEss => '1100', $this->nozzleMaz => '5100']], self::at('12:00:00')),
            fn () => $this->as($managerB)->open($this->station, ['attendant_id' => $this->attendant], self::at('12:00:00')),
            fn () => $this->as($managerB)->openingContext($this->station),
        ] as $call) {
            try {
                $call();
                self::fail('Poste d\'une autre station accessible');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
    }
}
