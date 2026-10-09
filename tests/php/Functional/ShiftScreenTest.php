<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Session;
use Tests\DatabaseTestCase;

/** Écrans des postes : ouverture par le gérant, saisie par le pompiste, contre-validation. */
final class ShiftScreenTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private string $org;
    private string $station;
    private string $owner;
    private string $managerId;
    private string $attendantId;
    private string $nozzle;
    private Session $manager;
    private Session $attendant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
        $this->owner = $this->createUser($this->org, 'owner');
        $this->managerId = $this->createUser($this->org, 'manager', [$this->station]);
        $this->attendantId = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste1');
        $product = $this->createProduct($this->station);
        $this->nozzle = $this->createNozzle($this->station, $this->createTank($this->station, $product), 'P-ESS');
        $past = date('Y-m-d H:i:s', time() - 86400);
        $this->createRate($this->station, 2300, $past, $this->owner);
        $this->createPrice($this->station, $product, 3450, $past, $this->owner);
        $this->manager = $this->sessionFor($this->managerId);
        $this->attendant = $this->sessionFor($this->attendantId);
    }

    private function post(Session $session, string $path, array $body, string $referer): \Petra\Core\Response
    {
        return $this->http($session, 'POST', $path, $body, ['referer' => 'http://localhost' . $referer]);
    }

    private function shiftId(): string
    {
        return (string) $this->value('SELECT id FROM shifts ORDER BY opened_at DESC, id DESC LIMIT 1');
    }

    public function testFullCycleFromOpeningToCounterValidation(): void
    {
        // Gérant : ouverture. Premier relevé du pistolet, donc index à saisir.
        $list = $this->http($this->manager, 'GET', '/shifts');
        self::assertStringContainsString('/shifts/open', $list->body);
        self::assertStringContainsString('Aucun poste ouvert', $list->body);
        $form = $this->http($this->manager, 'GET', '/shifts/open');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="opening[' . $this->nozzle . ']"', $form->body);
        self::assertStringContainsString('input--xl', $form->body, 'Gros champ numérique');
        self::assertStringContainsString('inputmode="decimal"', $form->body);
        self::assertStringContainsString('data-confirm="', $form->body, 'Confirmation SweetAlert2');
        self::assertStringContainsString('Attendant Test', $form->body);

        $opened = $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();
        self::assertSame('/shifts/' . $shift, $opened->headers['Location']);
        self::assertStringNotContainsString('/shifts/open"', $this->http($this->manager, 'GET', '/shifts')->body, 'Un seul poste ouvert : plus de bouton d\'ouverture');
        self::assertSame('/shifts/open', $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId], '/shifts/open')->headers['Location']);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM shifts'));

        // Pompiste : son poste en cours, une sortie non vendue, puis les index finaux.
        $mine = $this->http($this->attendant, 'GET', '/shifts');
        self::assertStringContainsString('Poste en cours', $mine->body);
        self::assertStringNotContainsString('/shifts/open', $mine->body);
        $sheet = $this->http($this->attendant, 'GET', "/shifts/$shift");
        self::assertStringContainsString('data-open="#new-output"', $sheet->body);
        self::assertStringContainsString("1\u{202F}000,00", $sheet->body);

        self::assertSame("/shifts/$shift", $this->post($this->attendant, "/shifts/$shift/outputs", ['nozzle_id' => $this->nozzle, 'output_type' => 'test', 'volume_l' => '10', 'reason' => 'Essai'], "/shifts/$shift")->headers['Location']);
        $closeForm = $this->http($this->attendant, 'GET', "/shifts/$shift/close");
        self::assertStringContainsString('name="final[' . $this->nozzle . ']"', $closeForm->body);
        self::assertStringContainsString('name="rollover[' . $this->nozzle . ']"', $closeForm->body);
        self::assertStringNotContainsString('<script>', $closeForm->body, 'Aucun calcul dans la page');

        // Index inférieur à l'initial : refusé, la saisie revient avec le message.
        self::assertSame("/shifts/$shift/close", $this->post($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '900']], "/shifts/$shift/close")->headers['Location']);
        $again = $this->http($this->attendant, 'GET', "/shifts/$shift/close");
        self::assertStringContainsString('inférieur à son index initial', $again->body);
        self::assertStringContainsString('value="900"', $again->body);

        self::assertSame("/shifts/$shift", $this->post($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '1250,5']], "/shifts/$shift/close")->headers['Location']);
        $closed = $this->http($this->attendant, 'GET', "/shifts/$shift");
        self::assertStringContainsString('À valider', $closed->body);
        self::assertStringContainsString('240,50', $closed->body, 'Litres vendus = (1 250,5 − 1 000) − 10');
        self::assertStringContainsString("829\u{202F}725 FC", $closed->body, '240,5 L × 3 450 FC');
        self::assertStringNotContainsString("/shifts/$shift/validate", $closed->body, 'Le pompiste ne valide pas');
        self::assertSame(403, $this->http($this->attendant, 'POST', "/shifts/$shift/validate")->status);

        // Gérant : contre-validation.
        $review = $this->http($this->manager, 'GET', "/shifts/$shift");
        self::assertStringContainsString("/shifts/$shift/validate", $review->body);
        self::assertSame("/shifts/$shift", $this->post($this->manager, "/shifts/$shift/validate", ['note' => 'RAS'], "/shifts/$shift")->headers['Location']);
        self::assertSame(['validated', $this->managerId], array_values($this->row('SELECT status, validated_by FROM shifts WHERE id = :id', ['id' => $shift])));
        self::assertStringContainsString('Validé', $this->http($this->manager, 'GET', "/shifts/$shift")->body);

        // Poste suivant : index repris, affiché verrouillé, sans champ de saisie.
        $next = $this->http($this->manager, 'GET', '/shifts/open');
        self::assertStringContainsString("1\u{202F}250,50", $next->body);
        self::assertStringContainsString('Repris du poste précédent', $next->body);
        self::assertStringNotContainsString('name="opening[', $next->body);
    }

    public function testRolloverNeedsTheManagersExplicitConfirmation(): void
    {
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'opening' => [$this->nozzle => '999900']], '/shifts/open');
        $shift = $this->shiftId();

        $this->post($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '150'], 'rollover' => [$this->nozzle => '1']], "/shifts/$shift/close");
        self::assertSame('closed', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));

        $review = $this->http($this->manager, 'GET', "/shifts/$shift");
        self::assertStringContainsString('Passage à zéro', $review->body);
        self::assertStringContainsString('name="confirm_rollover"', $review->body);
        self::assertStringContainsString('250,00', $review->body);

        $this->post($this->manager, "/shifts/$shift/validate", [], "/shifts/$shift");
        self::assertSame('closed', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
        self::assertStringContainsString('Confirmez le passage à zéro', $this->http($this->manager, 'GET', "/shifts/$shift")->body);

        $this->post($this->manager, "/shifts/$shift/validate", ['confirm_rollover' => '1'], "/shifts/$shift");
        self::assertSame('validated', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
        self::assertStringContainsString('Passage à zéro déclaré', $this->http($this->manager, 'GET', '/alerts')->body);
    }

    public function testListIsServerSideAndAnAttendantOnlySeesHisOwnShifts(): void
    {
        $colleague = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste2');
        $this->post($this->manager, '/shifts', ['attendant_id' => $colleague, 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();

        $page = $this->http($this->manager, 'GET', '/shifts');
        self::assertStringContainsString('Historique général', $page->body);
        self::assertMatchesRegularExpression('#data-drill="[^"]*shifts/data#', $page->body, 'Le tableau complet s\'ouvre en fenêtre');
        $all = json_decode($this->http($this->manager, 'GET', '/shifts/data?draw=1', [], self::AJAX)->body, true);
        self::assertSame([1, 'open', 'Attendant Test'], [$all['recordsTotal'], $all['data'][0]['status'], $all['data'][0]['attendant']]);
        self::assertSame(0, json_decode($this->http($this->manager, 'GET', '/shifts/data?status=validated', [], self::AJAX)->body, true)['recordsFiltered']);

        // Le premier pompiste n'est pas celui du poste : il ne le voit ni dans la liste ni par son adresse.
        self::assertSame(0, json_decode($this->http($this->attendant, 'GET', '/shifts/data', [], self::AJAX)->body, true)['recordsTotal']);
        self::assertStringNotContainsString('Poste en cours', $this->http($this->attendant, 'GET', '/shifts')->body);
        self::assertSame(403, $this->http($this->attendant, 'GET', "/shifts/$shift")->status);
        self::assertSame(403, $this->http($this->attendant, 'GET', "/shifts/$shift/close")->status);
        self::assertSame(403, $this->http($this->attendant, 'POST', "/shifts/$shift/close", ['final' => [$this->nozzle => '2000']])->status);
        self::assertSame(403, $this->http($this->attendant, 'GET', '/shifts/open')->status);
        self::assertSame(403, $this->http($this->attendant, 'POST', '/shifts', ['attendant_id' => $this->attendantId])->status);
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
    }

    public function testOpeningScreenExplainsWhatIsMissing(): void
    {
        $this->db->execute('UPDATE nozzles SET is_active = 0');

        $form = $this->http($this->manager, 'GET', '/shifts/open');

        self::assertSame(200, $form->status);
        self::assertStringContainsString('Le poste ne peut pas être ouvert', $form->body);
        self::assertStringContainsString('aucun pistolet en service', $form->body);
        self::assertStringNotContainsString('<form method="post" action="/shifts"', $form->body);
    }

    public function testNozzleRolloverIndexIsConfigurable(): void
    {
        $owner = $this->sessionFor($this->owner);
        self::assertStringContainsString('name="rollover_index"', $this->http($owner, 'GET', '/nozzles/' . $this->nozzle)->body);
        $tank = (string) $this->value('SELECT tank_id FROM nozzles WHERE id = :id', ['id' => $this->nozzle]);

        $this->post($owner, '/nozzles/' . $this->nozzle, ['tank_id' => $tank, 'code' => 'P-ESS', 'name' => 'Pistolet', 'rollover_index' => '100000', 'is_active' => '1'], '/nozzles/' . $this->nozzle);
        self::assertSame('100000.00', $this->value('SELECT rollover_index FROM nozzles WHERE id = :id', ['id' => $this->nozzle]));

        // Sans le champ, la valeur réglée est conservée.
        $this->post($owner, '/nozzles/' . $this->nozzle, ['tank_id' => $tank, 'code' => 'P-ESS', 'name' => 'Pistolet 1', 'is_active' => '1'], '/nozzles/' . $this->nozzle);
        self::assertSame(['Pistolet 1', '100000.00'], array_values($this->row('SELECT name, rollover_index FROM nozzles WHERE id = :id', ['id' => $this->nozzle])));
    }

    public function testDayBoardShowsOneCardPerSlotWithDetailsInWindows(): void
    {
        // Journée sans poste : une carte par créneau des horaires de la station, et le bouton d'ouverture.
        $empty = $this->http($this->manager, 'GET', '/shifts');
        self::assertSame(2, substr_count($empty->body, 'class="shift-card shift-card--idle"'), 'Matin et Après-midi');
        foreach (['Matin', 'Après-midi', 'Pas encore ouvert', '06:00 – 12:00', 'Historique de ce poste', 'js/pt-live.js', "Aujourd'hui"] as $text) {
            self::assertStringContainsString($text, html_entity_decode($empty->body, ENT_QUOTES | ENT_HTML5), $text);
        }

        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();

        // Poste ouvert : sa carte porte l'état et l'action suivante ; l'autre créneau reste à ouvrir plus tard.
        $board = $this->http($this->manager, 'GET', '/shifts');
        self::assertSame(1, substr_count($board->body, 'shift-card--open'));
        self::assertSame(1, substr_count($board->body, 'shift-card--idle'));
        self::assertStringContainsString("/shifts/$shift/close", $board->body, 'Action suivante : clôturer');
        self::assertStringContainsString("data-shift-detail=\"/shifts/$shift/summary\"", $board->body);
        self::assertStringContainsString('Attendant Test', $board->body);

        // Clôture puis caisse : la carte avance d'étape en étape.
        $this->post($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '1100']], "/shifts/$shift/close");
        $closed = $this->http($this->manager, 'GET', '/shifts');
        self::assertStringContainsString('shift-card--closed', $closed->body);
        self::assertStringContainsString("/shifts/$shift/cash", $closed->body, 'Action suivante : recevoir la caisse');
        self::assertStringContainsString('100,00 L', $closed->body);
        $this->post($this->manager, "/shifts/$shift/handovers", ['amount' => '345000', 'currency' => 'CDF'], "/shifts/$shift/cash");
        self::assertStringContainsString('shift-card--cash', $this->http($this->manager, 'GET', '/shifts')->body);
        $this->post($this->manager, "/shifts/$shift/validate", [], "/shifts/$shift");
        $done = $this->http($this->manager, 'GET', '/shifts');
        self::assertStringContainsString('shift-card--validated', $done->body);
        self::assertMatchesRegularExpression('#data-day="expected">345\x{202F}000 FC<#u', $done->body);
        self::assertMatchesRegularExpression('#data-day="variance" class="">0 FC<#u', $done->body);

        // Fenêtre de détail : ventes, caisse, événements — et le lien vers la fiche complète.
        $detail = $this->http($this->manager, 'GET', "/shifts/$shift/summary");
        self::assertSame(200, $detail->status);
        self::assertStringNotContainsString('<html', $detail->body, 'Fragment, sans gabarit');
        foreach (['data-tab="sales"', 'data-tab="cash"', 'data-tab="events"', 'P-ESS', "345\u{202F}000 FC", 'Ouverture de poste', 'Ouvrir la fiche complète'] as $text) {
            self::assertStringContainsString($text, $detail->body, $text);
        }
        // Fenêtre d'historique du créneau.
        $history = $this->http($this->manager, 'GET', '/shifts/history?station=' . $this->station . '&label=Matin');
        self::assertSame(200, $history->status);
        self::assertStringContainsString('Historique du poste « Matin »', $history->body);
        self::assertStringContainsString("/shifts/$shift", $history->body);
        self::assertStringContainsString('Aucun poste encore tenu', $this->http($this->manager, 'GET', '/shifts/history?station=' . $this->station . '&label=Nuit')->body);

        // Sélecteur de période : raccourcis, calendrier borné à aujourd'hui, semaine avec l'état de chaque jour.
        self::assertSame(7, substr_count($done->body, 'class="period__day'), 'Sept jours, du lundi au dimanche');
        self::assertSame(1, substr_count($done->body, 'aria-current="date"'));
        self::assertMatchesRegularExpression('#period__day period__day--bad is-active|period__day period__day--ok is-active#', $done->body, 'Le jour affiché porte son état');
        self::assertStringContainsString('data-day-input', $done->body);
        self::assertStringContainsString('max="' . date('Y-m-d') . '"', $done->body);
        self::assertStringContainsString('>Hier</a>', $done->body);

        // Jour précédent : les créneaux sont « non tenus », sans bouton d'ouverture ; une date future retombe sur aujourd'hui.
        $yesterday = $this->http($this->manager, 'GET', '/shifts?date=' . date('Y-m-d', time() - 86400));
        self::assertStringContainsString('Non tenu', $yesterday->body);
        // (Le raccourci du menu reste, lui : il ouvre le poste d'aujourd'hui.)
        self::assertStringNotContainsString('class="btn btn--primary" href="/shifts/open"', $yesterday->body);
        self::assertStringNotContainsString('btn--lg" href="/shifts/open"', $yesterday->body);
        self::assertStringContainsString('shift-card--validated', $this->http($this->manager, 'GET', '/shifts?date=2999-01-01')->body);
    }

    public function testBoardAndWindowsRespectStationsAndOwnership(): void
    {
        $colleague = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste2');
        $this->post($this->manager, '/shifts', ['attendant_id' => $colleague, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();

        // Un pompiste ne voit que ses postes : ni la carte du collègue, ni les créneaux vides, ni ses fenêtres.
        $mine = $this->http($this->attendant, 'GET', '/shifts');
        self::assertStringNotContainsString('shift-card', $mine->body);
        self::assertStringContainsString("Vous n'avez pas de poste", html_entity_decode($mine->body, ENT_QUOTES | ENT_HTML5));
        self::assertSame(403, $this->http($this->attendant, 'GET', "/shifts/$shift/summary")->status);
        self::assertStringNotContainsString("/shifts/$shift", $this->http($this->attendant, 'GET', '/shifts/history?station=' . $this->station . '&label=Matin')->body);

        // Une autre station : ni détail ni historique pour le gérant de la première.
        $other = $this->createStation($this->org, 'B');
        self::assertSame(403, $this->http($this->manager, 'GET', '/shifts/history?station=' . $other . '&label=Matin')->status);
        self::assertSame(404, $this->http($this->manager, 'GET', '/shifts/history?station=pas-un-uuid')->status);
        $stranger = $this->sessionFor($this->createUser($this->org, 'manager', [$other], 'gerant.b'));
        self::assertSame(403, $this->http($stranger, 'GET', "/shifts/$shift/summary")->status);
        self::assertStringNotContainsString('Attendant', $this->http($stranger, 'GET', '/shifts')->body);
    }

    public function testAShiftCanBeSettledWithAZeroHandover(): void
    {
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();
        $this->post($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '1100']], "/shifts/$shift/close");
        self::assertStringContainsString('saisissez 0', $this->http($this->manager, 'GET', "/shifts/$shift/cash")->body);

        // Le gérant constate que rien n'a été remis : il encode 0.
        $response = $this->post($this->manager, "/shifts/$shift/handovers", ['amount' => '0', 'currency' => 'CDF', 'note' => 'Caisse non remise'], "/shifts/$shift/cash");
        self::assertSame("/shifts/$shift/cash", $response->headers['Location']);
        self::assertSame(['0', '0', 'Caisse non remise'], array_map('strval', array_values($this->row('SELECT amount, amount_cdf, note FROM cash_handovers WHERE shift_id = :s', ['s' => $shift]))));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'cash_handover' AND entity_id = (SELECT id FROM cash_handovers WHERE shift_id = :s)", ['s' => $shift]));
        // La carte du poste passe à « À valider », puis le poste se valide.
        self::assertStringContainsString('shift-card--cash', $this->http($this->manager, 'GET', '/shifts')->body);
        $this->post($this->manager, "/shifts/$shift/validate", [], "/shifts/$shift");

        // L'écart n'est pas escamoté : 100 L × 3 450 FC attendus, 0 remis, alerte rattachée au poste et au pompiste.
        $row = $this->row('SELECT status, expected_amount, received_amount, variance_amount FROM shifts WHERE id = :id', ['id' => $shift]);
        self::assertSame(['validated', 345000, 0, -345000], [$row['status'], (int) $row['expected_amount'], (int) $row['received_amount'], (int) $row['variance_amount']]);
        $alert = $this->row("SELECT shift_id, user_id, station_id FROM alerts WHERE alert_type = 'cash_shortage'");
        self::assertSame([$shift, $this->attendantId, $this->station], array_values($alert));
        // Zéro dollar est accepté de la même façon ; un montant négatif ou illisible reste refusé.
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Après-midi'], '/shifts/open');
        $second = (string) $this->value("SELECT id FROM shifts WHERE status = 'open'");
        $this->post($this->attendant, "/shifts/$second/close", ['final' => [$this->nozzle => '1100']], "/shifts/$second/close");
        $this->post($this->manager, "/shifts/$second/handovers", ['amount' => '0', 'currency' => 'USD'], "/shifts/$second/cash");
        $this->post($this->manager, "/shifts/$second/handovers", ['amount' => '-10', 'currency' => 'CDF'], "/shifts/$second/cash");
        $this->post($this->manager, "/shifts/$second/handovers", ['amount' => 'zéro', 'currency' => 'CDF'], "/shifts/$second/cash");
        self::assertSame([['USD', 0]], array_map(static fn (array $h): array => [$h['currency'], (int) $h['amount']], $this->db->fetchAll('SELECT currency, amount FROM cash_handovers WHERE shift_id = :s', ['s' => $second])));
        // Aucune vente, rien à remettre : le poste se solde sans écart.
        $this->post($this->manager, "/shifts/$second/validate", [], "/shifts/$second");
        self::assertSame(['validated', 0], [$this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $second]), (int) $this->value('SELECT variance_amount FROM shifts WHERE id = :id', ['id' => $second])]);
    }

    public function testActionsOpenEntryWindowsAndSubmitWithoutReloadingThePage(): void
    {
        $json = ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'];
        $ajax = fn (Session $session, string $path, array $body): \Petra\Core\Response => $this->http($session, 'POST', $path, $body, $json);

        // Ouverture : la carte et l'en-tête ouvrent la fenêtre ; le lien classique reste pour un navigateur sans JavaScript.
        $board = $this->http($this->manager, 'GET', '/shifts')->body;
        self::assertStringContainsString('id="shifts-live"', $board, 'Zone rafraîchie après une saisie');
        self::assertMatchesRegularExpression('#href="/shifts/open" data-shift-form="/shifts/form\?action=open#', $board);
        $form = $this->http($this->manager, 'GET', '/shifts/form?action=open&label=Matin');
        self::assertSame(200, $form->status);
        self::assertStringNotContainsString('<html', $form->body, 'Fragment, sans gabarit');
        foreach (['data-ajax-form', 'action="/shifts"', 'name="attendant_id"', 'name="opening[' . $this->nozzle . ']"', 'data-recap=', 'name="_csrf"', 'Attendant Test'] as $text) {
            self::assertStringContainsString($text, $form->body, $text);
        }
        self::assertMatchesRegularExpression('#<option value="Matin" selected>#', $form->body, 'Créneau de la carte présélectionné');

        // Envoi sans rechargement : erreurs de saisie en JSON, puis succès en JSON (pas de redirection).
        $invalid = $ajax($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin']);
        self::assertSame(422, $invalid->status);
        self::assertArrayHasKey('opening_' . $this->nozzle, json_decode($invalid->body, true)['errors']);
        $opened = $ajax($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']]);
        self::assertSame(200, $opened->status);
        $shift = $this->shiftId();
        self::assertSame(['ok' => true, 'message' => 'Poste ouvert.', 'redirect' => "/shifts/$shift"], json_decode($opened->body, true));
        // Un poste est en cours : la fenêtre d'ouverture explique pourquoi on ne peut pas en ouvrir un autre.
        self::assertStringContainsString('ne peut pas être ouvert', $this->http($this->manager, 'GET', '/shifts/form?action=open')->body);

        // Clôture par le pompiste, dans sa fenêtre.
        $board = $this->http($this->attendant, 'GET', '/shifts')->body;
        self::assertStringContainsString("data-shift-form=\"/shifts/form?action=close&amp;shift=$shift\"", $board);
        self::assertStringContainsString("href=\"/shifts/$shift\"", $board, 'Bouton « Voir la fiche »');
        self::assertStringContainsString('Voir la fiche', $board);
        $form = $this->http($this->attendant, 'GET', "/shifts/form?action=close&shift=$shift");
        foreach (["action=\"/shifts/$shift/close\"", 'name="final[' . $this->nozzle . ']"', 'data-recap-static=', 'Voir la fiche'] as $text) {
            self::assertStringContainsString($text, $form->body, $text);
        }
        self::assertSame(422, $ajax($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '900']])->status);
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));
        self::assertStringStartsWith('Poste clôturé.', json_decode($ajax($this->attendant, "/shifts/$shift/close", ['final' => [$this->nozzle => '1100']])->body, true)['message']);

        // Caisse puis validation par le gérant, dans leurs fenêtres : repères du serveur dans l'étape de vérification.
        $form = $this->http($this->manager, 'GET', "/shifts/form?action=cash&shift=$shift");
        foreach (["action=\"/shifts/$shift/handovers\"", 'name="amount"', 'name="currency"', "345\u{202F}000 FC", 'saisissez 0'] as $text) {
            self::assertStringContainsString($text, html_entity_decode($form->body, ENT_QUOTES | ENT_HTML5), $text);
        }
        self::assertSame(422, $ajax($this->manager, "/shifts/$shift/handovers", ['amount' => 'beaucoup', 'currency' => 'CDF'])->status);
        self::assertTrue(json_decode($ajax($this->manager, "/shifts/$shift/handovers", ['amount' => '340000', 'currency' => 'CDF'])->body, true)['ok']);
        $form = $this->http($this->manager, 'GET', "/shifts/form?action=validate&shift=$shift");
        foreach (["action=\"/shifts/$shift/validate\"", "340\u{202F}000 FC", "-5\u{202F}000 FC", 'data-recap-warning='] as $text) {
            self::assertStringContainsString($text, html_entity_decode($form->body, ENT_QUOTES | ENT_HTML5), $text);
        }
        self::assertTrue(json_decode($ajax($this->manager, "/shifts/$shift/validate", ['note' => 'Compté'])->body, true)['ok']);
        self::assertSame(['validated', -5000], [$this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]), (int) $this->value('SELECT variance_amount FROM shifts WHERE id = :id', ['id' => $shift])]);
        // Mêmes règles et même journal que par les écrans complets.
        self::assertSame(['close', 'open', 'validate'], array_column($this->db->fetchAll("SELECT DISTINCT action FROM audit_log WHERE entity_type = 'shift' AND entity_id = :id ORDER BY action", ['id' => $shift]), 'action'));

        // Poste validé : plus de fenêtre de saisie, pour personne ; tentative envoyée quand même : refusée et journalisée.
        foreach (['close', 'cash', 'validate'] as $action) {
            self::assertSame(403, $this->http($this->manager, 'GET', "/shifts/form?action=$action&shift=$shift")->status, $action);
        }
        self::assertSame(422, $ajax($this->manager, "/shifts/$shift/handovers", ['amount' => '5000', 'currency' => 'CDF'])->status);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'modification_denied' AND entity_id = :id", ['id' => $shift]));
    }

    public function testEntryWindowsFollowTheSameRightsAsTheScreens(): void
    {
        $colleague = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste2');
        $this->post($this->manager, '/shifts', ['attendant_id' => $colleague, 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();

        // Un pompiste n'ouvre pas de poste, et ne clôture pas celui d'un collègue.
        self::assertSame(403, $this->http($this->attendant, 'GET', '/shifts/form?action=open')->status);
        self::assertSame(403, $this->http($this->attendant, 'GET', "/shifts/form?action=close&shift=$shift")->status);
        // Tant que le poste est ouvert, ni caisse ni validation ; action ou identifiant inconnus : introuvable.
        self::assertSame(403, $this->http($this->manager, 'GET', "/shifts/form?action=cash&shift=$shift")->status);
        self::assertSame(403, $this->http($this->manager, 'GET', "/shifts/form?action=validate&shift=$shift")->status);
        self::assertSame(404, $this->http($this->manager, 'GET', "/shifts/form?action=supprimer&shift=$shift")->status);
        self::assertSame(404, $this->http($this->manager, 'GET', '/shifts/form?action=close&shift=pas-un-uuid')->status);
        // Le gérant d'une autre station n'y a pas accès.
        $stranger = $this->sessionFor($this->createUser($this->org, 'manager', [$this->createStation($this->org, 'B')], 'gerant.b'));
        self::assertSame(403, $this->http($stranger, 'GET', "/shifts/form?action=close&shift=$shift")->status);
    }

    public function testTheOwnerCanSettleAShiftEntirelyAlone(): void
    {
        $json = ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'];
        $owner = $this->sessionFor($this->owner);
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin', 'opening' => [$this->nozzle => '1000']], '/shifts/open');
        $shift = $this->shiftId();

        // Le bouton n'existe que pour qui en a la permission (le Propriétaire) ; le gérant ne peut ni l'ouvrir ni l'envoyer.
        self::assertStringContainsString("/shifts/form?action=settle&amp;shift=$shift", $this->http($owner, 'GET', '/shifts')->body);
        self::assertStringNotContainsString('action=settle', $this->http($this->manager, 'GET', '/shifts')->body);
        self::assertSame(403, $this->http($this->manager, 'GET', "/shifts/form?action=settle&shift=$shift")->status);
        self::assertSame(403, $this->http($this->manager, 'POST', "/shifts/$shift/settle", ['final' => [$this->nozzle => '1100'], 'amount_cdf' => '345000'], $json)->status);
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));

        // La fenêtre du propriétaire réunit les index finaux et l'argent reçu.
        $form = $this->http($owner, 'GET', "/shifts/form?action=settle&shift=$shift");
        foreach (["action=\"/shifts/$shift/settle\"", 'name="final[' . $this->nozzle . ']"', 'name="amount_cdf"', 'name="amount_usd"', 'Vous clôturez et validez ce poste seul'] as $text) {
            self::assertStringContainsString($text, html_entity_decode($form->body, ENT_QUOTES | ENT_HTML5), $text);
        }

        // Tout ou rien : un index refusé n'enregistre ni la clôture ni la remise.
        $bad = $this->http($owner, 'POST', "/shifts/$shift/settle", ['final' => [$this->nozzle => '900'], 'amount_cdf' => '345000'], $json);
        self::assertSame(422, $bad->status);
        self::assertSame(['open', 0], [$this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]), (int) $this->value('SELECT COUNT(*) FROM cash_handovers')]);
        // Sans montant, on exige de l'écrire (quitte à saisir 0) ; un montant illisible annule aussi la clôture.
        self::assertArrayHasKey('amount_cdf', json_decode($this->http($owner, 'POST', "/shifts/$shift/settle", ['final' => [$this->nozzle => '1100']], $json)->body, true)['errors']);
        self::assertSame(422, $this->http($owner, 'POST', "/shifts/$shift/settle", ['final' => [$this->nozzle => '1100'], 'amount_cdf' => 'beaucoup'], $json)->status);
        self::assertSame('open', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $shift]));

        // Clôture totale : index, caisse en francs et en dollars, validation — en une opération.
        $done = $this->http($owner, 'POST', "/shifts/$shift/settle", ['final' => [$this->nozzle => '1100'], 'amount_cdf' => '110000', 'amount_usd' => '100', 'note' => 'Clôture par le propriétaire'], $json);
        self::assertSame(200, $done->status);
        self::assertSame('Poste clôturé et validé.', json_decode($done->body, true)['message']);
        $row = $this->row('SELECT status, closed_by, validated_by, expected_amount, received_amount, variance_amount FROM shifts WHERE id = :id', ['id' => $shift]);
        // 100 L × 3 450 FC = 345 000 attendus ; 110 000 FC + 100 $ × 2 300 = 340 000 remis ; écart −5 000.
        self::assertSame(['validated', $this->owner, $this->owner, 345000, 340000, -5000], [$row['status'], $row['closed_by'], $row['validated_by'], (int) $row['expected_amount'], (int) $row['received_amount'], (int) $row['variance_amount']]);
        self::assertSame([['CDF', 110000], ['USD', 10000]], array_map(static fn (array $h): array => [$h['currency'], (int) $h['amount']], $this->db->fetchAll('SELECT currency, amount FROM cash_handovers WHERE shift_id = :s ORDER BY currency', ['s' => $shift])));
        // Le journal garde les trois étapes au nom du propriétaire, et la mention « validé seul ».
        self::assertSame(['close', 'validate'], array_column($this->db->fetchAll("SELECT action FROM audit_log WHERE entity_type = 'shift' AND entity_id = :id AND created_by = :u ORDER BY action", ['id' => $shift, 'u' => $this->owner]), 'action'));
        self::assertStringContainsString('"sole_validation":true', (string) $this->value("SELECT after_data FROM audit_log WHERE entity_type = 'shift' AND entity_id = :id AND action = 'validate'", ['id' => $shift]));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'cash_handover' AND created_by = :u", ['u' => $this->owner]));
        // Une fois validé : plus de clôture totale, et la tentative est journalisée.
        self::assertSame(403, $this->http($owner, 'GET', "/shifts/form?action=settle&shift=$shift")->status);
        self::assertSame(422, $this->http($owner, 'POST', "/shifts/$shift/settle", ['amount_cdf' => '0'], $json)->status);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'modification_denied' AND entity_id = :id", ['id' => $shift]));

        // Poste déjà clôturé par le pompiste : le propriétaire le solde sans ressaisir les index, ici avec une caisse à 0.
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Après-midi'], '/shifts/open');
        $second = (string) $this->value("SELECT id FROM shifts WHERE status = 'open'");
        $this->post($this->attendant, "/shifts/$second/close", ['final' => [$this->nozzle => '1150']], "/shifts/$second/close");
        self::assertStringNotContainsString('name="final[', $this->http($owner, 'GET', "/shifts/form?action=settle&shift=$second")->body);
        self::assertSame(200, $this->http($owner, 'POST', "/shifts/$second/settle", ['amount_cdf' => '0'], $json)->status);
        self::assertSame(['validated', -172500], [$this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $second]), (int) $this->value('SELECT variance_amount FROM shifts WHERE id = :id', ['id' => $second])]);

        // La règle des deux personnes reste entière pour les autres : un gérant qui clôture ne valide pas lui-même.
        $this->post($this->manager, '/shifts', ['attendant_id' => $this->attendantId, 'label' => 'Matin'], '/shifts/open');
        $third = (string) $this->value("SELECT id FROM shifts WHERE status = 'open'");
        $this->post($this->manager, "/shifts/$third/close", ['final' => [$this->nozzle => '1200']], "/shifts/$third/close");
        self::assertSame(422, $this->http($this->manager, 'POST', "/shifts/$third/validate", [], $json)->status);
        self::assertSame('closed', $this->value('SELECT status FROM shifts WHERE id = :id', ['id' => $third]));
    }
}
