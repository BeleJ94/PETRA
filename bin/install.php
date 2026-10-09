<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

/**
 * Première mise en service, sur une base migrée et vide (serveur central, ou station autonome) :
 *
 *   php bin/install.php "Nom de l'organisation" Prénom Nom identifiant [email]
 *
 * Crée l'organisation et le compte Propriétaire (toutes les stations). Le mot de passe est généré et
 * affiché une seule fois : il se change ensuite dans l'application. Les stations se créent dans l'écran
 * « Stations ». Un serveur de station relié à un central n'utilise pas ce script : sa première
 * synchronisation (php bin/sync.php) lui apporte l'organisation, sa fiche et ses comptes.
 */

use Petra\Core\Database;
use Petra\Core\Uuid;
use Petra\Security\PasswordHasher;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

[$organization, $firstName, $lastName, $username, $email] = array_pad(array_map('trim', array_slice($argv, 1)), 5, '');
if ($organization === '' || $firstName === '' || $lastName === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $username)
    || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    fwrite(STDERR, "Usage : php bin/install.php \"Nom de l'organisation\" Prénom Nom identifiant [email]\n");
    fwrite(STDERR, "Identifiant : 3 à 60 caractères parmi a-z, 0-9, point, tiret, tiret bas.\n");
    exit(1);
}

$db = new Database($config['db']);
try {
    $role = $db->fetchValue("SELECT id FROM roles WHERE code = 'owner'");
} catch (Throwable) {
    $role = false;
}
if (!$role) {
    fwrite(STDERR, "Base non migrée : lancez d'abord php bin/migrate.php\n");
    exit(1);
}
if ((int) $db->fetchValue('SELECT COUNT(*) FROM organizations') > 0) {
    fwrite(STDERR, "La base contient déjà une organisation : installation annulée, rien n'a été modifié.\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
$db->transaction(static function () use ($db, $organization, $firstName, $lastName, $username, $email, $role, $password): void {
    $org = Uuid::v7();
    $db->execute('INSERT INTO organizations (id, name) VALUES (:id, :n)', ['id' => $org, 'n' => $organization]);
    $db->execute(
        'INSERT INTO users (id, organization_id, role_id, username, email, password_hash, first_name, last_name, all_stations)
         VALUES (:id, :o, :r, :u, :e, :h, :f, :l, 1)',
        ['id' => Uuid::v7(), 'o' => $org, 'r' => $role, 'u' => $username, 'e' => $email === '' ? null : $email, 'h' => PasswordHasher::hash($password), 'f' => $firstName, 'l' => $lastName]
    );
});

echo "Organisation « $organization » créée.\n";
echo "Compte Propriétaire : $username\n";
echo "Mot de passe (affiché une seule fois) : $password\n";
echo "Connectez-vous, changez ce mot de passe, puis créez vos stations dans l'écran « Stations ».\n";
exit(0);
