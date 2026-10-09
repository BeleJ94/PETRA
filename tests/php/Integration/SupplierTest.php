<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Petra\Core\HttpException;
use Petra\Core\Uuid;
use Petra\Core\ValidationException;
use Petra\Services\DeliveryService;
use Petra\Services\SupplierService;
use Tests\DatabaseTestCase;

/** Répertoire des fournisseurs : fiches par station, choisies à la réception d'une livraison. */
final class SupplierTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $a;
    private string $b;
    private string $owner;
    private string $manager;
    private string $managerB;
    private string $attendant;
    private string $tank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->a = $this->createStation($this->org, 'A');
        $this->b = $this->createStation($this->org, 'B');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->manager = $this->createUser($this->org, 'manager', [$this->a]);
        $this->managerB = $this->createUser($this->org, 'manager', [$this->b]);
        $this->attendant = $this->createUser($this->org, 'attendant', [$this->a]);
        $product = $this->createProduct($this->a, 'ESS', 'Essence');
        $this->tank = $this->createTank($this->a, $product, 'C-ESS', '30000.00');
        $this->createRate($this->a, 2300, '2026-10-05 05:00:00', $this->owner);
        $this->createPrice($this->a, $product, 3450, '2026-10-05 05:00:00', $this->owner);
        foreach ([['0.0', '0.00'], ['300.0', '30000.00']] as [$depth, $volume]) {
            $this->db->execute(
                'INSERT INTO tank_calibrations (id, station_id, tank_id, depth_cm, volume_l) VALUES (:id, :s, :t, :d, :v)',
                ['id' => Uuid::v7(), 's' => $this->a, 't' => $this->tank, 'd' => $depth, 'v' => $volume]
            );
        }
    }

    private function suppliers(?string $userId = null): SupplierService
    {
        return $this->service($userId ?? $this->manager, SupplierService::class);
    }

    private function receive(string $supplierId, string $time = '09:00:00', array $override = []): array
    {
        return $this->service($this->manager, DeliveryService::class)->receive($this->a, $override + [
            'tank_id' => $this->tank, 'supplier_id' => $supplierId, 'invoiced_volume_l' => '1000', 'received_volume_l' => '1000',
            'before_cm' => '50', 'after_cm' => '60', 'received_by' => $this->manager,
        ], null, new DateTimeImmutable('2026-10-05 ' . $time));
    }

    public function testASupplierIsCreatedInTheStationDirectoryAndAudited(): void
    {
        $id = $this->suppliers()->create($this->a, ['name' => '  SEP   Congo ', 'contact_name' => 'Marc Tshilombo', 'phone' => '+243 81 555 01 20', 'email' => 'depot@sep.example', 'address' => 'Av. Kasa-Vubu']);

        self::assertTrue(Uuid::isValid($id));
        self::assertSame(
            [$this->a, 'SEP Congo', 'Marc Tshilombo', '+243 81 555 01 20', 'depot@sep.example', 'Av. Kasa-Vubu', 1, $this->manager],
            array_values($this->row('SELECT station_id, name, contact_name, phone, email, address, is_active, created_by FROM suppliers WHERE id = :id', ['id' => $id]))
        );
        self::assertSame(['create', $this->a, $this->manager], array_values($this->row("SELECT action, station_id, created_by FROM audit_log WHERE entity_type = 'supplier' AND entity_id = :id", ['id' => $id])));
        // La fiche part vers le central avec les autres données de la station.
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'suppliers' AND entity_id = :id AND station_id = :s", ['id' => $id, 's' => $this->a]));
    }

    public function testNamesAreUniquePerStationAndValidated(): void
    {
        $this->suppliers()->create($this->a, ['name' => 'SEP Congo']);
        foreach ([[['name' => 'sep congo'], 'name'], [['name' => '   '], 'name'], [['name' => str_repeat('x', 121)], 'name'], [['name' => 'Engen', 'email' => 'pas-un-mail'], 'email']] as [$input, $field]) {
            try {
                $this->suppliers()->create($this->a, $input);
                self::fail('Fiche acceptée : ' . json_encode($input));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
        // Le même fournisseur livre une autre station : chaque station tient sa fiche.
        $this->suppliers($this->managerB)->create($this->b, ['name' => 'SEP Congo']);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM suppliers'));
    }

    public function testASupplierBelongsToItsStation(): void
    {
        $id = $this->suppliers()->create($this->a, ['name' => 'SEP Congo']);

        foreach ([fn () => $this->suppliers($this->managerB)->create($this->a, ['name' => 'Engen RDC']), fn () => $this->suppliers($this->managerB)->update($id, ['name' => 'Autre'])] as $attempt) {
            try {
                $attempt();
                self::fail("Écriture acceptée sur le répertoire d'une autre station");
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame(['SEP Congo'], array_column($this->db->fetchAll('SELECT name FROM suppliers'), 'name'));

        // Le gérant de B ne voit pas le répertoire de A ; le propriétaire voit les deux.
        $this->createSupplier($this->b, 'Puma Energy');
        $pageB = $this->http($this->sessionFor($this->managerB), 'GET', '/suppliers')->body;
        self::assertStringContainsString('Puma Energy', $pageB);
        self::assertStringNotContainsString('SEP Congo', $pageB);
        $all = $this->http($this->sessionFor($this->owner), 'GET', '/suppliers')->body;
        self::assertStringContainsString('Puma Energy', $all);
        self::assertStringContainsString('SEP Congo', $all);
    }

    public function testADeliveryPicksItsSupplierFromTheDirectory(): void
    {
        $sep = $this->suppliers()->create($this->a, ['name' => 'SEP Congo']);
        $elsewhere = $this->createSupplier($this->b, 'Puma Energy');
        $retired = $this->createSupplier($this->a, 'Ancien Dépôt', false);

        $delivery = $this->receive($sep);
        self::assertSame([$sep, 'SEP Congo'], array_values($this->row('SELECT supplier_id, supplier_name FROM deliveries WHERE id = :id', ['id' => $delivery['id']])));

        // Hors répertoire, désactivé ou absent : la réception est refusée, rien n'est écrit.
        // Le fournisseur d'une autre station : accès hors périmètre, refusé comme tel.
        try {
            $this->receive($elsewhere, '11:00:00', ['before_cm' => '60', 'after_cm' => '70']);
            self::fail("Réception acceptée avec le fournisseur d'une autre station");
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        foreach (['désactivé' => $retired, 'inconnu' => Uuid::v7(), 'absent' => ''] as $case => $supplierId) {
            try {
                $this->receive($supplierId, '11:00:00', ['before_cm' => '60', 'after_cm' => '70']);
                self::fail("Réception acceptée : fournisseur $case");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('supplier_id', $e->errors, $case);
            }
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM deliveries'));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM tank_dips'), 'Aucun jaugeage ne reste d\'une réception refusée');
    }

    public function testRenamingOrRetiringASupplierDoesNotRewriteDeliveries(): void
    {
        $sep = $this->suppliers()->create($this->a, ['name' => 'SEP Congo']);
        $delivery = $this->receive($sep);

        $this->suppliers()->update($sep, ['name' => 'SEP Congo SA', 'phone' => '0815550120', 'is_active' => '0']);

        self::assertSame(['SEP Congo SA', '0815550120', 0], array_values($this->row('SELECT name, phone, is_active FROM suppliers WHERE id = :id', ['id' => $sep])));
        // Ajout seul : la livraison garde le nom que le fournisseur portait à la réception.
        self::assertSame('SEP Congo', $this->value('SELECT supplier_name FROM deliveries WHERE id = :id', ['id' => $delivery['id']]));
        self::assertSame(['create', 'update'], array_column($this->db->fetchAll("SELECT action FROM audit_log WHERE entity_type = 'supplier' AND entity_id = :id ORDER BY created_at, id", ['id' => $sep]), 'action'));
        // Désactivé : il n'est plus proposé à la réception.
        $form = $this->http($this->sessionFor($this->manager), 'GET', '/deliveries/create')->body;
        self::assertStringContainsString('Aucun fournisseur en activité', $form);
        self::assertStringNotContainsString('name="supplier_id"', $form);
        // Le répertoire compte toujours sa livraison.
        $page = $this->http($this->sessionFor($this->manager), 'GET', '/suppliers')->body;
        foreach (['SEP Congo SA', 'Désactivé', '0 en activité sur 1 fiche(s)', "1\u{202F}000 L"] as $text) {
            self::assertStringContainsString($text, $page, $text);
        }
    }

    public function testDirectoryScreensAndRights(): void
    {
        $manager = $this->sessionFor($this->manager);

        // Fenêtre de création, envoi sans rechargement, liste à jour.
        $form = $this->http($manager, 'GET', '/suppliers/form');
        self::assertSame(200, $form->status);
        self::assertStringNotContainsString('<html', $form->body);
        foreach (['data-ajax-form', 'name="name"', 'name="contact_name"', 'name="phone"', 'name="email"', 'name="address"', 'Nouveau fournisseur'] as $text) {
            self::assertStringContainsString($text, $form->body, $text);
        }
        $created = $this->http($manager, 'POST', '/suppliers', ['name' => 'Engen RDC', 'phone' => '0997004410'], self::AJAX);
        self::assertSame(200, $created->status);
        self::assertSame(['ok' => true, 'message' => 'Fournisseur ajouté au répertoire.', 'redirect' => url('/suppliers')], json_decode($created->body, true));
        $id = (string) $this->value("SELECT id FROM suppliers WHERE name = 'Engen RDC'");

        $duplicate = $this->http($manager, 'POST', '/suppliers', ['name' => 'ENGEN RDC'], self::AJAX);
        self::assertSame(422, $duplicate->status);
        self::assertArrayHasKey('name', json_decode($duplicate->body, true)['errors']);

        $edit = $this->http($manager, 'GET', "/suppliers/$id/form");
        foreach (['value="Engen RDC"', 'value="0997004410"', 'name="is_active"', "action=\"" . url("/suppliers/$id") . '"'] as $text) {
            self::assertStringContainsString($text, $edit->body, $text);
        }
        self::assertSame(200, $this->http($manager, 'POST', "/suppliers/$id", ['name' => 'Engen RDC', 'contact_name' => 'Aline Mbuyi', 'is_active' => '1'], self::AJAX)->status);

        $page = $this->http($manager, 'GET', '/suppliers')->body;
        foreach (['Fournisseurs', 'Engen RDC', 'Aline Mbuyi', 'data-modal-url="' . url('/suppliers/form') . '"', "/suppliers/$id/form", 'Petra — développé par BELEJ-CONSULTING'] as $text) {
            self::assertStringContainsString($text, $page, $text);
        }
        // La réception propose la fiche.
        self::assertStringContainsString('<option value="' . $id . '">Engen RDC</option>', $this->http($manager, 'GET', '/deliveries/create')->body);

        // Le pompiste ne tient pas le répertoire ; le propriétaire sans station choisie est invité à en choisir une.
        $attendant = $this->sessionFor($this->attendant);
        foreach ([['GET', '/suppliers'], ['GET', '/suppliers/form'], ['POST', '/suppliers'], ['POST', "/suppliers/$id"]] as [$method, $path]) {
            self::assertSame(403, $this->http($attendant, $method, $path, ['name' => 'X'])->status, "$method $path");
        }
        $owner = $this->http($this->sessionFor($this->owner), 'GET', '/suppliers')->body;
        self::assertStringContainsString('Choisissez une station', $owner);
        self::assertStringNotContainsString('data-modal-url="' . url('/suppliers/form') . '"', $owner);
        self::assertSame(409, $this->http($this->sessionFor($this->owner), 'POST', '/suppliers', ['name' => 'Sans station'], self::AJAX)->status);
    }
}
