/*
 * Petra — développé par BELEJ-CONSULTING.
 * Tableaux de bord : graphiques Chart.js à partir des agrégations calculées par le serveur (SQL).
 * Aucun chiffre n'est calculé ici : le navigateur ne fait que dessiner. Chaque graphique a son tableau,
 * ouvert en fenêtre par le bouton « Détails » (lecture possible sans couleur ni canevas).
 */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.PTDashboard = api;
    api.boot(root);
    // Changement de période ou de station sans rechargement : la zone est remplacée, les graphiques sont redessinés.
    if (root.document) root.document.addEventListener('pt:live-updated', function () { api.boot(root); });
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var INK = '#1B2430';
  var MUTED = '#5B6573';
  var GRID = '#EEF0F3';
  var SURFACE = '#FFFFFF';
  var COLORS = { accent: '#1F4E79', soft: '#C9D8E8', warning: '#B54708', danger: '#B42318', success: '#1E7A4C', empty: '#EEF0F3', orange: '#E0662B' };
  /** Couleurs des produits, dans l'ordre de la légende de la page (legend__dot--0 à 4). */
  var PALETTE = ['#1F4E79', '#E0662B', '#1E7A4C', '#7A5AF8', '#8D9098'];

  /** « 2026-10-05 » → « 05/10 » (ou « 10-05 » en anglais) : libellé court de l'axe des jours. */
  function shortDay(value, locale) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    if (!m) return '';
    return locale === 'en' ? m[2] + '-' + m[3] : m[3] + '/' + m[2];
  }

  /** Montant abrégé pour les graduations : 1 250 000 → « 1,25 M », 485 000 → « 485 k ». Affichage seulement. */
  function compact(amount, locale) {
    var num = Number(amount);
    if (!isFinite(num)) return '';
    var abs = Math.abs(num);
    var sep = locale === 'en' ? '.' : ',';
    var out;
    if (abs >= 1e9) out = trim(abs / 1e9, sep) + ' Md';
    else if (abs >= 1e6) out = trim(abs / 1e6, sep) + ' M';
    else if (abs >= 1e3) out = trim(abs / 1e3, sep) + ' k';
    else out = String(Math.round(abs));
    return (num < 0 ? '-' : '') + out;
  }

  function trim(value, sep) {
    return value.toFixed(2).replace(/\.?0+$/, '').replace('.', sep);
  }

  /** Couleur d'un écart : manquant en rouge, excédent ou zéro en vert. */
  function varianceColor(amount) {
    return Number(amount) < 0 ? COLORS.danger : COLORS.success;
  }

  /** Couleur d'un produit dans l'anneau ; au-delà de cinq produits, le gris. */
  function productColor(index) {
    return PALETTE[index] || PALETTE[PALETTE.length - 1];
  }

  function boot(win) {
    var doc = win.document;
    if (!doc || !win.Chart) return;
    var el = doc.getElementById('dashboard-data');
    if (!el) return;

    var data = JSON.parse(el.textContent);
    var Chart = win.Chart;
    var Core = win.PTCore;
    var locale = (win.PT && win.PT.locale) || 'fr';
    var labels = data.labels || {};
    var money = function (value) { return Core.formatMoney(Math.round(Number(value)), 'CDF', locale); };
    var litres = function (value) { return Core.formatNumber(value, locale, 0) + ' L'; };

    Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = MUTED;
    var tooltip = { backgroundColor: SURFACE, titleColor: INK, bodyColor: INK, borderColor: '#DDE1E6', borderWidth: 1, padding: 10, boxPadding: 4, usePointStyle: true };

    // Ventes (barres) et encaissements (ligne) par jour : l'écart entre les deux se voit au premier coup d'œil.
    var days = doc.getElementById('chart-days');
    if (days) {
      new Chart(days, {
        data: {
          labels: data.days.labels.map(function (d) { return shortDay(d, locale); }),
          datasets: [
            { type: 'bar', label: labels.revenue, data: data.days.amounts, backgroundColor: COLORS.accent, borderRadius: 3, maxBarThickness: 26, order: 2 },
            { type: 'line', label: labels.received, data: data.days.received || [], borderColor: COLORS.orange, backgroundColor: COLORS.orange, borderWidth: 2, pointRadius: 2.5, tension: 0.25, order: 1 }
          ]
        },
        options: {
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: Object.assign({}, tooltip, {
              callbacks: {
                title: function (items) { return Core.formatDate(data.days.labels[items[0].dataIndex], locale); },
                label: function (item) { return item.dataset.label + ' : ' + money(item.raw); }
              }
            })
          },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: GRID }, ticks: { callback: function (v) { return compact(v, locale); } } }
          }
        }
      });
    }

    // Répartition des ventes par produit.
    var products = doc.getElementById('chart-products');
    if (products) {
      new Chart(products, {
        type: 'doughnut',
        data: {
          labels: data.products.labels,
          datasets: [{ data: data.products.amounts, backgroundColor: data.products.labels.map(function (_, i) { return productColor(i); }), borderColor: SURFACE, borderWidth: 3, hoverOffset: 4 }]
        },
        options: {
          maintainAspectRatio: false,
          cutout: '64%',
          plugins: {
            legend: { display: false },
            tooltip: Object.assign({}, tooltip, {
              callbacks: {
                label: function (item) { return litres(data.products.litres[item.dataIndex]) + ' · ' + money(item.raw); }
              }
            })
          }
        }
      });
    }
  }

  return { boot: boot, shortDay: shortDay, compact: compact, varianceColor: varianceColor, productColor: productColor, COLORS: COLORS, PALETTE: PALETTE };
});
