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
        headers: { Accept: 'text/plain' }
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
          var stillCount = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
          btn.disabled = stillCount < 1;
        });
    });
  }

  // --- AJAX whole-folder server search (no full reload) + Enter jump ---
  var input = document.getElementById('extracted-url-search');
  var list = document.getElementById('extracted-plain-list');
  var totalLabel = document.getElementById('extracted_total_label');
  var meta = document.querySelector('[data-extracted-url-search-meta]');
  var emptyHint = document.querySelector('[data-extracted-url-search-empty]');
  var emptyEl = document.getElementById('extracted-url-empty');
  var pagerEl = document.getElementById('extracted-url-pager');
  var matchLine = document.getElementById('extracted_match_line');
  var matchCountLabel = document.getElementById('extracted_match_count_label');
  var matchPlural = document.getElementById('extracted_match_plural');
  var matchQLabel = document.getElementById('extracted_match_q_label');
  var removeForm = document.getElementById('extracted-remove-matching');
  var matchRows = [];
  var matchIndex = -1;
  var debounceTimer = null;
  var DEBOUNCE_MS = 300;
  var lastCommittedQ = input ? String(input.value || '').trim() : '';
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
    if (!input) return;
    if (document.activeElement === input) return;
    try {
      input.focus({ preventScroll: true });
    } catch (e) {
      input.focus();
    }
  }

  function rowsOnPage() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-extracted-url-row]'));
  }

  function clearHits() {
    document.querySelectorAll('#extracted-plain-list .sheet-search-hit').forEach(function (el) {
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
    var q = input ? String(input.value || '').trim() : '';
    if (!q) {
      meta.hidden = true;
      meta.textContent = '';
      if (emptyHint) emptyHint.hidden = true;
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
        row.scrollIntoView({ block: 'nearest', behavior: 'auto' });
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
    if (matchLine) {
      matchLine.hidden = q === '';
      if (q !== '') {
        if (matchCountLabel) matchCountLabel.textContent = String(n);
        if (matchPlural) matchPlural.textContent = n === 1 ? '' : 'es';
        if (matchQLabel) matchQLabel.textContent = q;
      }
    }
    if (!removeForm) return;
    var show = q !== '' && n > 0;
    removeForm.hidden = !show;
    var qInput = document.getElementById('extracted_remove_q');
    var qLabel = document.getElementById('extracted_remove_q_label');
    var countLabel = document.getElementById('extracted_remove_count_label');
    var plural = document.getElementById('extracted_remove_plural');
    var rmBtn = document.getElementById('extracted_remove_matching_btn');
    if (qInput) qInput.value = q;
    if (qLabel) qLabel.textContent = q;
    if (countLabel) countLabel.textContent = String(n);
    if (plural) plural.textContent = n === 1 ? '' : 's';
    if (rmBtn) rmBtn.textContent = 'Remove ' + n + ' matching';
    if (show) {
      removeForm.setAttribute(
        'onsubmit',
        'return confirm(' + JSON.stringify(data.remove_confirm || ('Remove ' + n + ' matching site(s)?')) + ');'
      );
    }
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
    var onPage = (data.rows_html ? String(data.rows_html).match(/data-extracted-url-row/g) : []) || [];
    var shown = onPage.length;
    var total = Number(data.match_count || 0);
    if (!String(data.q || '').trim()) {
      total = Number(data.country_total || 0);
    }

    var nav = pagerEl.querySelector('.actions');
    if (!nav) return;

    var label = nav.querySelector('[data-extracted-page-label]');
    Array.prototype.slice.call(nav.querySelectorAll('a')).forEach(function (a) {
      if (a.closest('form')) return;
      a.remove();
    });

    if (label) {
      label.textContent = 'Page ' + pageNum + ' / ' + pages + ' · showing ' + shown + ' of ' + total;
      label.setAttribute('data-page', String(pageNum));
      label.setAttribute('data-pages', String(pages));
      label.setAttribute('data-on-page', String(shown));
      label.setAttribute('data-total', String(total));
    }

    if (pageNum > 1) {
      var prev = document.createElement('a');
      prev.href = '?' + qs + '&p=' + (pageNum - 1);
      prev.textContent = 'Prev';
      nav.insertBefore(prev, label || nav.firstChild);
    }
    if (pageNum < pages) {
      var next = document.createElement('a');
      next.href = '?' + qs + '&p=' + (pageNum + 1);
      next.textContent = 'Next';
      if (label && label.nextSibling) {
        nav.insertBefore(next, label.nextSibling);
      } else if (label) {
        nav.appendChild(next);
      } else {
        nav.appendChild(next);
      }
    }

    var perPageForm = nav.querySelector('form');
    if (perPageForm) {
      var qInput = perPageForm.querySelector('input[name="q"]');
      if (qInput) qInput.value = String(data.q || '');
    }
  }

  function updateTotalLabel(n) {
    if (totalLabel) totalLabel.textContent = String(n);
    if (btn) {
      btn.setAttribute('data-count', String(n));
      btn.disabled = n < 1;
    }
  }

  function applyPayload(data) {
    if (!data || !data.ok) return;
    if (list) {
      list.innerHTML = data.rows_html || '';
      list.hidden = !data.has_rows;
      var start = Number(data.list_start || 1) || 1;
      list.setAttribute('start', String(start));
    }
    if (emptyEl) {
      emptyEl.hidden = !!data.has_rows;
      var emptyText = emptyEl.querySelector('[data-extracted-empty-text]');
      if (emptyText) {
        emptyText.textContent = (data.q || '').trim()
          ? 'No search matches in this country.'
          : 'No URLs in this country yet.';
      }
    }
    if (typeof data.country_total === 'number') {
      updateTotalLabel(data.country_total);
    }
    updatePager(data);
    updateMatchActions(data);
    lastCommittedQ = String(data.q || '').trim();
    lastCommittedPage = Number(data.page || 1) || 1;
    syncAddressBar(lastCommittedQ, lastCommittedPage);
    searching = false;
    refreshPageMatches();
    keepFocus();
    if (window.SheetSelectUndo && typeof window.SheetSelectUndo.sync === 'function') {
      window.SheetSelectUndo.sync();
    }
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

  function updateTotalFromRemove(n) {
    updateTotalLabel(n);
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
          Accept: 'application/json'
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
          if (row) row.remove();
          if (typeof data.site_count === 'number') updateTotalFromRemove(data.site_count);
          setStatus('Removed ' + (data.domain || 'site') + '. Search kept — continue removing.');
          if (data.redirect) {
            window.setTimeout(function () {
              window.location.href = data.redirect;
            }, 250);
            return;
          }
          commitServerSearch(input ? input.value : lastCommittedQ, lastCommittedPage);
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
  }

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
        var q = (u.searchParams.get('q') || (input && input.value) || '').trim();
        commitServerSearch(q, pageNum);
      } catch (err) {
        window.location.href = href;
      }
    });
  }

  refreshPageMatches();
})();
