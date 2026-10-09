<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Données de test sur une longue période : php bin/seed-year.php [jours=365]
 *
 * (Re)crée la base nommée par DB_NAME — qui doit se terminer par « _demo » ou « _guide », pour ne jamais
 * effacer une base de travail —, y applique les migrations et bin/seed.php (deux stations), puis rejoue
 * jour après jour, par les services de l'application, l'activité des deux stations jusqu'à hier :
 * deux postes par jour, essai de pompe chaque lundi, remises en francs et parfois en dollars, ventes à
 * crédit et dépenses occasionnelles, manquants et excédents (certains au-delà des tolérances, parfois
 * justifiés), livraisons quand une cuve baisse (certaines avec écart), jaugeage de contrôle chaque
 * dimanche, changements de prix et de taux (dont quelques-uns en plein poste). Un poste reste ouvert aujourd'hui.
 *
 * Le tirage est fixe : deux exécutions le même jour donnent les mêmes données. L'horloge de la session
 * MariaDB est réglée sur l'heure de chaque opération : alertes et journal d'audit sont datés comme elle.
 * Comptes : ceux de bin/seed.php, mot de passe Petra-Demo-2026.
 */

use Petra\Core\App;
use Petra\Core\Database;
use Petra\Core\Migrator;
use Petra\Core\Request;
use Petra\Core\Session;
use Petra\Security\Auth;
use Petra\Security\PasswordHasher;
use Petra\Services\CashControl;
use Petra\Services\DeliveryService;
use Petra\Services\SupplierService;
use Petra\Services\PricingService;
use Petra\Services\ShiftService;
use Petra\Services\StockControl;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$root = dirname(__DIR__);
$name = (string) $config['db']['name'];
if (!preg_match('/^[A-Za-z0-9_]+_(guide|demo)$/', $name)) {
    fwrite(STDERR, "Refus : DB_NAME=$name. Cette commande efface la base ; son nom doit se terminer par _demo ou _guide.\n");
    exit(1);
}
$days = max(2, min(1100, (int) ($argv[1] ?? 365)));
const PASSWORD = 'Petra-Demo-2026';
mt_srand(20261006);
$started = microtime(true);

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db']['host'], $config['db']['port']), (string) $config['db']['user'], (string) $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `$name`");
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new Database($config['db']);
(new Migrator($db, $root . '/database/migrations'))->migrate();
$today = new DateTimeImmutable('today');
$first = $today->modify("-$days days");
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/seed.php') . ' ' . escapeshellarg(PASSWORD) . ' ' . escapeshellarg($first->modify('-1 day')->format('Y-m-d') . ' 08:00:00') . ' > ' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $code);
if ($code !== 0) {
    fwrite(STDERR, "bin/seed.php a échoué.\n");
    exit(1);
}

// --- Outils ---------------------------------------------------------------------------------------

$value = static fn (string $sql, array $params = []): mixed => $db->fetchValue($sql, $params);
$userId = static fn (string $username): string => (string) $value('SELECT id FROM users WHERE username = :u', ['u' => $username]);

/** Service de l'application au nom d'un utilisateur ; une application par utilisateur, gardée pour toute la génération. */
$apps = [];
$as = static function (string $username, string $class) use (&$apps, $config, $db, $userId): object {
    if (!isset($apps[$username])) {
        $id = $userId($username);
        $hash = (string) $db->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]);
        $apps[$username] = App::create($config, Session::memory([Auth::SESSION_USER => $id, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash)]), $db, new Request('GET', '/'));
    }

    return $apps[$username]->container()->get($class);
};
/** Règle l'horloge de la session MariaDB : NOW() et les dates par défaut valent l'heure de l'opération. */
$clock = static function (DateTimeImmutable $at) use ($db): DateTimeImmutable {
    $db->pdo()->exec("SET timestamp = UNIX_TIMESTAMP('" . $at->format('Y-m-d H:i:s') . "')");

    return $at;
};
$chance = static fn (int $percent): bool => mt_rand(1, 100) <= $percent;

// --- Stations --------------------------------------------------------------------------------------

