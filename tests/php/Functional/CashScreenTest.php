<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Session;
use Tests\DatabaseTestCase;

/** Écran « Caisse du poste » : remise, crédit, dépense, écart, justification. */
final class CashScreenTest extends DatabaseTestCase
{
    private string $org;
    private string $station;
    private string $owner;
    private string $managerId;
    private string $nozzle;
    private string $shift;
    private Session $manager;
    private Session $attendant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->managerId = $this->createUser($this->org, 'manager', [$this->station]);
        $attendantId = $this->createUser($this->org, 'attendant', [$this->station]);
        $product = $this->createProduct($this->station);
        $this->nozzle = $this->createNozzle($this->station, $this->createTank($this->station, $product), 'P-ESS');
        $past = date('Y-m-d H:i:s', time() - 86400);
        $this->createRate($this->station, 2300, $past, $this->owner);
        $this->createPrice($this->station, $product, 5000, $past, $this->owner);
        $this->manager = $this->sessionFor($this->managerId);
        $this->attendant = $this->sessionFor($attendantId);

        // Poste clôturé : 100 L à 5 000 FC, soit 500 000 FC de CA théorique.
        $this->post($this->manager, '/shifts', ['attendant_id' => $attendantId, 'opening' => [$this->nozzle => '0']]);
        $this->shift = (string) $this->value('SELECT id FROM shifts');
        $this->post($this->attendant, "/shifts/{$this->shift}/close", ['final' => [$this->nozzle => '100']]);
    }

    private function post(Session $session, string $path, array $body = []): \Petra\Core\Response
    {
        return $this->http($session, 'POST', $path, $body, ['referer' => 'http://localhost' . (isset($this->shift) ? "/shifts/{$this->shift}/cash" : '/shifts')]);
    }

    public function testCashStatementFromHandoverToJustification(): void
    {
        $path = "/shifts/{$this->shift}";
        $page = $this->http($this->manager, 'GET', "$path/cash");
        self::assertSame(200, $page->status);
        foreach (['CA théorique', "500\u{202F}000 FC", 'Caisse attendue', 'Caisse remise', 'Déficit', "-500\u{202F}000 FC"] as $text) {
            self::assertStringContainsString($text, $page->body);
        }
        foreach (['#new-handover', '#new-credit', '#new-expense'] as $dialog) {
            self::assertStringContainsString('data-open="' . $dialog . '"', $page->body);
        }
        self::assertStringContainsString('input--xl', $page->body, 'Gros champ numérique');
        self::assertStringNotContainsString('#new-justification', $page->body, 'Pas de justification avant la validation');
        self::assertStringNotContainsString('<script>', $page->body, 'Aucun calcul dans la page');

        self::assertSame("$path/cash", $this->post($this->manager, "$path/credit-sales", ['nozzle_id' => $this->nozzle, 'customer_name' => 'Mining Co', 'voucher_no' => 'B-7', 'volume_l' => '2'])->headers['Location']);
        $this->post($this->manager, "$path/expenses", ['category' => 'transport', 'description' => 'Course banque', 'amount' => '5000', 'currency' => 'CDF']);
        $this->post($this->manager, "$path/handovers", ['amount' => '470 000', 'currency' => 'CDF', 'note' => 'Compté devant le pompiste']);

        $page = $this->http($this->manager, 'GET', "$path/cash");
        foreach (['Mining Co', 'Bon n° B-7', 'Course banque', "485\u{202F}000 FC", "470\u{202F}000 FC", "-15\u{202F}000 FC", 'Manager Test'] as $text) {
            self::assertStringContainsString($text, $page->body);
        }

        // La fiche du poste montre l'écart avant la validation.
        $sheet = $this->http($this->manager, 'GET', $path);
        self::assertStringContainsString("$path/cash", $sheet->body);
        self::assertStringContainsString("-15\u{202F}000 FC", $sheet->body);
        $this->post($this->manager, "$path/validate");
        self::assertSame(-15000, (int) $this->value('SELECT variance_amount FROM shifts'));
        self::assertStringContainsString('Déficit de caisse de 15', $this->http($this->manager, 'GET', '/alerts')->body);

        // Après validation : plus de saisie, mais la justification devient possible.
        $page = $this->http($this->manager, 'GET', "$path/cash");
        self::assertStringNotContainsString('#new-handover', $page->body);
        self::assertStringContainsString('data-open="#new-justification"', $page->body);
        self::assertStringContainsString('Écart non justifié', $page->body);

        $this->post($this->manager, "$path/justifications", ['amount' => '15000', 'reason' => 'Billets refusés par la banque']);
        $page = $this->http($this->manager, 'GET', "$path/cash");
        self::assertStringContainsString('Billets refusés par la banque', $page->body);
        self::assertStringContainsString('reste à expliquer : 0 FC', $page->body);
        self::assertStringContainsString("-15\u{202F}000 FC", $page->body, 'L\'écart constaté reste affiché');
        self::assertStringNotContainsString('#new-justification', $page->body, 'Plus rien à expliquer');
    }

    public function testInvalidEntriesComeBackWithAMessage(): void
    {
        $path = "/shifts/{$this->shift}";

        self::assertSame("$path/cash", $this->post($this->manager, "$path/handovers", ['amount' => '12,5', 'currency' => 'CDF'])->headers['Location']);
        self::assertStringContainsString('nombre entier positif de francs', $this->http($this->manager, 'GET', "$path/cash")->body);
        $this->post($this->manager, "$path/credit-sales", ['nozzle_id' => $this->nozzle, 'customer_name' => 'Trop', 'volume_l' => '150']);
        self::assertStringContainsString('dépasse les litres vendus', $this->http($this->manager, 'GET', "$path/cash")->body);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM cash_handovers') + (int) $this->value('SELECT COUNT(*) FROM credit_sales'));
    }

    public function testTheAttendantSeesHisCashButDoesNotReceiveItHimself(): void
    {
        $path = "/shifts/{$this->shift}";
        $page = $this->http($this->attendant, 'GET', "$path/cash");

        self::assertSame(200, $page->status);
        self::assertStringContainsString('CA théorique', $page->body);
        foreach (['#new-handover', '#new-credit', '#new-expense', '#new-justification'] as $dialog) {
            self::assertStringNotContainsString($dialog, $page->body);
        }
        foreach (['handovers' => ['amount' => '500000', 'currency' => 'CDF'], 'credit-sales' => [], 'expenses' => [], 'justifications' => []] as $action => $body) {
            self::assertSame(403, $this->http($this->attendant, 'POST', "$path/$action", $body)->status, $action);
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM cash_handovers'));

        $colleague = $this->sessionFor($this->createUser($this->org, 'attendant', [$this->station]));
        self::assertSame(403, $this->http($colleague, 'GET', "$path/cash")->status);
    }

    public function testStationRuleForUnallocatedDollarsIsSetOnTheStationSheet(): void
    {
        $owner = $this->sessionFor($this->owner);
        $sheet = $this->http($owner, 'GET', "/stations/{$this->station}");
        self::assertStringContainsString('name="usd_unallocated_rate"', $sheet->body);
        self::assertStringContainsString('taux de la dernière tranche', $sheet->body);
        $thresholds = ['stock_tolerance_pct' => '0.5', 'stock_tolerance_l' => '50', 'low_stock_l' => '3000', 'cash_shortage_tolerance_cdf' => '5000', 'cash_surplus_tolerance_cdf' => '5000', 'delivery_variance_l' => '100'];

        $this->http($owner, 'POST', "/stations/{$this->station}/thresholds", $thresholds + ['usd_unallocated_rate' => 'lowest']);
        self::assertSame('lowest', $this->value("SELECT setting_value FROM station_settings WHERE setting_key = 'usd_unallocated_rate'"));
        self::assertSame('lowest', json_decode((string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'station_settings' ORDER BY created_at DESC, id DESC LIMIT 1"), true)['usd_unallocated_rate']);

        $this->http($owner, 'POST', "/stations/{$this->station}/thresholds", $thresholds + ['usd_unallocated_rate' => 'au hasard'], ['referer' => "http://localhost/stations/{$this->station}"]);
        self::assertSame('lowest', $this->value("SELECT setting_value FROM station_settings WHERE setting_key = 'usd_unallocated_rate'"));
        // Sans le champ : la règle en place est conservée.
        $this->http($owner, 'POST', "/stations/{$this->station}/thresholds", $thresholds);
        self::assertSame('lowest', $this->value("SELECT setting_value FROM station_settings WHERE setting_key = 'usd_unallocated_rate'"));
    }
}
