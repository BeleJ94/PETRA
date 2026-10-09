<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Petra\Core\ValidationException;
use Petra\Domain\StationSettings;
use Petra\Services\CashControl;
use Petra\Services\CatalogService;
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Petra\Services\StationService;
use Tests\DatabaseTestCase;

/**
 * Distributeurs et postes en parallèle : un poste par pompiste, sur les distributeurs qui lui sont confiés,
 * avec ses index et sa caisse ; horaires fixes ou libres selon le réglage de la station.
 */
final class ParallelShiftsTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $paul;
    private string $anne;
    /** @var array<string, string> distributeurs par code */
    private array $pump = [];
    /** @var array<string, string> pistolets par code */
    private array $nozzle = [];
    private string $essence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station]);
        $this->paul = $this->createUser($this->org, 'attendant', [$this->station], 'paul');
        $this->anne = $this->createUser($this->org, 'attendant', [$this->station], 'anne');
        $this->essence = $this->createProduct($this->station, 'ESS', 'Essence');
        $mazout = $this->createProduct($this->station, 'MAZ', 'Mazout');
        $tankEss = $this->createTank($this->station, $this->essence, 'C-ESS');
        $tankMaz = $this->createTank($this->station, $mazout, 'C-MAZ');
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->essence, 3000, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $mazout, 2000, self::DAY . ' 05:00:00', $this->owner);

        // D1 : multi-produits (essence + mazout). D2 : mono-produit (essence).
        $catalog = $this->service($this->owner, CatalogService::class);
        $this->pump['D1'] = $catalog->createPump($this->station, ['code' => 'd1', 'name' => 'Distributeur 1']);
        $this->pump['D2'] = $catalog->createPump($this->station, ['code' => 'D2', 'name' => 'Distributeur 2']);
        foreach ([['D1-ESS', 'D1', $tankEss], ['D1-MAZ', 'D1', $tankMaz], ['D2-ESS', 'D2', $tankEss]] as [$code, $pump, $tank]) {
            $this->nozzle[$code] = $catalog->createNozzle($this->station, ['pump_id' => $this->pump[$pump], 'tank_id' => $tank, 'code' => $code, 'name' => "Pistolet $code"]);
        }
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    private function shifts(?string $userId = null): ShiftService
    {
        return $this->service($userId ?? $this->manager, ShiftService::class);
    }

    private function open(string $attendant, array $pumps, array $opening, string $time, array $more = []): string
    {
        return $this->shifts()->open($this->station, ['attendant_id' => $attendant, 'pump_ids' => array_map(fn (string $c): string => $this->pump[$c], $pumps), 'opening' => $opening] + $more, self::at($time));
    }

    private function fails(callable $call, string $field): void
    {
        try {
            $call();
            self::fail("Opération acceptée (attendu : erreur sur $field)");
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors);
        }
    }

    public function testPumpsAreMonoOrMultiProductFromTheirNozzles(): void
    {
        $context = $this->shifts()->openingContext($this->station, self::at('06:00:00'));

        self::assertSame(
            [['D1', ['Essence', 'Mazout'], ['D1-ESS', 'D1-MAZ']], ['D2', ['Essence'], ['D2-ESS']]],
            array_map(static fn (array $p): array => [$p['code'], $p['products'], array_column($p['nozzles'], 'code')], $context['pumps'])
        );
        self::assertSame([], $context['blockers']);
        self::assertSame(['D1', 'D2'], array_column($this->db->fetchAll('SELECT code FROM pumps ORDER BY code'), 'code'), 'Le code est normalisé en majuscules');
        // Les distributeurs partent vers le central avec le reste de l'installation.
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'pumps' AND operation = 'insert'"));
    }

    public function testTwoAttendantsHoldTheirOwnPumpsAtTheSameTime(): void
    {
        $first = $this->open($this->paul, ['D1'], [$this->nozzle['D1-ESS'] => '1000', $this->nozzle['D1-MAZ'] => '5000'], '06:00:00', ['label' => 'Matin']);
        // D1 est tenu : il n'est plus proposé ; D2 reste libre.
        self::assertSame(['D2'], array_column($this->shifts()->openingContext($this->station, self::at('06:05:00'))['pumps'], 'code'));
        $second = $this->open($this->anne, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '06:10:00', ['label' => 'Matin']);

        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM shifts WHERE status = 'open' AND station_id = :s", ['s' => $this->station]));
        self::assertSame([$first => 'D1', $second => 'D2'], $this->service($this->manager, \Petra\Repositories\ShiftRepository::class)->pumpsOfShifts([$first, $second]));
        // Chaque poste ne relève que ses pistolets.
        self::assertSame(['D1-ESS', 'D1-MAZ'], array_column($this->shifts()->summary($this->shifts()->find($first))['segments'][0]['nozzles'], 'code'));
        self::assertSame(['D2-ESS'], array_column($this->shifts()->summary($this->shifts()->find($second))['segments'][0]['nozzles'], 'code'));

        // Tout est tenu : plus rien à ouvrir.
        $third = $this->createUser($this->org, 'attendant', [$this->station], 'tiers');
        $this->fails(fn () => $this->shifts()->open($this->station, ['attendant_id' => $third], self::at('06:20:00')), 'blocker_0');

        // Chacun clôture ses index : la caisse attendue est celle de ses ventes, pas de la station.
        $this->shifts($this->paul)->close($first, ['final' => [$this->nozzle['D1-ESS'] => '1100', $this->nozzle['D1-MAZ'] => '5050']], self::at('12:00:00'));
        self::assertSame(['closed', 'open'], [$this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $first]), $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $second])]);
        $this->shifts($this->anne)->close($second, ['final' => [$this->nozzle['D2-ESS'] => '260']], self::at('12:30:00'));
        $cash = $this->service($this->manager, CashControl::class);
        self::assertSame(100 * 3000 + 50 * 2000, $cash->forShift($this->shifts()->find($first))['expected_cdf']);
        self::assertSame(60 * 3000, $cash->forShift($this->shifts()->find($second))['expected_cdf']);

        // L'index final de D1 est repris au poste suivant sur D1, quel que soit le pompiste.
        $next = $this->open($this->anne, ['D1'], [], '13:00:00', ['label' => 'Après-midi']);
        self::assertSame(['1100.00', '5050.00'], array_column($this->shifts()->summary($this->shifts()->find($next))['segments'][0]['nozzles'], 'opening'));
    }

    public function testAPumpOrAnAttendantIsNeverInTwoOpenShifts(): void
    {
        $this->open($this->paul, ['D1'], [$this->nozzle['D1-ESS'] => '1000', $this->nozzle['D1-MAZ'] => '5000'], '06:00:00');

        // D1 déjà tenu ; Paul tient déjà un poste ; aucun distributeur coché.
        $this->fails(fn () => $this->open($this->anne, ['D1'], [], '06:10:00'), 'pump_ids');
        $this->fails(fn () => $this->open($this->anne, ['D1', 'D2'], [$this->nozzle['D2-ESS'] => '200'], '06:10:00'), 'pump_ids');
        $this->fails(fn () => $this->open($this->paul, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '06:10:00'), 'attendant_id');
        $this->fails(fn () => $this->shifts()->open($this->station, ['attendant_id' => $this->anne, 'pump_ids' => ['']], self::at('06:10:00')), 'pump_ids');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shifts'));

        // Un distributeur tenu ne se met pas hors service ; hors service, il n'est plus proposé.
        $catalog = $this->service($this->owner, CatalogService::class);
        $this->fails(fn () => $catalog->updatePump($this->pump['D1'], ['code' => 'D1', 'name' => 'Distributeur 1', 'is_active' => '0']), 'is_active');
        $catalog->updatePump($this->pump['D2'], ['code' => 'D2', 'name' => 'Distributeur 2', 'is_active' => '0']);
        $this->fails(fn () => $this->open($this->anne, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '06:20:00'), 'blocker_0');
    }

    public function testAPriceChangeSplitsEveryOpenShiftThatSellsTheProduct(): void
    {
        $first = $this->open($this->paul, ['D1'], [$this->nozzle['D1-ESS'] => '1000', $this->nozzle['D1-MAZ'] => '5000'], '06:00:00');
        $second = $this->open($this->anne, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '06:10:00');

        // Règle 5 : le nouveau prix de l'essence impose un relevé intermédiaire sur les deux postes ouverts.
        $pricing = $this->service($this->owner, PricingService::class);
        $this->fails(fn () => $pricing->publishPrice($this->essence, ['unit_price' => '3100', 'currency' => 'CDF', 'readings' => [$first => [$this->nozzle['D1-ESS'] => '1040', $this->nozzle['D1-MAZ'] => '5010']]], self::at('09:00:00')), 'reading_' . $this->nozzle['D2-ESS']);
        $pricing->publishPrice($this->essence, ['unit_price' => '3100', 'currency' => 'CDF', 'readings' => [
            $first => [$this->nozzle['D1-ESS'] => '1040', $this->nozzle['D1-MAZ'] => '5010'],
            $second => [$this->nozzle['D2-ESS'] => '230'],
        ]], self::at('09:00:00'));
        self::assertSame([2, 2], [
            (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $first]),
            (int) $this->value('SELECT COUNT(*) FROM shift_segments WHERE shift_id = :s', ['s' => $second]),
        ]);

        $this->shifts($this->anne)->close($second, ['final' => [$this->nozzle['D2-ESS'] => '250']], self::at('12:00:00'));
        // 30 L à 3 000 puis 20 L à 3 100.
        self::assertSame(30 * 3000 + 20 * 3100, $this->service($this->manager, CashControl::class)->forShift($this->shifts()->find($second))['expected_cdf']);
    }

    public function testFreeHoursKeepOnlyOpeningAndClosingTimes(): void
    {
        $settings = $this->service($this->owner, StationService::class);
        $settings->saveSchedule($this->station, ['timezone' => 'Africa/Lubumbashi', 'shift_mode' => 'free']);
        self::assertSame('free', StationSettings::shiftMode($this->service($this->owner, \Petra\Repositories\StationSettingRepository::class)->all($this->station)));

        $context = $this->shifts()->openingContext($this->station, self::at('14:37:00'));
        self::assertSame(['free', [], null], [$context['mode'], $context['schedule'], $context['slot']]);

        // Un nom de créneau envoyé est ignoré : le poste n'a que ses heures.
        $shift = $this->open($this->paul, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '14:37:00', ['label' => 'Matin']);
        $this->shifts($this->paul)->close($shift, ['final' => [$this->nozzle['D2-ESS'] => '215']], self::at('21:05:00'));
        self::assertSame([null, self::DAY . ' 14:37:00', self::DAY . ' 21:05:00'], array_values($this->row('SELECT label, opened_at, closed_at FROM shifts WHERE id = :id', ['id' => $shift])));

        // L'écran montre le poste par son distributeur, sans créneaux vides ; l'ouverture ne propose pas de créneau.
        $session = $this->sessionFor($this->manager);
        $board = $this->http($session, 'GET', '/shifts?date=' . self::DAY)->body;
        self::assertStringContainsString('Distributeur D2', $board);
        self::assertStringNotContainsString('Après-midi', $board);
        $form = $this->http($session, 'GET', '/shifts/form?action=open')->body;
        self::assertStringNotContainsString('name="label"', $form);
        foreach (['data-recap-static="Heure d', 'name="pump_ids[]"', 'data-pump="D1"', 'data-pump="D2"', 'Multi-produits · Essence, Mazout', 'Mono-produit · Essence'] as $text) {
            self::assertStringContainsString($text, $form, $text);
        }

        // Retour aux horaires fixes : les créneaux d'avant sont toujours là.
        $settings->saveSchedule($this->station, ['timezone' => 'Africa/Lubumbashi', 'shift_mode' => 'fixed', 'slots' => [['label' => 'Jour', 'start' => '06:00', 'end' => '18:00']]]);
        $fixed = $this->http($session, 'GET', '/shifts/form?action=open')->body;
        self::assertStringContainsString('name="label"', $fixed);
        self::assertStringContainsString('Jour (06:00 – 18:00)', $fixed);
    }

    public function testOpeningThroughTheScreenTakesOnlyTheTickedPumps(): void
    {
        $session = $this->sessionFor($this->manager);
        $ajax = ['x-requested-with' => 'XMLHttpRequest'];

        // Rien de coché : refusé, au lieu de confier toute la station au pompiste.
        $none = $this->http($session, 'POST', '/shifts', ['attendant_id' => $this->paul, 'pump_ids' => ['']], $ajax);
        self::assertSame(422, $none->status);
        self::assertArrayHasKey('pump_ids', json_decode($none->body, true)['errors']);

        $opened = $this->http($session, 'POST', '/shifts', ['attendant_id' => $this->paul, 'pump_ids' => ['', $this->pump['D2']], 'opening' => [$this->nozzle['D2-ESS'] => '200']], $ajax);
        self::assertSame(200, $opened->status);
        self::assertSame([$this->nozzle['D2-ESS']], array_column($this->db->fetchAll('SELECT nozzle_id FROM meter_readings'), 'nozzle_id'));

        // D1 reste libre : l'écran propose toujours d'ouvrir un poste, et la carte du poste porte son distributeur.
        $board = $this->http($session, 'GET', '/shifts')->body;
        self::assertStringContainsString('action=open', $board);
        self::assertStringContainsString('Distributeur D2', $board);
        $this->http($session, 'POST', '/shifts', ['attendant_id' => $this->anne, 'pump_ids' => [$this->pump['D1']], 'opening' => [$this->nozzle['D1-ESS'] => '1000', $this->nozzle['D1-MAZ'] => '5000']], $ajax);
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM shifts WHERE status = 'open'"));
        self::assertStringNotContainsString('action=open', $this->http($session, 'GET', '/shifts')->body);
    }

    public function testANozzleKeepsItsPumpAndProductOnceRead(): void
    {
        $catalog = $this->service($this->owner, CatalogService::class);
        $tankMaz = (string) $this->value("SELECT id FROM tanks WHERE code = 'C-MAZ'");
        $tankEss = (string) $this->value("SELECT id FROM tanks WHERE code = 'C-ESS'");
        $base = ['code' => 'D2-ESS', 'name' => 'Pistolet D2-ESS', 'is_active' => '1'];

        // Avant tout relevé, une erreur de saisie se corrige.
        $catalog->updateNozzle($this->nozzle['D2-ESS'], $base + ['tank_id' => $tankMaz, 'pump_id' => $this->pump['D1']]);
        $catalog->updateNozzle($this->nozzle['D2-ESS'], $base + ['tank_id' => $tankEss, 'pump_id' => $this->pump['D2']]);

        $this->open($this->paul, ['D2'], [$this->nozzle['D2-ESS'] => '200'], '06:00:00');
        $this->fails(fn () => $catalog->updateNozzle($this->nozzle['D2-ESS'], $base + ['tank_id' => $tankMaz, 'pump_id' => $this->pump['D2']]), 'tank_id');
        $this->fails(fn () => $catalog->updateNozzle($this->nozzle['D2-ESS'], $base + ['tank_id' => $tankEss, 'pump_id' => $this->pump['D1']]), 'pump_id');
        // Le reste se modifie toujours (nom, passage à zéro) ; le distributeur non renvoyé est conservé.
        $catalog->updateNozzle($this->nozzle['D2-ESS'], ['code' => 'D2-ESS', 'name' => 'Pistolet essence', 'tank_id' => $tankEss, 'is_active' => '1']);
        self::assertSame(['Pistolet essence', $this->pump['D2'], $tankEss], array_values($this->row('SELECT name, pump_id, tank_id FROM nozzles WHERE id = :id', ['id' => $this->nozzle['D2-ESS']])));

        $page = $this->http($this->sessionFor($this->owner), 'GET', '/nozzles/' . $this->nozzle['D2-ESS'])->body;
        self::assertStringContainsString('ne changent plus', $page);
        self::assertMatchesRegularExpression('/<select[^>]*name="pump_id"[^>]*disabled/', $page);
    }
}
