(function () {
  'use strict';

  var statusEl = document.getElementById('swe_status');
  var copyBtn = document.getElementById('swe_copy_emails');
  var totalLabel = document.getElementById('swe_total_label');
  var searchInput = document.getElementById('swe-row-search');
  var autosaveTimers = new WeakMap();

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
    row.classList.toggle('swe-row-no-email', !hasEmail);
    var tag = row.querySelector('[data-swe-no-email-tag]');
    var siteBlock = row.querySelector('.swe-site-block');
    if (!hasEmail && siteBlock && !tag) {
      tag = document.createElement('span');
      tag.className = 'swe-no-email-tag';
      tag.setAttribute('data-swe-no-email-tag', '');
      tag.textContent = 'No email — cannot push';
      siteBlock.appendChild(tag);
    } else if (hasEmail && tag) {
      tag.remove();
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

  function saveRowForm(form, opts) {
    opts = opts || {};
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    var saveBtn = form.querySelector('button[type="submit"]:not([form])');
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
        setStatus(opts.quiet ? 'Email updated.' : 'Saved.');
        if (saveBtn && !opts.quiet) {
          var old = saveBtn.textContent;
          saveBtn.textContent = 'Saved';
          window.setTimeout(function () { saveBtn.textContent = old || 'Save'; }, 1000);
        }
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

  // Autosave when clearing/editing emails with Backspace (debounced)
  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email]')) return;
    var form = input.closest('[data-swe-save]');
    if (!form) return;
    refreshRowSearchIndex(form.closest('[data-swe-row]'));
    filterRows();
    var prev = autosaveTimers.get(form);
    if (prev) window.clearTimeout(prev);
    var timer = window.setTimeout(function () {
      autosaveTimers.delete(form);
      saveRowForm(form, { quiet: true });
    }, 450);
    autosaveTimers.set(form, timer);
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

  // Manual Save / Remove keep search context
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
      saveRowForm(form, { quiet: false });
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
        setStatus('Removed complete row for ' + (data.domain || 'site') + '. Search kept.');
        filterRows();
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
