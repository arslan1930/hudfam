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
  if (!input) return;

  var matchRows = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-extracted-url-search-meta]');
  var empty = document.querySelector('[data-extracted-url-search-empty]');

  function clearHits() {
    document.querySelectorAll('#extracted-plain-list .sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function filterUrls() {
    var q = String(input.value || '').trim().toLowerCase();
    var rows = document.querySelectorAll('[data-extracted-url-row]');
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
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
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

  input.addEventListener('input', function () {
    matchIndex = -1;
    filterUrls();
  });
  input.addEventListener('search', function () {
    matchIndex = -1;
    filterUrls();
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

  filterUrls();
})();
