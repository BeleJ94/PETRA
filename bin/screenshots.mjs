/*
 * Petra — développé par BELEJ-CONSULTING.
 * Captures d'écran du guide utilisateur (Playwright, lecture seule : aucun formulaire métier n'est soumis).
 *
 *   npm run screenshots                  # toutes les captures → docs/guide/images/*.png
 *   npm run screenshots -- --only=20,31  # sous-ensemble (préfixes d'identifiant)
 *
 * Par défaut, le script prépare tout lui-même : il (re)crée la base de démonstration « petra_guide »
 * (php bin/guide-demo.php), sert l'application sur http://127.0.0.1:8091 le temps des captures, puis l'arrête.
 * La base de travail n'est jamais touchée.
 *
 * Variables : PETRA_URL (application déjà servie et déjà peuplée : rien n'est préparé), PETRA_PASSWORD
 * (défaut Petra-Demo-2026), PETRA_PHP (défaut « php »), PETRA_GUIDE_DB (défaut petra_guide).
 */
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'docs', 'guide', 'images');
const PHP = process.env.PETRA_PHP || 'php';
const PASSWORD = process.env.PETRA_PASSWORD || 'Petra-Demo-2026';
const PORT = 8091;
const EXTERNAL = !!process.env.PETRA_URL;
const BASE = (process.env.PETRA_URL || `http://127.0.0.1:${PORT}`).replace(/\/$/, '');
const arg = (name) => (process.argv.find((a) => a.startsWith(`--${name}=`)) || '').split('=')[1];
const ONLY = arg('only') ? arg('only').split(',') : null;

// Lubumbashi a un poste en cours (écrans du pompiste) ; l'ouverture d'un poste se montre donc à Kolwezi.
const ACCOUNTS = { owner: 'proprietaire', manager: 'gerant.lsh', manager2: 'gerant.kzi', attendant: 'pompiste1.lsh' };
const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

const BASE_PATH = new URL(BASE).pathname.replace(/\/$/, '');
const toUrl = (target) => {
  if (/^https?:/.test(target)) return target;
  if (BASE_PATH && target.startsWith(BASE_PATH + '/')) return new URL(target, BASE).href;
  return BASE + target;
};

/* Période des tableaux de bord et rapports : les sept derniers jours (la démonstration joue la veille et l'avant-veille). */
const day = (offset) => {
  const d = new Date(Date.now() - offset * 86400000);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const RANGE = `period=custom&from=${day(6)}&to=${day(0)}`;
const YESTERDAY = day(1);

/* Helpers (page déjà connectée) */
/** Première ligne d'une source DataTables satisfaisant un critère. */
const firstRow = async (page, source, predicate = () => true) => {
  const json = await page.evaluate(async (url) => (await fetch(url, { headers: { Accept: 'application/json' } })).json(), toUrl(`${source}?draw=1&start=0&length=100`));
  const row = (json.data || []).find(predicate);
  if (!row) throw new Error(`aucune ligne dans ${source}`);
  return row;
};
const shiftPath = (suffix, predicate) => async (page) => `/shifts/${(await firstRow(page, '/shifts/data', predicate)).id}${suffix}`;
const validated = (label) => (row) => row.status === 'validated' && (!label || row.label === label);
/** Le propriétaire choisit une station dans l'en-tête (préférence de session). */
const chooseStation = (code) => async (page) => {
  await page.goto(toUrl('/'));
  const id = code === null ? '' : await page.evaluate((c) => {
    const config = JSON.parse(document.getElementById('pt-config').textContent);
    return (config.stations.find((s) => s.name.includes(c)) || {}).id;
  }, code);
  await page.evaluate(async ({ url, id }) => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ station_id: id, _csrf: token }) });
  }, { url: toUrl('/context/station'), id });
};
const highlight = (selector) => async (page) => {
  await page.addStyleTag({ content: `${selector}{outline:3px solid #E0662B !important;outline-offset:3px;border-radius:6px}` });
};
/** Ouvre une boîte de dialogue de la page, sans la valider. */
const openDialog = (selector) => async (page) => {
  await page.locator(`[data-open="${selector}"]`).first().click();
  await page.waitForTimeout(600);
};

