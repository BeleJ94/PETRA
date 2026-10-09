/*
 * Petra — définitions des colonnes des tableaux DataTables (server-side). Développé par BELEJ-CONSULTING.
 * Chaque colonne : data (nom côté serveur, sert au tri), title, html(row) pour l'affichage,
 * text(row) pour les exports Excel/PDF, xl pour le type natif Excel. Module pur, testable sous Node.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./pt-core.js'));
  } else {
    root.PTTables = factory(root.PTCore);
  }
})(typeof self !== 'undefined' ? self : this, function (Core) {
  'use strict';

  var esc = Core.escapeHtml;

  /**
   * Tableaux de l'application : { nom: { order, columns } }. Chaque module y ajoute les siens.
   * Types Excel (xl) : int, dec (2 décimales), dec1, qty (unité de la ligne), date, datetime,
   * money ({ type: 'money', currency: 'CDF' } ou currencyKey pour une devise portée par la ligne).
   */
  function definitions(t, locale) {
    var num = function (v, d) { return Core.formatNumber(v, locale, d); };
    var active = function (key) {
      return {
        data: 'is_active', title: t('fields.status'),
        html: function (r) { return Core.badge(Number(r.is_active) ? 'success' : 'neutral', t(Number(r.is_active) ? key + '.active' : key + '.inactive')); },
        text: function (r) { return t(Number(r.is_active) ? key + '.active' : key + '.inactive'); }
      };
    };
    var shiftStatus = { open: t('js.shift_open'), closed: t('js.shift_closed'), validated: t('js.shift_validated') };
    var dipTypes = { opening: t('js.dip_opening'), closing: t('js.dip_closing'), before_delivery: t('js.dip_before_delivery'), after_delivery: t('js.dip_after_delivery'), control: t('js.dip_control') };
    /** Libellé d'un code (statut, action, entité) ; le code lui-même s'il n'a pas de traduction. */
    var coded = function (prefix, value) {
      if (!value) return '';
      var key = prefix + '.' + value;
      var text = t(key);
      return text === key ? value : text;
    };
    var code = { data: 'code', title: t('fields.code'), html: function (r) { return '<strong class="nowrap">' + esc(r.code) + '</strong>'; } };

    return {
      stations: {
        order: [[1, 'asc']],
        columns: [
          code,
          { data: 'name', title: t('fields.name') },
          { data: 'city', title: t('fields.city'), text: function (r) { return r.city || ''; } },
          { data: 'tank_count', title: t('fields.tank_count'), orderable: false, className: 'dt-right', xl: { type: 'int' } },
          { data: 'nozzle_count', title: t('fields.nozzle_count'), orderable: false, className: 'dt-right', xl: { type: 'int' } },
          active('js.station')
        ]
      },

      tanks: {
        order: [[0, 'asc']],
        columns: [
          code,
          { data: 'name', title: t('fields.name') },
          { data: 'product', title: t('fields.product') },
          { data: 'capacity_l', title: t('fields.capacity_l'), className: 'dt-right', xl: { type: 'dec' },
            text: function (r) { return num(r.capacity_l, 2); } },
          { data: 'calibration_count', title: t('fields.calibration_count'), orderable: false, className: 'dt-right', xl: { type: 'int' },
            html: function (r) {
              return Number(r.calibration_count) ? esc(r.calibration_count) : Core.badge('warning', t('js.no_calibration'));
            } },
          { data: 'nozzle_count', title: t('fields.nozzle_count'), orderable: false, className: 'dt-right', xl: { type: 'int' } },
          active('js.item')
        ]
      },

      nozzles: {
        order: [[0, 'asc']],
        columns: [
          code,
          { data: 'name', title: t('fields.name') },
          { data: 'pump', title: t('fields.pump'), text: function (r) { return r.pump || ''; } },
          { data: 'tank', title: t('fields.tank') },
          { data: 'product', title: t('fields.product') },
          active('js.item')
        ]
      },

      shifts: {
        order: [[0, 'desc']],
        columns: [
          { data: 'opened_at', title: t('fields.opened_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.opened_at, locale, true); } },
          { data: 'label', title: t('fields.label'), orderable: false, text: function (r) { return r.label || ''; } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'attendant', title: t('fields.attendant') },
          { data: 'closed_at', title: t('fields.closed_at'), orderable: false, className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.closed_at, locale, true); } },
          { data: 'expected_amount', title: t('fields.expected_amount'), className: 'dt-right', xl: { type: 'money', currency: 'CDF' },
            text: function (r) { return r.expected_amount === null ? '' : Core.formatMoney(r.expected_amount, 'CDF', locale); } },
          { data: 'status', title: t('fields.status'),
            html: function (r) { return Core.badge({ open: 'info', closed: 'warning', validated: 'success' }[r.status], shiftStatus[r.status] || r.status); },
            text: function (r) { return shiftStatus[r.status] || r.status; } }
        ]
      },

      dips: {
        order: [[0, 'desc']],
        columns: [
          { data: 'measured_at', title: t('fields.measured_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.measured_at, locale, true); } },
          { data: 'tank', title: t('fields.tank'), html: function (r) { return '<strong>' + esc(r.tank) + '</strong> <span class="muted">' + esc(r.product) + '</span>'; },
            text: function (r) { return r.tank + ' — ' + r.product; } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'dip_type', title: t('fields.dip_type'), orderable: false, text: function (r) { return dipTypes[r.dip_type] || r.dip_type; } },
          { data: 'depth_cm', title: t('fields.depth_cm'), orderable: false, className: 'dt-right', xl: { type: 'dec1' },
            text: function (r) { return num(r.depth_cm, 1); } },
          { data: 'volume_l', title: t('fields.volume_l'), className: 'dt-right', xl: { type: 'dec' }, text: function (r) { return num(r.volume_l, 2); } },
          { data: 'theoretical_l', title: t('fields.theoretical_l'), orderable: false, className: 'dt-right', xl: { type: 'dec' },
            html: function (r) { return r.theoretical_l === null ? Core.badge('info', t('js.dip_initial')) : esc(num(r.theoretical_l, 2)); },
            text: function (r) { return r.theoretical_l === null ? t('js.dip_initial') : num(r.theoretical_l, 2); } },
          { data: 'variance_l', title: t('fields.variance_l'), className: 'dt-right', xl: { type: 'dec' },
            html: function (r) {
              if (r.variance_l === null) return '';
              return '<span class="' + (Number(r.variance_l) < 0 ? 'overdue' : '') + '">' + esc(num(r.variance_l, 2)) + '</span>';
            },
            text: function (r) { return r.variance_l === null ? '' : num(r.variance_l, 2); } },
          { data: 'variance_amount', title: t('fields.variance_amount'), className: 'dt-right', xl: { type: 'money', currency: 'CDF' },
            text: function (r) { return r.variance_amount === null ? '' : Core.formatMoney(r.variance_amount, 'CDF', locale); } },
          { data: 'author', title: t('fields.author'), orderable: false }
        ]
      },

      deliveries: {
        order: [[0, 'desc']],
        columns: [
          { data: 'delivered_at', title: t('fields.delivered_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.delivered_at, locale, true); } },
          { data: 'supplier_name', title: t('fields.supplier_name'), html: function (r) {
            return '<strong>' + esc(r.supplier_name) + '</strong>' + (r.delivery_note_no ? '<br><span class="muted">' + esc(r.delivery_note_no) + '</span>' : '')
              + (Number(r.has_photo) ? ' <span class="chip">' + esc(t('js.photo')) + '</span>' : '');
          }, text: function (r) { return r.supplier_name + (r.delivery_note_no ? ' — ' + r.delivery_note_no : ''); } },
          { data: 'tank', title: t('fields.tank'), text: function (r) { return r.tank + ' — ' + r.product; } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'invoiced_volume_l', title: t('fields.invoiced_volume_l'), orderable: false, className: 'dt-right', xl: { type: 'dec' },
            text: function (r) { return num(r.invoiced_volume_l, 2); } },
          { data: 'received_volume_l', title: t('fields.received_volume_l'), className: 'dt-right', xl: { type: 'dec' },
            text: function (r) { return num(r.received_volume_l, 2); } },
          { data: 'announced_variance_l', title: t('fields.announced_variance_l'), className: 'dt-right', xl: { type: 'dec' },
            html: function (r) { return r.announced_variance_l === null ? '' : '<span class="' + (Number(r.announced_variance_l) < 0 ? 'overdue' : '') + '">' + esc(num(r.announced_variance_l, 2)) + '</span>'; },
            text: function (r) { return r.announced_variance_l === null ? '' : num(r.announced_variance_l, 2); } },
          { data: 'tank_variance_l', title: t('fields.tank_variance_l'), className: 'dt-right', xl: { type: 'dec' },
            html: function (r) { return r.tank_variance_l === null ? '' : '<span class="' + (Number(r.tank_variance_l) < 0 ? 'overdue' : '') + '">' + esc(num(r.tank_variance_l, 2)) + '</span>'; },
            text: function (r) { return r.tank_variance_l === null ? '' : num(r.tank_variance_l, 2); } },
          { data: 'receiver', title: t('fields.received_by'), orderable: false }
        ]
      },

      alerts: {
        order: [[0, 'desc']],
        columns: [
          { data: 'created_at', title: t('fields.created_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.created_at, locale, true); } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'severity', title: t('fields.severity'),
            html: function (r) { return Core.badge({ info: 'info', warning: 'warning', critical: 'danger' }[r.severity], coded('severity', r.severity)); },
            text: function (r) { return coded('severity', r.severity); } },
          { data: 'message', title: t('fields.message'), orderable: false },
          { data: 'amount', title: t('fields.amount'), className: 'dt-right', xl: { type: 'money', currencyKey: 'currency' },
            text: function (r) { return r.amount === null ? '' : Core.formatMoney(r.amount, r.currency, locale); } },
          { data: 'user_name', title: t('fields.user'), orderable: false, text: function (r) { return r.user_name || ''; } },
          { data: 'status', title: t('fields.status'),
            html: function (r) { return Core.badge({ open: 'danger', acknowledged: 'warning', resolved: 'success' }[r.status], coded('alert_status', r.status)); },
            text: function (r) { return coded('alert_status', r.status); } }
        ]
      },

      audit: {
        order: [[0, 'desc']],
        columns: [
          { data: 'created_at', title: t('fields.created_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.created_at, locale, true); } },
          { data: 'user_name', title: t('fields.user'), text: function (r) { return r.user_name || ''; } },
          { data: 'station', title: t('fields.station'), orderable: false, text: function (r) { return r.station || ''; } },
          { data: 'entity_type', title: t('fields.entity_type'), text: function (r) { return coded('entity', r.entity_type); } },
          { data: 'action', title: t('fields.action'), text: function (r) { return coded('action', r.action); } },
          { data: 'summary', title: t('fields.summary'), orderable: false,
            html: function (r) { return esc(r.summary || '') + diffHtml(r.before_data, r.after_data); },
            text: function (r) { return (r.summary || '') + diffText(r.before_data, r.after_data); } },
          { data: 'ip_address', title: t('fields.ip_address'), orderable: false, text: function (r) { return r.ip_address || ''; } }
        ]
      },

      prices: {
        order: [[0, 'desc']],
        columns: [
          { data: 'effective_at', title: t('fields.effective_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.effective_at, locale, true); } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'product', title: t('fields.product') },
          { data: 'unit_price', title: t('fields.unit_price'), className: 'dt-right', xl: { type: 'money', currencyKey: 'currency' },
            text: function (r) { return Core.formatMoney(r.unit_price, r.currency, locale); } },
          { data: 'author', title: t('fields.author') },
          { data: 'note', title: t('fields.note'), orderable: false, text: function (r) { return r.note || ''; } }
        ]
      },

      rates: {
        order: [[0, 'desc']],
        columns: [
          { data: 'effective_at', title: t('fields.effective_at'), className: 'nowrap', xl: { type: 'datetime' },
            text: function (r) { return Core.formatDate(r.effective_at, locale, true); } },
          { data: 'station', title: t('fields.station'), orderable: false },
          { data: 'rate', title: t('fields.rate'), className: 'dt-right', xl: { type: 'money', currency: 'CDF' },
            text: function (r) { return Core.formatMoney(r.rate, 'CDF', locale); } },
          { data: 'author', title: t('fields.author') },
          { data: 'note', title: t('fields.note'), orderable: false, text: function (r) { return r.note || ''; } }
        ]
      },

      calibrations: {
        order: [[0, 'asc']],
        columns: [
          { data: 'depth_cm', title: t('fields.depth_cm'), className: 'dt-right', xl: { type: 'dec1' },
            text: function (r) { return num(r.depth_cm, 1); } },
          { data: 'volume_l', title: t('fields.volume_l'), className: 'dt-right', xl: { type: 'dec' },
            text: function (r) { return num(r.volume_l, 2); } }
        ]
      }
    };
  }

  /** Date / date-heure SQL → Date UTC (Excel l'affiche telle quelle, sans décalage de fuseau). */
  function sqlDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(v || ''));
    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0))) : null;
  }

  function unitFormat(base, unit) {
    return unit ? base + '" ' + String(unit).replace(/"/g, '') + '"' : base;
  }

  /**
   * Valeur native d'une cellule pour Excel : { v: nombre | Date | null, fmt: code de format } ;
   * null si la colonne est textuelle (l'export utilise alors le texte affiché).
   */
  function cellValue(column, row, locale) {
    var xl = column.xl;
    if (!xl) return null;
    var raw = row[column.data];
    if (xl.type === 'date' || xl.type === 'datetime') {
      var d = sqlDate(raw);
      var dfmt = locale === 'en' ? 'yyyy-mm-dd' : 'dd/mm/yyyy';
      return { v: d, fmt: xl.type === 'datetime' ? dfmt + ' hh:mm' : dfmt };
    }
    if (raw === null || raw === undefined || raw === '' || !isFinite(Number(raw))) return { v: null, fmt: null };
    var n = Number(raw);
    if (xl.blankZero && n === 0) return { v: null, fmt: null };
    switch (xl.type) {
      case 'money': {
        // Le serveur envoie la plus petite unité (CDF entier, USD en cents) ; Excel reçoit l'unité affichée.
        var currency = String(xl.currencyKey ? row[xl.currencyKey] : xl.currency || '').toUpperCase();
        var cents = currency === 'USD';
        return { v: cents ? n / 100 : n, fmt: unitFormat(cents ? '#,##0.00' : '#,##0', Core.currencySymbol(currency)) };
      }
      case 'qty': return { v: n, fmt: unitFormat(Math.round(n) === n ? '#,##0' : '#,##0.00', row.unit) };
      case 'dec': return { v: n, fmt: unitFormat('#,##0.00', xl.unit) };
      case 'dec1': return { v: n, fmt: unitFormat('#,##0.0', xl.unit) };
      default: return { v: n, fmt: unitFormat('#,##0', xl.unit) };
    }
  }

  function parseJson(value) {
    if (!value) return null;
    try { return JSON.parse(value); } catch (e) { return null; }
  }

  function scalar(v) {
    if (v === null || v === undefined || v === '') return '∅';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
  }

  /** Liste « champ : avant → après » à partir des JSON du journal. */
  function diffEntries(beforeJson, afterJson) {
    var before = parseJson(beforeJson);
    var after = parseJson(afterJson);
    if (!after || !before) return [];
    return Object.keys(after).map(function (key) {
      return { key: key, before: scalar(before[key]), after: scalar(after[key]) };
    });
  }

  function diffHtml(beforeJson, afterJson) {
    var entries = diffEntries(beforeJson, afterJson);
    if (!entries.length) return '';
    return '<div class="diff">' + entries.map(function (e) {
      return '<div>' + esc(e.key) + ' : <del>' + esc(e.before) + '</del> → <ins>' + esc(e.after) + '</ins></div>';
    }).join('') + '</div>';
  }

  function diffText(beforeJson, afterJson) {
    var entries = diffEntries(beforeJson, afterJson);
    return entries.length ? ' [' + entries.map(function (e) { return e.key + ': ' + e.before + ' → ' + e.after; }).join('; ') + ']' : '';
  }

  /** Valeur texte d'une cellule (exports), à partir de la définition de colonne. */
  function cellText(column, row) {
    if (typeof column.text === 'function') return column.text(row);
    var v = row[column.data];
    return v === null || v === undefined ? '' : String(v);
  }

  /** Valeur HTML d'une cellule (affichage), toujours échappée. */
  function cellHtml(column, row) {
    if (typeof column.html === 'function') return column.html(row);
    return esc(cellText(column, row));
  }

  return {
    cellValue: cellValue,
    definitions: definitions,
    diffEntries: diffEntries,
    diffHtml: diffHtml,
    diffText: diffText,
    cellText: cellText,
    cellHtml: cellHtml
  };
});
