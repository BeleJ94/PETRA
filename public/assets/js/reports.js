/*
 * Petra — développé par BELEJ-CONSULTING.
 * Rapports : filtres, impression et exports PDF (pdfmake) / Excel (ExcelJS).
 * Le rapport est lu dans le bloc JSON #report-data produit par le serveur (CSP stricte) : l'écran,
 * l'impression et les exports portent les mêmes chiffres, sans recalcul dans le navigateur.
 */
(function (win) {
  'use strict';

  var doc = win.document;
  var PT = win.PT;
  var Exporter = win.PTExport;
  if (!doc || !PT || !Exporter) return;

  // Filtres : seuls les champs du type de rapport choisi sont affichés et envoyés.
  var form = doc.querySelector('[data-report-filters]');
  var type = form && form.querySelector('[data-report-type]');
  function syncFields() {
    Array.prototype.forEach.call(form.querySelectorAll('[data-for-type]'), function (field) {
      var active = field.getAttribute('data-for-type').split(' ').indexOf(type.value) !== -1;
      field.hidden = !active;
      Array.prototype.forEach.call(field.querySelectorAll('input, select'), function (input) { input.disabled = !active; });
    });
  }
  if (type) {
    type.addEventListener('change', syncFields);
    syncFields();
    // Changer de journée recharge la liste des postes proposés.
    var date = form.querySelector('[name="date"]');
    if (date) {
      date.addEventListener('change', function () {
        if (type.value !== 'shift') return;
        var shift = form.querySelector('[name="shift"]');
        if (shift) shift.value = '';
        form.submit();
      });
    }
  }

  var el = doc.getElementById('report-data');
  if (!el) return;
  var report;
  try { report = JSON.parse(el.textContent); } catch (e) { return; }

  Array.prototype.forEach.call(doc.querySelectorAll('[data-report-print]'), function (button) {
    button.addEventListener('click', function () { win.print(); });
  });

  Array.prototype.forEach.call(doc.querySelectorAll('[data-report-export]'), function (button) {
    button.addEventListener('click', function () {
      button.disabled = true;
      var job = button.getAttribute('data-report-export') === 'excel'
        ? PT.loadScript(PT.config.assets.exceljs).then(function () { return Exporter.exportReportExcel(win.ExcelJS, report); })
        : PT.loadPdfMake().then(function (pdfMake) { Exporter.exportReportPdf(pdfMake, report); });
      job.catch(function () { if (PT.alertError) PT.alertError(); })
        .then(function () { button.disabled = false; });
    });
  });
})(window);
