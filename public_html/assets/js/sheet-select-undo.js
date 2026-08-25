/**
 * Sheet Select all / Remove selected + Undo / Redo arrows.
 * Selection follows live Search: hidden / display:none rows are unchecked.
 * Used on Email campaign, Sites with emails, Extracted Sites, Our database.
 */
(function () {
  'use strict';
  if (window.__HF_SHEET_SELECT_UNDO__) return;
  window.__HF_SHEET_SELECT_UNDO__ = true;

  function rootEl() {
    return document.querySelector('[data-sheet-select-root]');
  }

  function rowOfCheck(el) {
    return el ? el.closest('tr, li, [data-swe-row], [data-extracted-url-row], [data-prospect-site-row]') : null;
  }

  function isRowVisible(row) {
    if (!row) return true;
    if (row.hidden) return false;
    if (row.getAttribute && row.getAttribute('hidden') != null) return false;
    try {
      if (window.getComputedStyle && getComputedStyle(row).display === 'none') return false;
    } catch (e) { /* ignore */ }
    return true;
  }

  function allChecks() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-sheet-row-check]'));
  }

  function visibleChecks() {
    return allChecks().filter(function (el) {
      return isRowVisible(rowOfCheck(el));
    });
  }

  function selectedChecks() {
    return visibleChecks().filter(function (el) { return el.checked; });
  }

  function clearHiddenSelection() {
    allChecks().forEach(function (el) {
      var row = rowOfCheck(el);
      if (isRowVisible(row)) return;
      el.checked = false;
      if (row) row.classList.remove('is-sheet-selected');
    });
  }

  function setStatus(msg, isError) {
    var el = document.getElementById('swe_status')
      || document.getElementById('extracted_copy_status')
      || document.getElementById('prospect_copy_status');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.classList.remove('is-error', 'is-ok', 'is-loading');
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
    el.classList.toggle('is-ok', !isError);
    el.classList.remove('is-loading');
  }

  function showProcessing(msg) {
    if (window.AppProcessing && typeof window.AppProcessing.show === 'function') {
      window.AppProcessing.show(msg);
    }
  }

  function hideProcessing() {
    if (window.AppProcessing && typeof window.AppProcessing.hide === 'function') {
      window.AppProcessing.hide();
    }
  }

  function applyState(data) {
    var root = rootEl();
    if (!root || !data) return;
    var undo = root.querySelector('[data-sheet-undo]');
    var redo = root.querySelector('[data-sheet-redo]');
    if (undo) undo.disabled = !data.can_undo;
    if (redo) redo.disabled = !data.can_redo;
  }

  function matchingLabel(n) {
    return n === 1
      ? 'Select all 1 matching row on this page'
      : 'Select all ' + n + ' matching rows on this page';
  }

  function syncRemoveButton() {
    clearHiddenSelection();
    var vis = visibleChecks();
    var n = vis.length;
    var c = selectedChecks().length;
    var root = rootEl();
    var btn = root ? root.querySelector('[data-sheet-remove-selected]') : null;
    if (btn) {
      btn.disabled = c < 1;
      btn.textContent = c > 0 ? ('Remove selected (' + c + ')') : 'Remove selected';
    }
    var all = document.querySelector('[data-sheet-select-all-check]');
    var title = matchingLabel(n);
    if (all) {
      all.checked = n > 0 && c === n;
      all.indeterminate = c > 0 && c < n;
      all.title = title;
      all.setAttribute('aria-label', title);
    }
    var selectAll = root ? root.querySelector('[data-sheet-select-all]') : null;
    if (selectAll) {
      selectAll.title = title;
      selectAll.setAttribute('aria-label', title);
    }
  }

  function markRowSelected(el, on) {
    el.checked = !!on;
    var row = rowOfCheck(el);
    if (row) row.classList.toggle('is-sheet-selected', !!on);
  }

  function setVisibleSelected(on) {
    visibleChecks().forEach(function (el) {
      markRowSelected(el, on);
    });
    syncRemoveButton();
  }

  function removeRowsByIds(ids) {
    ids.forEach(function (id) {
      var check = document.querySelector('[data-sheet-row-check][value="' + id + '"]');
      var row = check ? rowOfCheck(check) : document.querySelector('[data-site-id="' + id + '"]');
      if (row) row.remove();
    });
    syncRemoveButton();
    try {
      document.dispatchEvent(new CustomEvent('hf-sheet-rows-changed'));
    } catch (e) { /* ignore */ }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  function postForm(form, extra) {
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    if (extra) {
      Object.keys(extra).forEach(function (k) { body.set(k, extra[k]); });
    }
    if (!body.get('_csrf')) {
      var tok = csrfToken();
      if (tok) body.set('_csrf', tok);
    }
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
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
    }).then(function (data) {
      form.removeAttribute('data-busy');
      return data;
    }).catch(function (err) {
      form.removeAttribute('data-busy');
      throw err;
    });
  }

  function submitHistory(kind) {
    var root = rootEl();
    if (!root) return;
    var form = root.querySelector(kind === 'redo' ? '[data-sheet-redo-form]' : '[data-sheet-undo-form]');
    if (!form || form.getAttribute('data-busy') === '1') return;
    var label = kind === 'redo' ? 'Redoing…' : 'Undoing…';
    setStatus(label, false);
    showProcessing(label);
    postForm(form).then(function (data) {
      applyState(data);
      setStatus(kind === 'redo' ? 'Redid last change.' : 'Undid last change.');
      showProcessing('Loading…');
      window.location.reload();
    }).catch(function (err) {
      hideProcessing();
      setStatus(err.message || 'Could not undo/redo.', true);
    });
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.matches) return;
    if (t.matches('[data-sheet-row-check]')) {
      var row = rowOfCheck(t);
      if (row) row.classList.toggle('is-sheet-selected', !!t.checked);
      syncRemoveButton();
      return;
    }
    if (t.matches('[data-sheet-select-all-check]')) {
      setVisibleSelected(t.checked);
    }
  });

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-sheet-select-all], [data-sheet-remove-selected], [data-sheet-undo], [data-sheet-redo]') : null;
    if (!btn || btn.disabled) return;
    if (btn.hasAttribute('data-sheet-select-all')) {
      e.preventDefault();
      var vis = visibleChecks();
      var allOn = vis.length > 0 && vis.every(function (el) { return el.checked; });
      setVisibleSelected(!allOn);
      return;
    }
    if (btn.hasAttribute('data-sheet-undo')) {
      e.preventDefault();
      submitHistory('undo');
      return;
    }
    if (btn.hasAttribute('data-sheet-redo')) {
      e.preventDefault();
      submitHistory('redo');
      return;
    }
    if (!btn.hasAttribute('data-sheet-remove-selected')) return;
    e.preventDefault();
    var root = rootEl();
    var form = root && root.querySelector('[data-sheet-remove-selected-form]');
    if (!form) return;
    var ids = selectedChecks().map(function (el) { return String(el.value || ''); }).filter(Boolean);
    if (!ids.length) return;
    var confirmMsg = 'Remove ' + ids.length + ' selected site' + (ids.length === 1 ? '' : 's') + '?';
    if (!window.confirm(confirmMsg)) return;
    var idsInput = form.querySelector('[data-sheet-site-ids]');
    if (idsInput) idsInput.value = ids.join(',');
    setStatus('Removing selected…', false);
    showProcessing('Removing selected…');
    postForm(form).then(function (data) {
      var removed = (data.removed || []).map(function (r) {
        return String(r.id != null ? r.id : r);
      });
      if (!removed.length) removed = ids;
      removeRowsByIds(removed);
      applyState(data);
      var n = typeof data.count === 'number' ? data.count : removed.length;
      setStatus('Removed ' + n + ' selected site' + (n === 1 ? '' : 's') + '.');
      var totalLabel = document.getElementById('swe_total_label') || document.getElementById('extracted_total_label') || document.getElementById('prospect_country_total_label');
      if (totalLabel && typeof data.site_count === 'number') {
        totalLabel.textContent = String(data.site_count);
      }
      hideProcessing();
      if (data.redirect) {
        showProcessing('Loading…');
        window.location.href = data.redirect;
      }
    }).catch(function (err) {
      hideProcessing();
      setStatus(err.message || 'Could not remove selected.', true);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
    var t = e.target;
    if (t && t.closest && t.closest('input:not([type="checkbox"]):not([type="radio"]), textarea, select, [contenteditable="true"]')) {
      return;
    }
    var key = String(e.key || '').toLowerCase();
    if (key === 'z' && !e.shiftKey) {
      var undo = document.querySelector('[data-sheet-undo]');
      if (!undo || undo.disabled) return;
      e.preventDefault();
      undo.click();
      return;
    }
    if (key === 'y' || (key === 'z' && e.shiftKey)) {
      var redo = document.querySelector('[data-sheet-redo]');
      if (!redo || redo.disabled) return;
      e.preventDefault();
      redo.click();
    }
  });

  window.SheetSelectUndo = {
    applyState: applyState,
    sync: syncRemoveButton,
    syncPageStatus: function (shown, filtering) {
      var el = document.querySelector('[data-sheet-page-status]');
      if (!el) return;
      if (!el.getAttribute('data-default-text')) {
        el.setAttribute('data-default-text', String(el.textContent || '').replace(/\s+/g, ' ').trim());
      }
      if (!filtering) {
        el.textContent = el.getAttribute('data-default-text');
        return;
      }
      shown = Number(shown) || 0;
      if (shown < 1) {
        el.textContent = 'No search matches on this page';
        return;
      }
      var page = el.getAttribute('data-page') || '1';
      var pages = el.getAttribute('data-pages') || '1';
      var onPage = el.getAttribute('data-on-page') || String(shown);
      el.textContent = 'Page ' + page + ' / ' + pages + ' · showing ' + shown + ' of ' + onPage + ' on this page';
    },
    removed: function (ids, data) {
      if (ids && ids.length) removeRowsByIds(ids.map(String));
      applyState(data || {});
      syncRemoveButton();
    }
  };

  document.addEventListener('toggle', function (e) {
    var el = e.target;
    if (!el || !el.classList || !el.open) return;
    if (!el.classList.contains('sheet-row-more') && !el.classList.contains('sheet-tool-menu')) return;
    document.querySelectorAll('details.sheet-row-more[open], details.sheet-tool-menu[open]').forEach(function (d) {
      if (d !== el) d.open = false;
    });
  }, true);

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('details.sheet-row-more, details.sheet-tool-menu')) return;
    document.querySelectorAll('details.sheet-row-more[open], details.sheet-tool-menu[open]').forEach(function (d) {
      d.open = false;
    });
  });

  document.addEventListener('hf-sheet-rows-changed', function () {
    syncRemoveButton();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncRemoveButton);
  } else {
    syncRemoveButton();
  }
})();