/** Litres par cm de chaque cuve (barème linéaire de bin/seed.php) et volume d'une livraison. */
$tankSpec = ['ESS' => ['lpcm' => 120, 'delivery' => 18000, 'price' => 3450], 'MAZ' => ['lpcm' => 160, 'delivery' => 24000, 'price' => 3440]];
$stations = [];
foreach (['lsh' => [1.0, ['pompiste1.lsh', 'pompiste2.lsh']], 'kzi' => [0.8, ['pompiste1.kzi', 'pompiste2.kzi']]] as $slug => [$factor, $attendants]) {
    $stationId = (string) $value('SELECT us.station_id FROM user_stations us JOIN users u ON u.id = us.user_id WHERE u.username = :u', ['u' => "gerant.$slug"]);
    $station = ['id' => $stationId, 'slug' => $slug, 'manager' => "gerant.$slug", 'attendants' => $attendants, 'factor' => $factor, 'rate' => 2850, 'products' => []];
    foreach ($tankSpec as $code => $spec) {
        $station['products'][$code] = $spec + [
            'product' => (string) $value('SELECT id FROM products WHERE station_id = :s AND code = :c', ['s' => $stationId, 'c' => $code]),
            'tank' => (string) $value('SELECT id FROM tanks WHERE station_id = :s AND code = :c', ['s' => $stationId, 'c' => "C-$code"]),
            'nozzle' => (string) $value('SELECT id FROM nozzles WHERE station_id = :s AND code = :c', ['s' => $stationId, 'c' => "P-$code"]),
            'index' => 0, 'stock' => 0,
        ];
    }
    $stations[$slug] = $station;
}

/** Jauge une cuve à partir du stock suivi par ce script, moins une perte ; le stock suivi repart du volume jaugé. */
$dip = static function (array &$station, string $code, string $type, DateTimeImmutable $at, int $loss = 0) use ($as, $clock): void {
    $p = &$station['products'][$code];
    $depth = floor(max(0, $p['stock'] - $loss) / $p['lpcm'] * 10) / 10;
    $as($station['manager'], StockControl::class)->recordDip($p['tank'], ['depth_cm' => number_format($depth, 1, ',', ''), 'dip_type' => $type], $clock($at));
    $p['stock'] = (int) round($depth * $p['lpcm']);
};

// Répertoire des fournisseurs de chaque station : les livraisons y choisissent le leur.
$suppliers = [];
foreach ($stations as $slug => $station) {
    foreach (['SEP Congo', 'Engen RDC', 'SONAHYDROC', 'Puma Energy'] as $name) {
        $suppliers[$slug][] = $as($station['manager'], SupplierService::class)->create($station['id'], ['name' => $name]);
    }
}
$customers = ['Mining Services SARL', 'Transport Mulumba', 'ONG Espoir', 'Hôtel Karavia', 'Brasserie Simba'];
$expenses = [['transport', 'Course à la banque'], ['maintenance', 'Nettoyage de la piste'], ['supplies', 'Carnets de reçus'], ['maintenance', 'Réparation du flexible'], ['generator_fuel', 'Carburant du groupe électrogène']];
$counts = ['postes' => 0, 'livraisons' => 0, 'prix' => 0, 'taux' => 0];

// --- Journée par journée ---------------------------------------------------------------------------