/*
 * Liste des captures. Les identifiants sont repris dans docs/guide/guide-fr.html.
 * path : chemin fixe, ou fonction (page) => chemin ; before : préparation (choix de station) ; action : après chargement.
 */
const SHOTS = [
  { id: '01-login', role: null, path: '/login' },

  // Propriétaire
  { id: '10-owner-dashboard', role: 'owner', before: chooseStation(null), path: `/?${RANGE}&station=all`, fullPage: true },
  { id: '11-owner-stations', role: 'owner', path: '/stations' },
  { id: '12-owner-station', role: 'owner', path: async (page) => `/stations/${(await firstRow(page, '/stations/data', (r) => /Lubumbashi/.test(r.name))).id}`, fullPage: true },
  { id: '13-owner-users', role: 'owner', path: '/users' },
  { id: '14-owner-roles', role: 'owner', path: '/roles', fullPage: true },
  { id: '15-owner-prices', role: 'owner', before: chooseStation('Lubumbashi'), path: '/prices', fullPage: true },
  { id: '16-owner-rate', role: 'owner', before: chooseStation('Lubumbashi'), path: '/rates/new', fullPage: true },
  { id: '17-owner-report-month', role: 'owner', before: chooseStation(null), path: `/reports?type=monthly&month=${YESTERDAY.slice(0, 7)}&station=all`, fullPage: true },
  { id: '18-owner-alerts', role: 'owner', before: chooseStation(null), path: '/alerts', fullPage: true },
  { id: '19-owner-audit', role: 'owner', path: '/audit' },

  // Gérant
  { id: '20-manager-dashboard', role: 'manager', path: `/?${RANGE}`, fullPage: true },
  { id: '21-manager-shifts', role: 'manager', path: '/shifts' },
  { id: '22-manager-shift-open', role: 'manager2', path: '/shifts/open', fullPage: true },
  { id: '23-manager-shift', role: 'manager', path: shiftPath('', validated('Après-midi')), fullPage: true },
  { id: '24-manager-cash', role: 'manager', path: shiftPath('/cash', validated('Après-midi')), fullPage: true },
  { id: '25-manager-stock', role: 'manager', path: '/stock', fullPage: true },
  { id: '26-manager-deliveries', role: 'manager', path: '/deliveries' },
  { id: '27-manager-delivery-form', role: 'manager', path: '/deliveries/create', fullPage: true },
  { id: '28b-manager-suppliers', role: 'manager', path: '/suppliers' },
  { id: '28-manager-delivery', role: 'manager', path: async (page) => `/deliveries/${(await firstRow(page, '/deliveries/data')).id}`, fullPage: true },
  { id: '29-manager-alerts', role: 'manager', path: '/alerts', fullPage: true },
  { id: '30-manager-alert', role: 'manager', path: async (page) => `/alerts/${(await firstRow(page, '/alerts/data', (r) => r.alert_type === 'cash_shortage')).id}`, fullPage: true },
  { id: '31-manager-report-day', role: 'manager', path: `/reports?type=daily&date=${YESTERDAY}`, fullPage: true },
  { id: '32-manager-report-export', role: 'manager', path: `/reports?type=daily&date=${YESTERDAY}`, action: highlight('.page-header__actions') },
  { id: '33-manager-report-shift', role: 'manager', path: async (page) => `/reports?type=shift&shift=${(await firstRow(page, '/shifts/data', validated('Après-midi'))).id}`, fullPage: true },

  // Pompiste (téléphone)
  { id: '40-attendant-home', role: 'attendant', mobile: true, path: '/' },
  { id: '41-attendant-shifts', role: 'attendant', mobile: true, path: '/shifts' },
  { id: '42-attendant-shift', role: 'attendant', mobile: true, path: shiftPath('', (r) => r.status === 'open'), fullPage: true },
  { id: '43-attendant-output', role: 'attendant', mobile: true, path: shiftPath('', (r) => r.status === 'open'), action: openDialog('#new-output') },
  { id: '44-attendant-close', role: 'attendant', mobile: true, path: shiftPath('/close', (r) => r.status === 'open'), fullPage: true },
];

