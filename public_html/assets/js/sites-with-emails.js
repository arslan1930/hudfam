(function () {
  'use strict';

  var statusEl = document.getElementById('swe_status');
  var copyBtn = document.getElementById('swe_copy_emails');
  var totalLabel = document.getElementById('swe_total_label');
  var searchInput = document.getElementById('swe-row-search');
  var pushBtn = document.getElementById('swe-push-btn');
  var autosaveTimers = new WeakMap();

  function setStatus(msg, isError) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('is-error', 'is-ok');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
    statusEl.classList.toggle('is-ok', !isError);
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

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var url = copyBtn.getAttribute('data-export-url');
      if (!url) return;
      copyBtn.disabled = true;
      setStatus('Loading emails…');
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/plain' } })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not load emails.');
          return res.text();
        })
        .then(function (text) {
          text = String(text || '').replace(/\r\n/g, '\n').trim();
          if (!text) throw new Error('No emails to copy yet.');
          var lines = text.split('\n').filter(Boolean);
          return copyText(text).then(function () {
            setStatus('Copied ' + lines.length + ' email' + (lines.length === 1 ? '' : 's') + '.');
          });
        })
        .catch(function (err) {
          setStatus(err.message || 'Copy failed.', true);
        })
        .then(function () {
          copyBtn.disabled = false;
        });
    });
  }

  // Search: keep Site + Emails columns together (whole row)
  var matchRows = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-swe-row-search-meta]');
  var empty = document.querySelector('[data-swe-row-search-empty]');

  function clearHits() {
    document.querySelectorAll('[data-swe-row].sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
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
      // Keep plausible emails; also keep other tokens so user can fix typos after paste
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

  /**
   * Paste into any email box: if clipboard has multiple emails, fill all 4 fields.
   * Single value pastes into the focused box only.
   */
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

  function countReadyRows() {
    var n = 0;
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
      if (row.getAttribute('data-has-email') === '1') n++;
    });
    return n;
  }

  function syncPushButton() {
    if (!pushBtn) return;
    var ready = countReadyRows();
    // Page may have more rows on other pages — only enable from server if already enabled,
    // or enable when this page has at least one ready row.
    var serverReady = !pushBtn.disabled || ready > 0;
    if (ready > 0) {
      pushBtn.disabled = false;
      pushBtn.title = 'Push sites that have at least one email';
    } else if (pushBtn.getAttribute('data-server-ready') === '1') {
      // Keep server state when other pages may have ready rows
      pushBtn.disabled = false;
    }
    // If server had none and this page still has none, stay disabled
    if (ready < 1 && pushBtn.getAttribute('data-server-ready') !== '1') {
      pushBtn.disabled = true;
      pushBtn.title = 'Add at least one email on a site first';
    }
    void serverReady;
  }

  function refreshRowSearchIndex(row) {
    if (!row) return;
    var form = row.querySelector('[data-swe-save]');
    if (!form) return;
    var domainEl = form.querySelector('[name="domain"]');
    var domain = String((domainEl && domainEl.value) || '').trim().toLowerCase();
    var emails = ['email1', 'email2', 'email3', 'email4'].map(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      return String((el && el.value) || '').trim().toLowerCase();
    });
    var hasEmail = emails.some(function (e) { return e !== ''; });
    row.setAttribute('data-search', [domain].concat(emails).join(' '));
    row.setAttribute('data-has-email', hasEmail ? '1' : '0');
    syncPushButton();
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

  if (pushBtn && !pushBtn.disabled) {
    pushBtn.setAttribute('data-server-ready', '1');
  }

  function saveRowForm(form, opts) {
    opts = opts || {};
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    // Manual "Add site row" form still does a normal submit
    if (!form.matches('[data-swe-save]')) {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    if (searchInput) body.set('q', String(searchInput.value || ''));
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
        var row = form.closest('[data-swe-row]');
        refreshRowSearchIndex(row);
        if (!opts.quiet) {
          setStatus('Saved.');
        } else {
          setStatus('Autosaved.');
        }
        filterRows();
        syncPushButton();
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
    var prev = autosaveTimers.get(form);
    if (prev) window.clearTimeout(prev);
    var timer = window.setTimeout(function () {
      autosaveTimers.delete(form);
      saveRowForm(form, { quiet: true });
    }, 400);
    autosaveTimers.set(form, timer);
  }

  // × clear is handled by email-field-clear.js; its input event triggers autosave below.

  // Paste: fill up to 4 email boxes at once (no need to click each box)
  document.addEventListener('paste', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email]')) return;
    var clip = (e.clipboardData || window.clipboardData);
    var text = clip ? clip.getData('text') : '';
    if (!text) return;
    var multi = parseEmailPaste(text);
    if (multi.length <= 1) return; // email-field-clear.js syncs ×; let normal paste through
    e.preventDefault();
    applyEmailPaste(input, text);
    var form = input.closest('[data-swe-save]');
    var row = input.closest('[data-swe-row]');
    refreshRowSearchIndex(row);
    filterRows();
    setStatus('Pasted ' + Math.min(multi.length, 4) + ' email' + (multi.length === 1 ? '' : 's') + '.');
    if (form) scheduleAutosave(form);
  });

  // Autosave on every email / domain edit (also runs after × clear)
  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = input.closest('[data-swe-save]');
    if (!form) return;
    refreshRowSearchIndex(form.closest('[data-swe-row]'));
    filterRows();
    scheduleAutosave(form);
    if (input.matches('[data-swe-email]') && String(input.value || '').trim() === '') {
      setStatus('Email cleared.');
    }
  });

  document.addEventListener('blur', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = input.closest('[data-swe-save]');
    if (!form) return;
    var prev = autosaveTimers.get(form);
    if (prev) {
      window.clearTimeout(prev);
      autosaveTimers.delete(form);
    }
    saveRowForm(form, { quiet: true });
  }, true);

  // Remove row (ajax); row forms no longer have a Save submit
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches) return;

    if (form.matches('[data-swe-save]')) {
      // No Save button — keep Enter-in-field from full page reload; autosave instead
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
    if (form.getAttribute('data-busy') === '1') return;
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    if (searchInput) body.set('q', String(searchInput.value || ''));
    form.setAttribute('data-busy', '1');
    fetch(form.getAttribute('action') || window.location.href, {
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
            throw new Error((data && data.error) || 'Remove failed');
          }
          return data;
        });
      })
      .then(function (data) {
        var id = body.get('site_id');
        var row = document.querySelector('[data-swe-row][data-site-id="' + id + '"]');
        if (row) row.remove();
        if (typeof data.site_count === 'number' && totalLabel) {
          totalLabel.textContent = String(data.site_count);
        }
        setStatus('Removed complete row for ' + (data.domain || 'site') + '.');
        filterRows();
        syncPushButton();
        if (data.redirect) {
          window.setTimeout(function () { window.location.href = data.redirect; }, 250);
        }
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not remove.', true);
        form.removeAttribute('data-busy');
      });
  });
})();