for ($n = 0; $n < $days; $n++) {
    $day = $first->modify("+$n days");
    $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($day->format('Y-m-d') . " $time:00");
    $weekday = (int) $day->format('N');
    if ($day->format('d') === '01' || $n === 0) {
        printf("  %s… (%d s)\n", $day->format('m/Y'), (int) (microtime(true) - $started));
    }

    foreach ($stations as $slug => &$station) {
        // Premier jour : jaugeage d'ouverture, cuves aux quatre cinquièmes.
        if ($n === 0) {
            foreach ($station['products'] as $code => &$p) {
                $p['stock'] = 200 * $p['lpcm'];
                $dip($station, $code, 'opening', $at('05:00'));
            }
            unset($p);
        }

        // 05 h : livraison quand une cuve passe sous 8 000 L.
        foreach ($station['products'] as $code => &$p) {
            if ($p['stock'] >= 8000) {
                continue;
            }
            $missing = $chance(8) ? mt_rand(120, 320) : mt_rand(0, 60);
            $before = floor($p['stock'] / $p['lpcm'] * 10) / 10;
            $received = $p['delivery'] - $missing;
            $after = floor(($before * $p['lpcm'] + $received - mt_rand(0, 25)) / $p['lpcm'] * 10) / 10;
            $as($station['manager'], DeliveryService::class)->receive($station['id'], [
                'tank_id' => $p['tank'], 'supplier_id' => $suppliers[$slug][array_rand($suppliers[$slug])], 'delivery_note_no' => 'BL-' . $day->format('ymd') . '-' . strtoupper($slug),
                'truck_plate' => mt_rand(1000, 9999) . 'AB/0' . mt_rand(1, 9), 'driver_name' => 'Chauffeur ' . mt_rand(1, 12),
                'ordered_volume_l' => (string) $p['delivery'], 'invoiced_volume_l' => (string) $p['delivery'], 'received_volume_l' => (string) $received,
                'before_cm' => number_format($before, 1, ',', ''), 'after_cm' => number_format($after, 1, ',', ''), 'received_by' => (string) $value('SELECT id FROM users WHERE username = :u', ['u' => $station['manager']]),
                'note' => $missing > 100 ? 'Manque constaté au dépotage, signalé au chauffeur' : null,
            ], null, $clock($at('05:00')));
            $p['stock'] = (int) round($after * $p['lpcm']);
            $counts['livraisons']++;
        }
        unset($p);

        // 05 h 30 : le propriétaire ajuste un prix tous les deux mois environ, le taux chaque mois (hors poste).
        if ($n > 0 && $n % 61 === ($slug === 'lsh' ? 20 : 27)) {
            $code = $chance(50) ? 'ESS' : 'MAZ';
            $station['products'][$code]['price'] += [-40, 30, 50, 60, 80][mt_rand(0, 4)];
            $as('proprietaire', PricingService::class)->publishPrice($station['products'][$code]['product'], ['unit_price' => (string) $station['products'][$code]['price'], 'currency' => 'CDF', 'note' => 'Révision du prix'], $clock($at('05:30')));
            $counts['prix']++;
        }
        $midShiftRate = $n > 0 && $n % 97 === ($slug === 'lsh' ? 40 : 55);
        if ($n > 0 && !$midShiftRate && $n % 30 === ($slug === 'lsh' ? 10 : 12)) {
            $station['rate'] += mt_rand(-10, 35) ?: 15;
            $as('proprietaire', PricingService::class)->publishRate($station['id'], ['rate' => (string) $station['rate'], 'note' => 'Taux du mois'], $clock($at('05:35')));
            $counts['taux']++;
        }

        // Deux postes : 06 h–12 h et 12 h 30–18 h. Moins de ventes le dimanche, un peu plus le vendredi et le samedi.
        $activity = $station['factor'] * [1 => 1.0, 2 => 0.95, 3 => 1.0, 4 => 1.05, 5 => 1.2, 6 => 1.15, 7 => 0.6][$weekday];
        foreach ([['Matin', '06:00', '12:00'], ['Après-midi', '12:30', '18:00']] as $s => [$label, $from, $to]) {
            $attendant = $station['attendants'][($n + $s) % 2];
            $manager = $station['manager'];
            $open = new DateTimeImmutable($day->format('Y-m-d') . " $from:00");
            $close = new DateTimeImmutable($day->format('Y-m-d') . " $to:00");
            $opening = [];
            foreach ($station['products'] as $p) {
                $opening[$p['nozzle']] = (string) $p['index'];
            }
            $shift = $as($manager, ShiftService::class)->open($station['id'], ['attendant_id' => $userId($attendant), 'label' => $label] + ($n === 0 && $s === 0 ? ['opening' => $opening] : []), $clock($open));

            $test = $s === 0 && $weekday === 1 ? 20 : 0;
            if ($test > 0) {
                $as($attendant, ShiftService::class)->addOutput($shift, ['nozzle_id' => $station['products']['ESS']['nozzle'], 'output_type' => 'test', 'volume_l' => (string) $test, 'reason' => 'Essai de pompe hebdomadaire'], $clock($open->modify('+2 hours')));
            }
            $sold = [];
            foreach ($station['products'] as $code => $p) {
                $sold[$code] = (int) round(mt_rand($code === 'ESS' ? 300 : 380, $code === 'ESS' ? 560 : 720) * $activity) + ($code === 'ESS' ? $test : 0);
            }
            // Changement de taux à 14 h, en plein poste de l'après-midi : relevé intermédiaire de chaque pistolet.
            if ($s === 1 && $midShiftRate) {
                $station['rate'] += mt_rand(15, 40);
                $readings = [];
                foreach ($station['products'] as $code => $p) {
                    $readings[$p['nozzle']] = (string) ($p['index'] + intdiv($sold[$code], 3));
                }
                $as('proprietaire', PricingService::class)->publishRate($station['id'], ['rate' => (string) $station['rate'], 'note' => 'Taux révisé en cours de journée', 'readings' => [$shift => $readings]], $clock($open->modify('+90 minutes')));
                $counts['taux']++;
            }
            $final = [];
            foreach ($station['products'] as $code => &$p) {
                $p['index'] += $sold[$code];
                $p['stock'] -= $sold[$code];
                $final[$p['nozzle']] = (string) $p['index'];
            }
            unset($p);
            $as($attendant, ShiftService::class)->close($shift, ['final' => $final], $clock($close));

            // Caisse : ventes à crédit et dépenses de temps en temps, puis remise en francs, parfois aussi en dollars.
            $cash = $as($manager, CashControl::class);
            if ($chance(6)) {
                $code = $chance(50) ? 'ESS' : 'MAZ';
                $cash->addCreditSale($shift, ['nozzle_id' => $station['products'][$code]['nozzle'], 'customer_name' => $customers[array_rand($customers)], 'voucher_no' => 'B-' . mt_rand(100, 999), 'volume_l' => (string) mt_rand(20, 80)], $clock($close->modify('+2 minutes')));
            }
            if ($chance(7)) {
                [$category, $description] = $expenses[array_rand($expenses)];
                $cash->addExpense($shift, ['category' => $category, 'description' => $description, 'amount' => (string) (mt_rand(4, 30) * 1000), 'currency' => 'CDF'], $clock($close->modify('+3 minutes')));
            }
            $expected = (int) $value('SELECT expected_amount FROM shifts WHERE id = :id', ['id' => $shift]);
            $roll = mt_rand(1, 100);
            $delta = match (true) {
                $roll <= 4 => -mt_rand(6, 30) * 1000,       // manquant au-delà de la tolérance : alerte
                $roll <= 16 => -mt_rand(1, 9) * 500,        // petit manquant toléré
                $roll <= 19 => mt_rand(6, 15) * 1000,       // excédent au-delà de la tolérance : alerte
                $roll <= 26 => mt_rand(1, 8) * 500,         // petit excédent
                default => 0,
            };
            $dollars = $chance(22) ? mt_rand(1, 6) * 50 : 0;
            $francs = max(0, $expected + $delta - $dollars * $station['rate']);
            $cash->addHandover($shift, ['amount' => (string) $francs, 'currency' => 'CDF'], $clock($close->modify('+5 minutes')));
            if ($dollars > 0) {
                $cash->addHandover($shift, ['amount' => (string) $dollars, 'currency' => 'USD'], $clock($close->modify('+6 minutes')));
            }
            $as($manager, ShiftService::class)->validate($shift, [], $clock($close->modify('+20 minutes')));
            // Un écart signalé sur deux est justifié par le gérant.
            $variance = (int) $value('SELECT variance_amount FROM shifts WHERE id = :id', ['id' => $shift]);
            if (abs($variance) > 5000 && $chance(50)) {
                $cash->justify($shift, ['amount' => (string) abs($variance), 'reason' => $variance < 0 ? 'Billets refusés par la banque, repris par le pompiste' : 'Monnaie non rendue à un client'], $clock($close->modify('+40 minutes')));
            }
            $counts['postes']++;
        }

        // Dimanche soir : jaugeage de contrôle, avec la perte de la semaine (parfois anormale).
        if ($weekday === 7) {
            foreach (array_keys($station['products']) as $code) {
                $dip($station, $code, 'control', $at('18:40'), $chance(6) ? mt_rand(90, 260) : mt_rand(0, 30));
            }
        }
    }
    unset($station);

    // Les alertes anciennes ont été traitées : seules celles des deux dernières semaines restent ouvertes.
    if ($n % 7 === 6 || $n === $days - 1) {
        $clock($at('19:00'));
        $db->execute(
            "UPDATE alerts a SET a.status = 'resolved', a.acknowledged_at = COALESCE(a.acknowledged_at, NOW()), a.resolved_at = NOW(),
                    a.acknowledged_by = COALESCE(a.acknowledged_by, (SELECT u.id FROM users u JOIN user_stations us ON us.user_id = u.id JOIN roles r ON r.id = u.role_id WHERE us.station_id = a.station_id AND r.code = 'manager' LIMIT 1)),
                    a.resolved_by = (SELECT u.id FROM users u JOIN user_stations us ON us.user_id = u.id JOIN roles r ON r.id = u.role_id WHERE us.station_id = a.station_id AND r.code = 'manager' LIMIT 1),
                    a.resolution_note = 'Vérifié avec le pompiste, dossier classé'
              WHERE a.status <> 'resolved' AND a.created_at < :limit",
            ['limit' => $day->modify('-14 days')->format('Y-m-d H:i:s')]
        );
    }
}

// Aujourd'hui : l'horloge revient à l'heure réelle, un poste est ouvert à Lubumbashi.
$db->pdo()->exec('SET timestamp = DEFAULT');
$as('gerant.lsh', ShiftService::class)->open($stations['lsh']['id'], ['attendant_id' => $userId('pompiste1.lsh'), 'label' => 'Matin'], new DateTimeImmutable('-15 minutes'));

printf(
    "Base %s prête en %d s : %d jours, %d postes, %d livraisons, %d changements de prix, %d de taux, %d alertes (%d ouvertes).\n",
    $name,
    (int) (microtime(true) - $started),
    $days,
    $counts['postes'] + 1,
    $counts['livraisons'],
    $counts['prix'],
    $counts['taux'],
    (int) $value('SELECT COUNT(*) FROM alerts'),
    (int) $value("SELECT COUNT(*) FROM alerts WHERE status = 'open'")
);
echo 'Comptes (mot de passe ' . PASSWORD . ") : proprietaire, gerant.lsh, pompiste1.lsh, pompiste2.lsh, gerant.kzi, pompiste1.kzi, pompiste2.kzi\n";
