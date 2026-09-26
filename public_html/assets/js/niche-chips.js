/**
 * Multi-niche chip box — type to add, × to remove, click label to edit.
 * Optional data-autosave=1 + data-site-id posts action=save_niche.
 */
(function (global) {
  'use strict';

  var itemsCache = null;
  var saveTimers = new WeakMap();

  function loadItems(root) {
    if (itemsCache) return itemsCache;
    var el = document.getElementById('prospect-niche-taxonomy');
    if (!el && root) {
      el = root.querySelector('[data-niche-taxonomy]');
    }
    var list = [];
    try {
      list = JSON.parse((el && el.textContent) || '[]') || [];
    } catch (e) {
      list = [];
    }
    itemsCache = list;
    return itemsCache;
  }

  function norm(s) {
    return String(s || '').trim().toLowerCase();
  }

  function currentLabels(root) {
    var out = [];
    root.querySelectorAll('.niche-chip[data-niche]').forEach(function (chip) {
      var v = String(chip.getAttribute('data-niche') || '').trim();
      if (v) out.push(v);
    });
    return out;
  }

  function setHidden(root, labels) {
    var hidden = root.querySelector('[data-niche-value]');
    if (hidden) hidden.value = labels.join(', ');
    var row = root.closest('[data-prospect-site-row]');
    if (row) {
      var hay = String(row.getAttribute('data-search') || '');
      var domain = String(row.getAttribute('data-domain') || '');
      var extra = labels.join(' ').toLowerCase();
      if (domain) {
        row.setAttribute('data-search', (domain + ' ' + extra).trim());
      } else if (extra) {
        row.setAttribute('data-search', (hay + ' ' + extra).trim());
      }
    }
  }

  function chipHtml(label) {
    var span = document.createElement('span');
    span.className = 'niche-chip';
    span.setAttribute('data-niche', label);
    var text = document.createElement('span');
    text.className = 'niche-chip-label';
    text.textContent = label;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'niche-chip-x';
    btn.setAttribute('data-niche-remove', '1');
    btn.setAttribute('aria-label', 'Remove ' + label);
    btn.textContent = '×';
    span.appendChild(text);
    span.appendChild(btn);
    return span;
  }

  function filterItems(items, q, exclude) {
    q = norm(q);
    var skip = {};
    (exclude || []).forEach(function (l) {
      skip[norm(l)] = true;
    });
    var starts = [];
    var contains = [];
    items.forEach(function (it) {
      var value = String(it.value || it.label || '');
      if (!value || skip[norm(value)]) return;
      var hay = norm((it.label || '') + ' ' + (it.value || '') + ' ' + (it.group || ''));
      if (!q) {
        starts.push(it);
        return;
      }
      if (norm(value).indexOf(q) === 0 || norm(it.label || '').indexOf(q) === 0) {
        starts.push(it);
      } else if (hay.indexOf(q) !== -1) {
        contains.push(it);
      }
    });
    return starts.concat(contains).slice(0, 40);
  }

  function scheduleSave(root) {
    if (root.getAttribute('data-autosave') !== '1') return;
    var siteId = root.getAttribute('data-site-id');
    if (!siteId) return;
    var prev = saveTimers.get(root);
    if (prev) window.clearTimeout(prev);
    var timer = window.setTimeout(function () {
      saveTimers.delete(root);
      saveNow(root);
    }, 400);
    saveTimers.set(root, timer);
  }

  function saveNow(root) {
    var siteId = root.getAttribute('data-site-id');
    if (!siteId || root.getAttribute('data-autosave') !== '1') return;
    var hidden = root.querySelector('[data-niche-value]');
    var body = new URLSearchParams();
    body.set('action', 'save_niche');
    body.set('site_id', String(siteId));
    body.set('niche', hidden ? String(hidden.value || '') : '');
    body.set('ajax', '1');
    fetch(window.location.href, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body,
    }).catch(function () { /* keep chips; next edit retries */ });
  }

  function addLabel(root, label, replaceChip) {
    label = String(label || '').trim();
    if (!label) return false;
    var labels = currentLabels(root);
    var key = norm(label);
    if (replaceChip) {
      var old = String(replaceChip.getAttribute('data-niche') || '');
      labels = labels.filter(function (l) { return norm(l) !== norm(old); });
    }
    var exists = labels.some(function (l) { return norm(l) === key; });
    if (exists) {
      if (replaceChip && replaceChip.parentNode) {
        replaceChip.remove();
        setHidden(root, currentLabels(root));
        scheduleSave(root);
      }
      return false;
    }
    if (replaceChip && replaceChip.parentNode) {
      replaceChip.parentNode.replaceChild(chipHtml(label), replaceChip);
    } else {
      var list = root.querySelector('[data-niche-chips-list]');
      if (list) list.appendChild(chipHtml(label));
    }
    setHidden(root, currentLabels(root));
    scheduleSave(root);
    return true;
  }

  function removeChip(root, chip) {
    if (!chip || !chip.parentNode) return;
    chip.parentNode.removeChild(chip);
    setHidden(root, currentLabels(root));
    scheduleSave(root);
  }

  function initBox(root) {
    if (!root || root.getAttribute('data-niche-wired') === '1') return;
    if (root.getAttribute('data-disabled') === '1') return;
    root.setAttribute('data-niche-wired', '1');
    var input = root.querySelector('[data-niche-input]');
    var listEl = root.querySelector('[data-niche-list]');
    if (!input || !listEl) return;
    var items = loadItems(root);
    var open = false;
    var active = -1;
    var filtered = [];
    var editingChip = null;

    function closeList() {
      open = false;
      active = -1;
      listEl.hidden = true;
      listEl.innerHTML = '';
    }

    function renderList() {
      listEl.innerHTML = '';
      if (!filtered.length) {
        listEl.hidden = true;
        open = false;
        return;
      }
      filtered.forEach(function (it, idx) {
        var li = document.createElement('li');
        li.className = 'typeahead-option' + (idx === active ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.textContent = it.label || it.value;
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(it);
        });
        listEl.appendChild(li);
      });
      listEl.hidden = false;
      open = true;
    }

    function pick(it) {
      if (!it) return;
      var label = String(it.value || it.label || '').trim();
      addLabel(root, label, editingChip);
      editingChip = null;
      input.value = '';
      closeList();
    }

    function pickFirstOrExact() {
      var q = norm(input.value);
      if (!q) return false;
      for (var i = 0; i < items.length; i++) {
        var v = String(items[i].value || items[i].label || '');
        if (norm(v) === q || norm(items[i].label || '') === q) {
          pick(items[i]);
          return true;
        }
      }
      if (filtered.length) {
        pick(filtered[0]);
        return true;
      }
      return false;
    }

    function refreshFilter() {
      filtered = filterItems(items, input.value, currentLabels(root).filter(function (l) {
        return !editingChip || norm(l) !== norm(editingChip.getAttribute('data-niche') || '');
      }));
      active = filtered.length ? 0 : -1;
      renderList();
    }

    input.addEventListener('input', function () {
      refreshFilter();
    });

    input.addEventListener('focus', function () {
      refreshFilter();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopPropagation();
        if (!open) refreshFilter();
        if (!filtered.length) return;
        active = Math.min(filtered.length - 1, active + 1);
        renderList();
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        e.stopPropagation();
        active = Math.max(0, active - 1);
        renderList();
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        if (open && active >= 0 && filtered[active]) {
          pick(filtered[active]);
        } else {
          pickFirstOrExact();
        }
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        closeList();
        if (editingChip) {
          input.value = '';
          editingChip = null;
        }
        return;
      }
      if (e.key === 'Backspace' && !String(input.value || '') && !editingChip) {
        var chips = root.querySelectorAll('.niche-chip');
        if (chips.length) {
          e.preventDefault();
          removeChip(root, chips[chips.length - 1]);
        }
      }
    });

    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (document.activeElement === input) return;
        if (String(input.value || '').trim()) {
          pickFirstOrExact();
        }
        input.value = '';
        editingChip = null;
        closeList();
      }, 180);
    });

    root.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var rm = t.closest('[data-niche-remove]');
      if (rm) {
        e.preventDefault();
        e.stopPropagation();
        var chip = rm.closest('.niche-chip');
        editingChip = null;
        removeChip(root, chip);
        input.focus();
        return;
      }
      var chip2 = t.closest('.niche-chip');
      if (chip2 && root.contains(chip2)) {
        e.preventDefault();
        editingChip = chip2;
        input.value = String(chip2.getAttribute('data-niche') || '');
        input.focus();
        if (input.select) input.select();
        refreshFilter();
      }
    });
  }

  function initFilterMenu() {
    var input = document.getElementById('prospect-niche-menu-search');
    if (!input || input.getAttribute('data-wired') === '1') return;
    input.setAttribute('data-wired', '1');
    var meta = document.querySelector('[data-niche-menu-search-meta]');
    var empty = document.querySelector('[data-niche-menu-empty]');
    var matchIndex = -1;
    var matchEls = [];

    function items() {
      return Array.prototype.slice.call(document.querySelectorAll('[data-niche-menu-item]'));
    }

    function clearHits() {
      document.querySelectorAll('[data-niche-menu-item].sheet-search-hit').forEach(function (el) {
        el.classList.remove('sheet-search-hit');
      });
    }

    function apply() {
      var q = norm(input.value);
      matchEls = [];
      clearHits();
      items().forEach(function (el) {
        var hay = String(el.getAttribute('data-search') || '');
        var hit = !q || hay.indexOf(q) !== -1;
        el.hidden = !hit;
        if (hit && q) matchEls.push(el);
      });
      document.querySelectorAll('[data-niche-menu-group]').forEach(function (g) {
        var any = false;
        g.querySelectorAll('[data-niche-menu-item]').forEach(function (el) {
          if (!el.hidden) any = true;
        });
        g.hidden = q !== '' && !any;
      });
      if (empty) empty.hidden = !(q && matchEls.length === 0);
      if (meta) {
        if (!q) {
          meta.hidden = true;
          meta.textContent = '';
          matchIndex = -1;
        } else {
          meta.hidden = false;
          meta.textContent = !matchEls.length
            ? '0 · Enter = next'
            : (matchIndex >= 0
              ? (matchIndex + 1) + ' of ' + matchEls.length + ' · Enter = next'
              : matchEls.length + ' · Enter = next');
        }
      }
    }

    function jump(dir) {
      var q = String(input.value || '').trim();
      if (!q) return;
      apply();
      if (!matchEls.length) return;
      matchIndex = matchIndex < 0
        ? (dir > 0 ? 0 : matchEls.length - 1)
        : (matchIndex + dir + matchEls.length) % matchEls.length;
      clearHits();
      var el = matchEls[matchIndex];
      el.classList.add('sheet-search-hit');
      try {
        el.scrollIntoView({ block: 'nearest', behavior: 'auto' });
      } catch (err) {
        el.scrollIntoView(true);
      }
      if (meta) {
        meta.textContent = (matchIndex + 1) + ' of ' + matchEls.length + ' · Enter = next';
      }
    }

    input.addEventListener('input', function () {
      matchIndex = -1;
      apply();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      e.stopPropagation();
      jump(e.shiftKey ? -1 : 1);
    });
  }

  function initAll(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    root.querySelectorAll('[data-niche-chips]').forEach(initBox);
    initFilterMenu();
  }

  global.NicheChips = { init: initAll };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { initAll(document); });
  } else {
    initAll(document);
  }
})(window);
