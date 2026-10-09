<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Base de démonstration du guide utilisateur : php bin/guide-demo.php
 *
 * (Re)crée la base nommée par DB_NAME — qui doit se terminer par « _guide » ou « _demo », pour ne jamais
 * effacer une base de travail —, y applique les migrations et bin/seed.php, puis rejoue par les services
 * de l'application deux journées d'activité (l'avant-veille et la veille) et ouvre un poste aujourd'hui :
 * postes validés avec et sans écart, essai de pompe, paiement mixte CDF/USD, changement de taux à 14 h,
 * livraison avec écart, jaugeages. Lancé par « npm run screenshots » ; mot de passe des comptes : Petra-Demo-2026.
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
    fwrite(STDERR, "Refus : DB_NAME=$name. Cette commande efface la base ; son nom doit se terminer par _guide ou _demo.\n");
    exit(1);
}
const PASSWORD = 'Petra-Demo-2026';

// Base neuve, migrations, données de démonstration (prix et taux en vigueur depuis une semaine).
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db']['host'], $config['db']['port']), (string) $config['db']['user'], (string) $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `$name`");
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new Database($config['db']);
(new Migrator($db, $root . '/database/migrations'))->migrate();
$today = new DateTimeImmutable('today');
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/seed.php') . ' ' . escapeshellarg(PASSWORD) . ' ' . escapeshellarg($today->modify('-7 days')->format('Y-m-d') . ' 08:00:00') . ' > ' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $code);
if ($code !== 0) {
    fwrite(STDERR, "bin/seed.php a échoué.\n");
    exit(1);
}

/** Identifiant d'une ligne par une colonne unique dans sa station. */
$id = static fn (string $sql, array $params = []): string => (string) $db->fetchValue($sql, $params);
$user = static fn (string $username): string => $id('SELECT id FROM users WHERE username = :u', ['u' => $username]);

/**
 * Service de l'application, résolu au nom d'un utilisateur (comme s'il était connecté).
 *
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
$as = static function (string $username, string $class) use ($config, $db, $user): object {
    $userId = $user($username);
    $hash = (string) $db->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
    $session = Session::memory([Auth::SESSION_USER => $userId, Auth::SESSION_FINGERPRINT => PasswordHasher::fingerprint($hash)]);

    return App::create($config, $session, $db, new Request('GET', '/'))->container()->get($class);
};
$at = static fn (int $daysAgo, string $time): DateTimeImmutable => new DateTimeImmutable($today->modify("-$daysAgo days")->format('Y-m-d') . " $time:00");

/**
 * Poste complet : ouverture, essai de pompe éventuel, clôture, remises, validation.
 *
 * @param array<string, array{0: ?string, 1: string}> $nozzles code de pistolet => [index d'ouverture (null : reprise), index final]
 * @param list<array{0: string, 1: string}> $handovers [montant, devise]
 */
$shift = static function (string $slug, string $attendant, string $label, int $daysAgo, string $from, string $to, array $nozzles, array $handovers, ?string $test = null) use ($as, $at, $id, $user): string {
    $station = $id('SELECT s.id FROM stations s JOIN user_stations us ON us.station_id = s.id JOIN users u ON u.id = us.user_id WHERE u.username = :u', ['u' => "gerant.$slug"]);
    $nozzle = static fn (string $code): string => $id('SELECT id FROM nozzles WHERE station_id = :s AND code = :c', ['s' => $station, 'c' => $code]);
    $opening = [];
    $final = [];
    foreach ($nozzles as $code => [$open, $close]) {
        if ($open !== null) {
            $opening[$nozzle($code)] = $open;
        }
        $final[$nozzle($code)] = $close;
    }
    $shiftId = $as("gerant.$slug", ShiftService::class)->open($station, ['attendant_id' => $user($attendant), 'label' => $label] + ($opening === [] ? [] : ['opening' => $opening]), $at($daysAgo, $from));
    if ($test !== null) {
        $as($attendant, ShiftService::class)->addOutput($shiftId, ['nozzle_id' => $nozzle('P-ESS'), 'output_type' => 'test', 'volume_l' => $test, 'reason' => 'Essai de pompe du matin'], $at($daysAgo, $from)->modify('+2 hours'));
    }
    $as($attendant, ShiftService::class)->close($shiftId, ['final' => $final], $at($daysAgo, $to));
    foreach ($handovers as $i => [$amount, $currency]) {
        $as("gerant.$slug", CashControl::class)->addHandover($shiftId, ['amount' => $amount, 'currency' => $currency], $at($daysAgo, $to)->modify('+' . (5 + $i) . ' minutes'));
    }
    $as("gerant.$slug", ShiftService::class)->validate($shiftId, ['note' => 'Caisse comptée devant le pompiste'], $at($daysAgo, $to)->modify('+20 minutes'));

    return $shiftId;
};
$tank = static fn (string $slug, string $code): string => $id(
    'SELECT t.id FROM tanks t JOIN stations s ON s.id = t.station_id JOIN user_stations us ON us.station_id = s.id JOIN users u ON u.id = us.user_id WHERE u.username = :u AND t.code = :c',
    ['u' => "gerant.$slug", 'c' => $code]
);
$stationOf = static fn (string $slug): string => $id('SELECT us.station_id FROM user_stations us JOIN users u ON u.id = us.user_id WHERE u.username = :u', ['u' => "gerant.$slug"]);