async function login(context, role) {
  const page = await context.newPage();
  await page.goto(toUrl('/login'));
  await page.fill('[name=username]', ACCOUNTS[role]);
  await page.fill('[name=password]', PASSWORD);
  await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('form button[type=submit]')]);
  return page;
}

/** Attend la fin des chargements (DataTables, graphiques, icônes) et fige les animations. */
async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.addStyleTag({ content: '*,*::before,*::after{animation:none !important;transition:none !important;caret-color:transparent !important}' });
  await page.locator('.dt-processing:visible').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(700);
}

/** Base de démonstration puis serveur PHP intégré, sur la base du guide uniquement. */
async function prepare() {
  const env = { ...process.env, DB_NAME: process.env.PETRA_GUIDE_DB || 'petra_guide', APP_BASE_PATH: '', APP_MODE: 'station', STATION_ID: '', SYNC_URL: '', SYNC_TOKEN: '', APP_DEBUG: 'false' };
  console.log(`Base de démonstration ${env.DB_NAME}…`);
  const demo = spawnSync(PHP, [path.join(ROOT, 'bin', 'guide-demo.php')], { env, stdio: 'inherit' });
  if (demo.status !== 0) throw new Error('bin/guide-demo.php a échoué (PHP et MariaDB sont-ils démarrés ?)');
  const server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'bin', 'dev-router.php')], { env, stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try {
      if ((await fetch(BASE + '/login')).ok) return server;
    } catch { /* pas encore prêt */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  server.kill();
  throw new Error(`le serveur PHP ne répond pas sur ${BASE}`);
}

async function run() {
  const server = EXTERNAL ? null : await prepare();
  const browser = await chromium.launch();
  const failures = [];
  let count = 0;
  try {
    await mkdir(OUT, { recursive: true });
    const shots = SHOTS.filter((s) => !ONLY || ONLY.some((p) => s.id.startsWith(p)));
    const groups = new Map();
    for (const s of shots) {
      const key = `${s.role}|${s.mobile ? 'm' : 'd'}`;
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(s);
    }
    for (const [key, group] of groups) {
      const [role, device] = key.split('|');
      const context = await browser.newContext({
        viewport: device === 'm' ? MOBILE : DESKTOP,
        deviceScaleFactor: 2,
        locale: 'fr-FR',
        timezoneId: 'Africa/Lubumbashi',
        isMobile: device === 'm',
        hasTouch: device === 'm',
      });
      try {
        const page = role === 'null' ? await context.newPage() : await login(context, role);
        for (const shot of group) {
          try {
            if (shot.before) await shot.before(page);
            const target = typeof shot.path === 'function' ? await shot.path(page) : shot.path;
            const response = await page.goto(toUrl(target));
            if (!response || response.status() >= 400) throw new Error(`HTTP ${response ? response.status() : '?'} sur ${target}`);
            await settle(page);
            if (shot.action) await shot.action(page);
            await page.screenshot({ path: path.join(OUT, `${shot.id}.png`), fullPage: !!shot.fullPage && !shot.action });
            count++;
            console.log(`  ✓ ${shot.id}`);
          } catch (e) {
            failures.push(`${shot.id} : ${e.message.split('\n')[0]}`);
            console.log(`  ✗ ${shot.id}`);
          }
        }
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
    if (server) server.kill();
  }
  console.log(`\n${count} capture(s) dans docs/guide/images/`);
  if (failures.length) {
    console.log(`${failures.length} échec(s) :\n  ` + failures.join('\n  '));
    process.exitCode = 1;
  }
}

run().catch((e) => { console.error(e.message || e); process.exit(1); });
