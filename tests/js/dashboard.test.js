'use strict';
// Petra — développé par BELEJ-CONSULTING.
const test = require('node:test');
const assert = require('node:assert/strict');
const Dashboard = require('../../public/assets/js/dashboard.js');

test('libellé court des jours, sans conversion de fuseau', () => {
  assert.equal(Dashboard.shortDay('2026-10-05', 'fr'), '05/10');
  assert.equal(Dashboard.shortDay('2026-10-05', 'en'), '10-05');
  assert.equal(Dashboard.shortDay('2026-01-01 00:00:00', 'fr'), '01/01');
  assert.equal(Dashboard.shortDay('', 'fr'), '');
  assert.equal(Dashboard.shortDay(null, 'fr'), '');
});

test('graduations abrégées des montants', () => {
  assert.equal(Dashboard.compact(0, 'fr'), '0');
  assert.equal(Dashboard.compact(950, 'fr'), '950');
  assert.equal(Dashboard.compact(485000, 'fr'), '485 k');
  assert.equal(Dashboard.compact(1250000, 'fr'), '1,25 M');
  assert.equal(Dashboard.compact(1250000, 'en'), '1.25 M');
  assert.equal(Dashboard.compact(2000000, 'fr'), '2 M');
  assert.equal(Dashboard.compact(3400000000, 'fr'), '3,4 Md');
  assert.equal(Dashboard.compact(-15000, 'fr'), '-15 k');
  assert.equal(Dashboard.compact('abc', 'fr'), '');
});

test('un manquant est rouge, un excédent ou un écart nul est vert', () => {
  assert.equal(Dashboard.varianceColor(-15000), Dashboard.COLORS.danger);
  assert.equal(Dashboard.varianceColor(0), Dashboard.COLORS.success);
  assert.equal(Dashboard.varianceColor(2000), Dashboard.COLORS.success);
});

test('le module se charge sans navigateur et sans Chart.js', () => {
  assert.equal(typeof Dashboard.boot, 'function');
  assert.doesNotThrow(() => Dashboard.boot({ document: null }));
});

test('couleurs des produits dans l\'anneau, alignées sur la légende de la page', () => {
  assert.equal(Dashboard.productColor(0), '#1F4E79');
  assert.equal(Dashboard.productColor(1), '#E0662B');
  assert.equal(Dashboard.productColor(9), Dashboard.PALETTE[Dashboard.PALETTE.length - 1], 'au-delà de la palette : le gris');
  assert.equal(new Set(Dashboard.PALETTE).size, Dashboard.PALETTE.length);
});
