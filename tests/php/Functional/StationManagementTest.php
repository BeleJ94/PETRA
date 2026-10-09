<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Session;
use Petra\Core\Uuid;
use Tests\DatabaseTestCase;

/** Écran Propriétaire : stations, cuves, pistolets, barèmes, horaires et seuils. */
final class StationManagementTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $stationA;
    private string $stationB;
    private string $owner;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->stationA = $this->createStation($this->org, 'A');
        $this->stationB = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->session = $this->sessionFor($this->owner);
    }

    protected function tearDown(): void
    {
        putenv('STATION_ID');
        $this->restoreReferenceData();
    }

    private function post(string $path, array $body = [], string $referer = '/stations', array $files = []): \Petra\Core\Response
    {
        return $this->http($this->session, 'POST', $path, $body, ['referer' => 'http://localhost' . $referer], true, $files);
    }

    /** @return list<string> messages de validation renvoyés par la dernière requête */
    private function errors(): array
    {
        return array_values((array) ($this->session->pullFlash('errors') ?? []));
    }

    /** @return array<string, mixed> dernière entrée du journal pour cette entité et cette action */
    private function audit(string $entityType, string $action): array
    {
        return $this->row(
            'SELECT * FROM audit_log WHERE entity_type = :e AND action = :a ORDER BY created_at DESC, id DESC LIMIT 1',
            ['e' => $entityType, 'a' => $action]
        );
    }

    private function json(string $path): array
    {
        $response = $this->http($this->session, 'GET', $path, [], self::AJAX);
        self::assertSame(200, $response->status, $path);

        return json_decode($response->body, true);
    }

    // --- Liste (DataTables, pagination serveur) ---------------------------------------

    public function testStationListIsPaginatedAndSearchedOnTheServer(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->createStation($this->org, sprintf('K%02d', $i));
        }
        $page = $this->http($this->session, 'GET', '/stations');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-table="stations"', $page->body);
        self::assertStringContainsString('data-source="/stations/data"', $page->body);
        self::assertStringContainsString('data-row-link="/stations/{id}"></table>', $page->body, 'Tableau vide : les lignes viennent du serveur, page par page');

        $first = $this->json('/stations/data?draw=3&start=0&length=15&order[0][column]=0&order[0][dir]=asc&columns[0][data]=code');
        self::assertSame(3, $first['draw']);
        self::assertSame(32, $first['recordsTotal']);
        self::assertCount(15, $first['data']);
        self::assertSame('A', $first['data'][0]['code']);

        $last = $this->json('/stations/data?start=30&length=15&order[0][column]=0&order[0][dir]=asc&columns[0][data]=code');
        self::assertSame(['K29', 'K30'], array_column($last['data'], 'code'));

        $search = $this->json('/stations/data?search[value]=K07');
        self::assertSame(1, $search['recordsFiltered']);
        self::assertSame(32, $search['recordsTotal']);

        $this->db->execute('UPDATE stations SET is_active = 0 WHERE id = :id', ['id' => $this->stationB]);
        self::assertSame(['B'], array_column($this->json('/stations/data?is_active=0')['data'], 'code'));
        // Tri : une colonne hors liste blanche est ignorée, pas injectée.
        self::assertSame(32, $this->json('/stations/data?order[0][column]=0&columns[0][data]=id;DROP TABLE stations')['recordsTotal']);
    }

    // --- Création, modification -----------------------------------------------------------

    public function testOwnerCreatesAStation(): void
    {
        self::assertSame(200, $this->http($this->session, 'GET', '/stations/create')->status);

        $response = $this->post('/stations', ['code' => 'kzi-02', 'name' => ' Station Kolwezi Gare ', 'city' => 'Kolwezi', 'address' => '', 'timezone' => 'Africa/Lubumbashi'], '/stations/create');

        $station = $this->row("SELECT * FROM stations WHERE code = 'KZI-02'");
        self::assertSame(302, $response->status);
        self::assertSame('/stations/' . $station['id'], $response->headers['Location']);
        self::assertTrue(Uuid::isValid($station['id']));
        self::assertSame($this->org, $station['organization_id']);
        self::assertSame('Station Kolwezi Gare', $station['name']);
        self::assertSame($this->owner, $station['created_by']);
        self::assertSame(1, (int) $station['is_active']);

        $log = $this->audit('station', 'create');
        self::assertSame($station['id'], $log['entity_id']);
        self::assertSame($this->owner, $log['created_by']);
        self::assertSame('KZI-02', json_decode((string) $log['after_data'], true)['code']);

        $sheet = $this->http($this->session, 'GET', '/stations/' . $station['id']);
        self::assertSame(200, $sheet->status, 'La nouvelle station est aussitôt accessible à son propriétaire');
        self::assertStringContainsString('Station Kolwezi Gare', $this->http($this->session, 'GET', '/')->body, 'Elle apparaît dans le sélecteur de l\'en-tête');
    }

    public function testStationCreationIsValidated(): void
    {
        $valid = ['code' => 'NEW-1', 'name' => 'Nouvelle', 'timezone' => 'Africa/Kinshasa'];
        foreach ([
            ['code' => 'a'],                       // code déjà pris (insensible à la casse)
            ['code' => 'pas valide !'],
            ['code' => ''],
            ['name' => ''],
            ['timezone' => 'Mars/Olympus'],
            ['timezone' => "Africa/Lubumbashi'; DROP TABLE stations"],
        ] as $override) {
            $response = $this->post('/stations', $override + $valid, '/stations/create');
            self::assertSame('/stations/create', $response->headers['Location'], json_encode($override));
            self::assertNotSame([], $this->errors(), json_encode($override));
        }
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM stations'));
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'station'"));
    }

    public function testOwnerEditsAStationAndTheChangeIsLogged(): void
    {
        $response = $this->post("/stations/{$this->stationA}", ['code' => 'A', 'name' => 'Station Centre', 'city' => 'Likasi', 'timezone' => 'Africa/Kinshasa']);

        self::assertSame("/stations/{$this->stationA}", $response->headers['Location']);
        self::assertSame(['name' => 'Station Centre', 'city' => 'Likasi', 'timezone' => 'Africa/Kinshasa'], $this->row('SELECT name, city, timezone FROM stations WHERE id = :id', ['id' => $this->stationA]));
        $log = $this->audit('station', 'update');
        self::assertSame($this->stationA, $log['station_id']);
        self::assertSame(['name' => 'Station A', 'city' => null, 'timezone' => 'Africa/Lubumbashi'], json_decode((string) $log['before_data'], true), 'Seuls les champs modifiés sont journalisés');
        self::assertSame(['name' => 'Station Centre', 'city' => 'Likasi', 'timezone' => 'Africa/Kinshasa'], json_decode((string) $log['after_data'], true));

        // Reprendre le code d'une autre station est refusé.
        $this->post("/stations/{$this->stationA}", ['code' => 'B', 'name' => 'x', 'timezone' => 'UTC']);
        self::assertSame('A', $this->value('SELECT code FROM stations WHERE id = :id', ['id' => $this->stationA]));
    }

    // --- Désactivation ------------------------------------------------------------------------

    public function testDeactivationIsConfirmedThenCutsStaffAccessButKeepsHistoryForTheOwner(): void
    {
        $manager = $this->createUser($this->org, 'manager', [$this->stationA]);
        $this->createAlert($this->stationA, 'Écart ancien station A');
        $managerSession = $this->sessionFor($manager);
        self::assertStringContainsString('Écart ancien', $this->http($managerSession, 'GET', '/alerts')->body);

        $sheet = $this->http($this->session, 'GET', "/stations/{$this->stationA}");
        self::assertMatchesRegularExpression('#action="/stations/' . $this->stationA . '/deactivate" data-confirm="[^"]+"#', $sheet->body, 'Confirmation SweetAlert2');

        $response = $this->post("/stations/{$this->stationA}/deactivate");

        self::assertSame(302, $response->status);
        self::assertSame(0, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $this->stationA]));
        $log = $this->audit('station', 'deactivate');
        self::assertSame($this->owner, $log['created_by']);
        self::assertSame($this->stationA, $log['station_id']);

        self::assertStringNotContainsString('Écart ancien', $this->http($managerSession, 'GET', '/alerts')->body, 'Le gérant n\'y a plus accès');
        self::assertStringContainsString('Écart ancien', $this->http($this->session, 'GET', '/alerts')->body, 'Le propriétaire garde l\'historique');
        $after = $this->http($this->session, 'GET', "/stations/{$this->stationA}");
        self::assertStringContainsString("/stations/{$this->stationA}/activate", $after->body);

        $this->post("/stations/{$this->stationA}/activate");
        self::assertSame(1, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $this->stationA]));
        self::assertNotSame([], $this->audit('station', 'activate'));
        self::assertStringContainsString('Écart ancien', $this->http($managerSession, 'GET', '/alerts')->body);
    }

    public function testAStationWithAnOpenShiftCannotBeDeactivated(): void
    {
        $attendant = $this->createUser($this->org, 'attendant', [$this->stationA]);
        $this->db->execute(
            'INSERT INTO shifts (id, station_id, attendant_id, business_date, opened_at, opened_by, created_by) VALUES (:id, :s, :u, CURDATE(), NOW(), :o, :o2)',
            ['id' => Uuid::v7(), 's' => $this->stationA, 'u' => $attendant, 'o' => $this->owner, 'o2' => $this->owner]
        );

        $this->post("/stations/{$this->stationA}/deactivate", [], "/stations/{$this->stationA}");

        self::assertStringContainsString('postes sont encore ouverts', implode(' ', $this->errors()));
        self::assertSame(1, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $this->stationA]));
    }

    public function testNoOperationCanBeWrittenOnADeactivatedStation(): void
    {
        $this->db->execute('UPDATE stations SET is_active = 0 WHERE id = :id', ['id' => $this->stationA]);
        $alerts = $this->service($this->owner, \Petra\Repositories\AlertRepository::class);

        try {
            $alerts->raise($this->stationA, 'cash_variance', 'warning', 'Écart');
            self::fail('Écriture acceptée sur une station désactivée');
        } catch (\Petra\Core\HttpException $e) {
            self::assertSame(409, $e->status);
        }
        // Son installation reste modifiable (préparer une réouverture).
        self::assertSame(302, $this->post("/stations/{$this->stationA}/products", ['code' => 'ESS', 'name' => 'Essence'])->status);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM products WHERE station_id = :s', ['s' => $this->stationA]));
    }

    // --- Horaires, fuseau, seuils -----------------------------------------------------------------

    public function testScheduleDefaultsThenSavedWithTimezone(): void
    {
        $sheet = $this->http($this->session, 'GET', "/stations/{$this->stationA}");
        foreach (['value="Matin"', 'value="06:00"', 'value="12:00"', 'value="18:00"', 'value="Après-midi"'] as $default) {
            self::assertStringContainsString($default, $sheet->body);
        }

        $this->post("/stations/{$this->stationA}/schedule", ['timezone' => 'Africa/Kinshasa', 'slots' => [
            ['label' => 'Jour', 'start' => '06:00', 'end' => '18:00'],
            ['label' => 'Nuit', 'start' => '18:00', 'end' => '06:00'],
            ['label' => '', 'start' => '', 'end' => ''],
        ]]);

        self::assertSame('Africa/Kinshasa', $this->value('SELECT timezone FROM stations WHERE id = :id', ['id' => $this->stationA]));
        self::assertSame(
            [['label' => 'Jour', 'start' => '06:00', 'end' => '18:00'], ['label' => 'Nuit', 'start' => '18:00', 'end' => '06:00']],
            json_decode((string) $this->value("SELECT setting_value FROM station_settings WHERE station_id = :s AND setting_key = 'shift_schedule'", ['s' => $this->stationA]), true)
        );
        $log = $this->audit('station_schedule', 'update');
        self::assertSame('Africa/Lubumbashi', json_decode((string) $log['before_data'], true)['timezone']);
        self::assertSame('Nuit', json_decode((string) $log['after_data'], true)['shift_schedule'][1]['label']);
        self::assertStringContainsString('value="Nuit"', $this->http($this->session, 'GET', "/stations/{$this->stationA}")->body);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM station_settings WHERE station_id = :s', ['s' => $this->stationB]), 'Réglage propre à la station');

        // Chevauchement : rien n'est enregistré.
        $this->post("/stations/{$this->stationA}/schedule", ['timezone' => 'UTC', 'slots' => [
            ['label' => 'A', 'start' => '06:00', 'end' => '13:00'], ['label' => 'B', 'start' => '12:00', 'end' => '18:00'],
        ]], "/stations/{$this->stationA}");
        self::assertStringContainsString('chevauchent', implode(' ', $this->errors()));
        self::assertSame('Africa/Kinshasa', $this->value('SELECT timezone FROM stations WHERE id = :id', ['id' => $this->stationA]));
    }

    public function testThresholdsAreSavedPerStationAndLogged(): void
    {
        $sheet = $this->http($this->session, 'GET', "/stations/{$this->stationA}");
        foreach (['Tolérance de stock (%)', 'Tolérance de stock (L)', 'Stock bas (L)', 'Déficit de caisse toléré (FC)', 'Excédent de caisse toléré (FC)', 'Écart de livraison toléré (L)'] as $label) {
            self::assertStringContainsString($label, $sheet->body);
        }
        $values = [
            'stock_tolerance_pct' => '0,75', 'stock_tolerance_l' => '80', 'low_stock_l' => '2500', 'cash_shortage_tolerance_cdf' => '10000',
            'cash_surplus_tolerance_cdf' => '2000', 'delivery_variance_l' => '150,5',
        ];

        $this->post("/stations/{$this->stationA}/thresholds", $values);

        $stored = array_column($this->db->fetchAll('SELECT setting_key, setting_value, updated_by FROM station_settings WHERE station_id = :s', ['s' => $this->stationA]), null, 'setting_key');
        self::assertSame('0.75', $stored['stock_tolerance_pct']['setting_value']);
        self::assertSame('150.50', $stored['delivery_variance_l']['setting_value']);
        self::assertSame('10000', $stored['cash_shortage_tolerance_cdf']['setting_value']);
        self::assertSame($this->owner, $stored['low_stock_l']['updated_by']);
        $log = $this->audit('station_settings', 'update');
        self::assertSame('0.50', json_decode((string) $log['before_data'], true)['stock_tolerance_pct'], 'La valeur par défaut figure comme état précédent');
        self::assertSame('0.75', json_decode((string) $log['after_data'], true)['stock_tolerance_pct']);

        // Deuxième enregistrement : mise à jour des mêmes lignes, seul le champ changé est journalisé.
        $this->post("/stations/{$this->stationA}/thresholds", ['low_stock_l' => '4000'] + $values);
        self::assertSame(6, (int) $this->value('SELECT COUNT(*) FROM station_settings WHERE station_id = :s', ['s' => $this->stationA]));
        self::assertSame(['low_stock_l' => '4000.00'], json_decode((string) $this->audit('station_settings', 'update')['after_data'], true));

        // Valeur invalide : tout est refusé.
        $this->post("/stations/{$this->stationA}/thresholds", ['stock_tolerance_pct' => '150'] + $values, "/stations/{$this->stationA}");
        self::assertNotSame([], $this->errors());
        self::assertSame('0.75', $this->value("SELECT setting_value FROM station_settings WHERE station_id = :s AND setting_key = 'stock_tolerance_pct'", ['s' => $this->stationA]));
    }

    // --- Produits, cuves, pistolets -----------------------------------------------------------------

    public function testProductsTanksAndNozzlesAreManagedAndLogged(): void
    {
        $this->post("/stations/{$this->stationA}/products", ['code' => 'ess', 'name' => 'Essence']);
        $product = (string) $this->value("SELECT id FROM products WHERE station_id = :s AND code = 'ESS'", ['s' => $this->stationA]);
        self::assertNotSame('', $product);
        self::assertNotSame([], $this->audit('product', 'create'));

        $response = $this->post("/stations/{$this->stationA}/tanks", ['product_id' => $product, 'code' => 'c-ess', 'name' => 'Cuve Essence', 'capacity_l' => '30000,5']);
        $tank = $this->row("SELECT * FROM tanks WHERE code = 'C-ESS'");
        self::assertSame('/tanks/' . $tank['id'], $response->headers['Location']);
        self::assertSame('30000.50', $tank['capacity_l']);
        self::assertSame($this->stationA, $tank['station_id']);
        self::assertSame('30000.50', json_decode((string) $this->audit('tank', 'create')['after_data'], true)['capacity_l']);

        // Distributeurs : un code par station ; un pistolet se rattache à l'un d'eux.
        foreach ([['d1', 'Distributeur 1'], ['D2', 'Distributeur 2'], ['D1', 'Doublon']] as [$code, $name]) {
            $this->post("/stations/{$this->stationA}/pumps", ['code' => $code, 'name' => $name]);
        }
        $pumps = array_column($this->db->fetchAll('SELECT id, code FROM pumps WHERE station_id = :s ORDER BY code', ['s' => $this->stationA]), 'id', 'code');
        self::assertSame(['D1', 'D2'], array_keys($pumps));
        self::assertNotSame([], $this->audit('pump', 'create'));
        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tank['id'], 'code' => 'sans-distributeur', 'name' => 'Refusé']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM nozzles'), 'Un pistolet appartient toujours à un distributeur');

        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tank['id'], 'pump_id' => $pumps['D1'], 'code' => 'p-1', 'name' => 'Pistolet 1']);
        $nozzle = $this->row("SELECT * FROM nozzles WHERE code = 'P-1'");
        self::assertSame([$tank['id'], $pumps['D1']], [$nozzle['tank_id'], $nozzle['pump_id']]);
        self::assertNotSame([], $this->audit('nozzle', 'create'));
        $page = $this->http($this->session, 'GET', '/stations/' . $this->stationA)->body;
        foreach (['Distributeurs', 'data-pump="D1"', 'Mono-produit', 'Sans pistolet', 'name="pump_id"', 'name="shift_mode"', 'Horaires libres'] as $text) {
            self::assertStringContainsString($text, $page, $text);
        }

        $tanks = $this->json("/stations/{$this->stationA}/tanks/data");
        self::assertSame(1, $tanks['recordsTotal']);
        self::assertSame(['code' => 'C-ESS', 'product' => 'Essence', 'calibration_count' => 0, 'nozzle_count' => 1], array_intersect_key($tanks['data'][0], array_flip(['code', 'product', 'calibration_count', 'nozzle_count'])));
        self::assertSame('P-1', $this->json("/stations/{$this->stationA}/nozzles/data?search[value]=D1")['data'][0]['code']);
        self::assertSame(0, $this->json("/stations/{$this->stationB}/tanks/data")['recordsTotal']);

        // Doublons de code refusés.
        $this->post("/stations/{$this->stationA}/products", ['code' => 'ESS', 'name' => 'Autre']);
        $this->post("/stations/{$this->stationA}/tanks", ['product_id' => $product, 'code' => 'C-ESS', 'name' => 'Autre', 'capacity_l' => '100']);
        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tank['id'], 'pump_id' => $pumps['D1'], 'code' => 'P-1', 'name' => 'Autre']);
        self::assertSame([1, 1, 1], [(int) $this->value('SELECT COUNT(*) FROM products'), (int) $this->value('SELECT COUNT(*) FROM tanks'), (int) $this->value('SELECT COUNT(*) FROM nozzles')]);

        // Une cuve qui alimente un pistolet actif ne peut pas être désactivée.
        $this->post('/tanks/' . $tank['id'], ['code' => 'C-ESS', 'name' => 'Cuve Essence', 'capacity_l' => '30000.50', 'is_active' => '0'], '/tanks/' . $tank['id']);
        self::assertStringContainsString('pistolets actifs', implode(' ', $this->errors()));

        self::assertSame(200, $this->http($this->session, 'GET', '/nozzles/' . $nozzle['id'])->status);
        // Tant qu'aucun index n'a été relevé, une erreur de rattachement se corrige.
        $this->post('/nozzles/' . $nozzle['id'], ['tank_id' => $tank['id'], 'pump_id' => $pumps['D2'], 'code' => 'P-1', 'name' => 'Pistolet 1', 'is_active' => '0']);
        self::assertEquals(['pump_id' => $pumps['D2'], 'is_active' => 0], json_decode((string) $this->audit('nozzle', 'update')['after_data'], true));
        $this->post('/pumps/' . $pumps['D2'], ['code' => 'D2', 'name' => 'Distributeur 2 bis', 'is_active' => '0']);
        self::assertSame(['Distributeur 2 bis', 0], array_values($this->row('SELECT name, is_active FROM pumps WHERE id = :id', ['id' => $pumps['D2']])));

        $this->post('/tanks/' . $tank['id'], ['code' => 'C-ESS', 'name' => 'Cuve Essence', 'capacity_l' => '28000', 'is_active' => '0']);
        self::assertSame(['capacity_l' => '28000.00', 'is_active' => 0], $this->row('SELECT capacity_l, is_active FROM tanks WHERE id = :id', ['id' => $tank['id']]));
        self::assertEquals(['capacity_l' => '30000.50', 'is_active' => 1], json_decode((string) $this->audit('tank', 'update')['before_data'], true));
    }

    public function testATankOrANozzleNeverCrossesStations(): void
    {
        $productB = $this->createProduct($this->stationB);
        $tankB = $this->createTank($this->stationB, $productB);
        $productA = $this->createProduct($this->stationA);
        $tankA = $this->createTank($this->stationA, $productA);

        $this->post("/stations/{$this->stationA}/tanks", ['product_id' => $productB, 'code' => 'C-X', 'name' => 'Cuve', 'capacity_l' => '1000']);
        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tankB, 'pump_id' => $this->pumpFor($this->stationA), 'code' => 'P-X', 'name' => 'Pistolet']);

        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM tanks WHERE code = 'C-X'"));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM nozzles'));

        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tankA, 'pump_id' => $this->pumpFor($this->stationB), 'code' => 'P-B', 'name' => 'Pistolet']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM nozzles'), "Le distributeur d'une autre station est refusé");
        $this->post("/stations/{$this->stationA}/nozzles", ['tank_id' => $tankA, 'pump_id' => $this->pumpFor($this->stationA), 'code' => 'P-A', 'name' => 'Pistolet']);
        $nozzle = (string) $this->value("SELECT id FROM nozzles WHERE code = 'P-A'");
        $this->post("/nozzles/$nozzle", ['tank_id' => $tankB, 'code' => 'P-A', 'name' => 'Pistolet', 'is_active' => '1'], "/nozzles/$nozzle");
        self::assertSame($tankA, $this->value('SELECT tank_id FROM nozzles WHERE id = :id', ['id' => $nozzle]));
    }

    // --- Barème : saisie et import CSV ---------------------------------------------------------------

    private function csv(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'petra-csv-');
        file_put_contents($path, $content);

        return ['file' => ['name' => 'bareme.csv', 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK]];
    }

    public function testCalibrationPointsAreEnteredByHand(): void
    {
        $tank = $this->createTank($this->stationA, $this->createProduct($this->stationA));
        $page = $this->http($this->session, 'GET', "/tanks/$tank");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-table="calibrations"', $page->body);
        self::assertStringContainsString('enctype="multipart/form-data"', $page->body);

        foreach ([['0', '0'], ['10', '1200'], ['20,5', '2460,25']] as [$depth, $volume]) {
            self::assertSame("/tanks/$tank", $this->post("/tanks/$tank/calibrations", ['depth_cm' => $depth, 'volume_l' => $volume])->headers['Location']);
        }
        $data = $this->json("/tanks/$tank/calibrations/data?order[0][column]=0&order[0][dir]=desc&columns[0][data]=depth_cm");
        self::assertSame(3, $data['recordsTotal']);
        self::assertSame(['depth_cm' => '20.5', 'volume_l' => '2460.25'], array_intersect_key($data['data'][0], ['depth_cm' => 1, 'volume_l' => 1]));
        self::assertSame($this->stationA, $this->audit('tank_calibration', 'create')['station_id']);

        // Même hauteur : le volume est remplacé, et l'ancien figure au journal.
        $this->post("/tanks/$tank/calibrations", ['depth_cm' => '10.0', 'volume_l' => '1250']);
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM tank_calibrations'));
        $log = $this->audit('tank_calibration', 'update');
        self::assertSame('1200.00', json_decode((string) $log['before_data'], true)['volume_l']);
        self::assertSame('1250.00', json_decode((string) $log['after_data'], true)['volume_l']);

        // Refus : barème non croissant, volume au-delà de la capacité, valeur invalide.
        foreach ([['15', '1000'], ['5', '1300'], ['300', '30000.01'], ['-1', '10'], ['12.55', '10'], ['abc', '10']] as [$depth, $volume]) {
            $this->post("/tanks/$tank/calibrations", ['depth_cm' => $depth, 'volume_l' => $volume], "/tanks/$tank");
            self::assertNotSame([], $this->errors(), "$depth → $volume");
        }
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM tank_calibrations'));

        // La capacité ne peut pas passer sous le plus grand volume du barème.
        $this->post("/tanks/$tank", ['code' => 'C-ESS', 'name' => 'Cuve', 'capacity_l' => '2000', 'is_active' => '1'], "/tanks/$tank");
        self::assertStringContainsString('2460.25', implode(' ', $this->errors()));
    }

    public function testCsvImportReplacesTheWholeChart(): void
    {
        $tank = $this->createTank($this->stationA, $this->createProduct($this->stationA));
        $this->post("/tanks/$tank/calibrations", ['depth_cm' => '99', 'volume_l' => '29000']);

        $response = $this->post("/tanks/$tank/calibrations/import", [], "/tanks/$tank", $this->csv("hauteur_cm;volume_l\n0;0\n10;1200\n20,5;2460\n250;30000\n"));

        self::assertSame("/tanks/$tank", $response->headers['Location']);
        self::assertSame([], $this->errors());
        self::assertSame(
            [['0.0', '0.00'], ['10.0', '1200.00'], ['20.5', '2460.00'], ['250.0', '30000.00']],
            array_map('array_values', $this->db->fetchAll('SELECT depth_cm, volume_l FROM tank_calibrations WHERE tank_id = :t ORDER BY depth_cm', ['t' => $tank]))
        );
        self::assertSame(4, (int) $this->value('SELECT COUNT(*) FROM tank_calibrations WHERE station_id = :s AND created_by = :u', ['s' => $this->stationA, 'u' => $this->owner]));
        $log = $this->audit('tank_calibration', 'import');
        self::assertSame(['points' => 1, 'max_depth_cm' => '99.0', 'max_volume_l' => '29000.00'], json_decode((string) $log['before_data'], true));
        self::assertSame(['points' => 4, 'max_depth_cm' => '250.0', 'max_volume_l' => '30000.00'], json_decode((string) $log['after_data'], true));
        self::assertStringContainsString('4 points', $this->http($this->session, 'GET', "/tanks/$tank")->body);
    }

    public function testAFaultyCsvIsRefusedEntirelyWithItsLineNumbers(): void
    {
        $tank = $this->createTank($this->stationA, $this->createProduct($this->stationA));
        $this->post("/tanks/$tank/calibrations", ['depth_cm' => '99', 'volume_l' => '29000']);

        $this->post("/tanks/$tank/calibrations/import", [], "/tanks/$tank", $this->csv("0;0\n10;1200\n20;abc\n30;900\n40;99999\n"));

        $errors = implode(' | ', $this->errors());
        self::assertStringContainsString('Ligne 3', $errors);
        self::assertStringContainsString('Ligne 4', $errors);
        self::assertStringContainsString('30000.00 L', $errors);
        self::assertSame(['99.0'], array_column($this->db->fetchAll('SELECT depth_cm FROM tank_calibrations'), 'depth_cm'), 'Le barème existant est intact');
        self::assertSame([], $this->audit('tank_calibration', 'import'));

        foreach ([[], ['file' => ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE]], $this->csv(str_repeat("1;1\n", 300000))] as $files) {
            $this->post("/tanks/$tank/calibrations/import", [], "/tanks/$tank", $files);
            self::assertNotSame([], $this->errors());
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM tank_calibrations'));
    }

    // --- Droits et cloisonnement -----------------------------------------------------------------------

    public function testOnlyTheOwnerReachesTheseScreens(): void
    {
        $manager = $this->sessionFor($this->createUser($this->org, 'manager', [$this->stationA]));
        $tank = $this->createTank($this->stationA, $this->createProduct($this->stationA));

        foreach (['/stations', '/stations/data', '/stations/create', "/stations/{$this->stationA}", "/tanks/$tank", "/tanks/$tank/calibrations/data"] as $path) {
            self::assertSame(403, $this->http($manager, 'GET', $path)->status, $path);
        }
        foreach (["/stations", "/stations/{$this->stationA}/deactivate", "/stations/{$this->stationA}/thresholds", "/stations/{$this->stationA}/tanks", "/tanks/$tank/calibrations/import"] as $path) {
            self::assertSame(403, $this->http($manager, 'POST', $path)->status, $path);
        }
        self::assertStringNotContainsString('href="/stations"', $this->http($manager, 'GET', '/')->body);
        self::assertSame(1, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $this->stationA]));
    }

    public function testGrantedPermissionsStayInsideAssignedStations(): void
    {
        $this->grant('manager', 'stations.manage', 'catalog.manage', 'settings.manage');
        $manager = $this->sessionFor($this->createUser($this->org, 'manager', [$this->stationA]));
        $tankB = $this->createTank($this->stationB, $this->createProduct($this->stationB));

        self::assertSame(200, $this->http($manager, 'GET', "/stations/{$this->stationA}")->status);
        self::assertSame(['A'], array_column(json_decode($this->http($manager, 'GET', '/stations/data', [], self::AJAX)->body, true)['data'], 'code'));

        foreach ([
            ['GET', "/stations/{$this->stationB}"], ['GET', "/stations/{$this->stationB}/tanks/data"], ['GET', "/tanks/$tankB"], ['GET', "/tanks/$tankB/calibrations/data"],
            ['POST', "/stations/{$this->stationB}"], ['POST', "/stations/{$this->stationB}/deactivate"], ['POST', "/stations/{$this->stationB}/thresholds"],
            ['POST', "/stations/{$this->stationB}/schedule"], ['POST', "/stations/{$this->stationB}/products"], ['POST', "/stations/{$this->stationB}/tanks"],
            ['POST', "/tanks/$tankB"], ['POST', "/tanks/$tankB/calibrations"], ['POST', "/tanks/$tankB/calibrations/import"],
        ] as [$method, $path]) {
            self::assertSame(403, $this->http($manager, $method, $path, ['code' => 'X', 'name' => 'X', 'timezone' => 'UTC', 'depth_cm' => '1', 'volume_l' => '1'])->status, "$method $path");
        }
        self::assertSame(1, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $this->stationB]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM tank_calibrations'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM station_settings'));

        // Créer une station reste réservé à qui administre toute l'organisation.
        self::assertSame(403, $this->http($manager, 'POST', '/stations', ['code' => 'NEW', 'name' => 'Nouvelle', 'timezone' => 'UTC'])->status);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM stations'));
    }

    public function testAnotherOrganizationIsOutOfReach(): void
    {
        $foreignOrg = $this->createOrganization('Concurrent');
        $foreign = $this->createStation($foreignOrg, 'A');
        $tank = $this->createTank($foreign, $this->createProduct($foreign));

        self::assertSame(403, $this->http($this->session, 'GET', "/stations/$foreign")->status);
        self::assertSame(403, $this->post("/stations/$foreign/deactivate")->status);
        self::assertSame(403, $this->http($this->session, 'GET', "/tanks/$tank")->status);
        self::assertSame(2, $this->json('/stations/data')['recordsTotal']);
        // Le code « A » est déjà pris ailleurs : l'unicité est par organisation.
        self::assertSame(302, $this->post('/stations', ['code' => 'C', 'name' => 'Troisième', 'timezone' => 'UTC'])->status);
        self::assertSame(1, (int) $this->value('SELECT is_active FROM stations WHERE id = :id', ['id' => $foreign]));
    }

    /** Installation d'une station (STATION_ID) : on n'y crée pas d'autres stations. */
    public function testAStationInstallationCannotCreateStations(): void
    {
        putenv('STATION_ID=' . $this->stationA);
        $session = $this->sessionFor($this->owner);

        self::assertSame(403, $this->http($session, 'POST', '/stations', ['code' => 'NEW', 'name' => 'Nouvelle', 'timezone' => 'UTC'])->status);
        self::assertStringNotContainsString('/stations/create', $this->http($session, 'GET', '/stations')->body);
        self::assertSame(['A'], array_column(json_decode($this->http($session, 'GET', '/stations/data', [], self::AJAX)->body, true)['data'], 'code'));
    }
}
