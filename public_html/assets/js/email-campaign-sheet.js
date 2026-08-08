/**
 * Email campaign country sheet — same site + up to 4 emails workflow as Sites with emails.
 * Autosave, multi-email paste, row remove, in-sheet search.
 */
(function () {
  'use strict';

  var statusEl = document.getElementById('swe_status');
  var totalLabel = document.getElementById('swe_total_label');
  var searchInput = document.getElementById('swe-row-search');
  var autosaveTimers = new WeakMap();

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
    var status = row.querySelector('[data-swe-status], .swe-status-badge');
    if (status && !row.classList.contains('camp-add-row')) {
      status.classList.toggle('is-ready', hasEmail);
      status.classList.toggle('is-open', !hasEmail);
      status.textContent = hasEmail ? 'Ready' : 'Needs email';
    }
  }

  function filterRows() {
    if (!searchInput) return;
    var q = String(searchInput.value || '').trim().toLowerCase();
    matchRows = [];
    clearHits();
    var shown = 0;
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
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
        return;
      }
      meta.hidden = false;
      meta.textContent = !matchRows.length
        ? '0 · Enter = next · Ctrl+Enter = all pages'
        : (matchIndex >= 0
          ? (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails'
          : matchRows.length + ' · site + emails · Enter = next');
    }
    document.querySelectorAll('[data-swe-q]').forEach(function (el) {
      el.value = String(searchInput.value || '');
    });
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
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (meta) {
      meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      matchIndex = -1;
      filterRows();
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
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
    filterRows();
  }

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
        filterRows();
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
    filterRows();
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
    filterRows();
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
})();
