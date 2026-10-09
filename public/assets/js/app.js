/*
 * Petra — comportements d'interface (jQuery, DataTables, SweetAlert2, Lucide).
 * Aucun script inline : configuration et traductions sont lues depuis des blocs JSON.
 * Développé par BELEJ-CONSULTING.
 */
(function ($, Core, Tables, Exporter) {
  'use strict';

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) return {};
    try { return JSON.parse(el.textContent); } catch (e) { return {}; }
  }

  var config = readJson('pt-config');
  var t = Core.createTranslator(readJson('pt-i18n'));
  var locale = config.locale || 'fr';
  var csrf = $('meta[name="csrf-token"]').attr('content');

  window.PT = { t: t, locale: locale, config: config };

  // Tous les appels AJAX portent le jeton CSRF et s'identifient comme AJAX (réponses JSON).
  $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } });

  function icons() {
    if (window.lucide) window.lucide.createIcons();
  }

  function alertError(message) {
    return Swal.fire({ icon: 'error', title: t('js.error'), text: message || t('js.error_generic'), confirmButtonColor: '#1F4E79' });
  }

  function ajaxError(xhr) {
    var json = xhr && xhr.responseJSON;
    if (json && json.errors) {
      alertError(Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join('\n'));
    } else {
      alertError(json && json.error);
    }
  }

  var loadedScripts = {};
  function loadScript(src) {
    if (!loadedScripts[src]) {
      loadedScripts[src] = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = function () { reject(new Error('load ' + src)); };
        document.head.appendChild(s);
      });
    }
    return loadedScripts[src];
  }

  function loadPdfMake() {
    return loadScript(config.assets.pdfmake).then(function () { return loadScript(config.assets.vfs); })
      .then(function () { return window.pdfMake; });
  }

  // ---------------------------------------------------------------- Navigation
  $(document).on('click', '[data-toggle-sidebar]', function () {
    var open = $('#sidebar').toggleClass('is-open').hasClass('is-open');
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') $('#sidebar').removeClass('is-open');
  });

  // Menu réductible en icônes (mémorisé sur ce poste)
  function setMini(on) {
    document.body.classList.toggle('sidebar-mini', on);
    $('[data-sidebar-mini]').attr('aria-pressed', on ? 'true' : 'false');
    try { localStorage.setItem('pt.sidebarMini', on ? '1' : '0'); } catch (e) { /* stockage indisponible */ }
  }
  try { if (localStorage.getItem('pt.sidebarMini') === '1' && window.innerWidth > 860) setMini(true); } catch (e) { /* ignore */ }
  $(document).on('click', '[data-sidebar-mini]', function () { setMini(!document.body.classList.contains('sidebar-mini')); });

  // ---------------------------------------------------------------- Confirmations
  $(document).on('submit', 'form[data-confirm]', function (e) {
    var form = this;
    if (form.dataset.confirmed === '1') return;
    e.preventDefault();
    Swal.fire({
      icon: 'question',
      text: form.dataset.confirm,
      showCancelButton: true,
      confirmButtonText: t('js.confirm'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: '#1F4E79',
      reverseButtons: true
    }).then(function (res) {
      if (res.isConfirmed) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  });

  // Dialogues de saisie : le contenu vient d'un <template> contenant un formulaire.
  $(document).on('click', '[data-open]', function () {
    var tpl = document.querySelector(this.dataset.open);
    if (!tpl) return;
    Swal.fire({
      html: tpl.innerHTML,
      showCancelButton: true,
      confirmButtonText: t('js.save'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: '#1F4E79',
      reverseButtons: true,
      focusConfirm: false,
      didOpen: function (popup) {
        icons();
        var first = popup.querySelector('input:not([type=hidden]), select, textarea');
        if (first) first.focus();
      },
      preConfirm: function () {
        var form = Swal.getHtmlContainer().querySelector('form');
        if (!form.reportValidity()) return false;
        form.submit();
        return true;
      }
    });
  });

  // ---------------------------------------------------------------- DataTables
  function dtLanguage() {
    return {
      processing: t('js.dt.processing'),
      search: t('js.dt.search'),
      lengthMenu: t('js.dt.length'),
      info: t('js.dt.info'),
      infoEmpty: t('js.dt.info_empty'),
      infoFiltered: t('js.dt.info_filtered'),
      zeroRecords: t('js.dt.zero'),
      emptyTable: t('js.dt.empty'),
      loadingRecords: t('js.dt.loading'),
      paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    };
  }

  function filtersFor(name) {
    var form = document.querySelector('form[data-filters-for="' + name + '"]');
    if (!form) return {};
    var entries = [];
    new FormData(form).forEach(function (value, key) { entries.push([key, value]); });
    return Core.filtersFromEntries(entries);
  }

  var tables = {};
  var definitions = Tables.definitions(t, locale);

  // Présentation commune : tableaux compacts, 15 lignes par défaut, recherche + longueur en tête.
  var DT_PAGE = 15;
  var DT_LENGTHS = [15, 25, 50, 100];
  var DT_LAYOUT = { topStart: 'search', topEnd: 'pageLength', bottomStart: 'info', bottomEnd: 'paging' };

  function dtSearchPlaceholder(api) {
    $(api.table().container()).find('.dt-search input').attr('placeholder', t('js.dt.search_placeholder'));
  }

  $('table[data-table]').each(function () {
    var $table = $(this);
    var name = $table.data('table');
    var def = definitions[name];
    if (!def) return;
    var rowLink = $table.data('row-link');

    $table.addClass('compact');
    tables[name] = $table.DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 350,
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      layout: DT_LAYOUT,
      autoWidth: false,
      order: def.order,
      language: dtLanguage(),
      ajax: {
        url: $table.data('source'),
        data: function (params) { return $.extend(params, filtersFor(name)); },
        error: ajaxError
      },
      columns: def.columns.map(function (col) {
        return {
          data: col.data,
          name: col.data,
          title: col.title,
          orderable: col.orderable !== false,
          className: col.className || '',
          defaultContent: '',
          render: function (data, type, row) {
            return type === 'display' ? Tables.cellHtml(col, row) : Tables.cellText(col, row);
          }
        };
      }),
      createdRow: function (row, data) {
        if (rowLink) {
          $(row).addClass('row-link').attr('data-href', Core.fillUrl(rowLink, data)).attr('tabindex', '0');
        }
      },
      initComplete: function () { dtSearchPlaceholder(this.api()); }
    });
  });

  // Tableaux statiques longs (fiches, alertes, rapports) : pagination / tri / recherche côté client.
  $('table.table').each(function () {
    var $table = $(this);
    var rows = $table.children('tbody').children('tr').length;
    if (!$table.children('thead').length || rows <= DT_PAGE || $table.is('[data-no-dt]')) return;
    var numeric = [];
    var unsortable = [];
    $table.find('thead th').each(function (i) {
      if ($(this).hasClass('num')) numeric.push(i);
      if (!$.trim($(this).text())) unsortable.push(i);
    });
    $table.addClass('compact').DataTable({
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      layout: DT_LAYOUT,
      autoWidth: false,
      order: [],
      language: dtLanguage(),
      columnDefs: [
        { targets: numeric, className: 'dt-right' },
        { targets: unsortable, orderable: false, searchable: false }
      ],
      initComplete: function () { dtSearchPlaceholder(this.api()); }
    });
  });

  // Barres de filtres : compteur de filtres actifs + réinitialisation.
  function refreshFilterState($form) {
    var n = 0;
    $form.find('[data-filter]').each(function () {
      if (this.type === 'hidden') return;
      if (this.type === 'checkbox' ? this.checked : this.value !== '') n++;
    });
    $form.find('[data-filters-count]').text(n ? t('js.dt.active_filters', { n: n }) : '').prop('hidden', !n);
    $form.find('[data-filters-reset]').prop('hidden', !n);
  }

  $('form.filters').each(function () {
    var $form = $(this);
    if (!$form.find('[data-filter]').length) return;
    $form.addClass('filters--compact').append(
      '<div class="filters__tools">'
      + '<span class="filters__count" data-filters-count hidden></span>'
      + '<button type="button" class="btn btn--sm btn--ghost" data-filters-reset hidden><i data-lucide="rotate-ccw"></i>' + Core.escapeHtml(t('js.dt.reset')) + '</button>'
      + '</div>'
    );
    refreshFilterState($form);
  });
  icons();

  $(document).on('change', 'form.filters [data-filter]', function () { refreshFilterState($(this).closest('form')); });

  $(document).on('click', '[data-filters-reset]', function () {
    var $form = $(this).closest('form');
    $form.find('[data-filter]').each(function () {
      if (this.type === 'checkbox') this.checked = false;
      else if (this.type !== 'hidden') this.value = '';
    });
    refreshFilterState($form);
    var name = $form.data('filters-for');
    if (name && tables[name]) tables[name].ajax.reload();
    else $form.trigger('submit');
  });

  $(document).on('click keydown', 'tr.row-link', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    if ($(e.target).closest('a, button, input, select').length) return;
    window.location.href = this.dataset.href;
  });

  // Filtres : rechargement de la table ou soumission (formulaires GET classiques).
  $(document).on('change', '[data-filter]', function () {
    var form = $(this).closest('form');
    var name = form.data('filters-for');
    if (name && tables[name]) {
      tables[name].ajax.reload();
    } else if (form.length) {
      form.trigger('submit');
    }
  });

  // ---------------------------------------------------------------- Exports
  $(document).on('click', '[data-export]', function () {
    var name = this.dataset.exportTable;
    if (!tables[name] || !definitions[name]) return;
    exportTable(tables[name], definitions[name], $('table[data-table="' + name + '"]').data('source'), this.dataset.exportTitle, this.dataset.export, this);
  });

  /** Export Excel / PDF de toutes les lignes correspondant aux filtres et au tri courants d'une DataTable. */
  function exportTable(table, def, source, title, kind, button) {
    var params = $.extend(table.ajax.params(), { start: 0, length: -1, draw: 0 });
    button.disabled = true;

    $.getJSON(source, params)
      .then(function (json) {
        var data = Exporter.tableData(def.columns, json.data || [], locale);
        var meta = { subtitle: t('js.export_meta', { count: json.recordsFiltered, date: Core.formatDate(new Date().toISOString().slice(0, 10), locale) }) };
        if (json.recordsFiltered > json.data.length) {
          meta.subtitle += ' — ' + t('js.export_truncated', { count: json.data.length });
        }
        if (kind === 'excel') {
          return loadScript(config.assets.exceljs).then(function () { return Exporter.exportExcel(window.ExcelJS, title, data, meta); });
        }
        return loadPdfMake().then(function (pdfMake) { Exporter.exportPdf(pdfMake, title, data, meta); });
      })
      .fail(ajaxError)
      .catch(function () { alertError(); })
      .always(function () { button.disabled = false; });
  }

  // ---------------------------------------------------------------- Fenêtre de détail (KPI, graphiques, notifications)
  // Spécification : { table, source, title, params, page, row, chips: [{label, params}] }
  var drillState = null;

  function closeDrill() {
    if (!drillState) return;
    drillState.table.destroy();
    drillState.$root.remove();
    $('body').removeClass('has-drill');
    if (drillState.opener) drillState.opener.focus();
    drillState = null;
  }

  function openDrill(spec, opener) {
    var def = definitions[spec.table];
    if (!def) return;
    closeDrill();
    var esc = Core.escapeHtml;
    var chips = (spec.chips || []).map(function (c, i) {
      return '<button type="button" class="chip-btn" data-drill-chip="' + i + '" aria-pressed="' + (i === 0 ? 'true' : 'false') + '">' + esc(c.label) + '</button>';
    }).join('');
    var stations = (config.stations || []);
    var stationSelect = !spec.params.station_id && stations.length > 1
      ? '<select class="input input--sm" data-drill-station aria-label="' + esc(t('js.station')) + '"><option value="">' + esc(t('js.all_stations')) + '</option>'
        + stations.map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + '</option>'; }).join('') + '</select>'
      : '';
    var html = '<div class="drill" role="dialog" aria-modal="true" aria-labelledby="drill-title">'
      + '<div class="drill__backdrop" data-drill-close></div>'
      + '<div class="drill__panel" tabindex="-1">'
      + '<header class="drill__head">'
      + '<div class="drill__title"><h2 id="drill-title">' + esc(spec.title) + '</h2><span class="drill__count" data-drill-count>…</span></div>'
      + '<div class="drill__actions">'
      + '<button type="button" class="btn btn--sm" data-drill-export="excel"><i data-lucide="file-spreadsheet"></i>Excel</button>'
      + '<button type="button" class="btn btn--sm" data-drill-export="pdf"><i data-lucide="file-text"></i>PDF</button>'
      + (spec.page ? '<a class="btn btn--sm btn--ghost" href="' + esc(spec.page) + '" title="' + esc(t('js.open_list')) + '"><i data-lucide="external-link"></i><span class="drill__hide-sm">' + esc(t('js.open_list')) + '</span></a>' : '')
      + '<button type="button" class="icon-btn icon-btn--lg" data-drill-close aria-label="' + esc(t('js.close')) + '"><i data-lucide="x"></i></button>'
      + '</div></header>'
      + ((chips || stationSelect) ? '<div class="drill__filters"><div class="chips">' + chips + '</div>' + stationSelect + '</div>' : '')
      + '<div class="drill__body"><table class="display compact drill__table" style="width:100%"></table></div>'
      + '</div></div>';
    var $root = $(html).appendTo('body');
    $('body').addClass('has-drill');
    icons();

    var state = { $root: $root, spec: spec, def: def, chip: (spec.chips && spec.chips[0]) ? spec.chips[0].params : {}, station: '', opener: opener || null };
    state.table = $root.find('table').DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 300,
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      order: def.order,
      language: dtLanguage(),
      layout: DT_LAYOUT,
      ajax: {
        url: spec.source,
        data: function (p) {
          var extra = $.extend({}, spec.params, state.chip);
          if (state.station) extra.station_id = state.station;
          return $.extend(p, extra);
        },
        error: ajaxError
      },
      columns: def.columns.map(function (col) {
        return {
          data: col.data, name: col.data, title: col.title, orderable: col.orderable !== false,
          className: col.className || '', defaultContent: '',
          render: function (data, type, row) { return type === 'display' ? Tables.cellHtml(col, row) : Tables.cellText(col, row); }
        };
      }),
      createdRow: function (row, data) {
        if (spec.row) $(row).addClass('row-link').attr('data-href', Core.fillUrl(spec.row, data)).attr('tabindex', '0');
      },
      drawCallback: function () {
        var info = this.api().page.info();
        $root.find('[data-drill-count]').text(t('js.rows_count', { n: Core.formatNumber(info.recordsDisplay, locale, 0) }));
      },
      initComplete: function () { $root.find('.dt-search input').attr('placeholder', t('js.search_in')).trigger('focus'); }
    });
    drillState = state;

    $root.on('click', '[data-drill-close]', closeDrill);
    $root.on('click', '[data-drill-chip]', function () {
      var chip = spec.chips[Number(this.dataset.drillChip)];
      state.chip = chip ? chip.params : {};
      $root.find('[data-drill-chip]').attr('aria-pressed', 'false');
      $(this).attr('aria-pressed', 'true');
      state.table.ajax.reload();
    });
    $root.on('change', '[data-drill-station]', function () { state.station = this.value; state.table.ajax.reload(); });
    $root.on('click', '[data-drill-export]', function () {
      var suffix = $root.find('[data-drill-chip][aria-pressed="true"]').text();
      var title = spec.title + (suffix && suffix !== t('js.all') ? ' — ' + suffix : '');
      exportTable(state.table, def, spec.source, title, this.dataset.drillExport, this);
    });
  }

  window.PT.drill = openDrill;
  window.PT.loadScript = loadScript;
  window.PT.dtLanguage = dtLanguage;
  window.PT.loadPdfMake = loadPdfMake;
  window.PT.alertError = alertError;
  $(document).on('click', '[data-drill]', function (e) {
    e.preventDefault();
    var spec;
    try { spec = JSON.parse(this.dataset.drill); } catch (err) { return; }
    openDrill(spec, this);
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && drillState && !Swal.isVisible()) closeDrill();
  });

  // ---------------------------------------------------------------- Recherche instantanée dans un tableau simple
  $(document).on('input', '[data-table-search]', function () {
    var q = this.value.trim().toLowerCase();
    $(this.dataset.tableSearch).find('tbody tr').each(function () {
      this.hidden = q !== '' && this.textContent.toLowerCase().indexOf(q) === -1;
    });
  });

  // ---------------------------------------------------------------- Indicateurs cliquables → filtre du tableau
  $(document).on('click', '[data-set-filter]', function () {
    var $select = $('form.filters [name="' + this.dataset.setFilter + '"]');
    $select.val(this.dataset.value).trigger('change');
    var table = document.querySelector('.card table[data-table]');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // ---------------------------------------------------------------- Impression et envoi automatique des sélecteurs
  $(document).on('click', '[data-print]', function () { window.print(); });
  $(document).on('change', 'select[data-autosubmit]', function () { this.form.submit(); });

  // ---------------------------------------------------------------- Zones de texte extensibles (Ctrl+Entrée pour envoyer)
  $(document).on('input', 'textarea[data-autogrow]', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight + 2, 320) + 'px';
  });
  $(document).on('keydown', 'textarea[data-autogrow]', function (e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && this.value.trim()) {
      e.preventDefault();
      $(this).closest('form').trigger('submit');
    }
  });

  // ---------------------------------------------------------------- Menus « … »
  $(document).on('click', '[data-menu-toggle]', function (e) {
    e.stopPropagation();
    var $list = $(this).siblings('.menu__list');
    var open = $list.prop('hidden');
    $('.menu__list').prop('hidden', true);
    $('[data-menu-toggle]').attr('aria-expanded', 'false');
    $list.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('click', function () { $('.menu__list').prop('hidden', true); $('[data-menu-toggle]').attr('aria-expanded', 'false'); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') $('.menu__list').prop('hidden', true); });

  // Afficher / masquer un mot de passe
  $(document).on('click', '[data-toggle-password]', function () {
    var $btn = $(this);
    var input = document.getElementById($btn.data('toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    $btn.attr({ 'aria-pressed': String(show), 'aria-label': $btn.data(show ? 'label-hide' : 'label-show') });
    $btn.html('<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>');
    icons();
    input.focus();
  });

  icons();
})(window.jQuery, window.PTCore, window.PTTables, window.PTExport);
