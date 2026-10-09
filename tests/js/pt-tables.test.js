'use strict';
// Petra — développé par BELEJ-CONSULTING.
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pt-core.js');
const Tables = require('../../public/assets/js/pt-tables.js');

test('cellText / cellHtml : repli sur la donnée brute, HTML toujours échappé', () => {
  const row = { name: '<b>Kolwezi</b>', n: 0, none: null };
  assert.equal(Tables.cellText({ data: 'name' }, row), '<b>Kolwezi</b>');
  assert.equal(Tables.cellHtml({ data: 'name' }, row), '&lt;b&gt;Kolwezi&lt;/b&gt;');
  assert.equal(Tables.cellText({ data: 'n' }, row), '0');
  assert.equal(Tables.cellText({ data: 'none' }, row), '');
  assert.equal(Tables.cellText({ data: 'name', text: (r) => r.name.length + ' car.' }, row), '14 car.');
  assert.equal(Tables.cellHtml({ data: 'name', html: () => '<i>x</i>' }, row), '<i>x</i>');
});

test('cellValue : colonnes textuelles → null, dates sans décalage de fuseau', () => {
  assert.equal(Tables.cellValue({ data: 'name' }, { name: 'x' }, 'fr'), null);
  const date = Tables.cellValue({ data: 'd', xl: { type: 'datetime' } }, { d: '2026-09-24 07:05:00' }, 'fr');
  assert.equal(date.v.toISOString(), '2026-09-24T07:05:00.000Z');
  assert.equal(date.fmt, 'dd/mm/yyyy hh:mm');
  assert.deepEqual(Tables.cellValue({ data: 'd', xl: { type: 'date' } }, { d: null }, 'en'), { v: null, fmt: 'yyyy-mm-dd' });
});

test('cellValue : montants en plus petite unité → unité affichée dans Excel', () => {
  const cdf = Tables.cellValue({ data: 'amount', xl: { type: 'money', currency: 'CDF' } }, { amount: 1250000 }, 'fr');
  assert.deepEqual(cdf, { v: 1250000, fmt: '#,##0" FC"' });
  const usd = Tables.cellValue({ data: 'amount', xl: { type: 'money', currencyKey: 'currency' } }, { amount: '123405', currency: 'USD' }, 'fr');
  assert.deepEqual(usd, { v: 1234.05, fmt: '#,##0.00" $"' });
  assert.deepEqual(Tables.cellValue({ data: 'amount', xl: { type: 'money', currency: 'CDF', blankZero: true } }, { amount: 0 }, 'fr'), { v: null, fmt: null });
});

test('cellValue : volumes et entiers avec unité', () => {
  assert.deepEqual(Tables.cellValue({ data: 'v', xl: { type: 'dec', unit: 'L' } }, { v: '1500.50' }, 'fr'), { v: 1500.5, fmt: '#,##0.00" L"' });
  assert.deepEqual(Tables.cellValue({ data: 'n', xl: { type: 'int' } }, { n: '12' }, 'fr'), { v: 12, fmt: '#,##0' });
  assert.deepEqual(Tables.cellValue({ data: 'n', xl: { type: 'int' } }, { n: 'abc' }, 'fr'), { v: null, fmt: null });
});

test('journal : différences avant → après', () => {
  assert.deepEqual(Tables.diffEntries('{"price":100,"note":null}', '{"price":120,"note":"x"}'), [
    { key: 'price', before: '100', after: '120' },
    { key: 'note', before: '∅', after: 'x' }
  ]);
  assert.deepEqual(Tables.diffEntries(null, '{"a":1}'), []);
  assert.deepEqual(Tables.diffEntries('{', '{"a":1}'), []);
  assert.match(Tables.diffHtml('{"a":"<1>"}', '{"a":"2"}'), /<del>&lt;1&gt;<\/del> → <ins>2<\/ins>/);
  assert.equal(Tables.diffText('{"a":1}', '{"a":2}'), ' [a: 1 → 2]');
});

test('définitions : chaque colonne déclarée a un nom et un titre', () => {
  const defs = Tables.definitions(Core.createTranslator({}), 'fr');
  for (const [name, def] of Object.entries(defs)) {
    assert.ok(Array.isArray(def.order), name);
    for (const col of def.columns) {
      assert.ok(col.data && col.title, `${name}.${col.data}`);
    }
  }
});
