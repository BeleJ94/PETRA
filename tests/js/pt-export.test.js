'use strict';
// Petra — développé par BELEJ-CONSULTING.
const test = require('node:test');
const assert = require('node:assert/strict');
const Exporter = require('../../public/assets/js/pt-export.js');

const CREDIT = 'Petra — développé par BELEJ-CONSULTING';

const columns = [
  { data: 'name', title: 'Station' },
  { data: 'amount', title: 'Écart', xl: { type: 'money', currency: 'CDF' } }
];
const rows = [{ name: 'Kolwezi', amount: -2500 }, { name: 'Likasi', amount: 0 }];

/** Double minimal d'ExcelJS : enregistre ce que buildWorkbook écrit. */
function fakeExcel() {
  class Row {
    constructor(values) { this.values = values; this.cells = {}; }
    getCell(i) { return (this.cells[i] = this.cells[i] || {}); }
  }
  class Sheet {
    constructor(name, options) { this.name = name; this.options = options; this.rows = []; }
    addRow(values) { const row = new Row(values); this.rows.push(row); return row; }
    getRow(i) { return this.rows[i - 1]; }
    get columns() { return (this._columns = this._columns || this.rows[0].values.map(() => ({}))); }
  }
  class Workbook {
    constructor() { this.sheets = []; }
    addWorksheet(name, options) { const s = new Sheet(name, options); this.sheets.push(s); return s; }
  }
  return { Workbook };
}

test('tableData : textes pour le PDF, valeurs natives pour Excel', () => {
  const data = Exporter.tableData(columns, rows, 'fr');
  assert.deepEqual(data.headers, ['Station', 'Écart']);
  assert.deepEqual(data.body, [['Kolwezi', '-2500'], ['Likasi', '0']]);
  assert.deepEqual(data.cells[0], [null, { v: -2500, fmt: '#,##0" FC"' }]);
  assert.deepEqual(data.numeric, [false, true]);
});

test('Excel : mention BELEJ-CONSULTING en pied de feuille et dans les propriétés', () => {
  const wb = Exporter.buildWorkbook(fakeExcel(), 'Écarts de caisse', Exporter.tableData(columns, rows, 'fr'), { date: new Date(0) });
  const sheet = wb.sheets[0];
  assert.equal(wb.creator, CREDIT);
  assert.equal(wb.company, 'BELEJ-CONSULTING');
  assert.equal(sheet.headerFooter.oddFooter, '&L' + CREDIT + '&R&P / &N');
  assert.equal(sheet.name, 'Écarts de caisse');
  assert.deepEqual(sheet.rows.map((r) => r.values), [['Station', 'Écart'], ['Kolwezi', -2500], ['Likasi', 0]]);
  assert.equal(sheet.rows[1].cells[2].numFmt, '#,##0" FC"');
  assert.deepEqual(sheet.autoFilter, { from: { row: 1, column: 1 }, to: { row: 1, column: 2 } });
});

test('PDF : mention BELEJ-CONSULTING et pagination en pied de chaque page', () => {
  const doc = Exporter.listDocDefinition('Écarts', Exporter.tableData(columns, rows, 'fr'), { subtitle: '2 ligne(s)' });
  const footer = doc.footer(2, 5);
  assert.equal(footer.columns[0].text, CREDIT);
  assert.equal(footer.columns[1].text, '2 / 5');
  assert.equal(doc.info.author, 'BELEJ-CONSULTING');
  assert.equal(doc.content[2].table.body.length, 3);
  assert.equal(doc.content[2].table.headerRows, 1);
});

test('pdfSafe : espaces fines remplacées, images intactes, pied conservé', () => {
  const safe = Exporter.pdfSafe({ text: '1 250 FC', image: 'data: ', footer: () => ({ text: 'a b' }), list: ['x y'] });
  assert.equal(safe.text, '1 250 FC');
  assert.equal(safe.image, 'data: ');
  assert.equal(safe.footer().text, 'a b');
  assert.deepEqual(safe.list, ['x y']);
  assert.equal(Exporter.pdfSafe(Exporter.listDocDefinition('T', Exporter.tableData(columns, rows, 'fr'))).footer(1, 1).columns[0].text, CREDIT);
});

// --- Rapports ---------------------------------------------------------------

