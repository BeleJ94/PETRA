/*
 * Petra — développé par BELEJ-CONSULTING.
 * Écrans en cartes (postes, stock) : détail, historique et saisies s'ouvrent dans une fenêtre, à partir d'un
 * fragment HTML rendu par le serveur (data-modal-url, ou les attributs propres aux postes). Une saisie passe
 * par une étape de vérification, part sans recharger la page, puis seules les cartes sont rafraîchies.
 * Aucun calcul n'est fait dans le navigateur : la vérification reprend les valeurs saisies et les repères
 * fournis par le serveur.
 */
(function (win) {
  'use strict';

  var doc = win.document;
  var Swal = win.Swal;
  var PT = win.PT;
  if (!doc || !Swal || !PT) return;
  var t = PT.t;
  var esc = win.PTCore ? win.PTCore.escapeHtml : function (s) { return String(s); };

  /** Message bref en haut à droite, qui s'efface seul. */
  var toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3500, timerProgressBar: true, customClass: { popup: 'pt-toast' } });

  function icons(root) {
    if (win.lucide) win.lucide.createIcons(root ? { root: root } : undefined);
  }

  /** Onglets d'une fenêtre : un seul panneau visible à la fois. */
  function bindTabs(container) {
    var tabs = container.querySelectorAll('[data-tab]');
    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener('click', function () {
        Array.prototype.forEach.call(tabs, function (other) { other.setAttribute('aria-selected', other === tab ? 'true' : 'false'); });
        Array.prototype.forEach.call(container.querySelectorAll('[data-panel]'), function (panel) {
          panel.hidden = panel.getAttribute('data-panel') !== tab.getAttribute('data-tab');
        });
      });
    });
  }

  /** Valeur de tri d'une cellule : montants et volumes en nombre, dates JJ/MM/AAAA en AAAAMMJJ ; sinon le texte. */
  function sortKey(text, numeric) {
    var value = String(text || '').trim();
    var date = /^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/.exec(value);
    if (date) return date[3] + date[2] + date[1] + (date[4] || '00') + (date[5] || '00');
    if (!numeric) return null;
    // « 1 530 000 FC », « -15 000 FC », « 100,00 $ », « 49,5 L » : espaces (fines ou non) retirés, virgule décimale.
    var digits = value.replace(/[\s  ]/g, '').replace(',', '.').replace(/[^0-9.+-]/g, '');
    var number = parseFloat(digits);
    return isNaN(number) ? '0' : String(number);
  }

  /** Lignes d'un tableau telles qu'affichées (recherche et tri appliqués), pour l'export. */
  function tableData(table, api) {
    var text = function (cell) { return cell.textContent.replace(/\s+/g, ' ').trim(); };
    var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), text);
    var body = Array.prototype.map.call(api.rows({ search: 'applied', order: 'applied' }).nodes(), function (row) {
      return Array.prototype.map.call(row.cells, text);
    });
    var count = body.length;
    var foot = table.querySelector('tfoot tr');
    if (foot) body.push(Array.prototype.map.call(foot.cells, text));
    return { headers: headers, body: body, count: count };
  }

  /**
   * Tableaux d'une fenêtre : DataTables compact (recherche, tri, pagination) et exports Excel et PDF.
   * Les lignes viennent du serveur, déjà calculées ; l'export reprend ce qui est affiché.
   */
  function bindTables(container) {
    var $ = win.jQuery;
    var Exporter = win.PTExport;
    if (!$ || !$.fn.DataTable) return;
    var modalTitle = (container.querySelector('.shift-modal__head h2') || {}).textContent || '';
    var modalMeta = (container.querySelector('.shift-modal__meta') || {}).textContent || '';
    Array.prototype.forEach.call(container.querySelectorAll('table.table'), function (table) {
      if (!table.tBodies.length || !table.tBodies[0].rows.length) return;
      var numeric = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.classList.contains('num'); });
      Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
        Array.prototype.forEach.call(row.cells, function (cell, i) {
          var key = sortKey(cell.textContent, numeric[i]);
          if (key !== null) cell.setAttribute('data-order', key);
          // Dates, montants et libellés courts (statut, gravité) restent sur une ligne ; seuls les textes longs se replient.
          if (key !== null || cell.textContent.trim().length <= 18) cell.classList.add('nowrap');
        });
      });
      // Titre de l'export : celui de la fenêtre, précisé par le titre de la section quand il y en a un.
      var wrap = table.closest('.table-wrap') || table;
      var heading = wrap.previousElementSibling;
      var section = heading && /^H[34]$/.test(heading.tagName) ? heading.textContent.trim() : '';
      var title = (modalTitle.trim() + (section ? ' — ' + section : '')) || 'Petra';
      var rows = table.tBodies[0].rows.length;

      table.classList.add('compact');
      var api = $(table).DataTable({
        paging: rows > 10,
        pageLength: 10,
        lengthChange: false,
        searching: rows > 5,
        info: rows > 10,
        order: [],
        autoWidth: false,
        language: PT.dtLanguage ? PT.dtLanguage() : {},
        layout: { topStart: null, topEnd: rows > 5 ? 'search' : null, bottomStart: 'info', bottomEnd: 'paging' }
      });

      if (!Exporter) return;
      var tools = doc.createElement('div');
      tools.className = 'modal-table__tools';
      tools.innerHTML = '<span class="modal-table__count">' + esc(t('js.rows', { count: rows })) + '</span>'
        + '<button type="button" class="btn btn--sm" data-modal-export="excel"><i data-lucide="file-spreadsheet"></i>' + esc(t('js.export_excel')) + '</button>'
        + '<button type="button" class="btn btn--sm" data-modal-export="pdf"><i data-lucide="file-text"></i>' + esc(t('js.export_pdf')) + '</button>';
      var host = table.closest('.dt-container') || wrap;
      host.parentNode.insertBefore(tools, host);
      icons(tools);
      tools.addEventListener('click', function (event) {
        var button = event.target.closest('[data-modal-export]');
        if (!button) return;
        button.disabled = true;
        var data = tableData(table, api);
        var meta = { subtitle: [modalMeta.replace(/\s+/g, ' ').trim(), t('js.export_meta', { count: data.count, date: win.PTCore.formatDate(new Date().toISOString().slice(0, 10), PT.locale) })].filter(Boolean).join(' · ') };
        var job = button.getAttribute('data-modal-export') === 'excel'
          ? PT.loadScript(PT.config.assets.exceljs).then(function () { return Exporter.exportExcel(win.ExcelJS, title, data, meta); })
          : PT.loadPdfMake().then(function (pdfMake) { Exporter.exportPdf(pdfMake, title, data, meta); });
        job.catch(function () { if (PT.alertError) PT.alertError(); }).then(function () { button.disabled = false; });
      });
    });
  }

  /** Recharge les cartes et le bandeau de la journée, sans recharger la page. */
  function refreshBoard(url) {
    var live = doc.querySelector('[data-live]');
    if (!live) return Promise.resolve();
    live.classList.add('is-loading');
    return fetch(url || win.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (response) { return response.ok ? response.text() : Promise.reject(); })
      .then(function (html) {
        var fresh = new DOMParser().parseFromString(html, 'text/html');
        var next = fresh.querySelector('[data-live]');
        if (!next) return;
        live.innerHTML = next.innerHTML;
        // Le menu suit : raccourci d'ouverture et pastilles.
        var menu = doc.getElementById('sidebar');
        var freshMenu = fresh.getElementById('sidebar');
        if (menu && freshMenu) menu.innerHTML = freshMenu.innerHTML;
        icons();
        doc.dispatchEvent(new CustomEvent('pt:live-updated'));
      })
      .catch(function () { win.location.href = url || win.location.href; })
      .then(function () { live.classList.remove('is-loading'); });
  }

  /** Valeur lisible d'un champ pour l'étape de vérification ; null si rien n'a été saisi. */
  function fieldValue(field) {
    if (field.type === 'checkbox') return field.checked ? t('js.yes') : null;
    if (field.tagName === 'SELECT') {
      var option = field.options[field.selectedIndex];
      return option && option.value !== '' ? option.textContent.trim() : null;
    }
    var value = String(field.value || '').trim();
    return value === '' ? null : value;
  }

  /** Ouverture d'un poste : un distributeur coché est confié au pompiste ; les index des autres ne sont pas demandés. */
  function bindPumps(container) {
    Array.prototype.forEach.call(container.querySelectorAll('[data-pump-block]'), function (block) {
      var check = block.querySelector('[data-pump-check]');
      var sync = function () {
        block.classList.toggle('is-selected', check.checked);
        Array.prototype.forEach.call(block.querySelectorAll('[data-pump-input]'), function (input) {
          input.disabled = !check.checked;
          input.required = check.checked;
        });
      };
      check.addEventListener('change', sync);
      sync();
    });
  }

  /** Lignes de vérification : repères du serveur, valeurs reportées, puis valeurs saisies. */
  function recapRows(form) {
    var rows = [];
    try { rows = JSON.parse(form.getAttribute('data-context') || '[]'); } catch (e) { rows = []; }
    Array.prototype.forEach.call(form.querySelectorAll('[data-recap-static], [data-recap]'), function (el) {
      // Distributeur non coché : ses pistolets ne font pas partie du poste, donc pas de la vérification.
      var block = el.closest('[data-pump-block]');
      if (block && !block.querySelector('[data-pump-check]').checked) return;
      if (el.hasAttribute('data-recap-static')) {
        rows.push([el.getAttribute('data-recap-static'), el.getAttribute('data-recap-value'), false]);
        return;
      }
      var value = fieldValue(el);
      if (value !== null) rows.push([el.getAttribute('data-recap'), value, true]);
    });
    return rows;
  }

  function showErrors(form, payload) {
    var box = form.querySelector('.form-errors');
    var errors = (payload && payload.errors) || {};
    var messages = Object.keys(errors).map(function (key) { return errors[key]; });
    if (!messages.length) messages = [(payload && payload.error) || t('js.error_generic')];
    Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });
    Object.keys(errors).forEach(function (key) {
      var field = form.querySelector('[data-error-key="' + key + '"]') || form.querySelector('[name="' + key + '"]');
      if (field) field.setAttribute('aria-invalid', 'true');
    });
    if (box) {
      box.innerHTML = '<i data-lucide="alert-triangle"></i><div>' + messages.map(esc).join('<br>') + '</div>';
      box.hidden = false;
      icons(box);
    }
    var first = form.querySelector('[aria-invalid="true"]');
    if (first) first.focus();
  }

  /** Formulaire en deux temps dans la même fenêtre : saisie, puis vérification avant enregistrement. */
  function bindForm(container) {
    var form = container.querySelector('form[data-ajax-form]');
    Array.prototype.forEach.call(container.querySelectorAll('[data-modal-close]'), function (button) {
      button.addEventListener('click', function () { Swal.close(); });
    });
    if (!form) return;
    var first = form.querySelector('input:not([type=hidden]):not([type=checkbox]), select');
    if (first) first.focus();

    var recap = doc.createElement('div');
    recap.className = 'recap';
    recap.hidden = true;
    form.parentNode.appendChild(recap);

    function back() {
      recap.hidden = true;
      form.hidden = false;
    }

    function send() {
      var confirm = recap.querySelector('[data-recap-confirm]');
      confirm.disabled = true;
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (payload) { return { ok: response.ok, payload: payload }; });
        })
        .then(function (result) {
          if (!result.ok) {
            back();
            showErrors(form, result.payload);
            return;
          }
          Swal.close();
          toast.fire({ icon: 'success', title: result.payload.message || t('js.saved') });
          return refreshBoard();
        })
        .catch(function () {
          back();
          showErrors(form, null);
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var rows = recapRows(form);
      var warning = form.getAttribute('data-recap-warning');
      recap.innerHTML = '<h3 class="recap__title">' + esc(form.getAttribute('data-recap-title') || t('js.recap_title')) + '</h3>'
        + '<dl class="recap__rows">' + rows.map(function (row) {
          return '<div' + (row[2] ? ' class="is-entered"' : '') + '><dt>' + esc(row[0]) + '</dt><dd>' + esc(row[1]) + '</dd></div>';
        }).join('') + '</dl>'
        + (warning ? '<p class="recap__warning"><i data-lucide="lock"></i>' + esc(warning) + '</p>' : '')
        + '<div class="shift-form__actions"><button type="button" class="btn" data-recap-back><i data-lucide="pencil"></i>' + esc(t('js.recap_edit')) + '</button>'
        + '<button type="button" class="btn btn--primary" data-recap-confirm><i data-lucide="check"></i>' + esc(form.getAttribute('data-submit-label') || t('js.confirm')) + '</button></div>';
      icons(recap);
      recap.querySelector('[data-recap-back]').addEventListener('click', back);
      recap.querySelector('[data-recap-confirm]').addEventListener('click', send);
      form.hidden = true;
      recap.hidden = false;
      recap.querySelector('[data-recap-confirm]').focus();
    });
  }

  /** Ouvre une fenêtre dont le contenu vient de l'adresse donnée. */
  function openFragment(url, width) {
    Swal.fire({
      html: '<p class="muted">' + esc(t('js.loading')) + '</p>',
      width: width,
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: 'shift-popup' },
      didOpen: function () {
        fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
          .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
          })
          .then(function (html) {
            var container = Swal.getHtmlContainer();
            if (!container) return;
            container.innerHTML = html;
            icons(container);
            bindTabs(container);
            bindPumps(container);
            bindForm(container);
            bindTables(container);
          })
          .catch(function () {
            Swal.close();
            if (PT.alertError) PT.alertError();
          });
      }
    });
  }

  /** Change de journée sans recharger la page ; l'adresse suit, pour le retour arrière et le partage du lien. */
  function goToDay(url) {
    win.history.pushState({ shifts: true }, '', url);
    return refreshBoard(url);
  }
  win.addEventListener('popstate', function () { refreshBoard(win.location.href); });
  doc.addEventListener('change', function (event) {
    var input = event.target.closest('[data-day-input]');
    if (!input || !input.value) return;
    goToDay(input.getAttribute('data-day-url') + '?date=' + encodeURIComponent(input.value));
  });

  doc.addEventListener('click', function (event) {
    var day = event.target.closest('[data-day-link]');
    if (day && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      event.preventDefault();
      goToDay(day.getAttribute('href'));
      return;
    }
    var generic = event.target.closest('[data-modal-url]');
    if (generic) {
      event.preventDefault();
      openFragment(generic.getAttribute('data-modal-url'), Number(generic.getAttribute('data-modal-width')) || 720);
      return;
    }
    var form = event.target.closest('[data-shift-form]');
    if (form) {
      event.preventDefault();
      openFragment(form.getAttribute('data-shift-form'), 760);
      return;
    }
    var detail = event.target.closest('[data-shift-detail]');
    if (detail) {
      event.preventDefault();
      openFragment(detail.getAttribute('data-shift-detail'), 880);
      return;
    }
    var history = event.target.closest('[data-slot-history]');
    if (history) {
      event.preventDefault();
      openFragment(history.getAttribute('data-slot-history'), 720);
    }
  });
})(window);
