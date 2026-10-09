<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Seeds;

use Petra\Core\Database;
use Petra\Core\Uuid;

/**
 * Jeu d'essai de la recette, posé sur une base petra_test vide (tables de référence comprises).
 *
 * Organisation « Pétroles du Lualaba », deux stations :
 *
 *   Kolwezi (KZI)  Essence 3 000 FC/L · Mazout 2 900 FC/L · taux 2 800 FC pour 1 $
 *                  gérant : Patrick Ilunga · pompistes : Grâce Mwamba, Daniel Kasongo
 *   Likasi  (LKS)  Essence 3 100 FC/L · taux 2 820 FC pour 1 $
 *                  gérant : Sarah Kabwe · pompiste : Moïse Tshibangu
 *
 * Chaque produit a une cuve de 30 000 L (barème linéaire : 100 L par cm) et un pistolet.
 * Prix et taux sont en vigueur depuis la veille ; les cuves de Kolwezi sont jaugées à 05 h 30
 * le jour de la recette (Essence 20 000 L, Mazout 15 000 L), celle de Likasi à 12 000 L.
 * Seuils par défaut : tolérance de caisse 5 000 FC, écart de livraison 100 L, stock bas 3 000 L.
 */
final class RecetteSeed
{
    public const DAY = '2026-11-02';
    public const EVE = '2026-11-01';

    /** @var array<string, string> identifiants créés, par nom court */
    public array $id = [];

    private function __construct(private readonly Database $db, private readonly string $passwordHash)
    {
    }

    public static function install(Database $db, string $passwordHash): self
    {
        $seed = new self($db, $passwordHash);
        $seed->run();

        return $seed;
    }

    private function run(): void
    {
        $org = $this->insert('org', 'organizations', ['name' => 'Pétroles du Lualaba']);
        $k = $this->insert('kolwezi', 'stations', ['organization_id' => $org, 'code' => 'KZI', 'name' => 'Station Kolwezi', 'city' => 'Kolwezi']);
        $l = $this->insert('likasi', 'stations', ['organization_id' => $org, 'code' => 'LKS', 'name' => 'Station Likasi', 'city' => 'Likasi']);

        $owner = $this->user('owner', $org, 'owner', 'proprietaire', 'Joseph', 'Mutombo', []);
        $this->user('manager_k', $org, 'manager', 'gerant.kzi', 'Patrick', 'Ilunga', [$k]);
        $this->user('attendant_k1', $org, 'attendant', 'pompiste1.kzi', 'Grâce', 'Mwamba', [$k]);
        $this->user('attendant_k2', $org, 'attendant', 'pompiste2.kzi', 'Daniel', 'Kasongo', [$k]);
        $this->user('manager_l', $org, 'manager', 'gerant.lks', 'Sarah', 'Kabwe', [$l]);
        $this->user('attendant_l', $org, 'attendant', 'pompiste1.lks', 'Moïse', 'Tshibangu', [$l]);

        $this->insert('supplier_k', 'suppliers', ['station_id' => $k, 'name' => 'SEP Congo']);
        $this->pump('k_ess', $k, 'ESS', 'Essence', 3000, $owner, '200.0', '20000.00', $this->id['manager_k']);
        $this->pump('k_maz', $k, 'MAZ', 'Mazout', 2900, $owner, '150.0', '15000.00', $this->id['manager_k']);
        $this->pump('l_ess', $l, 'ESS', 'Essence', 3100, $owner, '120.0', '12000.00', $this->id['manager_l']);

        $this->insert('rate_k', 'exchange_rates', ['station_id' => $k, 'rate' => 2800, 'effective_at' => self::EVE . ' 08:00:00', 'created_by' => $owner]);
        $this->insert('rate_l', 'exchange_rates', ['station_id' => $l, 'rate' => 2820, 'effective_at' => self::EVE . ' 08:00:00', 'created_by' => $owner]);
    }

    /** Produit, cuve avec barème, pistolet, prix et jaugeage d'ouverture. */
    private function pump(string $key, string $station, string $code, string $name, int $price, string $owner, string $depth, string $volume, string $manager): void
    {
        $product = $this->insert("product_$key", 'products', ['station_id' => $station, 'code' => $code, 'name' => $name]);
        $tank = $this->insert("tank_$key", 'tanks', ['station_id' => $station, 'product_id' => $product, 'code' => "C-$code", 'name' => "Cuve $name", 'capacity_l' => '30000.00']);
        foreach ([['0.0', '0.00'], ['150.0', '15000.00'], ['300.0', '30000.00']] as [$cm, $litres]) {
            $this->insert("cal_{$key}_$cm", 'tank_calibrations', ['station_id' => $station, 'tank_id' => $tank, 'depth_cm' => $cm, 'volume_l' => $litres, 'created_by' => $owner]);
        }
        $this->insert("nozzle_$key", 'nozzles', ['station_id' => $station, 'tank_id' => $tank, 'code' => "P-$code", 'name' => "Pistolet $name"]);
        $this->insert("price_$key", 'prices', ['station_id' => $station, 'product_id' => $product, 'unit_price' => $price, 'currency' => 'CDF', 'effective_at' => self::EVE . ' 08:00:00', 'created_by' => $owner]);
        $this->insert("dip_$key", 'tank_dips', [
            'station_id' => $station, 'tank_id' => $tank, 'dip_type' => 'opening', 'depth_cm' => $depth, 'volume_l' => $volume,
            'measured_at' => self::DAY . ' 05:30:00', 'created_by' => $manager,
        ]);
    }

    /** @param list<string> $stations stations attribuées ; aucune pour le propriétaire (toutes) */
    private function user(string $key, string $org, string $role, string $username, string $first, string $last, array $stations): string
    {
        $id = $this->insert($key, 'users', [
            'organization_id' => $org, 'role_id' => (string) $this->db->fetchValue('SELECT id FROM roles WHERE code = :c', ['c' => $role]),
            'username' => $username, 'password_hash' => $this->passwordHash, 'first_name' => $first, 'last_name' => $last, 'all_stations' => $stations === [] ? 1 : 0,
        ]);
        foreach ($stations as $station) {
            $this->insert("us_{$key}_$station", 'user_stations', ['user_id' => $id, 'station_id' => $station]);
        }

        return $id;
    }

    private function insert(string $key, string $table, array $row): string
    {
        $row = ['id' => Uuid::v7()] + $row;
        $columns = array_keys($row);
        $this->db->execute("INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')', $row);

        return $this->id[$key] = $row['id'];
    }
}
