/**
 * Website prices country sheet — add row, save, tint, copy one / copy selected, jump search.
 * CSRF is injected by csrf.js on same-origin POST.
 */
(function (global) {
  'use strict';

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function tableEl() {
    return document.querySelector('[data-site-price-sheet]');
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('ajax', '1');
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] == null) return;
      body.set(k, String(data[k]));
    });
    var table = tableEl();
    if (table) {
      if (table.getAttribute('data-country') && !body.get('country')) {
        body.set('country', table.getAttribute('data-country'));
      }
      if (!body.get('p')) body.set('p', table.getAttribute('data-page') || '1');
      if (!body.get('per_page')) body.set('per_page', table.getAttribute('data-per-page') || '100');
    }
    return fetch(window.location.href, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body,
    }).then(function (res) {
      return res.json().then(function (json) {
        json._status = res.status;
        return json;
      }).catch(function () {
        return { ok: false, error: 'Could not save.', _status: res.status };
      });
    });
  }

  function nicheValue(root) {
    var hidden = root.querySelector('[data-niche-value]');
    return hidden ? String(hidden.value || '') : '';
  }

  function collectAdd(tr) {
    var tintEl = $('[data-site-price-tint-value]', tr);
    return {
      domain: ($('[data-add-domain]', tr) || {}).value || '',
      niche: nicheValue(tr),
      da: ($('[data-add-da]', tr) || {}).value || '',
      dr: ($('[data-add-dr]', tr) || {}).value || '',
      traffic: ($('[data-add-traffic]', tr) || {}).value || '',
      price_note: ($('[data-add-price]', tr) || {}).value || '',
      extra_note: ($('[data-add-note]', tr) || {}).value || '',
      reply_email: ($('[data-add-email]', tr) || {}).value || '',
      row_tint: tintEl ? String(tintEl.value || '') : '',
      status_slug: ($('[data-site-price-status]', tr) || {}).value || 'new',
    };
  }

  function collectRow(tr) {
    var tintEl = $('[data-site-price-tint-value]', tr);
    var data = {
      site_id: tr.getAttribute('data-row-id') || '',
      niche: nicheValue(tr),
      price_note: ($('[data-site-price-price]', tr) || {}).value || '',
      extra_note: ($('[data-site-price-note]', tr) || {}).value || '',
      reply_email: ($('[data-site-price-email]', tr) || {}).value || '',
      row_tint: tintEl ? String(tintEl.value || '') : (tr.getAttribute('data-tint') || ''),
      status_slug: ($('[data-site-price-status]', tr) || {}).value || 'new',
    };
    var domain = $('[data-site-price-domain]', tr);
    if (domain) {
      data.domain = domain.value || '';
      data.da = ($('[data-site-price-da]', tr) || {}).value || '';
      data.dr = ($('[data-site-price-dr]', tr) || {}).value || '';
      data.traffic = ($('[data-site-price-traffic]', tr) || {}).value || '';
    }
    return data;
  }

  function fitGrowBox(el, minPx) {
    if (!el || String(el.tagName || '').toLowerCase() !== 'textarea') return;
    el.style.height = '0px';
    var need = el.scrollHeight + 16;
    el.style.height = Math.max(need, minPx) + 'px';
  }

  function fitNoteBox(el) {
    fitGrowBox(el, 128);
  }

  function fitEmailBox(el) {
    fitGrowBox(el, 48);
  }

  function fitAllNotes(root) {
    var scope = root || document;
    if (!scope.querySelectorAll) return;
    scope.querySelectorAll('[data-site-price-note], [data-add-note]').forEach(fitNoteBox);
    scope.querySelectorAll('[data-site-price-email], [data-add-email]').forEach(fitEmailBox);
  }

  function setStatus(msg, isError) {
    var el = document.querySelector('[data-site-price-status-msg]');
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || '';
    el.classList.toggle('is-error', !!isError);
  }

  function applyTbody(html) {
    var tbody = document.querySelector('[data-site-price-tbody]');
    if (!tbody || html == null) return;
    tbody.innerHTML = html;
    if (global.NicheChips && typeof global.NicheChips.init === 'function') {
      global.NicheChips.init(tbody);
    }
    if (global.OpenSite && typeof global.OpenSite.refreshAll === 'function') {
      global.OpenSite.refreshAll(tbody);
    }
    applyFilters();
    syncSelectAll();
    fitAllNotes(tbody);
  }

  function applyPager(json) {
    var table = tableEl();
    if (!table || !json) return;
    if (json.page != null) table.setAttribute('data-page', String(json.page));
    if (json.pages != null) table.setAttribute('data-pages', String(json.pages));
    if (json.per_page != null) table.setAttribute('data-per-page', String(json.per_page));
  }

  function focusHint(el, tr) {
    if (!el || !tr || !tr.contains(el)) return null;
    if (el.hasAttribute('data-site-price-price')) return '[data-site-price-price]';
    if (el.hasAttribute('data-site-price-note')) return '[data-site-price-note]';
    if (el.hasAttribute('data-site-price-email')) return '[data-site-price-email]';
    if (el.hasAttribute('data-site-price-domain')) return '[data-site-price-domain]';
    if (el.hasAttribute('data-site-price-da')) return '[data-site-price-da]';
    if (el.hasAttribute('data-site-price-dr')) return '[data-site-price-dr]';
    if (el.hasAttribute('data-site-price-traffic')) return '[data-site-price-traffic]';
    if (el.hasAttribute('data-site-price-status')) return '[data-site-price-status]';
    if (el.hasAttribute('data-niche-input')) return '[data-niche-input]';
    return null;
  }

  function restoreFocus(rowId, sel, start, end) {
    if (!rowId || !sel) return;
    var row = document.querySelector('[data-site-price-row][data-row-id="' + rowId + '"]');
    var el = row ? row.querySelector(sel) : null;
    if (!el) return;
    el.focus();
    if (typeof el.setSelectionRange === 'function' && start != null && end != null) {
      try { el.setSelectionRange(start, end); } catch (err) { /* ignore */ }
    }
  }

  function clearSearchHits() {
    document.querySelectorAll('[data-site-price-row].sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  var filterTimer = null;
  var matchRows = [];
  var matchIndex = -1;

  function searchInputEl() {
    return document.querySelector('[data-site-price-filter="q"]');
  }

  function searchMetaEl() {
    return document.querySelector('[data-site-price-search-meta]');
  }

  function hideEmptyLanes() {
    var table = tableEl();
    if (!table) return;
    table.querySelectorAll('[data-site-price-lane]').forEach(function (hdr) {
      var lane = hdr.getAttribute('data-site-price-lane');
      var any = false;
      table.querySelectorAll('[data-site-price-row][data-lane="' + lane + '"]').forEach(function (row) {
        if (!row.hidden) any = true;
      });
      hdr.hidden = !any;
    });
  }

  function applyFilters() {
    var table = tableEl();
    var bar = document.querySelector('[data-site-price-filters]');
    if (!table) return;
    var q = '';
    var lane = '';
    var status = '';
    var added = '';
    var tint = '';
    if (bar) {
      var qEl = bar.querySelector('[data-site-price-filter="q"]');
      var laneEl = bar.querySelector('[data-site-price-filter="lane"]');
      var statusEl = bar.querySelector('[data-site-price-filter="status"]');
      var addedEl = bar.querySelector('[data-site-price-filter="added"]');
      var tintOn = bar.querySelector('[data-site-price-filter="tint"].is-on');
      q = String(qEl && qEl.value || '').trim().toLowerCase();
      lane = String(laneEl && laneEl.value || '');
      status = String(statusEl && statusEl.value || '');
      added = String(addedEl && addedEl.value || '');
      tint = tintOn ? String(tintOn.getAttribute('data-tint') || '') : '';
    }
    var active = !!(q || lane || status || added || tint);
    matchRows = [];
    clearSearchHits();
    var any = false;
    table.querySelectorAll('[data-site-price-row]').forEach(function (row) {
      var hit = true;
      if (q && String(row.getAttribute('data-search') || '').indexOf(q) === -1) hit = false;
      if (lane && row.getAttribute('data-lane') !== lane) hit = false;
      if (status && row.getAttribute('data-status') !== status) hit = false;
      if (added && row.getAttribute('data-added-by') !== added) hit = false;
      if (tint === 'none') {
        if (row.getAttribute('data-tint')) hit = false;
      } else if (tint && row.getAttribute('data-tint') !== tint) {
        hit = false;
      }
      row.hidden = !hit;
      var hist = row.nextElementSibling;
      if (hist && hist.hasAttribute('data-site-price-history-row') && !hit) {
        hist.hidden = true;
      }
      if (hit) {
        any = true;
        if (q) matchRows.push(row);
      }
    });
    hideEmptyLanes();
    var empty = table.querySelector('[data-site-price-filter-empty]');
    if (empty) empty.hidden = !active || any;
    var meta = searchMetaEl();
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
            ? (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next'
            : matchRows.length + ' · Enter = next · Ctrl+Enter = all pages');
      }
    }
    syncSelectAll();
  }

  function scheduleFilterRows() {
    if (filterTimer) window.clearTimeout(filterTimer);
    var qEl = searchInputEl();
    var q = qEl ? String(qEl.value || '').trim() : '';
    if (!q) {
      filterTimer = null;
      applyFilters();
      return;
    }
    filterTimer = window.setTimeout(function () {
      filterTimer = null;
      applyFilters();
    }, 160);
  }

  function jumpMatch(dir) {
    var qEl = searchInputEl();
    if (!qEl || !String(qEl.value || '').trim()) return;
    applyFilters();
    if (!matchRows.length) return;
    matchIndex = matchIndex < 0
      ? (dir > 0 ? 0 : matchRows.length - 1)
      : (matchIndex + dir + matchRows.length) % matchRows.length;
    var row = matchRows[matchIndex];
    clearSearchHits();
    row.classList.add('sheet-search-hit');
    try { row.scrollIntoView({ block: 'center', behavior: 'auto' }); } catch (err) { row.scrollIntoView(true); }
    var meta = searchMetaEl();
    if (meta) {
      meta.hidden = false;
      meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
    }
  }

  function searchAllPages() {
    var bar = document.querySelector('[data-site-price-filters]');
    var url = new URL(window.location.href);
    var qEl = bar ? bar.querySelector('[data-site-price-filter="q"]') : null;
    var laneEl = bar ? bar.querySelector('[data-site-price-filter="lane"]') : null;
    var statusEl = bar ? bar.querySelector('[data-site-price-filter="status"]') : null;
    var addedEl = bar ? bar.querySelector('[data-site-price-filter="added"]') : null;
    var tintOn = bar ? bar.querySelector('[data-site-price-filter="tint"].is-on') : null;
    var q = qEl ? String(qEl.value || '').trim() : '';
    var lane = laneEl ? String(laneEl.value || '') : '';
    var status = statusEl ? String(statusEl.value || '') : '';
    var added = addedEl ? String(addedEl.value || '') : '';
    var tint = tintOn ? String(tintOn.getAttribute('data-tint') || '') : '';
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (lane) url.searchParams.set('lane', lane); else url.searchParams.delete('lane');
    if (status) url.searchParams.set('status', status); else url.searchParams.delete('status');
    if (added) url.searchParams.set('added', added); else url.searchParams.delete('added');
    if (tint) url.searchParams.set('tint', tint); else url.searchParams.delete('tint');
    url.searchParams.delete('p');
    url.searchParams.delete('row');
    window.location.href = url.toString();
  }

  function bindFilters() {
    var bar = document.querySelector('[data-site-price-filters]');
    if (!bar || bar.getAttribute('data-wired') === '1') return;
    bar.setAttribute('data-wired', '1');
    var qEl = bar.querySelector('[data-site-price-filter="q"]');
    if (qEl) {
      qEl.addEventListener('input', function () {
        matchIndex = -1;
        scheduleFilterRows();
      });
      qEl.addEventListener('search', function () {
        matchIndex = -1;
        scheduleFilterRows();
      });
      qEl.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (filterTimer) {
          window.clearTimeout(filterTimer);
          filterTimer = null;
        }
        if (e.ctrlKey || e.metaKey) {
          searchAllPages();
          return;
        }
        jumpMatch(e.shiftKey ? -1 : 1);
      });
    }
    bar.querySelectorAll('[data-site-price-filter="lane"], [data-site-price-filter="status"], [data-site-price-filter="added"]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        searchAllPages();
      });
    });
    applyFilters();
  }

  function applyCount(n) {
    var el = document.querySelector('[data-site-price-count]');
    if (!el || n == null) return;
    var num = parseInt(n, 10) || 0;
    el.textContent = String(num) + ' site' + (num === 1 ? '' : 's');
  }

  var saveTimers = new WeakMap();

  function scheduleSave(tr) {
    var prev = saveTimers.get(tr);
    if (prev) window.clearTimeout(prev);
    var t = window.setTimeout(function () {
      saveTimers.delete(tr);
      saveRow(tr);
    }, 450);
    saveTimers.set(tr, t);
  }

  function saveRow(tr) {
    var data = collectRow(tr);
    if (!data.site_id) return;
    var active = document.activeElement;
    var sel = focusHint(active, tr);
    var start = active && typeof active.selectionStart === 'number' ? active.selectionStart : null;
    var end = active && typeof active.selectionEnd === 'number' ? active.selectionEnd : null;
    var rowId = data.site_id;
    setStatus('Saving…', false);
    post('save_row', data).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not save.', true);
        return;
      }
      setStatus('Saved.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
      restoreFocus(rowId, sel, start, end);
    }).catch(function () {
      setStatus('Could not save.', true);
    });
  }

  function addRow(tr) {
    var data = collectAdd(tr);
    if (!String(data.domain || '').trim()) {
      setStatus('Type a website first.', true);
      var inp = $('[data-add-domain]', tr);
      if (inp) inp.focus();
      return;
    }
    if (String(data.status_slug || '') === 'processing') {
      if (!window.confirm('Add as Processing? This adds the site to Order management Processing.')) {
        return;
      }
    }
    var btn = $('[data-site-price-add-btn]', tr);
    if (btn) btn.disabled = true;
    setStatus('Adding…', false);
    post('add_row', data).then(function (json) {
      if (btn) btn.disabled = false;
      if (!json.ok) {
        setStatus(json.error || 'Could not add that site.', true);
        return;
      }
      setStatus('Added ' + (json.domain || 'site') + '.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
      var addDomain = document.querySelector('[data-add-domain]');
      if (addDomain) addDomain.focus();
    }).catch(function () {
      if (btn) btn.disabled = false;
      setStatus('Could not add that site.', true);
    });
  }

  function unlockRow(tr) {
    var id = tr.getAttribute('data-row-id');
    if (!id) return;
    setStatus('Unlocking…', false);
    post('unlock_row', { site_id: id }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not unlock.', true);
        return;
      }
      setStatus('Unlocked — edit website / DA / DR / traffic, then it locks again on save.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
    }).catch(function () {
      setStatus('Could not unlock.', true);
    });
  }

  function suggestNiche(tr) {
    var domainEl = $('[data-add-domain]', tr);
    var domain = domainEl ? String(domainEl.value || '').trim() : '';
    if (!domain) return;
    if (nicheValue(tr)) return;
    post('suggest_niche', { domain: domain }).then(function (json) {
      if (!json.ok || !json.niche) return;
      var hidden = tr.querySelector('[data-niche-value]');
      var list = tr.querySelector('[data-niche-chips-list]');
      if (hidden) hidden.value = json.niche;
      if (list && json.chips_html) list.innerHTML = json.chips_html;
    }).catch(function () { /* leave blank */ });
  }

  function historyRowFor(tr) {
    if (!tr) return null;
    var id = tr.getAttribute('data-row-id') || '';
    var next = tr.nextElementSibling;
    if (next && next.getAttribute('data-site-price-history-row') === id) return next;
    return null;
  }

  function toggleHistory(tr) {
    var id = tr.getAttribute('data-row-id');
    if (!id) return;
    var existing = historyRowFor(tr);
    if (existing && !existing.hidden) {
      existing.hidden = true;
      return;
    }
    setStatus('Loading history…', false);
    post('row_history', { site_id: id }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not load history.', true);
        return;
      }
      setStatus('', false);
      var row = historyRowFor(tr);
      if (!row) {
        row = document.createElement('tr');
        row.className = 'site-price-history-row';
        row.setAttribute('data-site-price-history-row', id);
        var td = document.createElement('td');
        td.colSpan = String(tr.children.length || 12);
        row.appendChild(td);
        tr.parentNode.insertBefore(row, tr.nextSibling);
      }
      row.querySelector('td').innerHTML = json.html || '<p class="muted">No history yet.</p>';
      row.hidden = false;
    }).catch(function () {
      setStatus('Could not load history.', true);
    });
  }

  function claimRow(tr) {
    var id = tr.getAttribute('data-row-id');
    if (!id) return;
    setStatus('Taking…', false);
    post('claim_row', { site_id: id }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not take this site.', true);
        return;
      }
      setStatus(json.message || 'You are managing this site.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
    }).catch(function () {
      setStatus('Could not take this site.', true);
    });
  }

  function assignRow(tr, managedBy) {
    var id = tr.getAttribute('data-row-id');
    if (!id) return;
    setStatus('Saving manager…', false);
    post('assign_row', { site_id: id, managed_by: managedBy }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not assign manager.', true);
        return;
      }
      setStatus(json.message || 'Saved manager.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
    }).catch(function () {
      setStatus('Could not assign manager.', true);
    });
  }

  function removeRow(tr) {
    var id = tr.getAttribute('data-row-id');
    if (!id) return;
    var domain = '';
    var btn = tr.querySelector('[data-site-price-remove]');
    if (btn) domain = String(btn.getAttribute('data-domain') || '').trim();
    if (!domain) {
      var box = tr.querySelector('[data-site-price-select]');
      if (box) domain = String(box.getAttribute('data-domain') || '').trim();
    }
    var label = domain || 'this site';
    if (!window.confirm('Remove ' + label + ' from this country sheet? Order management rows stay; they will no longer point at this site.')) {
      return;
    }
    setStatus('Removing…', false);
    post('delete_row', { site_id: id }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not remove that site.', true);
        return;
      }
      setStatus(json.message || 'Removed.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
    }).catch(function () {
      setStatus('Could not remove that site.', true);
    });
  }

  function paintStatusSelect(sel) {
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var color = opt ? String(opt.getAttribute('data-color') || 'grey') : 'grey';
    ['green', 'blue', 'rose', 'grey', 'brown', 'teal'].forEach(function (c) {
      sel.classList.remove('is-color-' + c);
    });
    sel.classList.add('is-color-' + color);
  }

  function applyTintUi(tr, tint) {
    var hidden = $('[data-site-price-tint-value]', tr);
    if (hidden) hidden.value = tint || '';
    tr.setAttribute('data-tint', tint || '');
    tr.querySelectorAll('[data-site-price-tint]').forEach(function (btn) {
      btn.classList.toggle('is-on', String(btn.getAttribute('data-site-price-tint') || '') === String(tint || ''));
    });
    var summary = tr.querySelector('.site-price-color-summary');
    if (summary) {
      summary.classList.remove('is-yellow', 'is-pink', 'is-blue', 'is-green');
      summary.textContent = '⋯';
    }
  }

  function setTint(tr, tint) {
    applyTintUi(tr, tint);
    var menu = tr.querySelector('details.site-price-color-menu');
    if (menu) menu.open = false;
    scheduleSave(tr);
  }

  function visibleDataRows() {
    var table = tableEl();
    if (!table) return [];
    return Array.prototype.slice.call(table.querySelectorAll('[data-site-price-row]')).filter(function (row) {
      return !row.hidden;
    });
  }

  function syncSelectAll() {
    var all = document.querySelector('[data-site-price-select-all]');
    if (!all) return;
    var rows = visibleDataRows();
    var checked = rows.filter(function (row) {
      var box = row.querySelector('[data-site-price-select]');
      return box && box.checked;
    });
    all.checked = rows.length > 0 && checked.length === rows.length;
    all.indeterminate = checked.length > 0 && checked.length < rows.length;
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return false; });
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(!!ok);
    } catch (err) {
      return Promise.resolve(false);
    }
  }

  function copySelected() {
    var table = tableEl();
    if (table && table.getAttribute('data-admin') !== '1') {
      setStatus('Copy selected is Admin only. Use Copy on one site.', true);
      return;
    }
    var rows = visibleDataRows();
    var domains = [];
    rows.forEach(function (row) {
      var box = row.querySelector('[data-site-price-select]');
      if (!box || !box.checked) return;
      var d = String(box.getAttribute('data-domain') || '').trim();
      if (d) domains.push(d);
    });
    if (!domains.length) {
      setStatus('Select at least one row.', true);
      return;
    }
    copyText(domains.join('\n')).then(function (ok) {
      if (ok) setStatus('Copied ' + domains.length + ' website' + (domains.length === 1 ? '' : 's') + ' on this page.', false);
      else setStatus('Could not copy.', true);
    });
  }

  function copyOneSite(btn) {
    var d = String((btn && btn.getAttribute('data-domain')) || '').trim();
    if (!d) {
      setStatus('Nothing to copy.', true);
      return;
    }
    copyText(d).then(function (ok) {
      if (ok) setStatus('Copied ' + d + '.', false);
      else setStatus('Could not copy.', true);
    });
  }

  function reorderLane(lane, ids) {
    if (!lane || !ids || !ids.length) return;
    setStatus('Saving order…', false);
    post('reorder_lane', { lane: lane, ids: ids.join(',') }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not save order.', true);
        return;
      }
      setStatus('Order saved.', false);
      applyPager(json);
      if (json.tbody_html) applyTbody(json.tbody_html);
      if (json.total != null) applyCount(json.total);
    }).catch(function () {
      setStatus('Could not save order.', true);
    });
  }

  function bindDrag(root) {
    if (!root || root.getAttribute('data-admin') !== '1') return;
    var dragId = '';
    var dragLane = '';

    function clearMarks() {
      root.querySelectorAll('.is-dragging, .is-drop-before, .is-drop-after').forEach(function (el) {
        el.classList.remove('is-dragging', 'is-drop-before', 'is-drop-after');
      });
    }

    function rowFrom(el) {
      return el && el.closest ? el.closest('[data-site-price-row]') : null;
    }

    root.addEventListener('dragstart', function (e) {
      root.querySelectorAll('[data-site-price-history-row]').forEach(function (el) { el.remove(); });
      var handle = e.target && e.target.closest ? e.target.closest('[data-site-price-drag]') : null;
      if (!handle) {
        e.preventDefault();
        return;
      }
      var tr = handle.closest('[data-site-price-row]');
      if (!tr) {
        e.preventDefault();
        return;
      }
      dragId = tr.getAttribute('data-row-id') || '';
      dragLane = tr.getAttribute('data-lane') || '';
      if (!dragId || !dragLane) {
        e.preventDefault();
        return;
      }
      tr.classList.add('is-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragId);
      }
    });

    root.addEventListener('dragend', function () {
      clearMarks();
      dragId = '';
      dragLane = '';
    });

    root.addEventListener('dragover', function (e) {
      if (!dragId) return;
      var tr = rowFrom(e.target);
      if (!tr || tr.getAttribute('data-row-id') === dragId) return;
      if (tr.getAttribute('data-lane') !== dragLane) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      var rect = tr.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      root.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (el) {
        el.classList.remove('is-drop-before', 'is-drop-after');
      });
      tr.classList.add(before ? 'is-drop-before' : 'is-drop-after');
    });

    root.addEventListener('drop', function (e) {
      if (!dragId) return;
      var tr = rowFrom(e.target);
      if (!tr || tr.getAttribute('data-lane') !== dragLane) return;
      e.preventDefault();
      var src = root.querySelector('[data-site-price-row][data-row-id="' + dragId + '"]');
      if (!src || src === tr) {
        clearMarks();
        return;
      }
      var rect = tr.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      if (before) tr.parentNode.insertBefore(src, tr);
      else tr.parentNode.insertBefore(src, tr.nextSibling);
      clearMarks();
      var ids = [];
      root.querySelectorAll('[data-site-price-row][data-lane="' + dragLane + '"]').forEach(function (row) {
        ids.push(row.getAttribute('data-row-id'));
      });
      dragId = '';
      dragLane = '';
      reorderLane(tr.getAttribute('data-lane'), ids);
    });
  }

  function bind(root) {
    if (!root || root.getAttribute('data-site-price-wired') === '1') return;
    root.setAttribute('data-site-price-wired', '1');

    root.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var addBtn = t.closest('[data-site-price-add-btn]');
      if (addBtn) {
        e.preventDefault();
        var addTr = addBtn.closest('[data-site-price-add]');
        if (addTr) addRow(addTr);
        return;
      }
      var tintBtn = t.closest('[data-site-price-tint]');
      if (tintBtn) {
        e.preventDefault();
        var tintValue = String(tintBtn.getAttribute('data-site-price-tint') || '');
        var tintTr = tintBtn.closest('[data-site-price-row]');
        if (tintTr) {
          setTint(tintTr, tintValue);
          return;
        }
        var addTr = tintBtn.closest('[data-site-price-add]');
        if (addTr) applyTintUi(addTr, tintValue);
        return;
      }
      var un = t.closest('[data-site-price-unlock]');
      if (un) {
        e.preventDefault();
        var tr = un.closest('[data-site-price-row]');
        if (tr) unlockRow(tr);
        return;
      }
      var hist = t.closest('[data-site-price-history]');
      if (hist) {
        e.preventDefault();
        var htr = hist.closest('[data-site-price-row]');
        if (htr) toggleHistory(htr);
        return;
      }
      var claim = t.closest('[data-site-price-claim]');
      if (claim) {
        e.preventDefault();
        var ctr = claim.closest('[data-site-price-row]');
        if (ctr) claimRow(ctr);
        return;
      }
      var removeBtn = t.closest('[data-site-price-remove]');
      if (removeBtn) {
        e.preventDefault();
        var rtr = removeBtn.closest('[data-site-price-row]');
        if (rtr) removeRow(rtr);
        return;
      }
      var copyOne = t.closest('[data-site-price-copy-one]');
      if (copyOne) {
        e.preventDefault();
        copyOneSite(copyOne);
      }
    });

    root.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.matches('[data-site-price-select-all]')) {
        var on = !!t.checked;
        visibleDataRows().forEach(function (row) {
          var box = row.querySelector('[data-site-price-select]');
          if (box) box.checked = on;
        });
        syncSelectAll();
        return;
      }
      if (t.matches('[data-site-price-select]')) {
        syncSelectAll();
        return;
      }
      if (t.matches('[data-site-price-assign]')) {
        var arow = t.closest('[data-site-price-row]');
        if (arow) assignRow(arow, t.value);
        return;
      }
      if (t.matches('[data-site-price-status]')) {
        var srow = t.closest('[data-site-price-row]');
        if (srow) {
          var prev = String(srow.getAttribute('data-status') || 'new');
          if (String(t.value || '') === 'processing' && prev !== 'processing') {
            if (!window.confirm('Set to Processing? This adds the site to Order management Processing.')) {
              t.value = prev;
              paintStatusSelect(t);
              return;
            }
          }
        }
        paintStatusSelect(t);
      }
      var row = t.closest('[data-site-price-row]');
      if (row) scheduleSave(row);
    });

    root.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.matches('[data-site-price-note], [data-add-note]')) fitNoteBox(t);
      if (t.matches('[data-site-price-email], [data-add-email]')) fitEmailBox(t);
      var row = t.closest('[data-site-price-row]');
      if (row && (t.matches('[data-site-price-price], [data-site-price-note], [data-site-price-email], [data-site-price-domain], [data-site-price-da], [data-site-price-dr], [data-site-price-traffic]')
          || t.closest('[data-niche-chips]'))) {
        scheduleSave(row);
      }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var t = e.target;
      if (!t || !t.closest) return;
      var addTr = t.closest('[data-site-price-add]');
      if (addTr && t.matches('input') && !t.matches('[data-niche-input]')) {
        e.preventDefault();
        addRow(addTr);
      }
    });

    root.addEventListener('focusout', function (e) {
      var t = e.target;
      if (!t || !t.matches || !t.matches('[data-add-domain]')) return;
      var addTr = t.closest('[data-site-price-add]');
      if (addTr) suggestNiche(addTr);
    });
  }

  var jumpMatches = [];
  var jumpIndex = -1;
  var lastJumpQuery = '';
  var jumpTotal = 0;

  function jumpStatus(text) {
    var el = document.querySelector('[data-site-price-jump-status]');
    if (el) el.textContent = text || '';
  }

  function jumpCountLabel() {
    var n = jumpMatches.length;
    if (!n) return '';
    if (jumpTotal > n) return 'Showing ' + n + ' of ' + jumpTotal;
    return n + ' match' + (n === 1 ? '' : 'es');
  }

  function jumpButtons(show) {
    var prev = document.querySelector('[data-site-price-jump-prev]');
    var next = document.querySelector('[data-site-price-jump-next]');
    if (prev) prev.hidden = !show;
    if (next) next.hidden = !show;
  }

  function renderJumpResults(matches) {
    var ul = document.querySelector('[data-site-price-jump-results]');
    if (!ul) return;
    ul.innerHTML = '';
    if (!matches || !matches.length) {
      ul.hidden = true;
      return;
    }
    ul.hidden = false;
    matches.forEach(function (m, i) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = m.url || '#';
      a.setAttribute('data-jump-index', String(i));
      var status = String(m.status || '').replace(/_/g, ' ');
      a.textContent = (m.domain || '') + ' · ' + (m.country || '') + (status ? ' · ' + status : '');
      li.appendChild(a);
      ul.appendChild(li);
    });
  }

  function markJumpResult(index) {
    var ul = document.querySelector('[data-site-price-jump-results]');
    if (!ul) return;
    ul.querySelectorAll('a[data-jump-index]').forEach(function (a) {
      a.classList.toggle('is-on', String(a.getAttribute('data-jump-index')) === String(index));
    });
  }

  function goToMatch(i) {
    if (!jumpMatches.length) return;
    jumpIndex = ((i % jumpMatches.length) + jumpMatches.length) % jumpMatches.length;
    var m = jumpMatches[jumpIndex];
    var count = jumpCountLabel();
    jumpStatus((jumpIndex + 1) + ' of ' + jumpMatches.length + (jumpTotal > jumpMatches.length ? ' · ' + count : ''));
    jumpButtons(true);
    markJumpResult(jumpIndex);
    if (!m || !m.url) return;
    var table = tableEl();
    var sameCountry = table && table.getAttribute('data-country') === m.country;
    var samePage = table && String(table.getAttribute('data-page') || '1') === String(m.page || 1);
    var row = document.querySelector('[data-site-price-row][data-row-id="' + m.id + '"]');
    if (sameCountry && samePage && row) {
      highlightJump(row);
      return;
    }
    window.location.href = m.url;
  }

  function highlightJump(row) {
    if (!row) return;
    document.querySelectorAll('.is-jump-target').forEach(function (el) {
      el.classList.remove('is-jump-target');
    });
    row.classList.add('is-jump-target');
    try { row.scrollIntoView({ block: 'center' }); } catch (err) { row.scrollIntoView(true); }
  }

  function runJump() {
    var input = document.querySelector('[data-site-price-jump-q]');
    var q = input ? String(input.value || '').trim() : '';
    if (!q) {
      jumpMatches = [];
      jumpIndex = -1;
      jumpTotal = 0;
      lastJumpQuery = '';
      jumpStatus('');
      jumpButtons(false);
      renderJumpResults([]);
      setStatus('Type something to search all countries.', true);
      return;
    }
    setStatus('Searching…', false);
    post('jump_search', { q: q }).then(function (json) {
      if (!json.ok) {
        lastJumpQuery = '';
        setStatus(json.error || 'Could not search.', true);
        renderJumpResults([]);
        return;
      }
      jumpMatches = json.matches || [];
      jumpTotal = parseInt(json.total, 10) || jumpMatches.length;
      lastJumpQuery = q;
      renderJumpResults(jumpMatches);
      if (!jumpMatches.length) {
        jumpIndex = -1;
        jumpStatus('No sites match');
        jumpButtons(false);
        setStatus('No sites match that search.', true);
        return;
      }
      jumpIndex = -1;
      jumpStatus(jumpCountLabel() + ' · click a row or press Enter to open');
      jumpButtons(true);
      markJumpResult(-1);
      setStatus('', false);
      var first = jumpMatches[0];
      if (first && tableEl()) {
        var table = tableEl();
        var sameCountry = table.getAttribute('data-country') === first.country;
        var samePage = String(table.getAttribute('data-page') || '1') === String(first.page || 1);
        var row = document.querySelector('[data-site-price-row][data-row-id="' + first.id + '"]');
        if (sameCountry && samePage && row) highlightJump(row);
      }
    }).catch(function () {
      lastJumpQuery = '';
      setStatus('Could not search.', true);
    });
  }

  function bindJump() {
    var bar = document.querySelector('[data-site-price-jump]');
    if (!bar || bar.getAttribute('data-wired') === '1') return;
    bar.setAttribute('data-wired', '1');
    var go = bar.querySelector('[data-site-price-jump-go]');
    var prev = bar.querySelector('[data-site-price-jump-prev]');
    var next = bar.querySelector('[data-site-price-jump-next]');
    var input = bar.querySelector('[data-site-price-jump-q]');
    if (go) go.addEventListener('click', function (e) { e.preventDefault(); runJump(); });
    if (prev) prev.addEventListener('click', function (e) { e.preventDefault(); goToMatch(jumpIndex - 1); });
    if (next) next.addEventListener('click', function (e) { e.preventDefault(); goToMatch(jumpIndex + 1); });
    var results = bar.querySelector('[data-site-price-jump-results]');
    if (results) {
      results.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-jump-index]') : null;
        if (!a) return;
        e.preventDefault();
        goToMatch(parseInt(a.getAttribute('data-jump-index'), 10) || 0);
      });
    }
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          var qNow = String(input.value || '').trim();
          if (jumpMatches.length && qNow && qNow === lastJumpQuery) goToMatch(jumpIndex + 1);
          else runJump();
        }
      });
    }
  }

  function bindToolbar() {
    var copyBtn = document.querySelector('[data-site-price-copy-selected]');
    if (!copyBtn || copyBtn.getAttribute('data-wired') === '1') return;
    copyBtn.setAttribute('data-wired', '1');
    copyBtn.addEventListener('click', function (e) {
      e.preventDefault();
      copySelected();
    });
  }

  document.addEventListener('copy', function (e) {
    var el = e.target;
    if (el && el.closest && el.closest('[data-site-price-copy-one]')) return;
    if (el && el.closest && el.closest('[data-site-price-add]')) return;
    if (el && el.closest && (el.closest('.is-copy-lock')
        || el.closest('[data-site-price-sheet][data-admin="0"] [data-site-price-row]'))) {
      e.preventDefault();
    }
  });
  document.addEventListener('cut', function (e) {
    var el = e.target;
    if (el && el.closest && el.closest('[data-site-price-add]')) return;
    if (el && el.closest && (el.closest('.is-copy-lock')
        || el.closest('[data-site-price-sheet][data-admin="0"] [data-site-price-row]'))) {
      e.preventDefault();
    }
  });
  document.addEventListener('contextmenu', function (e) {
    var el = e.target;
    if (el && el.closest && el.closest('[data-site-price-add]')) return;
    if (el && el.closest && el.closest('[data-site-price-sheet][data-admin="0"] [data-site-price-row]')) {
      e.preventDefault();
    }
  });

  function restoreJump() {
    var input = document.querySelector('[data-site-price-jump-q]');
    var q = input ? String(input.value || '').trim() : '';
    if (!q) return;
    post('jump_search', { q: q }).then(function (json) {
      if (!json.ok) return;
      jumpMatches = json.matches || [];
      jumpTotal = parseInt(json.total, 10) || jumpMatches.length;
      lastJumpQuery = q;
      if (!jumpMatches.length) return;
      var table = tableEl();
      var rowId = table ? table.getAttribute('data-jump-row') : '';
      jumpIndex = 0;
      jumpMatches.forEach(function (m, i) {
        if (String(m.id) === String(rowId)) jumpIndex = i;
      });
      renderJumpResults(jumpMatches);
      markJumpResult(jumpIndex);
      var count = jumpCountLabel();
      jumpStatus((jumpIndex + 1) + ' of ' + jumpMatches.length + (jumpTotal > jumpMatches.length ? ' · ' + count : ''));
      jumpButtons(true);
    }).catch(function () { /* keep bar usable */ });
  }

  function boot() {
    var table = tableEl();
    if (table) {
      bind(table);
      bindDrag(table);
      fitAllNotes(table);
      requestAnimationFrame(function () { fitAllNotes(table); });
      window.setTimeout(function () { fitAllNotes(table); }, 80);
      var jumpId = table.getAttribute('data-jump-row');
      if (jumpId) {
        var row = table.querySelector('[data-site-price-row][data-row-id="' + jumpId + '"]');
        if (row) highlightJump(row);
      }
    }
    bindFilters();
    bindJump();
    bindToolbar();
    restoreJump();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
