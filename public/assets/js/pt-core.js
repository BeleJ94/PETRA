/*
 * Petra — fonctions utilitaires pures (navigateur + Node pour les tests).
 * Aucune dépendance au DOM ici. Développé par BELEJ-CONSULTING.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.PTCore = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /** Mention obligatoire en pied des exports PDF et Excel. */
  var CREDIT = 'Petra — développé par BELEJ-CONSULTING';

  var CURRENCY_SYMBOLS = { CDF: 'FC', USD: '$' };

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /** Traducteur sur un catalogue à plat ; paramètres :nom. Repli sur la clé. */
  function createTranslator(catalogue) {
    var dict = catalogue || {};
    return function t(key, params) {
      var text = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
      if (params) {
        Object.keys(params).forEach(function (name) {
          text = text.split(':' + name).join(String(params[name]));
        });
      }
      return text;
    };
  }

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  /**
   * Formate une date SQL (« AAAA-MM-JJ » ou « AAAA-MM-JJ HH:MM:SS ») sans conversion de fuseau.
   */
  function formatDate(value, locale, withTime) {
    if (!value) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    if (!m) return '';
    var date = locale === 'en' ? m[1] + '-' + m[2] + '-' + m[3] : m[3] + '/' + m[2] + '/' + m[1];
    if (withTime && m[4] !== undefined) {
      date += ' ' + m[4] + ':' + m[5];
    }
    return date;
  }

  function currencySymbol(currency) {
    var code = String(currency || '').toUpperCase();
    return CURRENCY_SYMBOLS[code] || code;
  }

  function group(digits, locale) {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, locale === 'en' ? ',' : '\u202F');
  }

  /**
   * Montant reçu dans la plus petite unité (CDF entier, USD en cents) : partie entière et cents
   * sont séparés en arithmétique entière, jamais par une division flottante.
   */
  function formatMoney(amount, currency, locale) {
    if (amount === null || amount === undefined || amount === '') return '';
    var num = Number(amount);
    if (!Number.isSafeInteger(num)) return '';
    var abs = Math.abs(num);
    var cents = String(currency || '').toUpperCase() === 'USD';
    var out = group(String(cents ? Math.trunc(abs / 100) : abs), locale);
    if (cents) out += (locale === 'en' ? '.' : ',') + pad(abs % 100);
    return (num < 0 ? '-' : '') + out + ' ' + currencySymbol(currency);
  }

  /** Nombre pour l'affichage uniquement (volumes, index). */
  function formatNumber(value, locale, decimals) {
    if (value === null || value === undefined || value === '') return '';
    var num = Number(value);
    if (!isFinite(num)) return '';
    var d = decimals === undefined ? (Math.round(num) === num ? 0 : 2) : decimals;
    var parts = Math.abs(num).toFixed(d).split('.');
    parts[0] = group(parts[0], locale);
    return (num < 0 ? '-' : '') + parts.join(locale === 'en' ? '.' : ',');
  }

  /** Pastille HTML (échappée) : tone = success | danger | info | warning | neutral. */
  function badge(tone, text) {
    if (!text) return '';
    return '<span class="badge badge--' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(text) + '</span>';
  }

  /** Remplace {clé} dans un gabarit d'URL par les valeurs (encodées) de la ligne. */
  function fillUrl(template, row) {
    return String(template).replace(/\{(\w+)\}/g, function (_, key) {
      return encodeURIComponent(row[key] === undefined || row[key] === null ? '' : row[key]);
    });
  }

  /** Paramètres de filtres à partir de paires [nom, valeur] (FormData), valeurs vides ignorées. */
  function filtersFromEntries(entries) {
    var out = {};
    (entries || []).forEach(function (pair) {
      var name = pair[0];
      var value = pair[1];
      if (value === '' || value === null || value === undefined) return;
      out[name] = value;
    });
    return out;
  }

  /** Nom de fichier sûr et daté pour les exports. */
  function exportFileName(title, extension, now) {
    var d = now || new Date();
    var slug = String(title || 'export')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'export';
    return 'petra-' + slug + '-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '.' + extension;
  }

  return {
    CREDIT: CREDIT,
    escapeHtml: escapeHtml,
    createTranslator: createTranslator,
    formatDate: formatDate,
    formatNumber: formatNumber,
    formatMoney: formatMoney,
    currencySymbol: currencySymbol,
    badge: badge,
    fillUrl: fillUrl,
    filtersFromEntries: filtersFromEntries,
    exportFileName: exportFileName
  };
});
