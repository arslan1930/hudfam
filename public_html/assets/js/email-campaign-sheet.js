/**
 * Email campaign country sheet — same site + up to 4 emails workflow as Sites with emails.
 * Autosave, multi-email paste, row remove, in-sheet search.
 */
(function () {
  'use strict';

  var statusEl = document.getElementById('swe_status');
  var totalLabel = document.getElementById('swe_total_label');
  var sentLabel = document.getElementById('swe_sent_label');
  var unsentLabel = document.getElementById('swe_unsent_label');
  var searchInput = document.getElementById('swe-row-search');
  var autosaveTimers = new WeakMap();
  var isCheckpointSheet = !!(document.querySelector('.swe-sheet-table.is-admin-checkpoint')
    || document.querySelector('[data-swe-mark]'));

  function setStatus(msg, isError, isLoading) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('is-error', 'is-ok', 'is-loading');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
    statusEl.classList.toggle('is-ok', !isError && !isLoading);
    statusEl.classList.toggle('is-loading', !!isLoading && !isError);
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

  function emailInputsIn(root) {
    if (!root) return [];
    return Array.prototype.slice.call(root.querySelectorAll('[data-swe-email]'));
  }

  function syncEmailClearButton(input) {
    if (window.EmailFieldClear && typeof window.EmailFieldClear.sync === 'function') {
      window.EmailFieldClear.sync(input);
      return;
    }
    if (!input) return;
    var wrap = input.closest('.email-field, .swe-email-field');
    if (!wrap) return;
    var btn = wrap.querySelector('[data-email-clear], [data-swe-email-clear]');
    var has = String(input.value || '').trim() !== '';
    wrap.classList.toggle('has-value', has);
    if (btn) btn.hidden = !has;
  }

  function parseEmailPaste(text) {
    var raw = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!raw) return [];
    var parts = raw.split(/[\n,;]+|\s+/);
    var out = [];
    var seen = {};
    for (var i = 0; i < parts.length; i++) {
      var e = String(parts[i] || '').trim().replace(/^<|>$/g, '');
      if (!e || seen[e.toLowerCase()]) continue;
      if (e.indexOf('@') === -1 && out.length === 0 && parts.length === 1) {
        out.push(e);
        break;
      }
      if (e.indexOf('@') === -1) continue;
      seen[e.toLowerCase()] = true;
      out.push(e);
      if (out.length >= 4) break;
    }
    return out;
  }

  function applyEmailPaste(targetInput, clipboardText) {
    var group = targetInput.closest('[data-swe-emails]') || targetInput.closest('form');
    var inputs = emailInputsIn(group);
    if (!inputs.length) return false;
    var emails = parseEmailPaste(clipboardText);
    if (!emails.length) return false;
    if (emails.length === 1) {
      targetInput.value = emails[0];
      syncEmailClearButton(targetInput);
      return true;
    }
    for (var i = 0; i < inputs.length; i++) {
      inputs[i].value = emails[i] || '';
      syncEmailClearButton(inputs[i]);
    }
    return true;
  }

  var matchRows = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-swe-row-search-meta]');
  var empty = document.querySelector('[data-swe-row-search-empty]');

  function clearHits() {
    document.querySelectorAll('[data-swe-row].sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function saveFormOf(el) {
    if (!el) return null;
    if (el.form && el.form.matches && el.form.matches('[data-swe-save]')) return el.form;
    return el.closest ? el.closest('[data-swe-save]') : null;
  }

  function refreshRowSearchIndex(row) {
    if (!row) return;
    var domainEl = row.querySelector('.swe-domain, [name="domain"]');
    var domain = String((domainEl && domainEl.value) || '').trim().toLowerCase();
    var lang = String((row.querySelector('.swe-td-lang .swe-cell-text') || {}).textContent || '')
      .trim().toLowerCase();
    var emails = Array.prototype.map.call(row.querySelectorAll('[data-swe-email]'), function (el) {
      return String(el.value || '').trim().toLowerCase();
    });
    var hasEmail = emails.some(function (e) { return e !== ''; });
    row.setAttribute('data-search', [domain, lang].concat(emails).filter(Boolean).join(' '));
    row.setAttribute('data-has-email', hasEmail ? '1' : '0');
    // Checkpoint sheets use Emailed / Not emailed — do not overwrite from email readiness.
    if (isCheckpointSheet || row.getAttribute('data-email-sent') != null) {
      return;
    }
    var status = row.querySelector('[data-swe-status], .swe-status-badge');
    if (status && !row.classList.contains('camp-add-row')) {
      status.classList.toggle('is-ready', hasEmail);
      status.classList.toggle('is-open', !hasEmail);
      status.textContent = hasEmail ? 'Ready' : 'Needs email';
    }
  }

  function updateSentStats(data) {
    if (!data) return;
    if (typeof data.sent === 'number' && sentLabel) {
      sentLabel.textContent = String(data.sent);
    }
    if (typeof data.unsent === 'number' && unsentLabel) {
      unsentLabel.textContent = String(data.unsent);
    }
    document.querySelectorAll('[data-camp-copy-domains]').forEach(function (btn) {
      var label = String(btn.getAttribute('data-copy-label') || 'all');
      if (label === 'not emailed') {
        btn.disabled = !(typeof data.unsent === 'number' && data.unsent > 0);
      } else if (label === 'emailed') {
        btn.disabled = !(typeof data.sent === 'number' && data.sent > 0);
      } else {
        var total = typeof data.total === 'number'
          ? data.total
          : ((typeof data.sent === 'number' ? data.sent : 0)
            + (typeof data.unsent === 'number' ? data.unsent : 0));
        btn.disabled = total < 1;
      }
    });
  }

  /** Update one campaign row's emailed UI without reloading (keeps scroll position). */
  function setRowEmailedState(row, emailed) {
    if (!row) return;
    var sent = !!emailed;
    row.setAttribute('data-email-sent', sent ? '1' : '0');
    row.classList.toggle('swe-row-emailed', sent);

    var status = row.querySelector('[data-swe-status]');
    if (status) {
      status.classList.toggle('is-emailed', sent);
      status.classList.toggle('is-open', !sent);
      status.classList.remove('is-ready', 'is-archive');
      status.textContent = sent ? 'Emailed' : 'Not emailed';
    }

    var markBtn = row.querySelector('[data-sheet-action="mark"]');
    if (markBtn) {
      markBtn.textContent = sent ? 'Clear emailed' : 'Mark emailed';
      markBtn.title = sent
        ? 'Clear emailed mark on this site only'
        : 'Mark this site as emailed';
      markBtn.classList.toggle('secondary', sent);
      markBtn.setAttribute('data-email-sent', sent ? '0' : '1');
    }
  }

  function applyEmailedUpTo(siteId, emailed) {
    var maxId = parseInt(siteId, 10) || 0;
    document.querySelectorAll('[data-swe-row][data-site-id]').forEach(function (row) {
      var id = parseInt(row.getAttribute('data-site-id') || '0', 10);
      if (id > 0 && id <= maxId) {
        setRowEmailedState(row, emailed);
      }
    });
  }

  var filterTimer = null;
  function sheetActionRows() {
    return document.querySelectorAll('[data-swe-row]');
  }
  function scheduleFilterRows() {
    if (filterTimer) window.clearTimeout(filterTimer);
    var q = searchInput ? String(searchInput.value || '').trim() : '';
    if (!q) {
      filterTimer = null;
      filterRows();
      return;
    }
    filterTimer = window.setTimeout(function () {
      filterTimer = null;
      filterRows();
    }, 160);
  }
  function maybeRefilter() {
    if (searchInput && String(searchInput.value || '').trim()) {
      scheduleFilterRows();
    }
  }

  function filterRows() {
    if (!searchInput) return;
    var q = String(searchInput.value || '').trim().toLowerCase();
    matchRows = [];
    clearHits();
    var shown = 0;
    sheetActionRows().forEach(function (row) {
      var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
      row.hidden = !hit;
      if (hit) {
        shown++;
        if (q) matchRows.push(row);
      }
    });
    if (empty) empty.hidden = !(q && shown === 0);
    if (meta) {
      if (!q) {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
      } else {
        meta.hidden = false;
        meta.textContent = !matchRows.length
          ? '0 · Enter = next · Ctrl+Enter = all pages'
          : (matchIndex >= 0
            ? (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails'
            : matchRows.length + ' · site + emails · Enter = next');
      }
    }
    document.querySelectorAll('[data-swe-q]').forEach(function (el) {
      el.value = String(searchInput.value || '');
    });
    if (window.SheetSelectUndo && typeof window.SheetSelectUndo.sync === 'function') {
      window.SheetSelectUndo.sync();
    }
    if (window.SheetSelectUndo && typeof window.SheetSelectUndo.syncPageStatus === 'function') {
      window.SheetSelectUndo.syncPageStatus(shown, !!q);
    }
  }

  function jump(dir) {
    if (!searchInput || !String(searchInput.value || '').trim()) return;
    filterRows();
    if (!matchRows.length) return;
    matchIndex = matchIndex < 0
      ? (dir > 0 ? 0 : matchRows.length - 1)
      : (matchIndex + dir + matchRows.length) % matchRows.length;
    var row = matchRows[matchIndex];
    clearHits();
    row.classList.add('sheet-search-hit');
    row.scrollIntoView({ block: 'center', behavior: 'auto' });
    if (meta) {
      meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      matchIndex = -1;
      scheduleFilterRows();
    });
    searchInput.addEventListener('search', function () {
      matchIndex = -1;
      scheduleFilterRows();
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (filterTimer) {
        window.clearTimeout(filterTimer);
        filterTimer = null;
      }
      if (e.ctrlKey || e.metaKey) {
        var url = new URL(window.location.href);
        var q = String(searchInput.value || '').trim();
        if (q) url.searchParams.set('q', q);
        else url.searchParams.delete('q');
        url.searchParams.delete('p');
        window.location.href = url.toString();
        return;
      }
      jump(e.shiftKey ? -1 : 1);
    });
    if (String(searchInput.value || '').trim()) {
      filterRows();
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-sheet-action]') : null;
    if (!btn || btn.disabled) return;
    var kind = String(btn.getAttribute('data-sheet-action') || '');
    var form = document.getElementById('camp-shared-' + kind)
      || document.getElementById('swe-shared-' + kind);
    if (!form) return;
    e.preventDefault();
    var confirmMsg = btn.getAttribute('data-confirm');
    if (confirmMsg && !window.confirm(confirmMsg)) return;
    var siteInput = form.querySelector('[name="site_id"]');
    if (siteInput) siteInput.value = String(btn.getAttribute('data-site-id') || '');
    if (kind === 'mark') {
      var sentInput = form.querySelector('[name="email_sent"]');
      if (sentInput) sentInput.value = String(btn.getAttribute('data-email-sent') || '1');
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
  });

  function removeRowFromDom(siteId, siteCount) {
    var gone = document.querySelector('[data-swe-row][data-site-id="' + siteId + '"]');
    if (gone) gone.remove();
    if (typeof siteCount === 'number' && totalLabel) {
      totalLabel.textContent = String(siteCount);
    }
    filterRows();
  }

  function saveRowForm(form, opts) {
    opts = opts || {};
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    if (!form.matches('[data-swe-save]')) {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Save failed');
          }
          return data;
        });
      })
      .then(function (data) {
        form.removeAttribute('data-swe-dirty');
        if (data.row_deleted) {
          var id = body.get('site_id');
          removeRowFromDom(id, data.site_count);
          setStatus('Removed ' + (data.domain || 'site') + ' (no emails left).');
          return data;
        }
        var row = form.closest('[data-swe-row]');
        var slots = data.slots || null;
        if (slots && form) {
          ['email1', 'email2', 'email3', 'email4'].forEach(function (name, idx) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) {
              el.value = slots[idx] || '';
              syncEmailClearButton(el);
            }
          });
        }
        if (data.domain && form) {
          var domainEl = form.querySelector('[name="domain"]');
          if (domainEl && data.domain) domainEl.value = data.domain;
        }
        refreshRowSearchIndex(row);
        setStatus(opts.quiet ? 'Autosaved.' : 'Saved.');
        maybeRefilter();
        return data;
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not save.', true);
        return null;
      })
      .then(function (data) {
        form.removeAttribute('data-busy');
        return data;
      });
  }

  function scheduleAutosave(form) {
    if (!form || !form.matches('[data-swe-save]')) return;
    form.setAttribute('data-swe-dirty', '1');
    var prev = autosaveTimers.get(form);
    if (prev) window.clearTimeout(prev);
    var timer = window.setTimeout(function () {
      autosaveTimers.delete(form);
      saveRowForm(form, { quiet: true });
    }, 400);
    autosaveTimers.set(form, timer);
  }

  document.addEventListener('paste', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email]')) return;
    var clip = e.clipboardData || window.clipboardData;
    var text = clip ? clip.getData('text') : '';
    if (!text) return;
    var multi = parseEmailPaste(text);
    if (multi.length <= 1) return;
    e.preventDefault();
    applyEmailPaste(input, text);
    var form = saveFormOf(input);
    var row = input.closest('[data-swe-row]');
    refreshRowSearchIndex(row);
    maybeRefilter();
    setStatus('Pasted ' + Math.min(multi.length, 4) + ' emails.');
    if (form) {
      var prev = autosaveTimers.get(form);
      if (prev) {
        window.clearTimeout(prev);
        autosaveTimers.delete(form);
      }
      form.setAttribute('data-swe-dirty', '1');
      saveRowForm(form, { quiet: true });
    }
  });

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = saveFormOf(input);
    if (!form) return;
    refreshRowSearchIndex(input.closest('[data-swe-row]'));
    maybeRefilter();
    scheduleAutosave(form);
    if (input.matches('[data-swe-email]') && String(input.value || '').trim() === '') {
      setStatus('Email cleared.');
    }
  });

  document.addEventListener('blur', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = saveFormOf(input);
    if (!form) return;
    var prev = autosaveTimers.get(form);
    if (prev) {
      window.clearTimeout(prev);
      autosaveTimers.delete(form);
    }
    saveRowForm(form, { quiet: true });
  }, true);

  function postAjaxForm(form, failLabel) {
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || failLabel);
          }
          return { data: data, siteId: body.get('site_id') };
        });
      })
      .catch(function (err) {
        setStatus(err.message || failLabel, true);
        form.removeAttribute('data-busy');
        return null;
      });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches) return;

    if (form.matches('[data-swe-save]')) {
      e.preventDefault();
      var prev = autosaveTimers.get(form);
      if (prev) {
        window.clearTimeout(prev);
        autosaveTimers.delete(form);
      }
      saveRowForm(form, { quiet: true });
      return;
    }

    if (form.matches('[data-swe-clear-all-emailed]')) {
      e.preventDefault();
      setStatus('Clearing all emailed marks…', false, true);
      postAjaxForm(form, 'Could not clear emailed marks').then(function (result) {
        if (!result) return;
        var data = result.data;
        document.querySelectorAll('[data-swe-row][data-site-id]').forEach(function (row) {
          setRowEmailedState(row, false);
        });
        updateSentStats(data);
        if (window.SheetSelectUndo && typeof window.SheetSelectUndo.applyState === 'function') {
          window.SheetSelectUndo.applyState(data);
        }
        setStatus(
          'Cleared all emailed marks'
          + (typeof data.cleared === 'number' ? ' · ' + data.cleared + ' sites' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
        if (typeof data.sent === 'number' && data.sent < 1) {
          form.hidden = true;
        }
      });
      return;
    }

    if (form.matches('[data-swe-mark]')) {
      e.preventDefault();
      var markSent = String((form.querySelector('[name="email_sent"]') || {}).value || '') === '1';
      setStatus(markSent ? 'Marking emailed…' : 'Clearing emailed mark…', false, true);
      postAjaxForm(form, 'Could not update emailed mark').then(function (result) {
        if (!result) return;
        var data = result.data;
        var id = result.siteId;
        var rowEl = document.querySelector('[data-swe-row][data-site-id="' + id + '"]');
        var nextSent = typeof data.email_sent === 'boolean' ? data.email_sent : markSent;
        setRowEmailedState(rowEl, nextSent);
        updateSentStats(data);
        if (window.SheetSelectUndo && typeof window.SheetSelectUndo.applyState === 'function') {
          window.SheetSelectUndo.applyState(data);
        }
        setStatus(
          (nextSent ? 'Marked emailed: ' : 'Cleared emailed mark: ')
          + (data.domain || 'site')
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (form.matches('[data-swe-mark-upto]')) {
      e.preventDefault();
      setStatus('Marking emailed up to here…', false, true);
      postAjaxForm(form, 'Could not mark checkpoint').then(function (result) {
        if (!result) return;
        var data = result.data;
        applyEmailedUpTo(result.siteId, true);
        updateSentStats(data);
        if (window.SheetSelectUndo && typeof window.SheetSelectUndo.applyState === 'function') {
          window.SheetSelectUndo.applyState(data);
        }
        setStatus(
          'Marked emailed up to ' + (data.domain || 'site')
          + (typeof data.marked === 'number' ? ' · ' + data.marked + ' newly marked' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (form.matches('[data-swe-clear-upto]')) {
      e.preventDefault();
      setStatus('Clearing emailed up to here…', false, true);
      postAjaxForm(form, 'Could not clear checkpoint').then(function (result) {
        if (!result) return;
        var data = result.data;
        applyEmailedUpTo(result.siteId, false);
        updateSentStats(data);
        if (window.SheetSelectUndo && typeof window.SheetSelectUndo.applyState === 'function') {
          window.SheetSelectUndo.applyState(data);
        }
        setStatus(
          'Cleared emailed up to ' + (data.domain || 'site')
          + (typeof data.cleared === 'number' ? ' · ' + data.cleared + ' cleared' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (!form.matches('[data-swe-remove]')) return;
    e.preventDefault();
    setStatus('Removing site…', false, true);
    showProcessing('Removing site…');
    postAjaxForm(form, 'Remove failed').then(function (result) {
      if (!result) {
        hideProcessing();
        return;
      }
      removeRowFromDom(result.siteId, result.data.site_count);
      updateSentStats(result.data);
      if (window.SheetSelectUndo && typeof window.SheetSelectUndo.applyState === 'function') {
        window.SheetSelectUndo.applyState(result.data);
      }
      setStatus('Removed ' + (result.data.domain || 'site') + '.');
      form.removeAttribute('data-busy');
      hideProcessing();
    });
  });

  // Admin (+) add row: reveal inline site + 4 emails form
  var addRow = document.getElementById('camp-add-row');
  var addDomain = document.getElementById('camp_add_domain');
  var emptyState = document.getElementById('camp-empty-state');

  function openAddRow() {
    if (!addRow) return;
    addRow.hidden = false;
    if (emptyState) emptyState.hidden = true;
    if (addDomain) {
      try { addDomain.focus({ preventScroll: false }); } catch (err) { addDomain.focus(); }
      addDomain.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }

  function closeAddRow() {
    if (!addRow) return;
    addRow.hidden = true;
    var form = document.getElementById('camp-add-form');
    if (form) form.reset();
    if (emptyState && !document.querySelector('[data-swe-row]')) {
      emptyState.hidden = false;
    }
  }

  document.querySelectorAll('[data-camp-add-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (addRow && !addRow.hidden && document.activeElement === addDomain) {
        closeAddRow();
        return;
      }
      openAddRow();
    });
  });
  document.querySelectorAll('[data-camp-add-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      closeAddRow();
    });
  });
  if (window.location.hash === '#add-site') {
    openAddRow();
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        if (!document.execCommand('copy')) reject(new Error('Copy failed'));
        else resolve();
      } catch (e) {
        reject(e);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  function bindCopyDomainsButton(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-export-url');
      if (!url) return;
      var label = String(btn.getAttribute('data-copy-label') || 'all');
      var wasDisabled = btn.disabled;
      btn.disabled = true;
      var loading =
        label === 'not emailed' ? 'Loading not-emailed domains…'
          : label === 'emailed' ? 'Loading emailed domains…'
            : 'Loading domains…';
      setStatus(loading, false, true);
      showProcessing(loading);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/plain' } })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not load domains.');
          return res.text();
        })
        .then(function (text) {
          text = String(text || '').replace(/\r\n/g, '\n').trim();
          if (!text) {
            throw new Error(
              label === 'not emailed' ? 'No not-emailed domains to copy.'
                : label === 'emailed' ? 'No emailed domains to copy.'
                  : 'No domains to copy yet.'
            );
          }
          var lines = text.split('\n').filter(Boolean);
          return copyText(text).then(function () {
            var kind =
              label === 'not emailed' ? ' not-emailed'
                : label === 'emailed' ? ' emailed'
                  : '';
            setStatus(
              'Copied ' + lines.length + kind + ' domain' + (lines.length === 1 ? '' : 's') + '.'
            );
          });
        })
        .catch(function (err) {
          setStatus(err.message || 'Copy failed.', true);
        })
        .then(function () {
          hideProcessing();
          btn.disabled = wasDisabled;
        });
    });
  }

  document.querySelectorAll('[data-camp-copy-domains]').forEach(bindCopyDomainsButton);
})();
