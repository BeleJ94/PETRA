/*
 * Petra — développé par BELEJ-CONSULTING.
 * Génère le guide utilisateur PDF à partir de docs/guide/guide-fr.html (Playwright / Chromium).
 *   npm run guide
 * Les captures PNG (×2) sont converties en JPEG à la volée pour alléger le PDF.
 * Prérequis : les captures de docs/guide/images/ (npm run screenshots).
 */
import { chromium } from 'playwright';
import path from 'node:path';
import { readFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'docs', 'guide', 'guide-fr.html');
const OUT = path.join(ROOT, 'docs', 'guide', 'Guide-utilisateur-Petra.pdf');
const CREDIT = 'Petra — développé par BELEJ-CONSULTING';

// Chaque image citée par le guide doit exister : un guide avec des cadres vides ne doit pas sortir.
const html = await readFile(SRC, 'utf8');
const wanted = [...html.matchAll(/src="images\/([^"]+)"/g)].map((m) => m[1]);
const present = new Set(await readdir(path.join(ROOT, 'docs', 'guide', 'images')).catch(() => []));
const missing = [...new Set(wanted)].filter((name) => !present.has(name));
if (missing.length) {
  console.error(`Captures manquantes (lancez « npm run screenshots ») :\n  ${missing.join('\n  ')}`);
  process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage();
// Origine HTTP virtuelle servie depuis le disque (en file://, les images « souillent » le canvas de compression)
const ORIGIN = 'http://guide.local';
const TYPES = { '.html': 'text/html', '.png': 'image/png', '.woff2': 'font/woff2', '.css': 'text/css', '.svg': 'image/svg+xml' };
await page.route(ORIGIN + '/**', async (route) => {
  const rel = decodeURIComponent(new URL(route.request().url()).pathname);
  const file = path.join(ROOT, rel);
  if (!file.startsWith(ROOT)) return route.abort();
  try {
    await route.fulfill({ body: await readFile(file), contentType: TYPES[path.extname(file)] || 'application/octet-stream' });
  } catch {
    await route.fulfill({ status: 404 });
  }
});
await page.goto(`${ORIGIN}/${path.relative(ROOT, SRC).split(path.sep).join('/')}`, { waitUntil: 'networkidle' });

// Date d'édition sur la page de garde
await page.evaluate((date) => {
  document.querySelectorAll('[data-edition]').forEach((el) => { el.textContent = date; });
}, new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(new Date()));

// Compression des images : PNG → JPEG qualité 0,82, largeur max 1800 px
await page.evaluate(async () => {
  await Promise.all([...document.images].map(async (img) => {
    await img.decode().catch(() => {});
    if (!img.naturalWidth) return;
    const scale = Math.min(1, 1800 / img.naturalWidth);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(img.naturalWidth * scale);
    canvas.height = Math.round(img.naturalHeight * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    img.src = canvas.toDataURL('image/jpeg', 0.82);
    await img.decode().catch(() => {});
  }));
});

const footer = `<div style="width:100%;font:8px Arial,sans-serif;color:#5B6573;padding:0 14mm;display:flex;justify-content:space-between">
  <span>Petra — Guide utilisateur</span><span>${CREDIT}</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;

await page.pdf({
  path: OUT,
  format: 'A4',
  printBackground: true,
  preferCSSPageSize: true,
  displayHeaderFooter: true,
  headerTemplate: '<div></div>',
  footerTemplate: footer,
  outline: true,
  tagged: true,
});
await browser.close();
console.log('PDF généré : ' + path.relative(ROOT, OUT));
