/**
 * Our database · country folder
 * Copy all / Copy matches (streamed) + AJAX whole-folder search (keeps focus)
 * + Enter jump on loaded page. Clipboard fail → auto-download .txt fallback.
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

  function downloadTextFile(filename, text) {
    var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || 'sites-our-database.txt';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    window.setTimeout(function () {
      try {
        URL.revokeObjectURL(url);
      } catch (e) { /* ignore */ }
      if (a.parentNode) a.parentNode.removeChild(a);
    }, 500);
  }

  function wireCopyButton(btn, statusEl) {
    if (!btn || btn.getAttribute('data-copy-wired') === '1') return;
    btn.setAttribute('data-copy-wired', '1');
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-export-url');
      var filename = btn.getAttribute('data-download-name') || 'sites-our-database.txt';
      var count = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
      if (!url) return;
      btn.disabled = true;
      setStatus(
        statusEl,
        'Loading ' + (count ? count.toLocaleString() : '') + ' site' + (count === 1 ? '' : 's') + '…'
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
          return copyText(text).then(
            function () {
              setStatus(
                statusEl,
                'Copied ' + lines.length.toLocaleString() + ' site' + (lines.length === 1 ? '' : 's') + '.'
              );
            },
            function () {
              downloadTextFile(filename, text + '\n');
              setStatus(
                statusEl,
                'Clipboard blocked — downloaded ' +
                  lines.length.toLocaleString() +
                  ' site' +
                  (lines.length === 1 ? '' : 's') +
                  ' as ' +
                  filename +
                  '.'
              );
            }
          );
        })
        .catch(function (err) {
          var dl = btn.getAttribute('data-fallback-download-url');
          if (dl) {
            window.location.href = dl;
            setStatus(statusEl, 'Copy failed — started download instead.', true);
            return;
          }
          setStatus(
            statusEl,
            (err && err.message ? err.message : 'Copy failed.') +
              ' Use Download .txt / CSV if the list is very large.',
            true
          );
        })
        .then(function () {
          var stillCount = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
          btn.disabled = stillCount < 1 && btn.id === 'prospect_copy_all';
        });
    });
  }

  var status = document.getElementById('prospect_copy_status');
  wireCopyButton(document.getElementById('prospect_copy_all'), status);
  wireCopyButton(document.getElementById('prospect_copy_matches'), status);

  // --- AJAX whole-folder server search (no full reload) + Enter jump ---
  var input = document.getElementById('prospect-site-search');
  if (!input) return;

  var meta = document.querySelector('[data-prospect-site-search-meta]');
  var emptyHint = document.querySelector('[data-prospect-site-search-empty]');
  var tbody = document.getElementById('prospect-site-tbody');
  var tableEl = document.getElementById('prospect-site-table');
  var emptyEl = document.getElementById('prospect-site-empty');
  var pagerEl = document.getElementById('prospect-site-pager');
  var matchActions = document.getElementById('prospect-match-actions');
  var matchLine = document.getElementById('prospect_match_line');
  var matchCountLabel = document.getElementById('prospect_match_count_label');
  var matchPlural = document.getElementById('prospect_match_plural');
  var matchQLabel = document.getElementById('prospect_match_q_label');
  var qHidden = document.getElementById('prospect_q_hidden');
  var matchRows = [];
  var matchIndex = -1;
  var debounceTimer = null;
  var DEBOUNCE_MS = 300;
  var lastCommittedQ = String(input.value || '').trim();
  var lastCommittedPage = (function () {
    try {
      return parseInt(new URL(window.location.href).searchParams.get('p') || '1', 10) || 1;
    } catch (e) {
      return 1;
    }
  })();
  var reqSeq = 0;
  var searching = false;

  function keepFocus() {
    if (document.activeElement === input) return;
    try {
      input.focus({ preventScroll: true });
    } catch (e) {
      input.focus();
    }
  }

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
    matchIndex = -1;
    clearHits();
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
    if (searching) {
      meta.textContent = 'Searching whole folder…';
      return;
    }
    if (!matchRows.length) {
      meta.textContent = '0 on this page · Enter = next';
    } else if (matchIndex >= 0) {
      meta.textContent = matchIndex + 1 + ' of ' + matchRows.length + ' on page · Enter = next';
    } else {
      meta.textContent = matchRows.length + ' on page · Enter = next';
    }
    if (emptyHint) emptyHint.hidden = matchRows.length > 0;
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
    keepFocus();
  }

  function syncAddressBar(q, pageNum) {
    var url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q);
    else url.searchParams.delete('q');
    if (pageNum && pageNum > 1) url.searchParams.set('p', String(pageNum));
    else url.searchParams.delete('p');
    url.searchParams.delete('ajax');
    history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function updateMatchActions(data) {
    var q = String(data.q || '').trim();
    var n = Number(data.match_count || 0);
    var show = q !== '' && n > 0 && data.export_matches_url;

    if (matchLine) {
      matchLine.hidden = q === '';
      if (q !== '') {
        if (matchCountLabel) matchCountLabel.textContent = String(n);
        if (matchPlural) matchPlural.textContent = n === 1 ? '' : 'es';
        if (matchQLabel) matchQLabel.textContent = q;
      }
    }

    if (!matchActions) return;
    if (!show) {
      matchActions.hidden = true;
      return;
    }
    matchActions.hidden = false;
    var copyBtn = document.getElementById('prospect_copy_matches');
    var txtA = document.getElementById('prospect_matches_txt');
    var csvA = document.getElementById('prospect_matches_csv');
    if (copyBtn) {
      copyBtn.setAttribute('data-export-url', data.export_matches_url || '');
      copyBtn.setAttribute('data-download-name', data.download_matches_name || 'matches-our-database.txt');
      copyBtn.setAttribute('data-fallback-download-url', data.download_matches_txt || '');
      copyBtn.setAttribute('data-count', String(n));
      wireCopyButton(copyBtn, status);
    }
    if (txtA) txtA.setAttribute('href', data.download_matches_txt || '#');
    if (csvA) csvA.setAttribute('href', data.download_matches_csv || '#');
  }

  function updatePager(data) {
    if (!pagerEl) return;
    var hasRows = !!data.has_rows;
    pagerEl.hidden = !hasRows;
    if (!hasRows) return;

    var pageNum = Number(data.page || 1) || 1;
    var pages = Number(data.pages || 1) || 1;
    var perPage = Number(data.per_page || 50) || 50;
    var qs = String(data.qs || '');
    var actions = pagerEl.querySelector('.actions');
    if (!actions) return;

    var label = actions.querySelector('[data-prospect-page-label]');
    if (label) {
      label.textContent = 'Page ' + pageNum + ' / ' + pages + ' · ' + perPage + ' per page';
    }

    var perPageForm = actions.querySelector('form');
    Array.prototype.slice.call(actions.querySelectorAll('a')).forEach(function (a) {
      if (perPageForm && perPageForm.contains(a)) return;
      a.remove();
    });

    if (pageNum > 1) {
      var prev = document.createElement('a');
      prev.href = '?' + qs + '&p=' + (pageNum - 1);
      prev.textContent = 'Prev';
      actions.insertBefore(prev, label || actions.firstChild);
    }
    if (pageNum < pages) {
      var next = document.createElement('a');
      next.href = '?' + qs + '&p=' + (pageNum + 1);
      next.textContent = 'Next';
      if (label && label.nextSibling) {
        actions.insertBefore(next, label.nextSibling);
      } else if (label) {
        actions.appendChild(next);
      } else {
        actions.insertBefore(next, actions.firstChild);
      }
    }

    if (qHidden) qHidden.value = String(data.q || '');
    if (perPageForm) {
      var qInput = perPageForm.querySelector('input[name="q"]');
      if (qInput) qInput.value = String(data.q || '');
    }
  }

  function applyPayload(data) {
    if (!data || !data.ok) return;
    if (tbody) {
      tbody.innerHTML = data.rows_html || '';
    }
    if (tableEl) {
      tableEl.hidden = !data.has_rows;
    }
    if (emptyEl) {
      emptyEl.hidden = !!data.has_rows;
      var emptyText = emptyEl.querySelector('[data-prospect-empty-text]');
      if (emptyText) {
        emptyText.textContent = (data.q || '').trim()
          ? 'No sites in this country match your search.'
          : 'No sites in this country yet.';
      }
      var addLink = emptyEl.querySelector('[data-prospect-empty-add]');
      if (addLink) {
        addLink.hidden = (data.q || '').trim() !== '';
      }
    }
    updatePager(data);
    updateMatchActions(data);
    lastCommittedQ = String(data.q || '').trim();
    lastCommittedPage = Number(data.page || 1) || 1;
    syncAddressBar(lastCommittedQ, lastCommittedPage);
    searching = false;
    refreshPageMatches();
    keepFocus();
  }

  function buildAjaxUrl(q, pageNum) {
    var url = new URL(window.location.href);
    url.searchParams.set('ajax', '1');
    if (q) url.searchParams.set('q', q);
    else url.searchParams.delete('q');
    if (pageNum && pageNum > 1) url.searchParams.set('p', String(pageNum));
    else url.searchParams.delete('p');
    return url.toString();
  }

  function commitServerSearch(q, pageNum) {
    q = String(q || '').trim();
    pageNum = pageNum || 1;
    searching = true;
    updateMeta();
    var seq = ++reqSeq;
    fetch(buildAjaxUrl(q, pageNum), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('search failed');
        var ct = (res.headers.get('content-type') || '').toLowerCase();
        if (ct.indexOf('application/json') === -1) {
          throw new Error('not json');
        }
        return res.json();
      })
      .then(function (data) {
        if (seq !== reqSeq) return;
        if (!data || !data.ok) throw new Error('bad payload');
        applyPayload(data);
      })
      .catch(function () {
        if (seq !== reqSeq) return;
        searching = false;
        // Fallback full navigation if AJAX unavailable
        var url = new URL(window.location.href);
        if (q) url.searchParams.set('q', q);
        else url.searchParams.delete('q');
        if (pageNum > 1) url.searchParams.set('p', String(pageNum));
        else url.searchParams.delete('p');
        url.searchParams.delete('ajax');
        window.location.href = url.toString();
      });
  }

  function scheduleSearch() {
    if (debounceTimer) window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () {
      commitServerSearch(input.value, 1);
    }, DEBOUNCE_MS);
  }

  input.addEventListener('input', function () {
    matchIndex = -1;
    searching = true;
    if (meta) {
      meta.hidden = false;
      meta.textContent = 'Searching whole folder…';
    }
    scheduleSearch();
  });

  input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (debounceTimer) {
      window.clearTimeout(debounceTimer);
      debounceTimer = null;
    }
    var q = String(input.value || '').trim();
    if (q !== lastCommittedQ) {
      commitServerSearch(q, 1);
      return;
    }
    jump(e.shiftKey ? -1 : 1);
  });

  if (pagerEl) {
    pagerEl.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a || !pagerEl.contains(a)) return;
      if (a.closest('form')) return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#') return;
      e.preventDefault();
      try {
        var u = new URL(href, window.location.origin);
        var pageNum = parseInt(u.searchParams.get('p') || '1', 10) || 1;
        var q = (u.searchParams.get('q') || input.value || '').trim();
        commitServerSearch(q, pageNum);
      } catch (err) {
        window.location.href = href;
      }
    });
  }

  refreshPageMatches();
})();
