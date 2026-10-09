# Petra

Gestion et contrôle de stations-service, multi-stations : du carburant reçu au carburant stocké, vendu
et encaissé, chaque écart est chiffré en CDF et rattaché à une station, un poste et un utilisateur.

**Petra — développé par BELEJ-CONSULTING.**

| Document | Contenu |
| --- | --- |
| [docs/guide/Guide-utilisateur-Petra.pdf](docs/guide/Guide-utilisateur-Petra.pdf) | Guide utilisateur, une partie par rôle : Propriétaire, Gérant, Pompiste |
| [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) | Installation en production (station sous XAMPP, central sous Plesk), tâches planifiées, sauvegardes, restauration |
| [docs/schema.md](docs/schema.md) | Schéma de la base, diagramme et choix de conception |

## Fonctionnalités

- **Comptes et droits** : rôles Propriétaire, Gérant, Pompiste ; permissions réglables ; chaque
  utilisateur ne voit que ses stations (`StationContext`, filtre commun à tous les repositories).
  Accès refusés journalisés et signalés.
- **Stations** (`/stations`) : produits, cuves, distributeurs (mono ou multi-produits) et leurs pistolets, chacun avec
  son code, barèmes de jaugeage (saisie ou import CSV), horaires des postes fixes ou libres, seuils et délais par station.
- **Postes en parallèle** : un poste est ouvert pour un pompiste sur un ou plusieurs distributeurs ; plusieurs postes
  tournent en même temps, chacun avec ses index et sa caisse.
- **Prix et taux USD/CDF** (`/prices`) : versions datées, en ajout seul. Pendant un poste ouvert, un
  changement impose un relevé intermédiaire et ouvre une nouvelle tranche de poste.
- **Postes** (`/shifts`) : un poste ouvert par station, index repris du poste précédent, sorties non
  vendues, clôture par le pompiste, validation par le gérant.
- **Caisse** (`/shifts/{id}/cash`) : remises en francs et en dollars (convertis au taux de la tranche),
  ventes à crédit, dépenses ; écart figé à la validation ; justification en nouvelle ligne.
- **Stock** (`/stock`) : jaugeage en cm converti par le barème, stock théorique, écarts, stock bas.
- **Livraisons** (`/deliveries`) : fournisseur choisi dans le répertoire, jaugeages avant et après, écarts annoncé / reçu / cuve, photo du bon.
- **Fournisseurs** (`/suppliers`) : répertoire des fournisseurs de chaque station (contact, téléphone, activité) ; une fiche se désactive, elle ne se supprime pas.
- **Alertes** (`/alerts`) et **journal d'audit** (`/audit`) : le journal ne se modifie ni ne s'efface.
- **Tableaux de bord** (`/`) : par station ou consolidé, filtres jour / semaine / mois / période.
- **Rapports** (`/reports`) : par poste, journalier (les dix questions du contrôle), mensuel ; exports
  Excel et PDF, impression.
- **Synchronisation** station ↔ central, sauvegardes, contrôles quotidiens (voir plus bas).

Reste à faire : l'écran de correction d'une opération (nouvelle ligne liée par `corrects_id`, approuvée).
Le schéma et les calculs en tiennent déjà compte.

## Prérequis

- PHP ≥ 8.2 avec `pdo_mysql`, `mbstring`, `zlib`, `curl` — compatible 8.2 (XAMPP) et 8.4 (Plesk)
- MariaDB ≥ 10.11 recommandé (développé et testé sur 10.4.32, celui de XAMPP 8.2.12)
- Composer 2
- Node ≥ 20 pour les tests JavaScript et le guide utilisateur
- Apache avec `mod_rewrite`, ou le serveur PHP intégré en développement

Aucune dépendance PHP en production : Composer ne sert qu'à l'autoload PSR-4 et à PHPUnit.

## Installation (développement)

```bash
composer install
cp .env.example .env          # puis adapter (DB_USER, DB_PASS, APP_BASE_PATH…)
```

Créez les deux bases ; `petra_test` est réservée aux tests et vidée à chaque exécution :

```sql
CREATE DATABASE petra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE petra_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
composer migrate              # applique database/migrations/NNNN_nom.sql
composer seed                 # données de démonstration (mot de passe affiché à la fin)
composer serve                # http://127.0.0.1:8081
```

Sous XAMPP, l'application répond sur `http://localhost/Petra/` avec `APP_BASE_PATH=/Petra` dans `.env`.
En production, le domaine pointe sur `public/` et `APP_BASE_PATH` reste vide.

`composer seed` crée une organisation de démonstration, deux stations et les comptes `proprietaire`,
`gerant.lsh`, `pompiste1.lsh`, `pompiste2.lsh`, `gerant.kzi`, `pompiste1.kzi`, `pompiste2.kzi`.
Pour une vraie mise en service, sans données de démonstration :
`php bin/install.php "Organisation" Prénom Nom identifiant [email]`.

## Modes station et central

