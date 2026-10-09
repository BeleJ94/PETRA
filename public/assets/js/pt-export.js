/*
 * Petra — exports Excel (ExcelJS) et PDF (pdfmake). Développé par BELEJ-CONSULTING.
 * Les constructions de documents sont pures (testées sous Node) ; seules exportExcel/exportPdf
 * touchent au navigateur. Tout export porte la mention Core.CREDIT en pied.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./pt-core.js'), require('./pt-tables.js'));
  } else {
    root.PTExport = factory(root.PTCore, root.PTTables);
  }
})(typeof self !== 'undefined' ? self : this, function (Core, Tables) {
  'use strict';

  var ACCENT = '#1F4E79';

  /**
   * { headers, body, cells } à partir des colonnes et des lignes JSON.
   * body : textes affichés (PDF) ; cells : valeurs natives pour Excel ({v, fmt} ou null = texte).
   */
  function tableData(columns, rows, locale) {
    return {
      headers: columns.map(function (c) { return c.title; }),
      body: rows.map(function (row) {
        return columns.map(function (c) { return Tables.cellText(c, row); });
      }),
      cells: rows.map(function (row) {
        return columns.map(function (c) { return Tables.cellValue(c, row, locale); });
      }),
      numeric: columns.map(function (c) { return !!c.xl; })
    };
  }

  /** Mention obligatoire en pied de page imprimé de chaque feuille, avec la pagination. */
  function creditSheet(sheet) {
    sheet.headerFooter = { oddFooter: '&L' + Core.CREDIT.replace(/&/g, '&&') + '&R&P / &N' };
    return sheet;
  }

  /** Classeur ExcelJS : en-tête à la couleur d'accent, filtre auto, volets figés, largeurs ajustées. */
  function buildWorkbook(ExcelJS, title, data, meta) {
    var wb = new ExcelJS.Workbook();
    wb.creator = Core.CREDIT;
    wb.company = 'BELEJ-CONSULTING';
    wb.created = meta && meta.date ? meta.date : new Date();
    var sheet = creditSheet(wb.addWorksheet(String(title).slice(0, 31) || 'Export', {
      views: [{ state: 'frozen', ySplit: 1 }]
    }));
    sheet.addRow(data.headers);
    data.body.forEach(function (r, i) {
      var row = sheet.addRow(r.map(function (text, c) {
        var native = data.cells && data.cells[i][c];
        return native ? native.v : text;
      }));
      (data.cells ? data.cells[i] : []).forEach(function (native, c) {
        if (!native) return;
        var cell = row.getCell(c + 1);
        if (native.fmt) cell.numFmt = native.fmt;
        cell.alignment = { horizontal: 'right' };
      });
    });
    var header = sheet.getRow(1);
    header.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    header.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E79' } };
    header.alignment = { vertical: 'middle' };
    sheet.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: Math.max(1, data.headers.length) } };
    sheet.columns.forEach(function (col, i) {
      var max = String(data.headers[i] || '').length;
      data.body.forEach(function (r) { max = Math.max(max, String(r[i] || '').length); });
      col.width = Math.min(60, Math.max(10, max + 2));
    });
    return wb;
  }

  function pdfStyles() {
    return {
      title: { fontSize: 15, bold: true, color: ACCENT, margin: [0, 0, 0, 4] },
      meta: { fontSize: 8, color: '#5B6573', margin: [0, 0, 0, 10] },
      th: { bold: true, color: '#FFFFFF', fillColor: ACCENT, fontSize: 8 },
      h2: { fontSize: 11, bold: true, color: ACCENT, margin: [0, 12, 0, 6] },
      label: { color: '#5B6573' }
    };
  }

  /** Pied de page pdfmake commun : mention obligatoire à gauche, pagination à droite. */
  function pdfFooter(current, count) {
    return {
      columns: [
        { text: Core.CREDIT, alignment: 'left' },
        { text: current + ' / ' + count, alignment: 'right' }
      ],
      fontSize: 7, color: '#5B6573', margin: [28, 8, 28, 0]
    };
  }

  /** Document pdfmake : tableau paysage avec en-tête répété et pagination. */
  function listDocDefinition(title, data, meta) {
    var widths = data.headers.map(function () { return '*'; });
    return {
      pageOrientation: 'landscape',
      pageSize: 'A4',
      pageMargins: [28, 32, 28, 36],
      defaultStyle: { fontSize: 8 },
      styles: pdfStyles(),
      info: { title: title, author: 'BELEJ-CONSULTING', creator: 'Petra' },
      footer: pdfFooter,
      content: [
        { text: title, style: 'title' },
        { text: (meta && meta.subtitle) || '', style: 'meta' },
        {
          table: {
            headerRows: 1,
            widths: widths,
            body: [data.headers.map(function (h) { return { text: h, style: 'th' }; })].concat(data.body)
          },
          layout: {
            hLineColor: function () { return '#DDE1E6'; },
            vLineWidth: function () { return 0; },
            fillColor: function (rowIndex) { return rowIndex > 0 && rowIndex % 2 === 0 ? '#F5F6F8' : null; }
          }
        }
      ]
    };
  }

  /**
   * La police Roboto embarquée par pdfmake ne contient pas les espaces fines (U+202F séparateur de milliers
   * français, U+2009) : elles s'affichent en caractères parasites. On les remplace par l'espace insécable U+00A0
   * dans tout le document (textes, cellules, pieds de page générés).
   */
  var PDF_UNSUPPORTED_SPACES = /[   ]/g;
  function pdfSafe(node) {
    if (typeof node === 'string') return node.replace(PDF_UNSUPPORTED_SPACES, ' ');
    if (typeof node === 'function') {
      return function () { return pdfSafe(node.apply(this, arguments)); };
    }
    if (Array.isArray(node)) return node.map(pdfSafe);
    if (node && typeof node === 'object') {
      var out = {};
      Object.keys(node).forEach(function (k) {
        // Images (data URL) et couleurs : inchangées
        out[k] = k === 'image' ? node[k] : pdfSafe(node[k]);
      });
      return out;
    }
    return node;
  }

  /** Génère et télécharge un PDF après normalisation des caractères non pris en charge par la police. */
  function downloadPdf(pdfMake, doc, filename) {
    pdfMake.createPdf(pdfSafe(doc)).download(filename);
  }

  // --- Rapports (par poste, journalier, mensuel) ----------------------------
  // Un rapport = { title, station, period, generated_at, author, summary: [[libellé, cellule]], sections: [{ title, columns, rows, foot, empty, note }] }.
  // Une cellule est un texte ou { t: texte affiché, v: valeur native, f: 'CDF' | 'USD' | 'L' | 'N' }.

  var REPORT_FORMATS = { CDF: '#,##0" FC"', USD: '#,##0.00" $"', L: '#,##0.00" L"', N: '#,##0.00' };

  function cellText(cell) {
    if (cell === null || cell === undefined) return '';
    return typeof cell === 'object' ? String(cell.t === undefined ? '' : cell.t) : String(cell);
  }

  /** Valeur numérique et format Excel d'une cellule, ou null pour un texte. */
  function cellNative(cell) {
    if (!cell || typeof cell !== 'object' || cell.v === null || cell.v === undefined || !REPORT_FORMATS[cell.f]) return null;
    var value = Number(cell.v);
    return isFinite(value) ? { v: value, fmt: REPORT_FORMATS[cell.f] } : null;
  }

  function isNumericColumn(column) {
    return Array.isArray(column);
  }

  function columnTitle(column) {
    return Array.isArray(column) ? column[0] : column;
  }

  /** Ligne d'identification portée par chaque export : station, période, date de génération, auteur. */
  function reportSubtitle(report) {
    var labels = report.labels || {};
    return [
      (labels.station || 'Station') + ' : ' + report.station,
      (labels.period || 'Période') + ' : ' + report.period,
      (labels.generated || 'Généré le') + ' ' + report.generated_at,
      (labels.author || 'Auteur') + ' : ' + report.author
    ].join(' · ');
  }

  /** Classeur ExcelJS d'un rapport : une feuille, en-tête d'identification, puis une table par section. */
  function buildReportWorkbook(ExcelJS, report) {
    var labels = report.labels || {};
    var wb = new ExcelJS.Workbook();
    wb.creator = Core.CREDIT;
    wb.company = 'BELEJ-CONSULTING';
    wb.created = new Date();
    var sheet = creditSheet(wb.addWorksheet(String(report.title).replace(/[\\\/?*\[\]:]/g, ' ').slice(0, 31) || 'Rapport'));
    var widths = [];
    function add(values, style) {
      var row = sheet.addRow(values.map(function (cell) {
        var native = cellNative(cell);
        return native ? native.v : cellText(cell);
      }));
      values.forEach(function (cell, i) {
        var native = cellNative(cell);
        widths[i] = Math.max(widths[i] || 0, style === 'wide' ? 0 : cellText(cell).length);
        if (native) {
          row.getCell(i + 1).numFmt = native.fmt;
          row.getCell(i + 1).alignment = { horizontal: 'right' };
        }
      });
      if (style === 'title') row.font = { bold: true, size: 14, color: { argb: 'FF1F4E79' } };
      if (style === 'section') row.font = { bold: true, size: 11, color: { argb: 'FF1F4E79' } };
      if (style === 'foot') row.font = { bold: true };
      if (style === 'head') {
        row.font = { bold: true, color: { argb: 'FFFFFFFF' } };
        row.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E79' } };
      }
      return row;
    }

    add([report.title], 'title');
    add([labels.station || 'Station', report.station], 'meta');
    add([labels.period || 'Période', report.period], 'meta');
    add([labels.generated || 'Généré le', report.generated_at], 'meta');
    add([labels.author || 'Auteur', report.author], 'meta');
    add([Core.CREDIT], 'wide');

    if ((report.summary || []).length) {
      add([]);
      add([labels.figures || 'Chiffres clés'], 'section');
      report.summary.forEach(function (pair) { add([pair[0], pair[1]]); });
    }
    (report.sections || []).forEach(function (section) {
      add([]);
      add([section.title], 'wide').font = { bold: true, size: 11, color: { argb: 'FF1F4E79' } };
      if (!section.rows.length) {
        add([section.empty || ''], 'wide');
        return;
      }
      add(section.columns.map(columnTitle), 'head');
      section.rows.forEach(function (r) { add(r); });
      if (section.foot) add(section.foot, 'foot');
      if (section.note) add([section.note], 'wide');
    });
    sheet.columns.forEach(function (col, i) { col.width = Math.min(60, Math.max(12, (widths[i] || 0) + 2)); });
    return wb;
  }

  /** Document pdfmake d'un rapport : identification en tête de chaque page, mention et pagination en pied. */
  function reportDocDefinition(report) {
    var labels = report.labels || {};
    var subtitle = reportSubtitle(report);
    var layout = {
      hLineColor: function () { return '#DDE1E6'; },
      vLineWidth: function () { return 0; },
      fillColor: function (rowIndex) { return rowIndex > 0 && rowIndex % 2 === 0 ? '#F5F6F8' : null; }
    };
    var content = [
      { text: report.title, style: 'title' },
      {
        style: 'meta',
        table: {
          widths: ['auto', '*', 'auto', '*'],
          body: [
            [{ text: labels.station || 'Station', style: 'label' }, report.station, { text: labels.period || 'Période', style: 'label' }, report.period],
            [{ text: labels.generated || 'Généré le', style: 'label' }, report.generated_at, { text: labels.author || 'Auteur', style: 'label' }, report.author]
          ]
        },
        layout: 'noBorders'
      }
    ];
    if ((report.summary || []).length) {
      content.push({ text: labels.figures || 'Chiffres clés', style: 'h2' });
      content.push({
        table: {
          widths: report.summary.map(function () { return '*'; }),
          body: [
            report.summary.map(function (pair) { return { text: pair[0], style: 'label', fontSize: 7 }; }),
            report.summary.map(function (pair) { return { text: cellText(pair[1]), bold: true, fontSize: 10 }; })
          ]
        },
        layout: 'noBorders'
      });
    }
    (report.sections || []).forEach(function (section) {
      content.push({ text: section.title, style: 'h2' });
      if (!section.rows.length) {
        content.push({ text: section.empty || '', style: 'label' });
        return;
      }
      var numeric = section.columns.map(isNumericColumn);
      var line = function (row, bold) {
        return row.map(function (cell, i) {
          return { text: cellText(cell), alignment: numeric[i] ? 'right' : 'left', bold: !!bold, noWrap: !!numeric[i] };
        });
      };
      var body = [section.columns.map(function (c, i) { return { text: columnTitle(c), style: 'th', alignment: numeric[i] ? 'right' : 'left' }; })]
        .concat(section.rows.map(function (r) { return line(r, false); }));
      if (section.foot) body.push(line(section.foot, true));
      content.push({
        table: { headerRows: 1, widths: section.columns.map(function (c, i) { return numeric[i] ? 'auto' : '*'; }), body: body },
        layout: layout
      });
      if (section.note) content.push({ text: section.note, style: 'label', fontSize: 7, margin: [0, 4, 0, 0] });
    });

    return {
      pageOrientation: 'landscape',
      pageSize: 'A4',
      pageMargins: [28, 40, 28, 40],
      defaultStyle: { fontSize: 8 },
      styles: pdfStyles(),
      info: { title: report.title + ' — ' + report.station + ' — ' + report.period, author: report.author, creator: Core.CREDIT, producer: 'BELEJ-CONSULTING' },
      header: function () {
        return { text: report.title + ' · ' + subtitle, fontSize: 7, color: '#5B6573', margin: [28, 16, 28, 0] };
      },
      footer: function (current, count) {
        return {
          columns: [
            { text: Core.CREDIT, alignment: 'left' },
            { text: report.station + ' · ' + report.period, alignment: 'center' },
            { text: current + ' / ' + count, alignment: 'right' }
          ],
          fontSize: 7, color: '#5B6573', margin: [28, 12, 28, 0]
        };
      },
      content: content
    };
  }

  // --- Navigateur -----------------------------------------------------------

  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  function exportExcel(ExcelJS, title, data, meta) {
    return buildWorkbook(ExcelJS, title, data, meta).xlsx.writeBuffer().then(function (buffer) {
      saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        Core.exportFileName(title, 'xlsx'));
    });
  }

  function exportReportExcel(ExcelJS, report) {
    return buildReportWorkbook(ExcelJS, report).xlsx.writeBuffer().then(function (buffer) {
      saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        Core.exportFileName(report.file || report.title, 'xlsx'));
    });
  }

  function exportReportPdf(pdfMake, report) {
    downloadPdf(pdfMake, reportDocDefinition(report), Core.exportFileName(report.file || report.title, 'pdf'));
  }

  function exportPdf(pdfMake, title, data, meta) {
    downloadPdf(pdfMake, listDocDefinition(title, data, meta), Core.exportFileName(title, 'pdf'));
  }

  return {
    tableData: tableData,
    creditSheet: creditSheet,
    buildWorkbook: buildWorkbook,
    pdfStyles: pdfStyles,
    pdfFooter: pdfFooter,
    listDocDefinition: listDocDefinition,
    exportExcel: exportExcel,
    exportPdf: exportPdf,
    cellText: cellText,
    cellNative: cellNative,
    reportSubtitle: reportSubtitle,
    buildReportWorkbook: buildReportWorkbook,
    reportDocDefinition: reportDocDefinition,
    exportReportExcel: exportReportExcel,
    exportReportPdf: exportReportPdf,
    pdfSafe: pdfSafe,
    downloadPdf: downloadPdf,
    saveBlob: saveBlob
  };
});
