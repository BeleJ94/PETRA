<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Jeu de données de démonstration (base vide uniquement) : php bin/seed.php [mot-de-passe] ["AAAA-MM-JJ HH:MM:SS"]
 * Le second argument date l'entrée en vigueur des prix et du taux (par défaut : maintenant) ; bin/guide-demo.php
 * s'en sert pour rejouer ensuite quelques journées d'activité passées.
 * Une organisation, deux stations ; par station : Essence et Mazout, une cuve (avec barème) et un pistolet
 * par produit, un prix par produit, un taux USD/CDF, les seuils et horaires par défaut, un gérant et deux pompistes.
 */

use Petra\Core\Database;
use Petra\Core\Uuid;
use Petra\Domain\StationSettings;
use Petra\Security\PasswordHasher;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$password = $argv[1] ?? 'Petra-Demo-2026';
$db = new Database($config['db']);
if ((int) $db->fetchValue('SELECT COUNT(*) FROM organizations') > 0) {
    fwrite(STDERR, "La base contient déjà des données : seed annulé.\n");
    exit(1);
}

/** Insère une ligne avec un UUID neuf et retourne cet identifiant. */
$insert = static function (string $table, array $row) use ($db): string {
    $row = ['id' => Uuid::v7()] + $row;
    $columns = array_keys($row);
    $db->execute(
        "INSERT INTO `$table` (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')',
        $row
    );

    return $row['id'];
};

$roles = array_column($db->fetchAll('SELECT code, id FROM roles'), 'id', 'code');
$hash = PasswordHasher::hash($password);
$now = isset($argv[2]) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $argv[2]) ? $argv[2] : date('Y-m-d H:i:s');

$stations = [
    ['code' => 'LSH-01', 'name' => 'Station Lubumbashi Centre', 'city' => 'Lubumbashi', 'address' => 'Avenue Kasa-Vubu', 'slug' => 'lsh',
        'manager' => ['Patrick', 'Ilunga'], 'attendants' => [['Grâce', 'Mwamba'], ['Jean', 'Kabongo']]],
    ['code' => 'KZI-01', 'name' => 'Station Kolwezi Mutoshi', 'city' => 'Kolwezi', 'address' => 'Route de Likasi', 'slug' => 'kzi',
        'manager' => ['Sarah', 'Tshibanda'], 'attendants' => [['Dieudonné', 'Kasongo'], ['Esther', 'Ngoy']]],
];
// Produit : code, nom, capacité de la cuve (L), hauteur utile (cm), prix du litre (CDF)
$products = [
    ['ESS', 'Essence', '30000.00', 250, 3450],
    ['MAZ', 'Mazout', '40000.00', 250, 3440],
];
// Seuils par station (principe 9 : tout seuil est paramétrable).
$settings = array_map(static fn (array $definition): string => $definition[1], StationSettings::THRESHOLDS)
    + [StationSettings::SCHEDULE_KEY => json_encode(StationSettings::DEFAULT_SCHEDULE, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];

$summary = $db->transaction(static function () use ($insert, $roles, $hash, $now, $stations, $products, $settings): array {
    $organizationId = $insert('organizations', ['name' => 'Pétrole du Katanga (démo)', 'legal_name' => 'Pétrole du Katanga SARL']);
    $ownerId = $insert('users', [
        'organization_id' => $organizationId, 'role_id' => $roles['owner'], 'username' => 'proprietaire',
        'email' => 'proprietaire@petra.test', 'password_hash' => $hash, 'first_name' => 'Joseph', 'last_name' => 'Mutombo', 'all_stations' => 1,
    ]);
    $logins = ['proprietaire'];

    foreach ($stations as $station) {
        $stationId = $insert('stations', [
            'organization_id' => $organizationId, 'code' => $station['code'], 'name' => $station['name'],
            'city' => $station['city'], 'address' => $station['address'], 'created_by' => $ownerId,
        ]);

        // Un distributeur multi-produits par station : ses pistolets servent l'essence et le mazout.
        $pumpId = $insert('pumps', ['station_id' => $stationId, 'code' => 'D1', 'name' => 'Distributeur 1', 'created_by' => $ownerId]);
        foreach ($products as [$code, $name, $capacity, $height, $price]) {
            $productId = $insert('products', ['station_id' => $stationId, 'code' => $code, 'name' => $name, 'created_by' => $ownerId]);
            $tankId = $insert('tanks', [
                'station_id' => $stationId, 'product_id' => $productId, 'code' => 'C-' . $code, 'name' => 'Cuve ' . $name,
                'capacity_l' => $capacity, 'created_by' => $ownerId,
            ]);
            // Barème de démonstration : cuve à section constante, un point tous les 10 cm (calcul entier, en centilitres).
            $capacityCl = (int) str_replace('.', '', $capacity);
            for ($depth = 0; $depth <= $height; $depth += 10) {
                $cl = intdiv($capacityCl * $depth, $height);
                $insert('tank_calibrations', [
                    'station_id' => $stationId, 'tank_id' => $tankId, 'depth_cm' => $depth . '.0',
                    'volume_l' => intdiv($cl, 100) . '.' . str_pad((string) ($cl % 100), 2, '0', STR_PAD_LEFT), 'created_by' => $ownerId,
                ]);
            }
            $insert('nozzles', [
                'station_id' => $stationId, 'tank_id' => $tankId, 'code' => 'P-' . $code, 'name' => 'Pistolet ' . $name,
                'pump_id' => $pumpId, 'created_by' => $ownerId,
            ]);
            $insert('prices', [
                'station_id' => $stationId, 'product_id' => $productId, 'unit_price' => $price, 'currency' => 'CDF',
                'effective_at' => $now, 'note' => 'Prix initial (démonstration)', 'created_by' => $ownerId,
            ]);
        }

        $insert('exchange_rates', [
            'station_id' => $stationId, 'rate' => 2850, 'effective_at' => $now, 'note' => 'Taux initial (démonstration)', 'created_by' => $ownerId,
        ]);
        foreach ($settings as $key => $value) {
            $insert('station_settings', ['station_id' => $stationId, 'setting_key' => $key, 'setting_value' => $value, 'created_by' => $ownerId]);
        }

        $staff = [['manager', 'gerant.' . $station['slug'], $station['manager']]];
        foreach ($station['attendants'] as $i => $attendant) {
            $staff[] = ['attendant', 'pompiste' . ($i + 1) . '.' . $station['slug'], $attendant];
        }
        foreach ($staff as [$role, $username, [$first, $last]]) {
            $userId = $insert('users', [
                'organization_id' => $organizationId, 'role_id' => $roles[$role], 'username' => $username,
                'password_hash' => $hash, 'first_name' => $first, 'last_name' => $last, 'created_by' => $ownerId,
            ]);
            $insert('user_stations', ['user_id' => $userId, 'station_id' => $stationId, 'created_by' => $ownerId]);
            $logins[] = $username;
        }
    }

    return $logins;
});

echo "Données de démonstration créées.\n";
echo 'Comptes (mot de passe : ' . $password . ") :\n  " . implode("\n  ", $summary) . "\n";
