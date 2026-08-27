/**
 * Website prices country sheet — add row, per-row save, identity lock / Admin unlock.
 * CSRF is injected by csrf.js on same-origin POST.
 */
(function (global) {
  'use strict';

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('ajax', '1');
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] == null) return;
      body.set(k, String(data[k]));
    });
    var table = document.querySelector('[data-site-price-sheet]');
    if (table && table.getAttribute('data-country') && !body.get('country')) {
      body.set('country', table.getAttribute('data-country'));
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
    return {
      domain: ($('[data-add-domain]', tr) || {}).value || '',
      niche: nicheValue(tr),
      da: ($('[data-add-da]', tr) || {}).value || '',
      dr: ($('[data-add-dr]', tr) || {}).value || '',
      traffic: ($('[data-add-traffic]', tr) || {}).value || '',
      price_note: ($('[data-add-price]', tr) || {}).value || '',
      extra_note: ($('[data-add-note]', tr) || {}).value || '',
      status_slug: ($('[data-site-price-status]', tr) || {}).value || 'new',
    };
  }

  function collectRow(tr) {
    var data = {
      site_id: tr.getAttribute('data-row-id') || '',
      niche: nicheValue(tr),
      price_note: ($('[data-site-price-price]', tr) || {}).value || '',
      extra_note: ($('[data-site-price-note]', tr) || {}).value || '',
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
  }

  function focusHint(el, tr) {
    if (!el || !tr || !tr.contains(el)) return null;
    if (el.hasAttribute('data-site-price-price')) return '[data-site-price-price]';
    if (el.hasAttribute('data-site-price-note')) return '[data-site-price-note]';
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

  function reorderLane(lane, ids) {
    if (!lane || !ids || !ids.length) return;
    setStatus('Saving order…', false);
    post('reorder_lane', { lane: lane, ids: ids.join(',') }).then(function (json) {
      if (!json.ok) {
        setStatus(json.error || 'Could not save order.', true);
        return;
      }
      setStatus('Order saved.', false);
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
      var un = t.closest('[data-site-price-unlock]');
      if (un) {
        e.preventDefault();
        var tr = un.closest('[data-site-price-row]');
        if (tr) unlockRow(tr);
      }
    });

    root.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var row = t.closest('[data-site-price-row]');
      if (row) scheduleSave(row);
    });

    root.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var row = t.closest('[data-site-price-row]');
      if (row && (t.matches('[data-site-price-price], [data-site-price-note], [data-site-price-domain], [data-site-price-da], [data-site-price-dr], [data-site-price-traffic]')
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

  document.addEventListener('copy', function (e) {
    var el = e.target;
    if (el && el.closest && el.closest('.is-copy-lock')) {
      e.preventDefault();
    }
  });
  document.addEventListener('cut', function (e) {
    var el = e.target;
    if (el && el.closest && el.closest('.is-copy-lock')) {
      e.preventDefault();
    }
  });

  function boot() {
    var table = document.querySelector('[data-site-price-sheet]');
    if (table) {
      bind(table);
      bindDrag(table);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
