'use strict';
// Petra — développé par BELEJ-CONSULTING.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { ROOT, loadCatalogue } = require('./helpers.js');

test('toutes les clés utilisées par le JavaScript existent et sont exposées au navigateur', () => {
  const fr = loadCatalogue('fr');
  assert.ok(Object.keys(fr).length > 50);
  const i18nPhp = fs.readFileSync(path.join(ROOT, 'app/Core/I18n.php'), 'utf8');
  const prefixes = [...(/JS_PREFIXES = \[([^\]]+)\]/.exec(i18nPhp)[1].matchAll(/'([^']+)'/g))].map((m) => m[1]);
  const dir = path.join(ROOT, 'public/assets/js');
  for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.js'))) {
    const source = fs.readFileSync(path.join(dir, file), 'utf8');
    for (const [, key] of source.matchAll(/\bt\('([a-z_]+\.[a-z0-9_.]+)'/g)) {
      assert.ok(fr[key] !== undefined, `${file} : clé inconnue ${key}`);
      assert.ok(prefixes.some((p) => key.startsWith(p)), `${file} : ${key} non exposée (JS_PREFIXES)`);
    }
  }
});

test('la mention du catalogue est celle des exports', () => {
  assert.equal(loadCatalogue('fr')['app.credit'], require('../../public/assets/js/pt-core.js').CREDIT);
});
