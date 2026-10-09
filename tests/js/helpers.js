'use strict';
// Petra — développé par BELEJ-CONSULTING.
// Lecture du catalogue PHP (tableau 'clé' => 'texte') sans exécuter PHP.
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../..');

function loadCatalogue(locale) {
  const source = fs.readFileSync(path.join(ROOT, 'lang', locale + '.php'), 'utf8');
  const out = {};
  const re = /'([a-z_]+\.[a-z0-9_.]+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g;
  let m;
  while ((m = re.exec(source))) {
    out[m[1]] = (m[2] !== undefined ? m[2] : m[3]).replace(/\\'/g, "'");
  }
  return out;
}

module.exports = { ROOT, loadCatalogue };