const report = {
  title: 'Rapport journalier', file: 'rapport-journalier-2026-10-05', station: 'Station A', period: '05/10/2026',
  generated_at: '06/10/2026 18:00', author: 'Jeanne Gérante',
  labels: { station: 'Station', period: 'Période', generated: 'Généré le', author: 'Auteur', figures: 'Chiffres clés' },
  summary: [['Montant attendu', { t: '730 000 FC', v: 730000, f: 'CDF' }], ['Remis en USD', { t: '100,00 $', v: '100.00', f: 'USD' }]],
  sections: [
    {
      key: 'products', title: '1–2. Litres vendus par produit', empty: 'Aucune vente relevée.',
      columns: ['Produit', ['Litres vendus', 'num'], ['Montant', 'num']],
      rows: [['Essence', { t: '100,00 L', v: '100.00', f: 'L' }, { t: '500 000 FC', v: 500000, f: 'CDF' }]],
      foot: ['Total', { t: '100,00 L', v: '100.00', f: 'L' }, { t: '500 000 FC', v: 500000, f: 'CDF' }],
      note: 'Note de bas de section.'
    },
    { key: 'deliveries', title: '8. Ravitaillements', empty: 'Aucun ravitaillement.', columns: ['Heure'], rows: [] }
  ]
};

test('cellules de rapport : texte affiché et valeur native', () => {
  assert.equal(Exporter.cellText('Essence'), 'Essence');
  assert.equal(Exporter.cellText({ t: '100,00 L', v: '100.00', f: 'L' }), '100,00 L');
  assert.equal(Exporter.cellText(null), '');
  assert.deepEqual(Exporter.cellNative({ t: '100,00 $', v: '100.00', f: 'USD' }), { v: 100, fmt: '#,##0.00" $"' });
  assert.deepEqual(Exporter.cellNative({ t: '-15 000 FC', v: -15000, f: 'CDF' }), { v: -15000, fmt: '#,##0" FC"' });
  assert.equal(Exporter.cellNative('Essence'), null);
  assert.equal(Exporter.cellNative({ t: '—', v: null, f: 'L' }), null);
});

test('chaque export de rapport porte station, période, date de génération, auteur et la mention', () => {
  assert.equal(Exporter.reportSubtitle(report), 'Station : Station A · Période : 05/10/2026 · Généré le 06/10/2026 18:00 · Auteur : Jeanne Gérante');

  // PDF
  const doc = Exporter.reportDocDefinition(report);
  const text = JSON.stringify(doc.content);
  for (const expected of ['Rapport journalier', 'Station A', '05/10/2026', '06/10/2026 18:00', 'Jeanne Gérante', '1–2. Litres vendus par produit', 'Aucun ravitaillement.', 'Note de bas de section.']) {
    assert.ok(text.includes(expected), 'PDF : ' + expected);
  }
  assert.equal(doc.footer(2, 5).columns[0].text, CREDIT);
  assert.equal(doc.footer(2, 5).columns[1].text, 'Station A · 05/10/2026');
  assert.equal(doc.footer(2, 5).columns[2].text, '2 / 5');
  assert.ok(doc.header().text.includes('Généré le 06/10/2026 18:00 · Auteur : Jeanne Gérante'), 'identification répétée en tête de page');
  assert.equal(doc.info.author, 'Jeanne Gérante');
  assert.equal(doc.info.creator, CREDIT);
  const table = doc.content.find((node) => node.table && node.table.headerRows === 1).table;
  assert.deepEqual(table.body[0].map((c) => c.text), ['Produit', 'Litres vendus', 'Montant']);
  assert.deepEqual(table.body[1].map((c) => [c.text, c.alignment]), [['Essence', 'left'], ['100,00 L', 'right'], ['500 000 FC', 'right']]);
  assert.equal(table.body[2][0].bold, true, 'ligne de total en gras');
  assert.ok(!JSON.stringify(Exporter.pdfSafe(doc.content)).includes(' '), 'espaces fines remplacées pour la police du PDF');

  // Excel
  const wb = Exporter.buildReportWorkbook(fakeExcel(), report);
  const sheet = wb.sheets[0];
  const rows = sheet.rows.map((r) => r.values);
  assert.equal(sheet.name, 'Rapport journalier');
  assert.deepEqual(rows.slice(0, 6), [['Rapport journalier'], ['Station', 'Station A'], ['Période', '05/10/2026'], ['Généré le', '06/10/2026 18:00'], ['Auteur', 'Jeanne Gérante'], [CREDIT]]);
  assert.ok(sheet.headerFooter.oddFooter.includes(CREDIT), 'mention au pied de chaque page imprimée');
  assert.equal(wb.creator, CREDIT);
  const head = rows.findIndex((r) => r[0] === 'Produit');
  assert.deepEqual(rows[head], ['Produit', 'Litres vendus', 'Montant']);
  assert.deepEqual(rows[head + 1], ['Essence', 100, 500000], 'valeurs numériques natives');
  assert.deepEqual(rows[head + 2], ['Total', 100, 500000]);
  assert.equal(sheet.rows[head + 1].cells[3].numFmt, '#,##0" FC"');
  assert.equal(sheet.rows[head + 1].cells[2].numFmt, '#,##0.00" L"');
  assert.deepEqual(rows.find((r) => r[0] === 'Remis en USD'), ['Remis en USD', 100]);
  assert.ok(rows.some((r) => r[0] === 'Aucun ravitaillement.'), 'section vide signalée');
});
