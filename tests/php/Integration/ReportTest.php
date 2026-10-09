<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Petra\Core\HttpException;
use Petra\Core\Uuid;
use Petra\Services\ReportService;

/**
 * Rapports par poste, journalier et mensuel, pour une station ou en consolidé.
 * Jeu d'essai : voir TwoStationsTestCase (A : 730 000 FC le 05/10 et 250 000 FC le 06/10 ; B : 800 000 FC le 05/10).
 */
final class ReportTest extends TwoStationsTestCase
{
    private function report(string $user, array $query, string $day = '06'): array
    {
        return $this->service($user, ReportService::class)->build($query, self::at($day, '18:00'));
    }

    private static function section(array $report, string $key): array
    {
        foreach ($report['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }
        self::fail("Section absente : $key");
    }

    /** Valeurs natives d'une ligne (ou texte quand la cellule n'en a pas). */
    private static function values(array $row): array
    {
        return array_map(static fn (mixed $cell): mixed => is_array($cell) ? $cell['v'] : $cell, $row);
    }

    private function fullName(string $userId): string
    {
        return (string) $this->value("SELECT TRIM(CONCAT(first_name, ' ', last_name)) FROM users WHERE id = :id", ['id' => $userId]);
    }

    public function testDailyReportAnswersTheTenQuestions(): void
    {
        $r = $this->report($this->managerA, ['type' => 'daily', 'date' => '2026-10-05']);

        // En-tête porté par l'écran, l'impression et les exports.
        self::assertSame(
            ['Rapport journalier', 'Station A', '05/10/2026', '06/10/2026 18:00', $this->fullName($this->managerA), 'Petra — développé par BELEJ-CONSULTING'],
            [$r['title'], $r['station'], $r['period'], $r['generated_at'], $r['author'], $r['credit']]
        );
        self::assertSame(['products', 'shifts', 'rates', 'stock', 'deliveries', 'anomalies', 'operations'], array_column($r['sections'], 'key'));
        self::assertSame(
            ['1–2.', '3–5.', '6.', '7.', '8.', '9.', '10.'],
            array_map(static fn (array $s): string => explode(' ', $s['title'])[0], $r['sections'])
        );

        // 1–2. Litres par produit.
        $products = self::section($r, 'products');
        self::assertSame([['Essence', '100.00', 500000], ['Gaz', '40.00', 230000]], array_map([self::class, 'values'], $products['rows']));
        self::assertSame(['Total', '140.00', 730000], self::values($products['foot']));
        self::assertSame('100,00 L', $products['rows'][0][1]['t']);

        // 3–5. Attendu, remis en CDF, remis en USD (avec contre-valeur) et écart validé.
        $shifts = self::section($r, 'shifts');
        self::assertCount(1, $shifts['rows']);
        self::assertSame([730000, 485000, '100.00', 230000, 715000, -15000], array_slice(self::values($shifts['rows'][0]), 2));
        self::assertSame(["485\u{202F}000 FC", '100,00 $', "-15\u{202F}000 FC"], [$shifts['rows'][0][3]['t'], $shifts['rows'][0][4]['t'], $shifts['rows'][0][7]['t']]);
        self::assertSame($this->fullName($this->attendant1), $shifts['rows'][0][0]);

        // 6. Taux utilisés.
        $rates = self::section($r, 'rates');
        self::assertSame([[2300, '05/10/2026 05:00', '1', $this->fullName($this->owner)]], array_map([self::class, 'values'], $rates['rows']));

        // 7. Stock théorique restant en fin de journée : jaugé 9 800 L à 13 h, plus rien de débité ensuite.
        $stock = array_column(array_map([self::class, 'values'], self::section($r, 'stock')['rows']), null, 0);
        self::assertSame(['C-ESS', 'Essence', '05/10/2026 13:00', '9800.00', '0.00', '0.00', '9800.00', '30000.00'], $stock['C-ESS']);
        self::assertSame(['Jamais jaugée', '—'], [$stock['C-GAZ'][2], $stock['C-GAZ'][6]]);
        // Le lendemain soir : 50 L débités depuis le jaugeage.
        $next = array_column(array_map([self::class, 'values'], self::section($this->report($this->managerA, ['type' => 'daily', 'date' => '2026-10-06']), 'stock')['rows']), null, 0);
        self::assertSame(['50.00', '9750.00'], [$next['C-ESS'][5], $next['C-ESS'][6]]);

        // 9. Écarts et anomalies : écart de caisse, écart de jaugeage, alertes.
        $anomalies = array_map([self::class, 'values'], self::section($r, 'anomalies')['rows']);
        $byType = [];
        foreach ($anomalies as $row) {
            $byType[$row[1]][] = $row;
        }
        self::assertSame([-15000, $this->fullName($this->attendant1)], [$byType['Écart de caisse'][0][3], $byType['Écart de caisse'][0][4]]);
        self::assertSame(['-95.00', $this->fullName($this->managerA)], [$byType['Écart de stock'][0][3], $byType['Écart de stock'][0][4]]);
        // Les alertes sont datées de leur levée (l'horloge réelle, dans ce test) : elles figurent au rapport de ce jour-là.
        $today = array_column(array_map([self::class, 'values'], self::section($this->report($this->managerA, ['type' => 'daily', 'date' => date('Y-m-d')]), 'anomalies')['rows']), 2, 1);
        self::assertArrayHasKey('Alerte (Critique)', $today);

        // 10. Chaque opération a son responsable.
        $operations = array_map([self::class, 'values'], self::section($r, 'operations')['rows']);
        $counts = array_count_values(array_column($operations, 1));
        self::assertEquals(
            ['Publication de taux' => 1, 'Publication de prix' => 2, 'Jaugeage' => 2, 'Ouverture de poste' => 1, 'Sortie non vendue' => 1, 'Clôture de poste' => 1, 'Remise de caisse reçue' => 2, 'Validation de poste' => 1],
            $counts
        );
        foreach ($operations as $operation) {
            self::assertNotSame('', trim((string) $operation[3]), 'Opération sans responsable : ' . $operation[1]);
        }
        $who = array_column($operations, 3, 1);
        self::assertSame(
            [$this->fullName($this->managerA), $this->fullName($this->attendant1), $this->fullName($this->managerA), $this->fullName($this->owner)],
            [$who['Ouverture de poste'], $who['Clôture de poste'], $who['Validation de poste'], $who['Publication de taux']]
        );
        self::assertSame($operations, array_values($operations), 'Dans l\'ordre chronologique');
        self::assertSame(array_column($operations, 0), (static function (array $times): array {
            $sorted = $times;
            usort($sorted, static fn (string $a, string $b): int => strcmp(substr($a, 6, 4) . substr($a, 3, 2) . substr($a, 0, 2) . substr($a, 11), substr($b, 6, 4) . substr($b, 3, 2) . substr($b, 0, 2) . substr($b, 11)));

            return $sorted;
        })(array_column($operations, 0)));

        // Chiffres clés.
        $summary = array_column(array_map(static fn (array $pair): array => [$pair[0], $pair[1]['v']], $r['summary']), 1, 0);
        self::assertSame(['Litres Essence' => '100.00', 'Litres Gaz' => '40.00', 'Montant attendu' => 730000, 'Remis en CDF' => 485000, 'Remis en USD' => '100.00', 'Total remis' => 715000], $summary);
    }

    public function testDailyReportListsDeliveriesWithTheirReceiver(): void
    {
        $this->db->execute(
            "INSERT INTO deliveries (id, station_id, tank_id, supplier_name, delivery_note_no, invoiced_volume_l, received_volume_l, announced_variance_l, delivered_at, received_by, created_by)
             VALUES (:id, :s, :t, 'SEP Congo', 'BL-77', 5000, 4950, -50, '2026-10-05 15:00:00', :u, :u2)",
            ['id' => Uuid::v7(), 's' => $this->a, 't' => $this->tankA, 'u' => $this->managerA, 'u2' => $this->managerA]
        );

        $r = $this->report($this->managerA, ['type' => 'daily', 'date' => '2026-10-05']);

        $deliveries = self::section($r, 'deliveries');
        self::assertSame([['05/10/2026 15:00', 'C-ESS', 'Essence', 'SEP Congo', 'BL-77', '5000.00', '4950.00', '-50.00', $this->fullName($this->managerA)]], array_map([self::class, 'values'], $deliveries['rows']));
        self::assertSame('4950.00', $deliveries['foot'][6]['v']);
        // 8 → 7 : la livraison entre dans le stock théorique ; 9 : son écart figure aux anomalies ; 10 : au journal.
        $stock = array_column(array_map([self::class, 'values'], self::section($r, 'stock')['rows']), null, 0);
        self::assertSame(['4950.00', '14750.00'], [$stock['C-ESS'][4], $stock['C-ESS'][6]]);
        self::assertContains('Écart de livraison', array_column(array_map([self::class, 'values'], self::section($r, 'anomalies')['rows']), 1));
        self::assertContains('Réception de livraison', array_column(array_map([self::class, 'values'], self::section($r, 'operations')['rows']), 1));
    }

    public function testConsolidatedDailyReport(): void
    {
        $r = $this->report($this->owner, ['type' => 'daily', 'date' => '2026-10-05', 'station' => 'all']);

        self::assertSame('Toutes les stations', $r['station']);
        $shifts = self::section($r, 'shifts');
        self::assertSame('Station', $shifts['columns'][0]);
        self::assertSame(['Station A', 'Station B'], array_column($shifts['rows'], 0));
        self::assertSame([1530000, 1285000, '100.00', 230000, 1515000, -15000], array_slice(self::values($shifts['foot']), 3));
        self::assertSame([['Station A', 'Essence'], ['Station A', 'Gaz'], ['Station B', 'Gasoil']], array_map(static fn (array $row): array => [$row[0], $row[1]], self::section($r, 'products')['rows']));
        self::assertSame([2300, 2350], array_map(static fn (array $row): int => $row[1]['v'], self::section($r, 'rates')['rows']));
        self::assertCount(3, self::section($r, 'stock')['rows']);

        // Une seule station choisie par le propriétaire : plus de colonne Station.
        $b = $this->report($this->owner, ['type' => 'daily', 'date' => '2026-10-05', 'station' => $this->b]);
        self::assertSame('Station B', $b['station']);
        self::assertSame([['Gasoil', '200.00', 800000]], array_map([self::class, 'values'], self::section($b, 'products')['rows']));
    }

    public function testMonthlyReport(): void
    {
        $r = $this->report($this->owner, ['type' => 'monthly', 'month' => '2026-10', 'station' => 'all']);

        self::assertSame(['Rapport mensuel', '01/10/2026 – 31/10/2026', 'Toutes les stations'], [$r['title'], $r['period'], $r['station']]);
        self::assertSame(['days', 'products', 'by-station', 'by-attendant', 'rates', 'deliveries', 'anomalies', 'stock'], array_column($r['sections'], 'key'));
        $days = self::section($r, 'days');
        self::assertSame([
            ['05/10/2026', '2', '340.00', 1530000, 1285000, '100.00', 1515000, -15000],
            ['06/10/2026', '1', '50.00', 250000, 252000, '0.00', 252000, 2000],
        ], array_map([self::class, 'values'], $days['rows']));
        self::assertSame(['Total', '3', '390.00', 1780000, 1537000, '100.00', 1767000, -13000], self::values($days['foot']));

        // Par station et par pompiste : le plus gros manquant en tête.
        self::assertSame([['Station A', '2', '190.00', 980000, 967000, -13000], ['Station B', '1', '200.00', 800000, 800000, 0]], array_map([self::class, 'values'], self::section($r, 'by-station')['rows']));
        $attendants = array_map([self::class, 'values'], self::section($r, 'by-attendant')['rows']);
        self::assertSame([-15000, 0, 2000], array_column($attendants, 6));
        self::assertSame([$this->fullName($this->attendant1), 'Station A'], [$attendants[0][0], $attendants[0][1]]);

        // Pour le gérant : sa station seule, sans section « Par station » ; un mois sans activité reste lisible.
        $a = $this->report($this->managerA, ['type' => 'monthly', 'month' => '2026-10']);
        self::assertNotContains('by-station', array_column($a['sections'], 'key'));
        self::assertSame(980000, self::section($a, 'days')['foot'][3]['v']);
        $empty = $this->report($this->managerA, ['type' => 'monthly', 'month' => '2026-08']);
        self::assertSame('01/08/2026 – 31/08/2026', $empty['period']);
        self::assertSame([[], null], [self::section($empty, 'days')['rows'], self::section($empty, 'days')['foot']]);
        // Mois illisible : le mois en cours.
        self::assertSame('01/10/2026 – 31/10/2026', $this->report($this->managerA, ['type' => 'monthly', 'month' => '2026-13'])['period']);
    }

    public function testShiftReport(): void
    {
        $r = $this->report($this->managerA, ['type' => 'shift', 'shift' => $this->shift1]);

        self::assertSame(['Rapport de poste', 'Station A'], [$r['title'], $r['station']]);
        self::assertStringStartsWith('05/10/2026 · 05/10/2026 06:00 → 05/10/2026 12:00', $r['period']);
        self::assertSame(['readings', 'rates', 'handovers', 'outputs', 'credits', 'expenses', 'anomalies', 'operations'], array_column($r['sections'], 'key'));

        $readings = self::section($r, 'readings');
        self::assertSame([
            ['1', 'P-ESS', 'Essence', '0.00', '105.00', '5.00', '100.00', 5000, 500000],
            ['1', 'P-GAZ', 'Gaz', '0.00', '40.00', '0.00', '40.00', '2.50', 230000],
        ], array_map([self::class, 'values'], $readings['rows']));
        self::assertSame(['140.00', 730000], [$readings['foot'][6]['v'], $readings['foot'][8]['v']]);

        $handovers = array_map([self::class, 'values'], self::section($r, 'handovers')['rows']);
        self::assertSame([[485000, '', 485000, $this->fullName($this->managerA)], ['100.00', 2300, 230000, $this->fullName($this->managerA)]], array_map(static fn (array $row): array => array_slice($row, 1), $handovers));
        self::assertSame(715000, self::section($r, 'handovers')['foot'][3]['v']);
        self::assertSame([['P-ESS', 'Essai', '5.00', 'Essai', $this->fullName($this->attendant1)]], array_map(static fn (array $row): array => array_slice(self::values($row), 1), self::section($r, 'outputs')['rows']));
        self::assertSame([], self::section($r, 'credits')['rows']);
        // L'alerte de déficit levée à la validation est rattachée au poste.
        self::assertContains('Alerte (Critique)', array_column(array_map([self::class, 'values'], self::section($r, 'anomalies')['rows']), 1));

        $summary = array_column(array_map(static fn (array $pair): array => [$pair[0], is_array($pair[1]) ? $pair[1]['v'] : $pair[1]], $r['summary']), 1, 0);
        self::assertSame([$this->fullName($this->attendant1), 'Validé', 730000, -15000], [$summary['Pompiste'], $summary['Statut'], $summary['Montant attendu'], $summary['Écart de caisse']]);
        self::assertSame($this->shift1, $r['filters']['shift']);
        self::assertSame([$this->shift1], array_column($r['filters']['shifts'], 'id'), 'Postes de la même journée proposés');

        // Sans poste choisi (ou poste inconnu) : la page invite à en choisir un parmi ceux de la journée.
        $none = $this->report($this->managerA, ['type' => 'shift', 'date' => '2026-10-06', 'shift' => Uuid::v7()]);
        self::assertSame([[], 1], [$none['sections'], count($none['filters']['shifts'])]);
    }

    public function testReportsNeverCrossStations(): void
    {
        $shiftB = (string) $this->value('SELECT id FROM shifts WHERE station_id = :s', ['s' => $this->b]);
        foreach ([['type' => 'daily', 'station' => $this->b], ['type' => 'monthly', 'station' => $this->b], ['type' => 'shift', 'shift' => $shiftB]] as $query) {
            try {
                $this->report($this->managerA, $query + ['date' => '2026-10-05']);
                self::fail('Station B lue par le gérant de A : ' . $query['type']);
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
        // « Toutes les stations » pour un gérant, c'est la sienne.
        $all = $this->report($this->managerA, ['type' => 'daily', 'date' => '2026-10-05', 'station' => 'all']);
        self::assertSame('Station A', $all['station']);
        self::assertStringNotContainsString('Gasoil', json_encode($all, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('Station B', json_encode($all, JSON_UNESCAPED_UNICODE));

        $session = $this->sessionFor($this->managerA);
        self::assertSame(403, $this->http($session, 'GET', '/reports?type=daily&station=' . $this->b)->status);
        self::assertSame(403, $this->http($session, 'GET', '/reports?type=shift&shift=' . $shiftB)->status);
        // Le pompiste n'a pas le droit reports.view.
        self::assertSame(403, $this->http($this->sessionFor($this->attendant1), 'GET', '/reports')->status);
    }

    public function testReportPageCarriesTheExportModel(): void
    {
        $page = $this->http($this->sessionFor($this->owner), 'GET', '/reports?type=daily&date=2026-10-05&station=all');

        self::assertSame(200, $page->status);
        foreach (['Rapport journalier', 'data-report-meta="station">Toutes les stations<', 'data-report-meta="period">05/10/2026<', 'data-report-meta="generated">', 'data-report-meta="author">' . $this->fullName($this->owner) . '<',
                  'Petra — développé par BELEJ-CONSULTING', 'data-report-export="excel"', 'data-report-export="pdf"', 'data-report-print', 'js/reports.js',
                  '10. Utilisateur responsable de chaque opération', "1\u{202F}530\u{202F}000 FC", 'href="/reports"'] as $text) {
            self::assertStringContainsString($text, $page->body, $text);
        }
        $data = json_decode(html_entity_decode((string) preg_replace('#^.*<script type="application/json" id="report-data">(.*?)</script>.*$#s', '$1', $page->body)), true);
        self::assertSame(['Rapport journalier', 'Toutes les stations', '05/10/2026', $this->fullName($this->owner), 'rapport-journalier-2026-10-05'], [$data['title'], $data['station'], $data['period'], $data['author'], $data['file']]);
        self::assertCount(7, $data['sections']);
        self::assertSame(['Station', 'Période', 'Généré le', 'Auteur'], [$data['labels']['station'], $data['labels']['period'], $data['labels']['generated'], $data['labels']['author']]);

        // Les trois types s'affichent ; des filtres illisibles retombent sur le rapport du jour.
        $session = $this->sessionFor($this->managerA);
        foreach (['/reports', '/reports?type=monthly&month=2026-10', '/reports?type=shift&shift=' . $this->shift1, '/reports?type=shift', '/reports?type=x&date=2026-99-99&shift=abc'] as $path) {
            self::assertSame(200, $this->http($session, 'GET', $path)->status, $path);
        }
        self::assertStringContainsString('Choisissez la journée puis le poste', $this->http($session, 'GET', '/reports?type=shift')->body);
    }
}
