(function () {
  'use strict';

  var shell = document.getElementById('sites_list_shell');
  var box = document.getElementById('sites_list_box');
  if (!shell || !box) return;

  var batchId = String(shell.getAttribute('data-batch-id') || '');
  var postUrl = shell.getAttribute('data-post-url') || window.location.href;
  var selKey = 'txf-extract-sel-' + batchId;
  var histKey = 'txf-extract-hist-' + batchId;

  var openBtn = document.getElementById('sites_open_btn');
  var undoBtn = document.getElementById('sites_undo_btn');
  var redoBtn = document.getElementById('sites_redo_btn');
  var selectedLabel = document.getElementById('sites_selected_label');
  var statusEl = document.getElementById('sites_list_status');
  var countLabel = document.getElementById('sites_count_label');
  var footerCount = document.getElementById('sites_footer_count');
  var openPanel = document.getElementById('sites_open_panel');
  var openPanelList = document.getElementById('sites_open_panel_list');

  var selected = new Set();
  var lastIndex = -1;
  var busy = false;
  var history = loadHistory();

  function rows() {
    return Array.prototype.slice.call(box.querySelectorAll('.sites-list-row'));
  }

  function loadSelected() {
    try {
      var raw = localStorage.getItem(selKey);
      var arr = raw ? JSON.parse(raw) : [];
      selected = new Set(Array.isArray(arr) ? arr.map(String) : []);
    } catch (e) {
      selected = new Set();
    }
  }

  function saveSelected() {
    try {
      localStorage.setItem(selKey, JSON.stringify(Array.from(selected)));
    } catch (e) { /* ignore */ }
  }

  function loadHistory() {
    try {
      var raw = localStorage.getItem(histKey);
      var data = raw ? JSON.parse(raw) : null;
      if (!data || !Array.isArray(data.undo) || !Array.isArray(data.redo)) {
        return { undo: [], redo: [] };
      }
      return data;
    } catch (e) {
      return { undo: [], redo: [] };
    }
  }

  function saveHistory() {
    try {
      localStorage.setItem(histKey, JSON.stringify(history));
    } catch (e) { /* ignore */ }
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('is-error');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
  }

  function syncSelectionUi() {
    var list = rows();
    var existing = new Set(list.map(function (r) { return r.getAttribute('data-domain') || ''; }));
    Array.from(selected).forEach(function (d) {
      if (!existing.has(d)) selected.delete(d);
    });
    list.forEach(function (row) {
      var d = row.getAttribute('data-domain') || '';
      var on = selected.has(d);
      row.classList.toggle('is-selected', on);
      row.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    var n = selected.size;
    if (selectedLabel) selectedLabel.textContent = n + ' selected';
    if (openBtn) openBtn.disabled = n === 0 || busy;
    if (undoBtn) undoBtn.disabled = history.undo.length === 0 || busy;
    if (redoBtn) redoBtn.disabled = history.redo.length === 0 || busy;
    saveSelected();
    updateCounts(list.length);
    ensureEmptyState(list.length === 0);
    if (n === 0) hideOpenPanel();
  }

  function updateCounts(n) {
    if (countLabel) countLabel.textContent = String(n);
    if (footerCount) {
      footerCount.textContent = n + ' site' + (n === 1 ? '' : 's');
    }
  }

  function ensureEmptyState(empty) {
    var emptyEl = document.getElementById('sites_list_empty');
    if (empty) {
      if (!emptyEl) {
        emptyEl = document.createElement('div');
        emptyEl.className = 'sites-list-empty';
        emptyEl.id = 'sites_list_empty';
        emptyEl.textContent = 'Waiting for sites from the team mate';
        box.appendChild(emptyEl);
      }
    } else if (emptyEl) {
      emptyEl.remove();
    }
  }

  function createRow(payload) {
    var row = document.createElement('div');
    row.className = 'sites-list-row';
    row.setAttribute('role', 'option');
    row.setAttribute('aria-selected', 'false');
    row.setAttribute('data-domain', payload.domain || '');
    row.setAttribute('data-prospect-site-id', payload.prospect_site_id != null ? String(payload.prospect_site_id) : '');
    row.setAttribute('data-added-by', payload.added_by != null ? String(payload.added_by) : '');
    row.textContent = payload.domain || '';
    return row;
  }

  function sortRowsInPlace() {
    var list = rows();
    list.sort(function (a, b) {
      return (a.getAttribute('data-domain') || '').localeCompare(b.getAttribute('data-domain') || '');
    });
    list.forEach(function (r) { box.appendChild(r); });
  }

  function selectAt(index, opts) {
    opts = opts || {};
    var list = rows();
    if (index < 0 || index >= list.length) return;
    var domain = list[index].getAttribute('data-domain') || '';

    if (opts.range && lastIndex >= 0) {
      var a = Math.min(lastIndex, index);
      var b = Math.max(lastIndex, index);
      for (var i = a; i <= b; i++) {
        selected.add(list[i].getAttribute('data-domain') || '');
      }
      lastIndex = index;
    } else {
      if (selected.has(domain)) selected.delete(domain);
      else selected.add(domain);
      lastIndex = index;
    }
    syncSelectionUi();
  }

  box.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return;
    var row = e.target.closest('.sites-list-row');
    if (!row || !box.contains(row)) return;
    e.preventDefault();
    shell.focus();
    var list = rows();
    var index = list.indexOf(row);
    selectAt(index, { range: e.shiftKey });
  });

  function selectedDomains() {
    return rows()
      .map(function (r) { return r.getAttribute('data-domain') || ''; })
      .filter(function (d) { return d && selected.has(d); });
  }

  function toHttpsUrl(domain) {
    var d = String(domain || '').trim().replace(/^https?:\/\//i, '');
    d = d.replace(/\/.*$/, '');
    return d ? 'https://' + d : '';
  }

  function hideOpenPanel() {
    if (!openPanel) return;
    openPanel.hidden = true;
    if (openPanelList) openPanelList.innerHTML = '';
  }

  function showOpenPanel(items) {
    if (!openPanel || !openPanelList) return;
    openPanelList.innerHTML = '';
    items.forEach(function (it) {
      var a = document.createElement('a');
      a.href = it.url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.className = 'sites-open-link';
      a.textContent = it.domain;
      openPanelList.appendChild(a);
    });
    openPanel.hidden = false;
  }

  function tryOpenUrl(url) {
    var w = null;
    try {
      w = window.open(url, '_blank');
    } catch (err) {
      w = null;
    }
    if (!w || w.closed) return false;
    try { w.opener = null; } catch (err2) { /* ignore */ }
    return true;
  }

  /**
   * Open selected sites. Browsers usually allow only one window.open per click,
   * so for 2+ sites we open a helper tab with an “Open all” button (second click).
   * Inline links are always shown as a no-popup fallback.
   */
  function openSelected() {
    var domains = selectedDomains();
    if (!domains.length) return;

    var items = [];
    for (var i = 0; i < domains.length; i++) {
      var url = toHttpsUrl(domains[i]);
      if (!url) continue;
      items.push({ domain: domains[i], url: url });
    }
    if (!items.length) {
      setStatus('No valid site names to open.', true);
      return;
    }

    showOpenPanel(items);

    // One site: open directly (almost always allowed).
    if (items.length === 1) {
      if (tryOpenUrl(items[0].url)) {
        setStatus('Opened ' + items[0].domain + '. Selection kept.');
      } else {
        setStatus('Pop-up blocked — click the link below to open.', true);
      }
      return;
    }

    // Multiple: open a helper tab (single pop-up) with Open-all + individual links.
    var helperHtml = buildHelperHtml(items);
    var blob = new Blob([helperHtml], { type: 'text/html' });
    var helperUrl = URL.createObjectURL(blob);
    var helperWin = null;
    try {
      helperWin = window.open(helperUrl, '_blank');
    } catch (err) {
      helperWin = null;
    }

    if (helperWin) {
      try { helperWin.opener = null; } catch (err2) { /* ignore */ }
      setStatus(
        'Helper tab opened — click “Open all ' + items.length +
        ' tabs” there. Links are also listed below.'
      );
      window.setTimeout(function () {
        try { URL.revokeObjectURL(helperUrl); } catch (e) { /* ignore */ }
      }, 60000);
      return;
    }

    // Pop-ups fully blocked: inline links still work.
    setStatus(
      'Pop-ups blocked. Click each site link below (or allow pop-ups and press Open URLs again).',
      true
    );
    try { URL.revokeObjectURL(helperUrl); } catch (e2) { /* ignore */ }
  }

  function buildHelperHtml(items) {
    var safeItems = items.map(function (it) {
      return { domain: String(it.domain), url: String(it.url) };
    });
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width, initial-scale=1">'
      + '<title>Open ' + safeItems.length + ' sites</title>'
      + '<style>'
      + 'body{font:15px/1.45 system-ui,sans-serif;margin:0;padding:1.5rem;background:#f8fafc;color:#0f172a}'
      + 'h1{font-size:1.15rem;margin:0 0 0.5rem}'
      + 'p{margin:0 0 1rem;color:#475569}'
      + 'button{font:inherit;font-weight:650;padding:0.7rem 1.1rem;border:0;border-radius:8px;'
      + 'background:#1e3a5f;color:#fff;cursor:pointer}'
      + 'button:hover{filter:brightness(1.08)}'
      + '.list{margin-top:1.25rem;display:flex;flex-direction:column;gap:0.35rem}'
      + 'a{color:#1d4ed8;word-break:break-all}'
      + '#msg{margin-top:0.85rem;font-size:0.92rem;color:#475569}'
      + '</style></head><body>'
      + '<h1>Open ' + safeItems.length + ' selected sites</h1>'
      + '<p>Your browser blocks opening many tabs at once from the list. '
      + 'Click once below (allow pop-ups if asked).</p>'
      + '<button type="button" id="go">Open all ' + safeItems.length + ' tabs</button>'
      + '<p id="msg"></p>'
      + '<div class="list" id="list"></div>'
      + '<script>(function(){'
      + 'var items=' + JSON.stringify(safeItems) + ';'
      + 'var list=document.getElementById("list");'
      + 'items.forEach(function(it){'
      + 'var a=document.createElement("a");a.href=it.url;a.target="_blank";'
      + 'a.rel="noopener noreferrer";a.textContent=it.domain;list.appendChild(a);'
      + '});'
      + 'document.getElementById("go").onclick=function(){'
      + 'var opened=0,blocked=0;'
      + 'for(var i=0;i<items.length;i++){'
      + 'var w=null;try{w=window.open(items[i].url,"_blank");}catch(e){w=null;}'
      + 'if(w&&!w.closed){try{w.opener=null;}catch(e2){} opened++;}else{blocked++;}'
      + '}'
      + 'var msg=document.getElementById("msg");'
      + 'if(blocked===0){msg.textContent="Opened "+opened+" tabs. You can close this page.";}'
      + 'else if(opened===0){msg.textContent="Still blocked — allow pop-ups for this site, or click each link below.";}'
      + 'else{msg.textContent="Opened "+opened+", blocked "+blocked+". Click remaining links below.";}'
      + '};'
      + '})();<\/script>'
      + '</body></html>';
  }

  function postAction(action, fields) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('ajax', '1');
    Object.keys(fields || {}).forEach(function (k) {
      body.set(k, fields[k]);
    });
    return fetch(postUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data || data.ok === false) {
          throw new Error((data && data.error) || 'Request failed');
        }
        return data;
      });
    });
  }

  function deleteSelected() {
    var domains = selectedDomains();
    if (!domains.length || busy) return;
    busy = true;
    syncSelectionUi();
    setStatus('Deleting…');
    postAction('remove_sites', { domains_json: JSON.stringify(domains) })
      .then(function (data) {
        var removed = Array.isArray(data.removed) ? data.removed : [];
        if (removed.length) {
          history.undo.push(removed);
          history.redo = [];
          saveHistory();
        }
        var removeSet = new Set(removed.map(function (r) { return r.domain; }));
        rows().forEach(function (row) {
          var d = row.getAttribute('data-domain') || '';
          if (removeSet.has(d)) row.remove();
        });
        removed.forEach(function (r) { selected.delete(r.domain); });
        setStatus('Deleted ' + removed.length + ' site' + (removed.length === 1 ? '' : 's') + '. Undo available.');
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not delete.', true);
      })
      .then(function () {
        busy = false;
        syncSelectionUi();
      });
  }

  function undo() {
    if (!history.undo.length || busy) return;
    var rowsData = history.undo.pop();
    busy = true;
    syncSelectionUi();
    setStatus('Undoing…');
    postAction('restore_sites', { rows_json: JSON.stringify(rowsData) })
      .then(function (data) {
        history.redo.push(rowsData);
        saveHistory();
        var domains = Array.isArray(data.domains) ? data.domains : null;
        if (domains) {
          var meta = {};
          rowsData.forEach(function (r) { meta[r.domain] = r; });
          rows().forEach(function (r) { r.remove(); });
          domains.forEach(function (d) {
            box.appendChild(createRow(meta[d] || { domain: d }));
          });
        } else {
          rowsData.forEach(function (r) {
            if (!box.querySelector('.sites-list-row[data-domain="' + cssEscape(r.domain) + '"]')) {
              box.appendChild(createRow(r));
            }
          });
          sortRowsInPlace();
        }
        rowsData.forEach(function (r) { selected.add(r.domain); });
        setStatus('Undo restored ' + rowsData.length + ' site' + (rowsData.length === 1 ? '' : 's') + '.');
      })
      .catch(function (err) {
        history.undo.push(rowsData);
        saveHistory();
        setStatus(err.message || 'Could not undo.', true);
      })
      .then(function () {
        busy = false;
        syncSelectionUi();
      });
  }

  function redo() {
    if (!history.redo.length || busy) return;
    var rowsData = history.redo.pop();
    var domains = rowsData.map(function (r) { return r.domain; });
    busy = true;
    syncSelectionUi();
    setStatus('Redoing…');
    postAction('remove_sites', { domains_json: JSON.stringify(domains) })
      .then(function (data) {
        var removed = Array.isArray(data.removed) ? data.removed : rowsData;
        history.undo.push(removed);
        saveHistory();
        var removeSet = new Set(removed.map(function (r) { return r.domain; }));
        rows().forEach(function (row) {
          var d = row.getAttribute('data-domain') || '';
          if (removeSet.has(d)) row.remove();
        });
        removed.forEach(function (r) { selected.delete(r.domain); });
        setStatus('Redo deleted ' + removed.length + ' site' + (removed.length === 1 ? '' : 's') + '.');
      })
      .catch(function (err) {
        history.redo.push(rowsData);
        saveHistory();
        setStatus(err.message || 'Could not redo.', true);
      })
      .then(function () {
        busy = false;
        syncSelectionUi();
      });
  }

  function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/["\\]/g, '\\$&');
  }

  function isTypingTarget(el) {
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    return false;
  }

  document.addEventListener('keydown', function (e) {
    if (busy) return;
    if (isTypingTarget(e.target)) return;
    var inShell = shell.contains(document.activeElement) || document.activeElement === shell;
    if (!inShell && !shell.classList.contains('is-active')) return;

    var mod = e.metaKey || e.ctrlKey;
    var key = e.key;

    if (key === 'Escape') {
      if (selected.size === 0) return;
      e.preventDefault();
      selected.clear();
      hideOpenPanel();
      syncSelectionUi();
      return;
    }
    if (mod && key.toLowerCase() === 'a') {
      e.preventDefault();
      rows().forEach(function (r) {
        selected.add(r.getAttribute('data-domain') || '');
      });
      syncSelectionUi();
      return;
    }
    if (mod && key.toLowerCase() === 'z' && !e.shiftKey) {
      e.preventDefault();
      undo();
      return;
    }
    if (mod && (key.toLowerCase() === 'y' || (key.toLowerCase() === 'z' && e.shiftKey))) {
      e.preventDefault();
      redo();
      return;
    }
    if (key === 'Backspace' || key === 'Delete') {
      if (selected.size === 0) return;
      e.preventDefault();
      deleteSelected();
    }
  });

  shell.addEventListener('focusin', function () {
    shell.classList.add('is-active');
  });
  shell.addEventListener('mousedown', function () {
    shell.classList.add('is-active');
  });
  document.addEventListener('mousedown', function (e) {
    if (!shell.contains(e.target)) shell.classList.remove('is-active');
  });

  if (openBtn) {
    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openSelected();
    });
  }
  if (undoBtn) undoBtn.addEventListener('click', undo);
  if (redoBtn) redoBtn.addEventListener('click', redo);

  var closePanelBtn = document.getElementById('sites_open_panel_close');
  if (closePanelBtn) {
    closePanelBtn.addEventListener('click', function () {
      hideOpenPanel();
    });
  }

  loadSelected();
  syncSelectionUi();
})();
