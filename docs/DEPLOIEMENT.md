# Petra — Guide de déploiement

Petra — développé par **BELEJ-CONSULTING**.

Ce guide s'adresse à la personne qui installe et entretient les serveurs. Il couvre :

1. [l'architecture](#1-architecture) ;
2. [le serveur central](#2-serveur-central-plesk-php-84) (Plesk, PHP 8.4, cron) — à installer en premier ;
3. [le serveur d'une station](#3-serveur-dune-station-windows-xampp) (Windows, XAMPP, Planificateur de tâches) ;
4. [les sauvegardes](#4-sauvegardes) et [la restauration](#5-restaurer-une-sauvegarde) ;
5. [les contrôles quotidiens](#6-contrôles-quotidiens) ;
6. [les mises à jour](#7-mettre-à-jour-petra) et [le dépannage](#8-dépannage).

## 1. Architecture

| | Serveur de station | Serveur central |
| --- | --- | --- |
| Où | Un PC dans chaque station | Un hébergement (VPS avec Plesk) |
| `APP_MODE` | `station` | `central` |
| Sert à | Saisir les postes, la caisse, les jaugeages, les livraisons — **même sans Internet** | Vue consolidée du propriétaire ; comptes, rôles, stations, prix et taux |
| Reçoit du central | Comptes, rôles, fiche de station, prix, taux | — |
| Envoie au central | Toutes ses opérations, ses alertes, son journal d'audit, une copie de sa sauvegarde | — |

Le même code tourne des deux côtés ; seul le fichier `.env` change. La station appelle le central
(jamais l'inverse) : aucun port n'est à ouvrir vers la station.

Prérequis communs : PHP ≥ 8.2 avec les extensions `pdo_mysql`, `mbstring`, `zlib`, `curl` (ou `openssl`
pour les flux HTTPS) et la fonction `proc_open` autorisée en ligne de commande ; MariaDB ≥ 10.11
recommandé. Petra est développé et testé sur XAMPP 8.2.12 (PHP 8.2, MariaDB 10.4.32) et prévu pour
PHP 8.4 sur le central.

## 2. Serveur central (Plesk, PHP 8.4)

### 2.1 Domaine et PHP

1. Dans Plesk, créez le domaine ou sous-domaine (par exemple `petra.exemple.com`).
2. **Paramètres PHP** : version **8.4**, exécution en FPM. Gardez `post_max_size` et
   `upload_max_filesize` à 8 M ou plus (valeur par défaut).
3. **SSL/TLS** : installez un certificat Let's Encrypt et forcez la redirection HTTP → HTTPS.
   Les stations refusent de synchroniser vers une adresse qui n'est pas en HTTPS.
4. **Paramètres d'hébergement → Racine du document** : pointez sur le sous-dossier `public` de Petra
   (par exemple `httpdocs/public`). Le reste de l'application (`app`, `config`, `storage`, `.env`) ne
   doit pas être accessible depuis le web.

### 2.2 Fichiers et base de données

1. Envoyez le code dans le dossier du domaine (Git, SFTP ou gestionnaire de fichiers).
2. En SSH, dans le dossier de Petra, installez l'autoload (aucune dépendance de production) :

   ```bash
   /opt/plesk/php/8.4/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader
   ```

   (ou l'outil « PHP Composer » de Plesk, action *Install*).
3. Dans Plesk, **Bases de données → Ajouter** : une base `petra`, un utilisateur dédié, un mot de passe fort.
4. Copiez `.env.example` en `.env` et renseignez :

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_BASE_PATH=
   SESSION_SECURE=true
   APP_MODE=central
   DB_HOST=127.0.0.1
   DB_NAME=petra
   DB_USER=petra
   DB_PASS=le-mot-de-passe
   ```

   `STATION_ID`, `SYNC_URL` et `SYNC_TOKEN` restent vides sur le central.
5. Donnez à l'utilisateur système du domaine le droit d'écrire dans `storage/` (logs, uploads, backups).
6. Créez les tables, puis l'organisation et le compte Propriétaire :

   ```bash
   /opt/plesk/php/8.4/bin/php bin/migrate.php
   /opt/plesk/php/8.4/bin/php bin/install.php "Nom de l'organisation" Prenom Nom identifiant email@exemple.com
   ```

   Le mot de passe du propriétaire est affiché **une seule fois** : notez-le, connectez-vous sur
   `https://petra.exemple.com`, puis changez-le dans l'écran « Utilisateurs ».
   N'utilisez pas `bin/seed.php` en production : il crée des données de démonstration.

### 2.3 Déclarer les stations

Pour chaque station :

1. Connecté en propriétaire sur le central : écran **Stations → Nouvelle station** (code, nom, ville).
2. Écran **Utilisateurs** : créez le gérant et les pompistes, et attribuez-leur la station.
3. En SSH, générez le jeton de synchronisation de la station (remplacez `LSH-01` par son code) :

   ```bash
   /opt/plesk/php/8.4/bin/php bin/sync-token.php LSH-01
   ```

   La commande affiche trois lignes (`APP_MODE`, `STATION_ID`, `SYNC_TOKEN`) à copier dans le `.env` du
   serveur de la station. Le jeton n'est affiché qu'une fois ; en générer un nouveau révoque l'ancien.
   `php bin/sync-token.php` sans argument liste les stations et leur dernière synchronisation.

### 2.4 Tâches planifiées (cron)

Dans Plesk, **Tâches planifiées → Ajouter une tâche**, type « Exécuter une commande » :

| Tâche | Planification | Commande |
| --- | --- | --- |
| Sauvegarde du central | Tous les jours à 01:30 | `/opt/plesk/php/8.4/bin/php /var/www/vhosts/exemple.com/httpdocs/bin/backup.php` |
| Contrôles (stations muettes) | Toutes les heures | `/opt/plesk/php/8.4/bin/php /var/www/vhosts/exemple.com/httpdocs/bin/daily-checks.php` |

Adaptez le chemin à votre domaine. `bin/sync.php` ne tourne **pas** sur le central : ce sont les
stations qui l'appellent.

Les copies de sauvegarde envoyées par les stations arrivent dans
`storage/backups/stations/{STATION_ID}/` et y sont gardées 30 jours.

## 3. Serveur d'une station (Windows, XAMPP)

### 3.1 Matériel et Windows

- Un PC dédié, qui reste allumé pendant les heures d'ouverture, relié au réseau local de la station.
- **Onduleur conseillé** (600 VA ou plus) : une coupure de courant pendant une écriture peut corrompre la
  base. L'onduleur laisse le temps d'éteindre proprement, ou de tenir jusqu'au retour du courant.
- Dans le BIOS, activez le redémarrage automatique au retour du courant (*Restore on AC power loss : Power On*).
- Windows : **Options d'alimentation → Ne jamais mettre en veille** ; heure et fuseau réglés sur
  Lubumbashi (UTC+2), synchronisation de l'heure activée.
- Donnez au PC une adresse IP fixe sur le réseau local (par exemple `192.168.1.10`) et autorisez le
  port 80 dans le pare-feu Windows pour le réseau privé : les autres postes ouvrent
  `http://192.168.1.10/Petra/`.
- Si possible, un second disque ou une clé USB qui reste branchée, pour les sauvegardes (`BACKUP_DIR`).

### 3.2 XAMPP

1. Installez XAMPP (PHP 8.2 ou plus) dans `C:\xampp`.
2. Ouvrez le panneau de contrôle XAMPP **en administrateur** et cochez la case *Svc* devant **Apache**
   et **MySQL** : ils deviennent des services Windows et redémarrent seuls après une coupure.
3. Dans `C:\xampp\php\php.ini`, vérifiez que `extension=curl`, `extension=mbstring`,
   `extension=pdo_mysql` et `extension=zlib` ne sont pas commentées (c'est le cas par défaut).
4. Créez la base et un utilisateur dédié (le compte `root` de XAMPP n'a pas de mot de passe) :

   ```bat
   C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE petra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'petra'@'localhost' IDENTIFIED BY 'un-mot-de-passe-fort'; GRANT ALL ON petra.* TO 'petra'@'localhost'; GRANT PROCESS ON *.* TO 'petra'@'localhost';"
   ```

   Pour qu'un essai de restauration puisse créer sa base d'essai, ajoutez
   `GRANT ALL ON petra_essai.* TO 'petra'@'localhost';`.

### 3.3 Petra

1. Copiez le code dans `C:\xampp\htdocs\Petra` et installez l'autoload :

   ```bat
   cd C:\xampp\htdocs\Petra
   composer install --no-dev --optimize-autoloader
   ```

2. Copiez `.env.example` en `.env` et renseignez (les trois lignes `APP_MODE`, `STATION_ID`,
   `SYNC_TOKEN` viennent de `bin/sync-token.php`, étape 2.3) :

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_BASE_PATH=/Petra
   SESSION_SECURE=false
   APP_MODE=station
   STATION_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   SYNC_URL=https://petra.exemple.com
   SYNC_TOKEN=le-jeton-de-la-station
   DB_NAME=petra
   DB_USER=petra
   DB_PASS=un-mot-de-passe-fort
   BACKUP_DIR=D:\PetraSauvegardes
   ```

   `SYNC_URL` est l'adresse du central, sans `/api/...`. `SESSION_SECURE=false` parce que le réseau
   local de la station est en HTTP. `BACKUP_DIR` est facultatif (par défaut `storage\backups`).
3. Créez les tables, puis lancez la première synchronisation : elle apporte l'organisation, la fiche de
   la station et les comptes créés au central.

   ```bat
   C:\xampp\php\php.exe bin\migrate.php
   C:\xampp\php\php.exe bin\sync.php
   ```

   Résultat attendu : `Envoi : success …` puis `Réception : success …`.
4. Ouvrez `http://localhost/Petra/`, connectez-vous avec un compte créé au central, puis déclarez
   l'installation de la station (écran **Stations**) : produits, cuves avec leur barème de jaugeage,
   pistolets, horaires des postes et seuils. Ces données appartiennent à la station et remontent au central.

Une station **sans central** (une seule station, pas d'Internet) s'installe de la même façon, en laissant
`STATION_ID`, `SYNC_URL` et `SYNC_TOKEN` vides et en créant l'organisation avec `bin\install.php`
(voir 2.2, point 6) au lieu de la première synchronisation.

### 3.4 Planificateur de tâches Windows

Ouvrez une invite de commandes **en administrateur** et créez les trois tâches. Elles tournent sous le
compte Système, que quelqu'un soit connecté ou non, et écrivent leur compte rendu dans `storage\logs`.

```bat
schtasks /Create /TN "Petra\Synchronisation" /SC MINUTE /MO 5 /RU SYSTEM /F ^
  /TR "cmd /c C:\xampp\php\php.exe C:\xampp\htdocs\Petra\bin\sync.php >> C:\xampp\htdocs\Petra\storage\logs\sync.log 2>&1"

schtasks /Create /TN "Petra\Sauvegarde" /SC DAILY /ST 23:30 /RU SYSTEM /F ^
  /TR "cmd /c C:\xampp\php\php.exe C:\xampp\htdocs\Petra\bin\backup.php >> C:\xampp\htdocs\Petra\storage\logs\backup.log 2>&1"

schtasks /Create /TN "Petra\Controles" /SC DAILY /ST 06:00 /RU SYSTEM /F ^
  /TR "cmd /c C:\xampp\php\php.exe C:\xampp\htdocs\Petra\bin\daily-checks.php >> C:\xampp\htdocs\Petra\storage\logs\checks.log 2>&1"
```

| Tâche | Fréquence | Rôle |
| --- | --- | --- |
| Synchronisation | Toutes les 5 minutes | Envoie la file au central, reçoit comptes, prix et taux. Sans Internet, elle sort sans rien perdre et réessaie 5 minutes plus tard. |
| Sauvegarde | Tous les jours à 23:30 | `mysqldump` compressé, rotation à 30 jours, copie vers le central s'il est joignable. |
| Contrôles | Tous les jours à 06:00 | Alertes : postes non clôturés, stock bas, station sans synchronisation depuis 24 h. |

Choisissez pour la sauvegarde une heure où le PC est allumé. Dans le Planificateur de tâches
(`taskschd.msc`), dossier **Petra**, cochez pour la sauvegarde et les contrôles l'option *Exécuter la
tâche dès que possible si un démarrage planifié est manqué*.

Vérification : `schtasks /Run /TN "Petra\Sauvegarde"`, puis lisez `storage\logs\backup.log`. Dans
l'application, l'en-tête affiche l'état de la synchronisation (« Synchronisé », « En attente (n) »,
« Hors ligne ») et l'écran **Synchronisation** le détail des passes.

## 4. Sauvegardes

`php bin/backup.php` :

1. écrit `petra-{base}-{AAAAMMJJ-HHMMSS}.sql.gz` dans le dossier de sauvegarde (structure, données,
   déclencheurs), après avoir vérifié que le fichier est complet ;
2. supprime les sauvegardes de plus de 30 jours (`BACKUP_RETENTION_DAYS`) — la plus récente est toujours
   gardée ;
3. sur une station reliée, envoie une copie au central par l'API de synchronisation (HTTPS, jeton de la
   station). Central injoignable : la sauvegarde reste locale et la tâche réussit quand même.

Codes de sortie : `0` sauvegarde faite, `1` sauvegarde échouée, `2` sauvegarde faite mais copie au
central refusée. `--no-upload` saute la copie.

Deux points d'attention :

- **Les photos des bons de livraison ne sont pas dans la sauvegarde** : elles sont dans
  `storage\uploads`. Copiez ce dossier vers le disque de sauvegarde, par exemple avec une quatrième
  tâche quotidienne : `robocopy C:\xampp\htdocs\Petra\storage\uploads D:\PetraSauvegardes\uploads /MIR`.
- Une sauvegarde rangée sur le même disque que la base ne protège pas d'une panne de ce disque : réglez
  `BACKUP_DIR` sur un autre disque, et comptez sur la copie au central.

## 5. Restaurer une sauvegarde

Cette procédure est couverte par les tests automatiques (`MaintenanceTest`) : une base sauvegardée puis
restaurée contient les mêmes tables, les mêmes déclencheurs et les mêmes lignes, et un fichier tronqué ou
incomplet est refusé avant toute modification.

### 5.1 Exercice sans risque (à faire une fois par mois)

```bat
C:\xampp\php\php.exe bin\restore.php
C:\xampp\php\php.exe bin\restore.php latest --to=petra_essai
```

La première commande liste les sauvegardes. La seconde restaure la plus récente dans une base d'essai
`petra_essai`, sans toucher à la base de travail. Contrôlez-la (par exemple
`SELECT COUNT(*), MAX(opened_at) FROM petra_essai.shifts;`), puis supprimez-la
(`DROP DATABASE petra_essai;`). Une sauvegarde qu'on n'a jamais restaurée n'est pas une sauvegarde.

### 5.2 Restauration réelle

À faire seulement si la base de travail est perdue ou corrompue.

1. Prévenez le gérant : plus aucune saisie pendant l'opération. Désactivez la tâche de synchronisation
   (`schtasks /Change /TN "Petra\Synchronisation" /DISABLE`).
2. Choisissez la sauvegarde. Si le disque de la station est perdu, récupérez la copie sur le central
   (`storage/backups/stations/{STATION_ID}/`) et déposez-la dans le dossier de sauvegarde de la station.
3. Restaurez :

   ```bat
   C:\xampp\php\php.exe bin\restore.php petra-petra-20261006-233000.sql.gz
   ```

   Le script vérifie le fichier, demande de taper `RESTAURER`, **sauvegarde d'abord l'état actuel**
   (filet de sécurité), puis remplace la base. `--yes` remplace la confirmation dans un script.
4. Appliquez les migrations parues depuis la sauvegarde : `C:\xampp\php\php.exe bin\migrate.php`.
5. Remettez le dossier `storage\uploads` depuis sa copie (photos des bons de livraison).
6. Ouvrez l'application et contrôlez le dernier poste, le dernier jaugeage et la dernière livraison.
7. Réactivez la synchronisation (`schtasks /Change /TN "Petra\Synchronisation" /ENABLE`) et lancez
   `bin\sync.php` une fois à la main.

Ce qu'il faut savoir sur une station reliée au central :

- ce qui a été saisi **après** la sauvegarde disparaît du serveur de la station ;
- ce qui avait déjà été synchronisé reste visible au central (rapports consolidés), mais ne redescend
  pas sur la station : ressaisissez sur place ce qui manque pour que les index et les stocks se suivent ;
- renvoyer au central ce qu'il a déjà reçu ne crée aucun doublon.

Sur un PC neuf : suivez la section 3 jusqu'à `bin\migrate.php`, puis restaurez au lieu de lancer la
première synchronisation.

Sur le central, la procédure est la même avec `/opt/plesk/php/8.4/bin/php bin/restore.php …`.

## 6. Contrôles quotidiens

`php bin/daily-checks.php` lève une alerte, visible dans le centre d'alertes et sur le tableau de bord :

| Alerte | Condition | Délai ou seuil, réglable par station |
| --- | --- | --- |
| Poste non clôturé | Poste ouvert depuis trop longtemps | « Durée maximale d'un poste ouvert », 16 h par défaut |
| Stock bas | Stock théorique d'une cuve sous le seuil | « Stock bas », 3 000 L par défaut |
| Station non synchronisée | Aucune synchronisation réussie depuis trop longtemps | « Délai maximal sans synchronisation », 24 h par défaut |

Les réglages sont dans la fiche de la station (écran **Stations**), sur le serveur de la station.

- Sur un serveur de station, les trois contrôles portent sur ses propres données ; ses alertes remontent
  au central par synchronisation.
- Sur le central, seul le contrôle de synchronisation tourne, pour chaque station qui a un jeton : c'est
  lui qui signale au propriétaire une station muette (PC éteint, Internet coupé, tâche arrêtée).
- Une alerte n'est pas levée deux fois : tant que la précédente n'est pas traitée, elle suffit. Le stock
  d'une cuve qui n'a jamais été jaugée n'est pas contrôlé.

## 7. Mettre à jour Petra

1. Lancez une sauvegarde : `php bin/backup.php`.
2. Remplacez le code (sans toucher à `.env` ni à `storage/`).
3. `composer install --no-dev --optimize-autoloader`.
4. `php bin/migrate.php`.

Mettez à jour le central **avant** les stations : il doit connaître les nouvelles colonnes avant de les
recevoir.

## 8. Dépannage

| Symptôme | À vérifier |
| --- | --- |
| En-tête « Hors ligne » | Internet de la station ; `SYNC_URL` joignable depuis le PC ; certificat du central valide. Les saisies continuent et partiront au retour de la connexion. |
| `sync.log` : `HTTP 401` | Jeton erroné ou régénéré, `STATION_ID` qui n'est pas celui du jeton, ou station désactivée au central. Générez un nouveau jeton (2.3). |
| `SYNC_URL doit être en HTTPS` | Le central doit être en HTTPS ; seul `http://localhost` est accepté, pour les essais. |
| `commande introuvable : mysqldump` | Renseignez `MYSQL_BIN_DIR` dans `.env` (dossier contenant `mysqldump` et `mysql`). `C:\xampp\mysql\bin` est trouvé tout seul. |
| Sauvegarde échouée sur le central | `proc_open` désactivée dans le PHP en ligne de commande (`disable_functions`), ou `storage/backups` non inscriptible. |
| « En attente (n) » qui ne baisse pas | Tâche « Petra\Synchronisation » arrêtée : `schtasks /Query /TN "Petra\Synchronisation"`, puis `storage\logs\sync.log`. |
| Conflits de synchronisation | Écran **Synchronisation** : chaque conflit y est décrit. Rien n'est écrasé ; un prix ou un taux venu du central pendant un poste ouvert s'applique à la passe qui suit sa clôture. |
| Page blanche ou erreur 500 | `storage/logs/` ; droits d'écriture sur `storage/` ; `APP_BASE_PATH` (`/Petra` sous XAMPP, vide si le domaine pointe sur `public`). |
