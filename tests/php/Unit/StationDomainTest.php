<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Petra\Core\I18n;
use Petra\Core\ValidationException;
use Petra\Domain\CalibrationCsv;
use Petra\Domain\StationSettings;
use PHPUnit\Framework\TestCase;

/** Réglages d'une station (seuils, horaires) et lecture d'un barème CSV. */
final class StationDomainTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::setCurrent(new I18n(dirname(__DIR__, 3) . '/lang'));
    }

    // --- Seuils -------------------------------------------------------------------

    public function testThresholdsFallBackToDefaults(): void
    {
        $thresholds = StationSettings::thresholds(['low_stock_l' => '4500.00', 'autre' => 'x']);

        self::assertSame(
            ['stock_tolerance_pct', 'stock_tolerance_l', 'low_stock_l', 'cash_shortage_tolerance_cdf', 'cash_surplus_tolerance_cdf', 'delivery_variance_l'],
            array_keys($thresholds)
        );
        self::assertSame('4500.00', $thresholds['low_stock_l']);
        self::assertSame('0.50', $thresholds['stock_tolerance_pct']);
        self::assertSame('5000', $thresholds['cash_shortage_tolerance_cdf']);
    }

    public function testThresholdsAreNormalisedWithoutFloats(): void
    {
        $values = StationSettings::validateThresholds([
            'stock_tolerance_pct' => '0,5', 'stock_tolerance_l' => '75', 'low_stock_l' => ' 2500.5 ',
            'cash_shortage_tolerance_cdf' => '10000', 'cash_surplus_tolerance_cdf' => '0', 'delivery_variance_l' => '120,25',
        ]);

        self::assertSame([
            'stock_tolerance_pct' => '0.50', 'stock_tolerance_l' => '75.00', 'low_stock_l' => '2500.50',
            'cash_shortage_tolerance_cdf' => '10000', 'cash_surplus_tolerance_cdf' => '0', 'delivery_variance_l' => '120.25',
        ], $values);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidThresholds(): array
    {
        return [
            'pourcentage au-delà de 100' => ['stock_tolerance_pct', '120'],
            'pourcentage négatif' => ['stock_tolerance_pct', '-1'],
            'volume à trois décimales' => ['low_stock_l', '10.123'],
            'montant décimal' => ['cash_shortage_tolerance_cdf', '50.5'],
            'montant négatif' => ['cash_surplus_tolerance_cdf', '-5'],
            'valeur absente' => ['delivery_variance_l', ''],
            'texte' => ['stock_tolerance_l', 'beaucoup'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidThresholds')]
    public function testInvalidThresholdsAreRejected(string $key, string $value): void
    {
        $input = array_map(static fn (array $d): string => $d[1], StationSettings::THRESHOLDS);
        try {
            StationSettings::validateThresholds([$key => $value] + $input);
            self::fail('Seuil accepté');
        } catch (ValidationException $e) {
            self::assertSame([$key], array_keys($e->errors));
        }
    }

    // --- Horaires -------------------------------------------------------------------

    public function testDefaultScheduleIsMorningAndAfternoon(): void
    {
        self::assertSame(
            [['label' => 'Matin', 'start' => '06:00', 'end' => '12:00'], ['label' => 'Après-midi', 'start' => '12:00', 'end' => '18:00']],
            StationSettings::schedule([])
        );
        self::assertSame(StationSettings::DEFAULT_SCHEDULE, StationSettings::schedule([StationSettings::SCHEDULE_KEY => 'pas du json']));
    }

    public function testScheduleIgnoresEmptyRowsAndAllowsANightShift(): void
    {
        $schedule = StationSettings::validateSchedule([
            ['label' => ' Jour ', 'start' => '06:00', 'end' => '14:00'],
            ['label' => '', 'start' => '', 'end' => ''],
            ['label' => 'Soir', 'start' => '14:00', 'end' => '22:00'],
            ['label' => 'Nuit', 'start' => '22:00', 'end' => '06:00'],
        ]);

        self::assertSame(['Jour', 'Soir', 'Nuit'], array_column($schedule, 'label'));
    }

    public function testFourSlotsWithTheLongestLabelsFitInTheSettingValue(): void
    {
        $label = str_repeat('é', 16);
        $schedule = StationSettings::validateSchedule([
            ['label' => $label, 'start' => '00:00', 'end' => '06:00'], ['label' => $label, 'start' => '06:00', 'end' => '12:00'],
            ['label' => $label, 'start' => '12:00', 'end' => '18:00'], ['label' => $label, 'start' => '18:00', 'end' => '00:00'],
        ]);

        self::assertCount(4, $schedule);
        self::assertLessThanOrEqual(255, mb_strlen((string) json_encode($schedule, JSON_UNESCAPED_UNICODE)), 'station_settings.setting_value est un VARCHAR(255)');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidSchedules(): array
    {
        return [
            'aucun poste' => [[]],
            'pas un tableau' => ['06:00-12:00'],
            'chevauchement' => [[['label' => 'A', 'start' => '06:00', 'end' => '12:00'], ['label' => 'B', 'start' => '11:00', 'end' => '18:00']]],
            'chevauchement par la nuit' => [[['label' => 'Nuit', 'start' => '22:00', 'end' => '07:00'], ['label' => 'Matin', 'start' => '06:00', 'end' => '12:00']]],
            'heure invalide' => [[['label' => 'A', 'start' => '25:00', 'end' => '12:00']]],
            'début égal à la fin' => [[['label' => 'A', 'start' => '06:00', 'end' => '06:00']]],
            'sans nom' => [[['label' => '', 'start' => '06:00', 'end' => '12:00']]],
            'nom trop long' => [[['label' => str_repeat('x', 17), 'start' => '06:00', 'end' => '12:00']]],
            'cinq postes' => [array_map(static fn (int $i): array => ['label' => "P$i", 'start' => sprintf('%02d:00', $i * 4), 'end' => sprintf('%02d:00', $i * 4 + 4)], range(0, 4))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSchedules')]
    public function testInvalidSchedulesAreRejected(mixed $rows): void
    {
        $this->expectException(ValidationException::class);
        StationSettings::validateSchedule($rows);
    }

    // --- Barème CSV -------------------------------------------------------------------

    public function testCsvAcceptsHeaderSemicolonAndDecimalComma(): void
    {
        $parsed = CalibrationCsv::parse("\xEF\xBB\xBFhauteur_cm;volume_l\r\n20,5;2460\r\n0;0\r\n\r\n10;1200,00\r\n", '30000.00');

        self::assertSame([], $parsed['errors']);
        self::assertSame([
            ['depth_cm' => '0.0', 'volume_l' => '0.00'],
            ['depth_cm' => '10.0', 'volume_l' => '1200.00'],
            ['depth_cm' => '20.5', 'volume_l' => '2460.00'],
        ], $parsed['rows'], 'Points triés par hauteur, valeurs normalisées');
    }

    public function testCsvAcceptsCommaAndTabSeparators(): void
    {
        self::assertSame(
            [['depth_cm' => '0.0', 'volume_l' => '0.00'], ['depth_cm' => '10.5', 'volume_l' => '1260.25']],
            CalibrationCsv::parse("0,0\n10.5,1260.25\n", '30000.00')['rows']
        );
        self::assertSame(
            [['depth_cm' => '10.5', 'volume_l' => '1260.25']],
            CalibrationCsv::parse("\"10,5\"\t\"1 260,25\"\n", '30000.00')['rows']
        );
    }

    public function testCsvIsRefusedAsAWholeWithTheFaultyLines(): void
    {
        $parsed = CalibrationCsv::parse(implode("\n", [
            '0;0',            // 1
            '10;1200',        // 2
            '10;1300',        // 3 : hauteur en double
            '20;abc',         // 4 : non numérique
            '30;900',         // 5 : volume qui redescend
            '40;31000',       // 6 : au-delà de la capacité
            '50',             // 7 : une seule colonne
            '60;-5',          // 8 : négatif
            '70,55;8000',     // 9 : hauteur à deux décimales
        ]), '30000.00');

        self::assertSame([], $parsed['rows']);
        self::assertSame(
            [3 => 'csv_duplicate', 4 => 'csv_number', 5 => 'csv_not_increasing', 6 => 'csv_capacity', 7 => 'csv_columns', 8 => 'csv_number', 9 => 'csv_number'],
            array_column($parsed['errors'], 'error', 'line')
        );
    }

    public function testCsvEdgeCases(): void
    {
        self::assertSame('csv_empty', CalibrationCsv::parse("hauteur;volume\n\n", '100.00')['errors'][0]['error']);
        self::assertSame('csv_columns', CalibrationCsv::parse("12,5,1500\n", '30000.00')['errors'][0]['error'], 'Virgule décimale ambiguë avec le séparateur virgule');
        self::assertSame('csv_number', CalibrationCsv::parse("0;0\ntitre;oublié\n", '100.00')['errors'][0]['error'], 'Un texte après des données n\'est pas un en-tête');

        $big = implode("\n", array_map(static fn (int $i): string => "$i;$i", range(0, CalibrationCsv::MAX_ROWS)));
        self::assertSame('csv_too_many', CalibrationCsv::parse($big, '9999999.00')['errors'][0]['error']);
        // Au-delà de la précision d'un float : comparaison exacte avec la capacité.
        self::assertSame([], CalibrationCsv::parse('1;9999999.99', '9999999.99')['errors']);
        self::assertSame('csv_capacity', CalibrationCsv::parse('1;9999999.99', '9999999.98')['errors'][0]['error']);
    }
}
