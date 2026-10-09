# Petra — Gestion et contrôle de stations-service (multi-stations)

Développé par **BELEJ-CONSULTING**. Mention obligatoire :
- pied de page de toutes les pages : "Petra — développé par BELEJ-CONSULTING" ;
- écran de connexion, page "À propos", README, composer.json (authors), en-têtes des fichiers PHP ;
- en-tête ou pied de chaque export PDF (pdfmake) et Excel (ExcelJS), et du guide Playwright.

## Référence
Le projet PerfectMaint (C:\xampp\htdocs\perfectMaint) est la référence de structure,
de conventions et de style. Reproduire son noyau app/Core/ et ses patterns.

## Stack (identique à PerfectMaint)
- PHP ≥ 8.2, declare(strict_types=1), enums, readonly. Compatible 8.2 (XAMPP) et 8.4 (Plesk).
- Aucun framework. Noyau maison app/Core/ : routeur, conteneur DI autowiring, Request/Response,
  Session, CSRF, Validator, i18n, View (PHP natif, layouts/partials), Migrator.
- Composer : zéro dépendance en production ; autoload PSR-4 et scripts migrate/seed/serve/test/sync.
- MariaDB ≥ 10.11 via PDO, requêtes préparées, pas d'ORM. Migrations SQL numérotées dans
  database/migrations/, appliquées par bin/migrate.php.
- Fuseau Africa/Lubumbashi (PHP et session MariaDB), paramétrable.
- Front : jQuery, DataTables (pagination serveur), SweetAlert2, Lucide, Chart.js, ExcelJS, pdfmake,
  servis depuis public/assets/vendor/. Pas de CDN, pas de bundler.
- JS : public/assets/js/ (pt-core.js, pt-tables.js, pt-export.js, app.js, dashboard.js, reports.js).
- CSS unique : public/assets/css/app.css avec variables CSS.
- Apache (.htaccess → public/index.php). Uploads dans storage/uploads, hors racine web.
- Tests : PHPUnit 11.5 sur la base petra_test ; JS avec node:test ; guide avec Playwright.

## Architecture
- APP_MODE=station : serveur local dans chaque station, utilisable sans Internet.
- APP_MODE=central : VPS, reçoit toutes les stations, vue consolidée du propriétaire.
- Synchronisation par bin/sync.php (cron) via API HTTPS avec jeton par station.
- Une seule base de code pour les deux modes.

## Règles métier non négociables
1. Toute donnée métier porte un station_id. Filtrage imposé par une couche commune (StationContext).
2. Contrôle : carburant reçu → stocké → vendu → encaissé ; tout écart rattaché à station, poste, utilisateur.
3. Ajout seul : aucune opération validée n'est modifiée ni supprimée ; une correction est une
   nouvelle ligne liée (corrects_id, approved_by).
4. Prix et taux USD/CDF versionnés ; un calcul utilise la version en vigueur à l'instant de l'opération.
5. Ventes contrôlées par index ; un changement de prix ou de taux pendant un poste impose
   un relevé intermédiaire (tranches de poste).
6. Écarts en CDF ; l'USD remis est converti au taux de la tranche.
7. Clés primaires UUID générées en PHP.
8. Montants en BIGINT (CDF entier, USD en cents) ; volumes en DECIMAL(12,2) ; jamais de float.
9. Tous les seuils sont paramétrables par station.

## Langue
Interface en français via i18n. Code, tables et colonnes en anglais.

## Méthode
- Avant chaque module, lire les fichiers équivalents de PerfectMaint.
- Exécuter la tâche demandée dans le message ; s'arrêter aux points de validation indiqués.
- Écrire les tests PHPUnit avec le code. Ne jamais affaiblir une règle métier pour faire passer un test.
