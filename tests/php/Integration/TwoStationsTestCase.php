<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Petra\Core\Uuid;
use Petra\Services\CashControl;
use Petra\Services\CatalogService;
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Petra\Services\StockControl;
use Tests\DatabaseTestCase;

/**
 * Jeu d'essai commun aux tableaux de bord et aux rapports : deux stations, trois postes validés.
 *
 * Station A — 05/10 : Essence 100 L vendus à 5 000 FC (105 L débités dont 5 L d'essai) = 500 000 FC ;
 *                     Gaz 40 L à 2,50 $ au taux 2 300 = 100 $ = 230 000 FC ; remis 485 000 FC + 100 $ → écart −15 000 FC.
 *             06/10 : Essence 50 L = 250 000 FC ; remis 252 000 FC → écart +2 000 FC.
 * Station B — 05/10 : Gasoil 200 L à 4 000 FC = 800 000 FC ; remis 800 000 FC → écart 0.
 */
abstract class TwoStationsTestCase extends DatabaseTestCase
{
    protected string $org;
    protected string $a;
    protected string $b;
    protected string $owner;
    protected string $managerA;
    protected string $attendant1;
    protected string $attendant2;
    protected string $tankA;
    protected string $nozzleEss;
    protected string $shift1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->a = $this->createStation($this->org, 'A');
        $this->b = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->managerA = $this->createUser($this->org, 'manager', [$this->a], 'gerant.a');
        $managerB = $this->createUser($this->org, 'manager', [$this->b], 'gerant.b');
        $this->attendant1 = $this->createUser($this->org, 'attendant', [$this->a], 'pompiste.1');
        $this->attendant2 = $this->createUser($this->org, 'attendant', [$this->a], 'pompiste.2');
        $attendantB = $this->createUser($this->org, 'attendant', [$this->b], 'pompiste.b');

        // Station A : deux produits, dont un vendu en dollars.
        $catalog = $this->service($this->owner, CatalogService::class);
        $ess = $catalog->createProduct($this->a, ['code' => 'ESS', 'name' => 'Essence']);
        $gaz = $catalog->createProduct($this->a, ['code' => 'GAZ', 'name' => 'Gaz']);
        $this->tankA = $catalog->createTank($this->a, ['product_id' => $ess, 'code' => 'C-ESS', 'name' => 'Cuve essence', 'capacity_l' => '30000']);
        $tankGaz = $catalog->createTank($this->a, ['product_id' => $gaz, 'code' => 'C-GAZ', 'name' => 'Cuve gaz', 'capacity_l' => '10000']);
        $this->nozzleEss = $catalog->createNozzle($this->a, ['pump_id' => $this->pumpFor($this->a), 'tank_id' => $this->tankA, 'code' => 'P-ESS', 'name' => 'Pistolet essence']);
        $nozzleGaz = $catalog->createNozzle($this->a, ['pump_id' => $this->pumpFor($this->a), 'tank_id' => $tankGaz, 'code' => 'P-GAZ', 'name' => 'Pistolet gaz']);
        $csv = tempnam(sys_get_temp_dir(), 'petra-csv-');
        file_put_contents($csv, "0;0\n150;15000\n300;30000\n");
        $catalog->importCalibrations($this->tankA, ['tmp_name' => $csv, 'error' => UPLOAD_ERR_OK]);
        $pricing = $this->service($this->owner, PricingService::class);
        $pricing->publishRate($this->a, ['rate' => '2300'], self::at('05', '05:00'));
        $pricing->publishPrice($ess, ['unit_price' => '5000', 'currency' => 'CDF'], self::at('05', '05:00'));
        $pricing->publishPrice($gaz, ['unit_price' => '2,50', 'currency' => 'USD'], self::at('05', '05:00'));
        $this->service($this->managerA, StockControl::class)->recordDip($this->tankA, ['depth_cm' => '100', 'dip_type' => 'opening'], self::at('05', '05:30'));

        $this->shift1 = $this->shift($this->a, $this->managerA, $this->attendant1, '05', [$this->nozzleEss => ['0', '105'], $nozzleGaz => ['0', '40']], [['485000', 'CDF'], ['100', 'USD']], [$this->nozzleEss, '5']);
        $this->service($this->managerA, StockControl::class)->recordDip($this->tankA, ['depth_cm' => '98', 'dip_type' => 'control'], self::at('05', '13:00'));
        $this->shift($this->a, $this->managerA, $this->attendant2, '06', [$this->nozzleEss => [null, '155'], $nozzleGaz => [null, '40']], [['252000', 'CDF']]);

        // Station B : un produit, un poste sans écart.
        $catalog = $this->service($this->owner, CatalogService::class);
        $dsl = $catalog->createProduct($this->b, ['code' => 'DSL', 'name' => 'Gasoil']);
        $tankB = $catalog->createTank($this->b, ['product_id' => $dsl, 'code' => 'C-DSL', 'name' => 'Cuve gasoil', 'capacity_l' => '20000']);
        $nozzleB = $catalog->createNozzle($this->b, ['pump_id' => $this->pumpFor($this->b), 'tank_id' => $tankB, 'code' => 'P-DSL', 'name' => 'Pistolet gasoil']);
        $pricing = $this->service($this->owner, PricingService::class);
        $pricing->publishRate($this->b, ['rate' => '2350'], self::at('05', '05:00'));
        $pricing->publishPrice($dsl, ['unit_price' => '4000', 'currency' => 'CDF'], self::at('05', '05:00'));
        $this->shift($this->b, $managerB, $attendantB, '05', [$nozzleB => ['0', '200']], [['800000', 'CDF']]);
    }

    protected static function at(string $day, string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-$day $time:00");
    }

    /**
     * Poste complet : ouverture, sortie non vendue éventuelle, clôture, remises, validation.
     *
     * @param array<string, array{0: ?string, 1: string}> $nozzles pistolet => [index d'ouverture (null : reprise du dernier), index final]
     * @param list<array{0: string, 1: string}> $handovers [montant, devise]
     * @param array{0: string, 1: string}|null $test [pistolet, litres d'essai]
     */
    protected function shift(string $station, string $manager, string $attendant, string $day, array $nozzles, array $handovers, ?array $test = null): string
    {
        $opening = array_filter(array_map(static fn (array $n): ?string => $n[0], $nozzles), static fn (?string $v): bool => $v !== null);
        $id = $this->service($manager, ShiftService::class)->open($station, ['attendant_id' => $attendant] + ($opening === [] ? [] : ['opening' => $opening]), self::at($day, '06:00'));
        if ($test !== null) {
            $this->service($attendant, ShiftService::class)->addOutput($id, ['nozzle_id' => $test[0], 'output_type' => 'test', 'volume_l' => $test[1], 'reason' => 'Essai'], self::at($day, '07:00'));
        }
        $this->service($attendant, ShiftService::class)->close($id, ['final' => array_map(static fn (array $n): string => $n[1], $nozzles)], self::at($day, '12:00'));
        foreach ($handovers as $i => [$amount, $currency]) {
            $this->service($manager, CashControl::class)->addHandover($id, ['amount' => $amount, 'currency' => $currency], self::at($day, '12:1' . $i));
        }
        $this->service($manager, ShiftService::class)->validate($id, [], self::at($day, '12:30'));

        return $id;
    }
}