Le même code tourne dans les deux modes ; seul `APP_MODE` dans `.env` change.

| | `APP_MODE=station` | `APP_MODE=central` |
| --- | --- | --- |
| Où | Un PC dans chaque station (XAMPP) | Un hébergement (Plesk, PHP 8.4) |
| Rôle | Saisie des postes, de la caisse, des jaugeages et des livraisons, **même sans Internet** | Vue consolidée du propriétaire ; comptes, rôles, stations, prix et taux |
| Décide de | Son installation et ses opérations, ses alertes, son journal d'audit | Organisation, fiche de station, rôles, comptes, prix, taux |
| Données de l'autre côté | Reçues du central, non modifiables sur place une fois la station reliée | Reçues des stations, en lecture seule |

Une station fonctionne aussi **seule**, sans central : laissez `STATION_ID`, `SYNC_URL` et `SYNC_TOKEN` vides.

Relier une station au central :

1. sur le central, créez la station (écran **Stations**) et ses comptes ;
2. sur le central, `php bin/sync-token.php CODE` affiche `APP_MODE`, `STATION_ID` et `SYNC_TOKEN` ;
3. copiez-les dans le `.env` de la station, avec `SYNC_URL=https://adresse-du-central` ;
4. sur la station, `php bin/migrate.php` puis `php bin/sync.php` : la première passe apporte
   l'organisation, la fiche de la station et ses comptes.

Fonctionnement de la synchronisation :

- **File d'attente** : des déclencheurs MariaDB (migration 0016) inscrivent chaque écriture métier dans
  `sync_queue`, dans la même transaction.
- **Passe** : `php bin/sync.php` (toutes les 5 minutes) envoie la file par lots à `{SYNC_URL}/api/sync/push`
  puis appelle `/api/sync/pull`. curl ou flux PHP natifs, certificat vérifié ; HTTP n'est accepté que vers
  `localhost`. Codes de sortie : 0 réussi, 1 échec, 2 central injoignable.
- **Sans doublon** : une ligne déjà reçue à l'identique est ignorée ; un lot renvoyé après une coupure ne
  double rien.
- **Jeton** : le central ne garde que son empreinte et refuse toute ligne dont le `station_id` n'est pas
  celui du jeton.
- **Conflits** : jamais écrasés ; consignés dans `sync_log` et signalés au gérant. Un prix ou un taux
  venu du central pendant un poste ouvert s'applique à la passe qui suit sa clôture.
- **En-tête** : « Hors ligne », « En attente (n) » ou « Synchronisé » ; détail sur `/sync`.
- Les photos des bons de livraison restent sur le serveur de la station.

Installation pas à pas des deux serveurs : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md).

## Configuration (`.env`)

| Variable | Rôle |
| --- | --- |
| `APP_MODE` | `station` ou `central` (voir ci-dessus). |
| `STATION_ID` | UUID de la station (mode `station`, station reliée). |
| `SYNC_URL`, `SYNC_TOKEN` | Adresse HTTPS du central (sans `/api/...`) et jeton de la station. Vides : station autonome. |
| `APP_ENV`, `APP_DEBUG` | `production` et `false` en exploitation. |
| `APP_BASE_PATH` | Sous-répertoire d'URL (`/Petra` sous XAMPP), vide si le domaine pointe sur `public/`. |
| `SESSION_SECURE` | `true` en HTTPS (central), `false` sur le réseau local d'une station. |
| `APP_TIMEZONE` | Fuseau de PHP et de la session MariaDB (`Africa/Lubumbashi` par défaut). |
| `APP_CURRENCY` | Devise de référence des écarts (`CDF`). |
| `DB_*` | Connexion MariaDB. |
| `BACKUP_DIR`, `BACKUP_RETENTION_DAYS`, `MYSQL_BIN_DIR` | Dossier des sauvegardes (`storage/backups` par défaut), jours de conservation (30), dossier de `mysqldump` et `mysql`. |
| `UPLOADS_DIR` | Dossier des fichiers envoyés (`storage/uploads` par défaut). |

## Scripts Composer

