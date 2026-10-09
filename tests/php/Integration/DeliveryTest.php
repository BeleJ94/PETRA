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
use Petra\Domain\DeliveryCalculator;
use Petra\Services\DeliveryService;
use Petra\Services\FileStore;
use Petra\Services\StockControl;
use Tests\DatabaseTestCase;

/** Livraisons : réception, écarts annoncé / reçu et reçu / variation de cuve, alerte, photo protégée. */
final class DeliveryTest extends DatabaseTestCase
{
    private const DAY = '2026-10-05';
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];
    /** PNG 1 × 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $org;
    private string $station;
    private string $owner;
    private string $manager;
    private string $product;
    private string $tank;
    private string $supplier;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/petra-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->uploads);
        putenv('UPLOADS_DIR=' . $this->uploads);

        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->station]);
        $this->product = $this->createProduct($this->station, 'ESS', 'Essence');
        $this->tank = $this->createTank($this->station, $this->product, 'C-ESS', '30000.00');
        $this->supplier = $this->createSupplier($this->station, 'SEP Congo');
        $this->createRate($this->station, 2300, self::DAY . ' 05:00:00', $this->owner);
        $this->createPrice($this->station, $this->product, 3450, self::DAY . ' 05:00:00', $this->owner);
        // Barème linéaire : 100 L par cm, de 0 à 300 cm.
        foreach ([['0.0', '0.00'], ['300.0', '30000.00']] as [$depth, $volume]) {
            $this->db->execute(
                'INSERT INTO tank_calibrations (id, station_id, tank_id, depth_cm, volume_l) VALUES (:id, :s, :t, :d, :v)',
                ['id' => Uuid::v7(), 's' => $this->station, 't' => $this->tank, 'd' => $depth, 'v' => $volume]
            );
        }
    }

    protected function tearDown(): void
    {
        putenv('UPLOADS_DIR');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($this->uploads);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $time);
    }

    private function deliveries(?string $userId = null): DeliveryService
    {
        return $this->service($userId ?? $this->manager, DeliveryService::class);
    }

    /** Livraison annoncée à 10 000 L ; cuve jaugée à 50 cm (5 000 L) avant dépotage. */
    private function input(array $override = []): array
    {
        return $override + [
            'tank_id' => $this->tank, 'supplier_id' => $this->supplier, 'delivery_note_no' => 'BL-2026-0412', 'truck_plate' => '1234AB/05',
            'driver_name' => 'Paul Kalala', 'ordered_volume_l' => '10000', 'invoiced_volume_l' => '10000', 'received_volume_l' => '10000',
            'before_cm' => '50', 'after_cm' => '150', 'received_by' => $this->manager, 'note' => 'Plombs intacts',
        ];
    }

    private function upload(string $content, string $name = 'bon.png'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'petra-img-');
        file_put_contents($path, $content);

        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    // --- Calcul des écarts --------------------------------------------------------------

    public function testCalculatorGivesBothGapsAndPicksTheWorst(): void
    {
        // Annoncé 10 000, reçu 9 900, cuve montée de 9 850 : il manque 100 L sur le bon, puis 50 L dans la cuve.
        $f = DeliveryCalculator::compute('10000', '9900', '5000', '14850', '100.00');
        self::assertSame(['9850.00', '-100.00', '-50.00'], [$f['tank_variation_l'], $f['announced_variance_l'], $f['tank_variance_l']]);
        self::assertSame([false, false, false], [$f['announced_exceeds'], $f['tank_exceeds'], $f['exceeds']], 'Un écart égal à la tolérance est accepté');

        $f = DeliveryCalculator::compute('10000', '9899.99', '5000', '14850', '100.00');
        self::assertSame([true, false, true, true, '-100.01'], [$f['announced_exceeds'], $f['tank_exceeds'], $f['exceeds'], $f['shortage'], $f['worst_l']]);

        // Reçu déclaré conforme au bon, mais la cuve n'a monté que de 9 700 L.
        $f = DeliveryCalculator::compute('10000', '10000', '5000', '14700', '100.00');
        self::assertSame(['0.00', '-300.00', false, true, '-300.00'], [$f['announced_variance_l'], $f['tank_variance_l'], $f['announced_exceeds'], $f['tank_exceeds'], $f['worst_l']]);

        // Un manque prime sur un excédent plus grand.
        $f = DeliveryCalculator::compute('10000', '10500', '5000', '15200', '100.00');
        self::assertSame(['500.00', '-300.00', true, '-300.00'], [$f['announced_variance_l'], $f['tank_variance_l'], $f['shortage'], $f['worst_l']]);

        $f = DeliveryCalculator::compute('10000', '10200', '5000', '15200', '100.00');
        self::assertSame([true, false, '200.00'], [$f['exceeds'], $f['shortage'], $f['worst_l']], 'Excédent seul');
        self::assertSame('0.01', DeliveryCalculator::compute('0.10', '0.20', '0.10', '0.31', '0')['tank_variance_l'], 'Sans erreur d\'arrondi flottant');
    }

    // --- Réception --------------------------------------------------------------------------

    public function testReceptionStoresEveryFieldBothDipsAndFrozenGaps(): void
    {
        $result = $this->deliveries()->receive($this->station, $this->input(['received_volume_l' => '9 950,5', 'after_cm' => '149,2']), null, self::at('09:00:00'));

        $row = $this->row('SELECT * FROM deliveries WHERE id = :id', ['id' => $result['id']]);
        self::assertSame(
            ['SEP Congo', 'BL-2026-0412', '1234AB/05', 'Paul Kalala', '10000.00', '10000.00', '9950.50', '-49.50', '-30.50', $this->manager, $this->manager, 'Plombs intacts', null],
            [$row['supplier_name'], $row['delivery_note_no'], $row['truck_plate'], $row['driver_name'], $row['ordered_volume_l'], $row['invoiced_volume_l'], $row['received_volume_l'],
                $row['announced_variance_l'], $row['tank_variance_l'], $row['received_by'], $row['created_by'], $row['note'], $row['photo_path']]
        );
        self::assertSame([$this->station, $this->tank, self::DAY . ' 09:00:01'], [$row['station_id'], $row['tank_id'], $row['delivered_at']]);

        $before = $this->row('SELECT * FROM tank_dips WHERE id = :id', ['id' => $row['dip_before_id']]);
        $after = $this->row('SELECT * FROM tank_dips WHERE id = :id', ['id' => $row['dip_after_id']]);
        self::assertSame(['before_delivery', '50.0', '5000.00', self::DAY . ' 09:00:00'], [$before['dip_type'], $before['depth_cm'], $before['volume_l'], $before['measured_at']]);
        self::assertSame(['after_delivery', '149.2', '14920.00', self::DAY . ' 09:00:01'], [$after['dip_type'], $after['depth_cm'], $after['volume_l'], $after['measured_at']]);
        self::assertSame(['14950.50', '-30.50'], [$after['theoretical_l'], $after['variance_l']], 'Théorique après = jaugé avant + reçu ; son écart est l\'écart reçu / cuve');

        self::assertFalse($result['alert']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM alerts'));
        $log = json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'delivery'"), true);
        self::assertSame(['9920.00', '5000.00', '14920.00', false], [$log['tank_variation_l'], $log['before_l'], $log['after_l'], $log['alert']]);
    }

    public function testTheDeliveryIsCountedOnceInTheTankStock(): void
    {
        $this->service($this->manager, StockControl::class)->recordDip($this->tank, ['depth_cm' => '50', 'dip_type' => 'control'], self::at('06:00:00'));
        $this->deliveries()->receive($this->station, $this->input(), null, self::at('09:00:00'));

        $position = $this->service($this->manager, StockControl::class)->position($this->tank, self::DAY . ' 10:00:00');

        self::assertSame(['15000.00', '0.00', '15000.00'], [$position['initial_l'], $position['deliveries_l'], $position['theoretical_l']], 'Le stock repart du jaugeage après : la livraison n\'est pas recomptée');
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
        self::assertSame('0.00', $this->value("SELECT variance_l FROM tank_dips WHERE dip_type = 'before_delivery'"), 'Le jaugeage avant est aussi un contrôle du stock');
    }

    // --- Alerte ---------------------------------------------------------------------------------

    public function testShortageBeyondToleranceRaisesACriticalAlert(): void
    {
        // Annoncé 10 000, reçu déclaré 10 000, mais la cuve ne monte que de 9 800 L : 200 L manquent (tolérance 100 L).
        $result = $this->deliveries()->receive($this->station, $this->input(['after_cm' => '148']), null, self::at('09:00:00'));

        self::assertTrue($result['alert']);
        $alert = $this->row("SELECT * FROM alerts WHERE alert_type = 'delivery_variance'");
        self::assertSame(
            [$this->station, 'delivery', $result['id'], '-200.00', -690000, 'CDF', '100.00', 'critical', $this->manager],
            [$alert['station_id'], $alert['entity_type'], $alert['entity_id'], $alert['volume_l'], (int) $alert['amount'], $alert['currency'], $alert['threshold_value'], $alert['severity'], $alert['user_id']],
            '200 L manquants × 3 450 FC'
        );
        self::assertStringContainsString('SEP Congo', $alert['message']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'stock_variance'"), 'Pas de seconde alerte de stock pour le même écart');
    }

    public function testAnnouncedVersusReceivedGapAndStationTolerance(): void
    {
        // Reçu 9 850 pour 10 000 annoncés, cuve cohérente avec le reçu : seul l'écart annoncé / reçu dépasse.
        $first = $this->deliveries()->receive($this->station, $this->input(['received_volume_l' => '9850', 'after_cm' => '148,5']), null, self::at('09:00:00'));
        self::assertSame(['-150.00', '0.00', true], [$first['figures']['announced_variance_l'], $first['figures']['tank_variance_l'], $first['alert']]);

        // Station plus tolérante : 200 L. Le même écart ne lève plus d'alerte.
        $this->db->execute("INSERT INTO station_settings (id, station_id, setting_key, setting_value) VALUES (UUID(), :s, 'delivery_variance_l', '200')", ['s' => $this->station]);
        $second = $this->deliveries()->receive($this->station, $this->input(['received_volume_l' => '9850', 'before_cm' => '148,5', 'after_cm' => '247']), null, self::at('11:00:00'));
        self::assertFalse($second['alert']);

        // Excédent : avertissement.
        $third = $this->deliveries()->receive($this->station, $this->input(['invoiced_volume_l' => '1000', 'received_volume_l' => '1300', 'before_cm' => '247', 'after_cm' => '260']), null, self::at('13:00:00'));
        self::assertTrue($third['alert']);
        self::assertSame(['warning', '300.00'], array_values($this->row("SELECT severity, volume_l FROM alerts WHERE entity_id = :id", ['id' => $third['id']])));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM alerts WHERE alert_type = 'delivery_variance'"));
    }

    // --- Validation -----------------------------------------------------------------------------

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'fournisseur absent' => [['supplier_id' => ''], 'supplier_id'],
            'fournisseur hors répertoire' => [['supplier_id' => '0190a7c2-6f3e-7b1a-9c4d-2e5f8a1b3c7e'], 'supplier_id'],
            'annoncé nul' => [['invoiced_volume_l' => '0'], 'invoiced_volume_l'],
            'reçu négatif' => [['received_volume_l' => '-5'], 'received_volume_l'],
            'reçu à trois décimales' => [['received_volume_l' => '10.123'], 'received_volume_l'],
            'commandé nul' => [['ordered_volume_l' => '0'], 'ordered_volume_l'],
            'jaugeage avant absent' => [['before_cm' => ''], 'before_cm'],
            'jaugeage après hors barème' => [['after_cm' => '300,1'], 'after_cm'],
            'cuve qui baisse' => [['before_cm' => '150', 'after_cm' => '149,9'], 'after_cm'],
            'cuve inconnue' => [['tank_id' => '0190a7c2-6f3e-7b1a-9c4d-2e5f8a1b3c7d'], 'tank_id'],
            'réceptionnaire inconnu' => [['received_by' => '0190a7c2-6f3e-7b1a-9c4d-2e5f8a1b3c7d'], 'received_by'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testInvalidReceptionsAreRejectedEntirely(array $override, string $field): void
    {
        try {
            $this->deliveries()->receive($this->station, $this->input($override), $this->upload(base64_decode(self::PNG)), self::at('09:00:00'));
            self::fail('Réception acceptée');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM deliveries') + (int) $this->value('SELECT COUNT(*) FROM tank_dips'));
        self::assertSame([], glob($this->uploads . '/*/deliveries/*') ?: [], 'Aucune photo orpheline');
    }

    public function testATankOfAnotherStationOrWithoutChartIsRefused(): void
    {
        $other = $this->createStation($this->org, 'B');
        $foreignTank = $this->createTank($other, $this->createProduct($other));
        $bare = $this->createTank($this->station, $this->product, 'C-NU');

        foreach ([$foreignTank => 'tank_id', $bare => 'before_cm'] as $tank => $field) {
            try {
                $this->deliveries($this->owner)->receive($this->station, $this->input(['tank_id' => $tank, 'received_by' => $this->owner]), null, self::at('09:00:00'));
                self::fail('Cuve acceptée');
            } catch (ValidationException $e) {
                self::assertSame([$field], array_keys($e->errors));
            }
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM deliveries'));
    }

    public function testDeliveriesAreAppendOnly(): void
    {
        $id = $this->deliveries()->receive($this->station, $this->input(), null, self::at('09:00:00'))['id'];

        foreach (["UPDATE deliveries SET received_volume_l = 1 WHERE id = '$id'", 'DELETE FROM deliveries'] as $sql) {
            try {
                $this->db->execute($sql);
                self::fail("Accepté : $sql");
            } catch (PDOException $e) {
                self::assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
    }

    // --- Photo -------------------------------------------------------------------------------------

    public function testPhotoIsStoredUnderTheStationFolderOutsideTheWebRoot(): void
    {
        $id = $this->deliveries()->receive($this->station, $this->input(), $this->upload(base64_decode(self::PNG), '../../public/index.php'), self::at('09:00:00'))['id'];

        $path = (string) $this->value('SELECT photo_path FROM deliveries WHERE id = :id', ['id' => $id]);
        self::assertMatchesRegularExpression('#^' . $this->station . '/deliveries/[0-9a-f-]{36}\.png$#', $path, 'storage/uploads/{station_id}/… avec un nom généré');
        self::assertFileExists($this->uploads . '/' . $path);
        self::assertSame(base64_decode(self::PNG), file_get_contents($this->uploads . '/' . $path));
        self::assertStringNotContainsString('index', $path, 'Le nom envoyé n\'entre pas dans le chemin');
        self::assertFalse(str_starts_with(realpath($this->uploads), realpath(dirname(__DIR__, 3) . '/public')));
        self::assertDirectoryDoesNotExist(dirname(__DIR__, 3) . '/public/uploads');
    }

    public function testOnlyRealImagesAreAccepted(): void
    {
        foreach ([
            'script déguisé' => $this->upload('<?php echo "x";', 'photo.png'),
            'texte' => $this->upload('pas une image', 'photo.jpg'),
            'trop lourde' => $this->upload(base64_decode(self::PNG) . str_repeat('0', FileStore::MAX_IMAGE_BYTES), 'photo.png'),
            'envoi interrompu' => ['tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL],
        ] as $case => $file) {
            try {
                $this->deliveries()->receive($this->station, $this->input(), $file, self::at('09:00:00'));
                self::fail("Fichier accepté : $case");
            } catch (ValidationException $e) {
                self::assertSame(['photo'], array_keys($e->errors), $case);
            }
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM deliveries'));
        // Aucun fichier envoyé : la photo est facultative.
        $this->deliveries()->receive($this->station, $this->input(), ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], self::at('09:00:00'));
        self::assertNull($this->value('SELECT photo_path FROM deliveries'));
    }

    public function testPhotoIsServedOnlyToUsersOfTheStation(): void
    {
        $id = $this->deliveries()->receive($this->station, $this->input(), $this->upload(base64_decode(self::PNG)), self::at('09:00:00'))['id'];
        $bare = $this->deliveries()->receive($this->station, $this->input(['before_cm' => '150', 'after_cm' => '250']), null, self::at('11:00:00'))['id'];
        $other = $this->createStation($this->org, 'B');
        $managerB = $this->sessionFor($this->createUser($this->org, 'manager', [$other]));
        $attendant = $this->sessionFor($this->createUser($this->org, 'attendant', [$this->station]));

        $photo = $this->http($this->sessionFor($this->manager), 'GET', "/deliveries/$id/photo");
        self::assertSame(200, $photo->status);
        self::assertSame('image/png', $photo->headers['Content-Type']);
        self::assertSame(base64_decode(self::PNG), $photo->body);
        self::assertSame('nosniff', $photo->headers['X-Content-Type-Options']);
        self::assertStringStartsWith('private', $photo->headers['Cache-Control']);

        self::assertSame(302, $this->http(\Petra\Core\Session::memory(), 'GET', "/deliveries/$id/photo")->status, 'Anonyme : renvoyé à la connexion');
        self::assertSame(403, $this->http($managerB, 'GET', "/deliveries/$id/photo")->status, 'Gérant d\'une autre station');
        self::assertSame(403, $this->http($managerB, 'GET', "/deliveries/$id")->status);
        self::assertSame(403, $this->http($attendant, 'GET', "/deliveries/$id/photo")->status, 'Pompiste : pas le droit de voir les livraisons');
        self::assertSame(404, $this->http($this->sessionFor($this->manager), 'GET', "/deliveries/$bare/photo")->status, 'Livraison sans photo');
        self::assertSame(200, $this->http($this->sessionFor($this->owner), 'GET', "/deliveries/$id/photo")->status);

        // Un chemin trafiqué en base ne sort jamais du dossier de la station.
        $store = new FileStore($this->uploads);
        foreach (['../secret.png', $other . '/deliveries/' . Uuid::v7() . '.png', $this->station . '/deliveries/../../x.png', '/etc/passwd'] as $path) {
            self::assertNull($store->read($path, $this->station), $path);
        }
    }

    // --- Écrans ---------------------------------------------------------------------------------------

    public function testScreensFromFormToSheet(): void
    {
        $session = $this->sessionFor($this->manager);

        $form = $this->http($session, 'GET', '/deliveries/create');
        self::assertSame(200, $form->status);
        foreach (['name="supplier_id"', '>SEP Congo</option>', 'name="delivery_note_no"', 'name="ordered_volume_l"', 'name="invoiced_volume_l"', 'name="received_volume_l"', 'name="before_cm"',
            'name="after_cm"', 'name="received_by"', 'name="note"', 'name="photo"', 'enctype="multipart/form-data"', 'field--xl', 'data-confirm="', 'C-ESS — Essence'] as $text) {
            self::assertStringContainsString($text, $form->body);
        }
        self::assertStringNotContainsString('<script>', $form->body, 'Aucun calcul dans la page');

        $post = $this->http($session, 'POST', '/deliveries', $this->input(['after_cm' => '148']), ['referer' => 'http://localhost/deliveries/create'], true, ['photo' => $this->upload(base64_decode(self::PNG))]);
        $id = (string) $this->value('SELECT id FROM deliveries');
        self::assertSame("/deliveries/$id", $post->headers['Location']);

        $sheet = $this->http($session, 'GET', "/deliveries/$id");
        foreach (['SEP Congo', 'Bon n° BL-2026-0412', 'Écart annoncé / reçu', 'Écart reçu / variation de cuve', "9\u{202F}800,00", '-200,00 L', 'Manager Test', "/deliveries/$id/photo", 'une alerte a été levée'] as $text) {
            self::assertStringContainsString($text, $sheet->body);
        }

        $list = $this->http($session, 'GET', '/deliveries');
        self::assertStringContainsString('data-table="deliveries"', $list->body);
        $data = json_decode($this->http($session, 'GET', '/deliveries/data?draw=2', [], self::AJAX)->body, true);
        self::assertSame([2, 1, 'SEP Congo', '-200.00', 1], [$data['draw'], $data['recordsTotal'], $data['data'][0]['supplier_name'], $data['data'][0]['tank_variance_l'], (int) $data['data'][0]['has_photo']]);

        // Saisie invalide : retour au formulaire avec le message et les valeurs.
        $bad = $this->http($session, 'POST', '/deliveries', $this->input(['before_cm' => '200', 'after_cm' => '100']), ['referer' => 'http://localhost/deliveries/create']);
        self::assertSame('/deliveries/create', $bad->headers['Location']);
        $again = $this->http($session, 'GET', '/deliveries/create');
        self::assertStringContainsString('ne peut pas être plus bas', $again->body);
        self::assertStringContainsString('value="' . $this->supplier . '" selected', $again->body);
    }

    public function testOnlyStaffOfTheStationReceivesDeliveries(): void
    {
        $other = $this->createStation($this->org, 'B');
        $managerB = $this->createUser($this->org, 'manager', [$other]);
        $attendant = $this->sessionFor($this->createUser($this->org, 'attendant', [$this->station]));

        try {
            $this->deliveries($managerB)->receive($this->station, $this->input(['received_by' => $managerB]), null, self::at('09:00:00'));
            self::fail('Réception sur une autre station acceptée');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame(403, $this->http($attendant, 'GET', '/deliveries')->status);
        self::assertSame(403, $this->http($attendant, 'POST', '/deliveries', $this->input())->status);
        self::assertSame(409, $this->http($this->sessionFor($this->owner), 'GET', '/deliveries/create')->status, 'Propriétaire sans station active');
        self::assertSame(0, json_decode($this->http($this->sessionFor($managerB), 'GET', '/deliveries/data', [], self::AJAX)->body, true)['recordsTotal']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM deliveries'));
    }
}