// Jaugeages d'ouverture, l'avant-veille à 05 h 30.
foreach (['lsh', 'kzi'] as $slug) {
    foreach (['C-ESS' => '150', 'C-MAZ' => '150'] as $code => $depth) {
        $as("gerant.$slug", StockControl::class)->recordDip($tank($slug, $code), ['depth_cm' => $depth, 'dip_type' => 'opening'], $at(2, '05:30'));
    }
}

// --- Lubumbashi, avant-veille : matin sans écart, après-midi avec un manquant de 9 600 FC.
$shift('lsh', 'pompiste1.lsh', 'Matin', 2, '06:00', '12:00', ['P-ESS' => ['0', '420'], 'P-MAZ' => ['0', '610']], [['3 547 400', 'CDF']]);
$shift('lsh', 'pompiste2.lsh', 'Après-midi', 2, '12:00', '18:00', ['P-ESS' => [null, '780'], 'P-MAZ' => [null, '1150']], [['3 090 000', 'CDF']]);
// --- Kolwezi, avant-veille et veille.
$shift('kzi', 'pompiste1.kzi', 'Matin', 2, '06:00', '12:00', ['P-ESS' => ['0', '300'], 'P-MAZ' => ['0', '500']], [['2 760 000', 'CDF']]);
$shift('kzi', 'pompiste2.kzi', 'Matin', 1, '06:00', '12:00', ['P-ESS' => [null, '640'], 'P-MAZ' => [null, '980']], [['2 824 200', 'CDF']]);

// --- Lubumbashi, veille : essai de pompe de 20 L, paiement mixte CDF/USD.
$shift('lsh', 'pompiste1.lsh', 'Matin', 1, '06:00', '12:00', ['P-ESS' => [null, '1230'], 'P-MAZ' => [null, '1700']], [['3 000 000', 'CDF'], ['130', 'USD']], '20');
// 13 h : livraison d'essence, 12 000 L annoncés, 11 850 L reçus.
// Répertoire des fournisseurs de la station, puis réception : le fournisseur s'y choisit.
$sepCongo = $as('gerant.lsh', SupplierService::class)->create($stationOf('lsh'), ['name' => 'SEP Congo', 'contact_name' => 'Marc Tshilombo', 'phone' => '+243 81 555 01 20', 'address' => 'Avenue Kasa-Vubu, Lubumbashi']);
$as('gerant.lsh', SupplierService::class)->create($stationOf('lsh'), ['name' => 'Engen RDC', 'phone' => '+243 99 700 44 10']);
$as('gerant.lsh', DeliveryService::class)->receive($stationOf('lsh'), [
    'tank_id' => $tank('lsh', 'C-ESS'), 'supplier_id' => $sepCongo, 'delivery_note_no' => 'BL-2026-0412', 'truck_plate' => '4521AB/07', 'driver_name' => 'Paul Kalala',
    'ordered_volume_l' => '12000', 'invoiced_volume_l' => '12000', 'received_volume_l' => '11850', 'before_cm' => '139,7', 'after_cm' => '238,4',
    'received_by' => $user('gerant.lsh'), 'note' => 'Manque constaté au dépotage, signalé au chauffeur',
], null, $at(1, '13:00'));
// Après-midi : le propriétaire passe le taux de 2 850 à 2 880 à 14 h, avec relevé intermédiaire.
$station = $stationOf('lsh');
$afternoon = $as('gerant.lsh', ShiftService::class)->open($station, ['attendant_id' => $user('pompiste2.lsh'), 'label' => 'Après-midi'], $at(1, '12:00'));
$ess = $id('SELECT id FROM nozzles WHERE station_id = :s AND code = :c', ['s' => $station, 'c' => 'P-ESS']);
$maz = $id('SELECT id FROM nozzles WHERE station_id = :s AND code = :c', ['s' => $station, 'c' => 'P-MAZ']);
// Le propriétaire a toutes les stations : il choisit Lubumbashi, comme dans l'en-tête de l'application.
$as('proprietaire', PricingService::class)->publishRate($station, ['rate' => '2880', 'note' => 'Taux du jour', 'readings' => [$afternoon => [$ess => '1380', $maz => '1900']]], $at(1, '14:00'));
$as('pompiste2.lsh', ShiftService::class)->close($afternoon, ['final' => [$ess => '1600', $maz => '2200']], $at(1, '18:00'));
$as('gerant.lsh', CashControl::class)->addHandover($afternoon, ['amount' => '2 700 000', 'currency' => 'CDF'], $at(1, '18:05'));
$as('gerant.lsh', CashControl::class)->addHandover($afternoon, ['amount' => '100', 'currency' => 'USD'], $at(1, '18:06'));
$as('gerant.lsh', ShiftService::class)->validate($afternoon, ['note' => 'Manquant reconnu par le pompiste'], $at(1, '18:20'));
$as('gerant.lsh', StockControl::class)->recordDip($tank('lsh', 'C-ESS'), ['depth_cm' => '235,3', 'dip_type' => 'closing'], $at(1, '18:30'));

// --- Aujourd'hui : un poste ouvert à Lubumbashi (écrans du pompiste), ouvert il y a un quart d'heure.
$as('gerant.lsh', ShiftService::class)->open($station, ['attendant_id' => $user('pompiste1.lsh'), 'label' => 'Matin'], new DateTimeImmutable('-15 minutes'));

printf(
    "Base %s prête : %d postes, %d alertes. Comptes (mot de passe %s) : proprietaire, gerant.lsh, pompiste1.lsh…\n",
    $name,
    (int) $db->fetchValue('SELECT COUNT(*) FROM shifts'),
    (int) $db->fetchValue('SELECT COUNT(*) FROM alerts'),
    PASSWORD
);