| Commande | Script | Effet |
| --- | --- | --- |
| `composer serve` | `php -S … bin/dev-router.php` | Serveur de développement sur `http://127.0.0.1:8081`. |
| `composer migrate` | `bin/migrate.php` | Applique les migrations en attente (`--status` pour l'état). |
| `composer seed` | `bin/seed.php` | Données de démonstration, sur une base vide. |
| `composer sync` | `bin/sync.php` | Une passe de synchronisation station → central → station. |
| `composer backup` | `bin/backup.php` | Sauvegarde compressée, rotation à 30 jours, copie vers le central. |
| `composer restore` | `bin/restore.php` | Liste les sauvegardes ; `php bin/restore.php latest --to=petra_essai` pour un essai sans risque. |
| `composer checks` | `bin/daily-checks.php` | Alertes : postes non clôturés, stock bas, stations non synchronisées. |
| `composer test` | PHPUnit puis `npm test` | Tous les tests. `composer test:php` et `composer test:js` séparément. |

Sans alias Composer :

| Commande | Effet |
| --- | --- |
| `php bin/install.php "Organisation" Prénom Nom identifiant [email]` | Première mise en service : organisation et compte Propriétaire. |
| `php bin/sync-token.php [CODE]` | Sur le central : liste les stations, ou génère le jeton d'une station. |
| `php bin/seed-year.php [jours]` | Données de test sur un an (365 jours par défaut) : deux stations, deux postes par jour, écarts, livraisons, changements de prix et de taux. Même garde-fou sur le nom de la base (`DB_NAME=petra_demo php bin/seed-year.php`). |
| `php bin/guide-demo.php` | Base de démonstration du guide (refuse toute base dont le nom ne finit pas par `_guide` ou `_demo`). |

Scripts npm :

| Commande | Effet |
| --- | --- |
| `npm test` | Tests des modules JavaScript (`node:test`). |
| `npm run screenshots` | Captures d'écran du guide (Playwright). |
| `npm run guide` | Guide utilisateur PDF. |

## Guide utilisateur

Le guide est écrit dans [docs/guide/guide-fr.html](docs/guide/guide-fr.html) et illustré par des captures
prises automatiquement.

```bash
npm install                    # une fois
npx playwright install chromium   # une fois
npm run screenshots            # docs/guide/images/*.png
npm run guide                  # docs/guide/Guide-utilisateur-Petra.pdf
```

`npm run screenshots` prépare tout lui-même : il recrée la base `petra_guide`, y rejoue deux journées
d'activité (`bin/guide-demo.php`), sert l'application sur le port 8091 le temps des captures, puis
l'arrête. La base de travail n'est pas touchée. PHP et MariaDB doivent être démarrés, et `php` accessible
dans le PATH (sinon `PETRA_PHP=C:\xampp\php\php.exe`). Détails : [docs/guide/README-captures.md](docs/guide/README-captures.md).

## Tests

```bash
composer test
```

- **PHPUnit** sur `petra_test` (et `petra_central_test` pour la synchronisation, créée au besoin) :
  tests unitaires des calculs, tests d'intégration des services, tests fonctionnels des écrans.
- **Recette** : `tests/php/Functional/RecetteTest.php` joue six journées types sur le jeu d'essai
  `tests/php/Seeds/RecetteSeed.php` — journée normale, changement de taux en cours de poste, essai de
  pompe et livraison avec écart, deux stations, hors ligne puis synchronisation, modification refusée.
- **JavaScript** : `tests/js/*.test.js` (formatage, tableaux, exports, tableau de bord, catalogue i18n).

## Structure

```
app/Core/          noyau : App, Router, Container (autowiring), Request/Response, Session, Csrf,
                   Validator, I18n, View, Database (PDO), Migrator
app/Domain/        calculs purs, en entiers : postes, caisse, stock, livraisons, périodes
app/Security/      Auth, CurrentUser, StationContext
app/Repositories/  accès SQL, filtrés par station
app/Services/      règles métier
app/Sync/          synchronisation station ↔ central
app/Maintenance/   sauvegarde, restauration, contrôles quotidiens
app/Controllers/   contrôleurs HTTP          app/Views/   gabarits PHP natifs
app/routes.php     table des routes
bin/               scripts en ligne de commande (PHP) et scripts du guide (Node)
config/app.php     configuration lue depuis .env
database/migrations/
docs/              schéma, déploiement, guide utilisateur
lang/fr.php        catalogue de traduction (interface en français)
public/            racine web : index.php, assets/{css,js,vendor,fonts}
storage/           logs, fichiers envoyés, sauvegardes (hors racine web)
tests/php, tests/js
```

Front sans CDN ni bundler : jQuery, DataTables, SweetAlert2, Lucide, Chart.js, ExcelJS et pdfmake sont
servis depuis `public/assets/vendor/`. JS applicatif : `pt-core.js` (fonctions pures), `pt-tables.js`
(colonnes des tableaux), `pt-export.js` (Excel / PDF), `dashboard.js`, `reports.js`, `app.js`.

## Conventions

- Clés primaires UUID générées en PHP ; les paramètres de route `{id}` n'acceptent que ce format.
- Montants en `BIGINT` dans la plus petite unité (CDF entier, USD en cents), volumes en
  `DECIMAL(12,2)`, jamais de float.
- Ajout seul : une opération validée ne se modifie ni ne se supprime (déclencheurs MariaDB) ; toute
  tentative est refusée et journalisée.
- Toute donnée affichée passe par `e()` ; aucun script inline (CSP stricte).
- Tout export PDF ou Excel, toute page et le guide portent la mention « Petra — développé par BELEJ-CONSULTING ».
- Code, tables et colonnes en anglais ; interface en français via `t('clé')`.

Structure et conventions reprises du projet PerfectMaint.
