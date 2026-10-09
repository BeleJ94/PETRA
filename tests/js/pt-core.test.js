'use strict';
// Petra — développé par BELEJ-CONSULTING.
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pt-core.js');

const NNBSP = ' ';

test('escapeHtml neutralise les caractères HTML', () => {
  assert.equal(Core.escapeHtml('<img src=x onerror="alert(\'1\')">&'), '&lt;img src=x onerror=&quot;alert(&#039;1&#039;)&quot;&gt;&amp;');
  assert.equal(Core.escapeHtml(null), '');
  assert.equal(Core.escapeHtml(0), '0');
});

test('traducteur : paramètres, repli sur la clé', () => {
  const t = Core.createTranslator({ 'a.b': ':count ligne(s) le :date', 'x.y': 'OK' });
  assert.equal(t('a.b', { count: 3, date: '24/09/2026' }), '3 ligne(s) le 24/09/2026');
  assert.equal(t('x.y'), 'OK');
  assert.equal(t('missing.key'), 'missing.key');
});

test('formatDate sans conversion de fuseau', () => {
  assert.equal(Core.formatDate('2026-09-24', 'fr'), '24/09/2026');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'fr', true), '24/09/2026 07:05');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'en', true), '2026-09-24 07:05');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'fr', false), '24/09/2026');
  assert.equal(Core.formatDate(null, 'fr'), '');
  assert.equal(Core.formatDate('n/a', 'fr'), '');
});

test('formatNumber : séparateurs français et anglais', () => {
  assert.equal(Core.formatNumber(1234567.5, 'fr', 2), `1${NNBSP}234${NNBSP}567,50`);
  assert.equal(Core.formatNumber(1234.5, 'en', 1), '1,234.5');
  assert.equal(Core.formatNumber('12.00', 'fr'), '12');
  assert.equal(Core.formatNumber(-3.25, 'fr'), '-3,25');
  assert.equal(Core.formatNumber('', 'fr'), '');
  assert.equal(Core.formatNumber('abc', 'fr'), '');
});

test('formatMoney : plus petite unité (CDF entier, USD en cents), même rendu que PHP', () => {
  assert.equal(Core.formatMoney(1250000, 'CDF', 'fr'), `1${NNBSP}250${NNBSP}000 FC`);
  assert.equal(Core.formatMoney(0, 'CDF', 'fr'), '0 FC');
  assert.equal(Core.formatMoney(123405, 'USD', 'fr'), `1${NNBSP}234,05 $`);
  assert.equal(Core.formatMoney(7, 'USD', 'fr'), '0,07 $');
  assert.equal(Core.formatMoney(-50, 'USD', 'fr'), '-0,50 $');
  assert.equal(Core.formatMoney('123405', 'usd', 'en'), '1,234.05 $');
  assert.equal(Core.formatMoney(null, 'CDF', 'fr'), '');
  assert.equal(Core.formatMoney(12.5, 'CDF', 'fr'), '', 'un montant non entier est refusé, pas arrondi');
});

test('badge : ton de la charte et libellé échappé', () => {
  assert.equal(Core.badge('danger', 'Écart <fort>'), '<span class="badge badge--danger">Écart &lt;fort&gt;</span>');
  assert.match(Core.badge(null, 'x'), /badge--neutral/);
  assert.equal(Core.badge('success', ''), '');
});

test('fillUrl encode les valeurs', () => {
  assert.equal(Core.fillUrl('/stations/{id}', { id: 'a-b' }), '/stations/a-b');
  assert.equal(Core.fillUrl('/x/{id}/edit', { id: '1/../2?a' }), '/x/1%2F..%2F2%3Fa/edit');
  assert.equal(Core.fillUrl('/x/{missing}', {}), '/x/');
});

test('filtersFromEntries ignore les valeurs vides', () => {
  assert.deepEqual(Core.filtersFromEntries([['station_id', ''], ['status', 'open'], ['mine', '1']]), { status: 'open', mine: '1' });
});

test('exportFileName : nom sûr et daté', () => {
  const d = new Date(2026, 8, 4);
  assert.equal(Core.exportFileName('Écarts de caisse', 'xlsx', d), 'petra-ecarts-de-caisse-20260904.xlsx');
  assert.equal(Core.exportFileName('../../etc/passwd', 'pdf', d), 'petra-etc-passwd-20260904.pdf');
  assert.equal(Core.exportFileName('', 'pdf', d), 'petra-export-20260904.pdf');
});

test('mention obligatoire des exports', () => {
  assert.equal(Core.CREDIT, 'Petra — développé par BELEJ-CONSULTING');
});
