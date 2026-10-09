# Captures d'écran et guide utilisateur

Petra — développé par BELEJ-CONSULTING.

- Captures : [`bin/screenshots.mjs`](../../bin/screenshots.mjs) (Playwright). Lecture seule : il se connecte
  avec les comptes de démonstration, visite les écrans et ouvre des boîtes de dialogue sans les valider.
- Guide : [`guide-fr.html`](guide-fr.html), converti en PDF par [`bin/build-guide.mjs`](../../bin/build-guide.mjs).

## Première installation

```bash
npm install
npx playwright install chromium
```

## Produire les images, puis le PDF

Prérequis : PHP et MariaDB démarrés, `php` dans le PATH.

```bash
npm run screenshots                  # docs/guide/images/*.png
npm run screenshots -- --only=20,31  # captures dont l'identifiant commence par 20 ou 31
npm run guide                        # docs/guide/Guide-utilisateur-Petra.pdf
```

`npm run screenshots` recrée la base `petra_guide` avec [`bin/guide-demo.php`](../../bin/guide-demo.php)
(données de `bin/seed.php`, puis deux journées d'activité et un poste ouvert), sert l'application sur
`http://127.0.0.1:8091` le temps des captures, puis arrête le serveur. La base de travail n'est jamais touchée.

| Variable | Effet |
| --- | --- |
| `PETRA_PHP` | Chemin de PHP (défaut `php`), par exemple `C:\xampp\php\php.exe`. |
| `PETRA_GUIDE_DB` | Nom de la base de démonstration (défaut `petra_guide` ; doit finir par `_guide` ou `_demo`). |
| `PETRA_URL` | Application déjà servie et déjà peuplée : le script ne prépare rien et capture cette adresse. |
| `PETRA_PASSWORD` | Mot de passe des comptes de démonstration (défaut `Petra-Demo-2026`). |

Les images sont en résolution ×2 (nettes à l'impression) : bureau 1440×900 pour le propriétaire et le
gérant, téléphone 390×844 pour le pompiste.

## Les captures

| Identifiants | Rôle | Écrans |
| --- | --- | --- |
| `01` | — | Connexion |
| `10`–`19` | Propriétaire | Tableau de bord consolidé, stations, fiche de station, utilisateurs, rôles, prix et taux, nouveau taux, rapport mensuel, alertes, journal d'audit |
| `20`–`33` | Gérant | Tableau de bord, postes, ouverture, fiche de poste, caisse, stock, livraisons, alertes, rapports |
| `40`–`44` | Pompiste | Accueil, postes, poste ouvert, sortie non vendue, clôture |

Pour ajouter une capture : ajoutez une entrée dans `SHOTS` (identifiant, rôle, chemin), puis citez
l'image dans `guide-fr.html` (`<img src="images/NN-nom.png">`). `npm run guide` refuse de produire le PDF
si une image citée manque.
