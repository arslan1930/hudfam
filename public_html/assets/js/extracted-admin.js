(function () {
  'use strict';

  // --- Copy all (streamed plain text) ---
  var btn = document.getElementById('extracted_copy_all');
  var status = document.getElementById('extracted_copy_status');

  function setStatus(msg, isError) {
    if (!status) return;
    if (!msg) {
      status.hidden = true;
      status.textContent = '';
      status.classList.remove('is-error');
      return;
    }
    status.hidden = false;
    status.textContent = msg;
    status.classList.toggle('is-error', !!isError);
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

  if (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-export-url');
      var count = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
      if (!url) return;

      btn.disabled = true;
      setStatus('Loading ' + (count ? count.toLocaleString() : '') + ' site names…');

      fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'text/plain' }
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not load site list (' + res.status + ').');
          return res.text();
        })
        .then(function (text) {
          text = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
          if (!text) throw new Error('No sites to copy.');
          var lines = text.split('\n').filter(Boolean);
          return copyText(text).then(function () {
            setStatus('Copied ' + lines.length.toLocaleString() + ' URL' + (lines.length === 1 ? '' : 's') + '.');
          });
        })
        .catch(function (err) {
          setStatus(
            (err && err.message ? err.message : 'Copy failed.') +
            ' Use Download .txt if the list is very large.',
            true
          );
        })
        .then(function () {
          btn.disabled = count < 1;
        });
    });
  }

  // --- Search + Enter jump on plain URL list ---
  var input = document.getElementById('extracted-url-search');
  var list = document.getElementById('extracted-plain-list');
  var totalLabel = document.getElementById('extracted_total_label');
  var countryKey = '';
  try {
    var params = new URLSearchParams(window.location.search);
    countryKey = 'txf-extracted-url-q-' + String(params.get('country') || '');
  } catch (e0) {
    countryKey = 'txf-extracted-url-q';
  }

  var matchRows = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-extracted-url-search-meta]');
  var empty = document.querySelector('[data-extracted-url-search-empty]');
  var filterTimer = null;
  var cachedRows = null;

  function extractedRows() {
    if (!cachedRows) cachedRows = document.querySelectorAll('[data-extracted-url-row]');
    return cachedRows;
  }

  function invalidateExtractedRows() {
    cachedRows = null;
  }
  document.addEventListener('hf-sheet-rows-changed', invalidateExtractedRows);

  function scheduleFilterUrls() {
    if (filterTimer) window.clearTimeout(filterTimer);
    var q = input ? String(input.value || '').trim() : '';
    if (!q) {
      filterTimer = null;
      filterUrls();
      return;
    }
    filterTimer = window.setTimeout(function () {
      filterTimer = null;
      filterUrls();
    }, 160);
  }

  function clearHits() {
    document.querySelectorAll('#extracted-plain-list .sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function syncRemoveQFields() {
    var q = input ? String(input.value || '') : '';
    document.querySelectorAll('[data-remove-q]').forEach(function (el) {
      el.value = q;
    });
  }

  function persistSearch() {
    if (!input || !countryKey) return;
    try {
      var q = String(input.value || '');
      if (q) localStorage.setItem(countryKey, q);
      else localStorage.removeItem(countryKey);
    } catch (e) { /* ignore */ }
    syncRemoveQFields();
  }

  function restoreSearch() {
    if (!input) return;
    // Prefer URL ?q=; otherwise restore last typed filter for this country.
    var urlQ = '';
    try {
      urlQ = String(new URLSearchParams(window.location.search).get('q') || '');
    } catch (e) { /* ignore */ }
    if (urlQ) {
      input.value = urlQ;
      return;
    }
    if (String(input.value || '').trim() !== '') return;
    try {
      var saved = localStorage.getItem(countryKey);
      if (saved) input.value = saved;
    } catch (e2) { /* ignore */ }
  }

  function filterUrls() {
    if (!input) return;
    var q = String(input.value || '').trim().toLowerCase();
    var rows = extractedRows();
    var shown = 0;
    matchRows = [];
    clearHits();
    rows.forEach(function (row) {
      var hay = String(row.getAttribute('data-search') || '');
      var hit = !q || hay.indexOf(q) !== -1;
      row.hidden = !hit;
      if (hit) {
        shown++;
        if (q) matchRows.push(row);
      }
    });
    if (empty) empty.hidden = !(q && shown === 0 && rows.length > 0);
    if (matchIndex >= matchRows.length) matchIndex = matchRows.length ? 0 : -1;
    if (meta) {
      if (q) {
        meta.hidden = false;
        if (!matchRows.length) {
          meta.textContent = '0 · Enter = next · Ctrl+Enter = search all pages';
        } else if (matchIndex >= 0) {
          meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
        } else {
          meta.textContent = matchRows.length + (matchRows.length === 1 ? ' match' : ' matches')
            + ' · Enter = next';
        }
      } else {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
      }
    }
    persistSearch();
  }

  function jumpToMatch(dir) {
    var q = String(input.value || '').trim();
    if (!q) return;
    filterUrls();
    if (!matchRows.length) return;
    if (matchIndex < 0) {
      matchIndex = dir > 0 ? 0 : matchRows.length - 1;
    } else {
      matchIndex = (matchIndex + dir + matchRows.length) % matchRows.length;
    }
    var row = matchRows[matchIndex];
    if (!row) return;
    clearHits();
    row.hidden = false;
    row.classList.add('sheet-search-hit');
    row.scrollIntoView({ block: 'center', behavior: 'auto' });
    if (meta) {
      meta.hidden = false;
      meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
    }
    window.setTimeout(function () {
      try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
      try {
        var len = String(input.value || '').length;
        input.setSelectionRange(len, len);
      } catch (err2) {}
    }, 0);
  }

  function searchAllPages() {
    var q = String(input.value || '').trim();
    var url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q);
    else url.searchParams.delete('q');
    url.searchParams.delete('p');
    window.location.href = url.toString();
  }

  function updateTotalLabel(n) {
    if (totalLabel) totalLabel.textContent = String(n);
    if (btn) {
      btn.setAttribute('data-count', String(n));
      btn.disabled = n < 1;
    }
  }

  // AJAX remove keeps the current search filter in place (no full reload).
  if (list) {
    list.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.matches || !form.matches('[data-remove-site]')) return;
      e.preventDefault();
      if (form.getAttribute('data-busy') === '1') return;

      var row = form.closest('[data-extracted-url-row]');
      var actionUrl = form.getAttribute('action') || window.location.href;
      var body = new URLSearchParams(new FormData(form));
      body.set('ajax', '1');
      if (input) body.set('q', String(input.value || ''));

      form.setAttribute('data-busy', '1');
      var submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;

      fetch(actionUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json'
        },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok || !data || data.ok === false) {
              throw new Error((data && data.error) || 'Could not remove site.');
            }
            return data;
          });
        })
        .then(function (data) {
          if (row) {
            row.remove();
            invalidateExtractedRows();
          }
          if (typeof data.site_count === 'number') updateTotalLabel(data.site_count);
          setStatus('Removed ' + (data.domain || 'site') + '. Search kept — continue removing.');
          filterUrls();
          if (data.redirect) {
            window.setTimeout(function () {
              window.location.href = data.redirect;
            }, 250);
            return;
          }
          if (input) {
            try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
          }
        })
        .catch(function (err) {
          setStatus(err.message || 'Could not remove site.', true);
          form.removeAttribute('data-busy');
          if (submitBtn) submitBtn.disabled = false;
        });
    });
  }

  if (input) {
    restoreSearch();
    input.addEventListener('input', function () {
      matchIndex = -1;
      scheduleFilterUrls();
    });
    input.addEventListener('search', function () {
      matchIndex = -1;
      scheduleFilterUrls();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (e.ctrlKey || e.metaKey) {
          searchAllPages();
          return;
        }
        jumpToMatch(e.shiftKey ? -1 : 1);
      }
    });
    if (String(input.value || '').trim()) {
      filterUrls();
    }
  }

  var searchAllBtn = document.getElementById('extracted_search_all_pages');
  if (searchAllBtn) {
    searchAllBtn.addEventListener('click', function () {
      searchAllPages();
    });
  }
})();

