/**
 * Our database · country folder
 * Copy all / Copy matches (streamed) + debounced whole-folder search + Enter jump.
 */
(function () {
  'use strict';

  function setStatus(el, msg, isError) {
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.classList.remove('is-error');
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
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

  function wireCopyButton(btn, statusEl) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-export-url');
      var count = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
      if (!url) return;
      btn.disabled = true;
      setStatus(
        statusEl,
        'Loading ' + (count ? count.toLocaleString() : '') + ' site name' + (count === 1 ? '' : 's') + '…'
      );
      fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'text/plain' },
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
            setStatus(
              statusEl,
              'Copied ' + lines.length.toLocaleString() + ' site' + (lines.length === 1 ? '' : 's') + '.'
            );
          });
        })
        .catch(function (err) {
          setStatus(
            statusEl,
            (err && err.message ? err.message : 'Copy failed.') +
              ' Use Download .txt / CSV if the list is very large.',
            true
          );
        })
        .then(function () {
          btn.disabled = count < 1;
        });
    });
  }

  var status = document.getElementById('prospect_copy_status');
  wireCopyButton(document.getElementById('prospect_copy_all'), status);
  wireCopyButton(document.getElementById('prospect_copy_matches'), status);

  // --- Debounced whole-folder server search + Enter jump on loaded page ---
  var input = document.getElementById('prospect-site-search');
  if (!input) return;

  var meta = document.querySelector('[data-prospect-site-search-meta]');
  var empty = document.querySelector('[data-prospect-site-search-empty]');
  var matchRows = [];
  var matchIndex = -1;
  var debounceTimer = null;
  var DEBOUNCE_MS = 300;
  var lastCommittedQ = String(input.value || '').trim();

  function rowsOnPage() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-prospect-site-row]'));
  }

  function clearHits() {
    document.querySelectorAll('[data-prospect-site-row].sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function refreshPageMatches() {
    matchRows = rowsOnPage();
    if (matchIndex >= matchRows.length) matchIndex = matchRows.length ? 0 : -1;
    updateMeta();
  }

  function updateMeta() {
    if (!meta) return;
    var q = String(input.value || '').trim();
    if (!q) {
      meta.hidden = true;
      meta.textContent = '';
      return;
    }
    meta.hidden = false;
    if (!matchRows.length) {
      meta.textContent = '0 on this page · Enter = next';
    } else if (matchIndex >= 0) {
      meta.textContent = matchIndex + 1 + ' of ' + matchRows.length + ' on page · Enter = next';
    } else {
      meta.textContent = matchRows.length + ' on page · Enter = next';
    }
    if (empty) empty.hidden = matchRows.length > 0;
  }

  function jump(dir) {
    if (!matchRows.length) {
      updateMeta();
      return;
    }
    if (matchIndex < 0) {
      matchIndex = dir > 0 ? 0 : matchRows.length - 1;
    } else {
      matchIndex = (matchIndex + dir + matchRows.length) % matchRows.length;
    }
    clearHits();
    var row = matchRows[matchIndex];
    if (row) {
      row.classList.add('sheet-search-hit');
      try {
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } catch (e) {
        row.scrollIntoView(true);
      }
    }
    updateMeta();
  }

  function commitServerSearch(q) {
    q = String(q || '').trim();
    if (q === lastCommittedQ) {
      refreshPageMatches();
      return;
    }
    lastCommittedQ = q;
    var url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q);
    else url.searchParams.delete('q');
    url.searchParams.delete('p');
    if (meta) {
      meta.hidden = false;
      meta.textContent = q ? 'Searching whole folder…' : 'Loading…';
    }
    window.location.href = url.toString();
  }

  function scheduleSearch() {
    if (debounceTimer) window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () {
      commitServerSearch(input.value);
    }, DEBOUNCE_MS);
  }

  input.addEventListener('input', function () {
    matchIndex = -1;
    if (meta) {
      meta.hidden = false;
      meta.textContent = 'Searching whole folder…';
    }
    scheduleSearch();
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (debounceTimer) {
        window.clearTimeout(debounceTimer);
        debounceTimer = null;
        // Jump uses current page; if query changed, commit first.
        var q = String(input.value || '').trim();
        if (q !== lastCommittedQ) {
          commitServerSearch(q);
          return;
        }
      }
      jump(e.shiftKey ? -1 : 1);
    }
  });

  refreshPageMatches();
})();
