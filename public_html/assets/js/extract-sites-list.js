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
    // Drop selection for domains no longer in the list
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
  }

  function updateCounts(n) {
    if (countLabel) countLabel.textContent = String(n);
    if (footerCount) {
      footerCount.textContent = n + ' domain' + (n === 1 ? '' : 's');
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

  function rowPayload(row) {
    var ps = row.getAttribute('data-prospect-site-id');
    var ab = row.getAttribute('data-added-by');
    return {
      domain: row.getAttribute('data-domain') || '',
      prospect_site_id: ps ? parseInt(ps, 10) : null,
      added_by: ab ? parseInt(ab, 10) : null
    };
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
      if (!opts.toggle) selected.clear();
      for (var i = a; i <= b; i++) {
        selected.add(list[i].getAttribute('data-domain') || '');
      }
    } else if (opts.toggle) {
      if (selected.has(domain)) selected.delete(domain);
      else selected.add(domain);
      lastIndex = index;
    } else {
      selected.clear();
      selected.add(domain);
      lastIndex = index;
    }
    syncSelectionUi();
  }

  box.addEventListener('mousedown', function (e) {
    var row = e.target.closest('.sites-list-row');
    if (!row || !box.contains(row)) return;
    e.preventDefault();
    shell.focus();
    var list = rows();
    var index = list.indexOf(row);
    selectAt(index, {
      toggle: e.metaKey || e.ctrlKey,
      range: e.shiftKey
    });
  });

  function selectedDomains() {
    return Array.from(selected);
  }

  /**
   * Open selected domains as https://… in new tabs (user-gesture click).
   * Selection stays in place (including after refresh via localStorage).
   */
  function openSelected() {
    var domains = selectedDomains();
    if (!domains.length) return;

    var maxOpen = 25;
    if (domains.length > maxOpen) {
      if (!window.confirm(
        'Open the first ' + maxOpen + ' of ' + domains.length +
        ' selected sites in new tabs?\n\n(Browsers may block very large batches.)'
      )) {
        return;
      }
      domains = domains.slice(0, maxOpen);
    } else if (domains.length > 8) {
      if (!window.confirm('Open ' + domains.length + ' sites in new browser tabs?')) {
        return;
      }
    }

    var blocked = 0;
    domains.forEach(function (domain, i) {
      var url = 'https://' + String(domain).replace(/^https?:\/\//i, '');
      window.setTimeout(function () {
        var w = window.open(url, '_blank');
        if (!w) blocked++;
        if (i === domains.length - 1) {
          if (blocked > 0) {
            setStatus(
              'Opened some tabs, but the browser blocked ' + blocked +
              '. Allow pop-ups for this site, or open fewer at a time.',
              true
            );
          } else {
            setStatus(
              'Opened ' + domains.length + ' link' +
              (domains.length === 1 ? '' : 's') + ' in new tabs.'
            );
          }
        }
      }, i * 80);
    });
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
        // Re-render from server domain list when provided, else insert payloads
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
        // Keep them selected after undo
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

  if (openBtn) openBtn.addEventListener('click', openSelected);
  if (undoBtn) undoBtn.addEventListener('click', undo);
  if (redoBtn) redoBtn.addEventListener('click', redo);

  loadSelected();
  syncSelectionUi();
})();
