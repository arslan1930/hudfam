/**
 * Enhance <select data-searchable> into type-to-search comboboxes.
 * Keeps the native <select> in the form for submit + change events.
 */
(function () {
  'use strict';

  function optionList(select) {
    var items = [];
    Array.prototype.forEach.call(select.options, function (opt, idx) {
      items.push({
        index: idx,
        value: opt.value,
        label: (opt.textContent || '').trim(),
        extra: String(opt.getAttribute('data-search') || ''),
        disabled: !!opt.disabled,
        group: opt.parentNode && opt.parentNode.tagName === 'OPTGROUP'
          ? (opt.parentNode.label || '')
          : ''
      });
    });
    return items;
  }

  function matches(item, q) {
    if (!q) return true;
    if (item.value === '') {
      return (item.label + ' all').toLowerCase().indexOf(q) !== -1;
    }
    var hay = (item.label + ' ' + item.value + ' ' + item.group + ' ' + item.extra).toLowerCase();
    return hay.indexOf(q) !== -1;
  }

  function enhance(select) {
    if (!select || select.dataset.searchEnhanced === '1') return;
    select.dataset.searchEnhanced = '1';

    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('ss-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'ss-input';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('data-no-draft', '');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    if (select.id) {
      input.id = select.id + '_search';
      var lab = document.querySelector('label[for="' + select.id + '"]');
      if (lab) lab.setAttribute('for', input.id);
    }
    if (select.required) input.setAttribute('aria-required', 'true');
    if (select.disabled) input.disabled = true;

    var list = document.createElement('div');
    list.className = 'ss-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    wrap.insertBefore(input, select);
    wrap.appendChild(list);

    var items = optionList(select);
    var activeIdx = -1;
    var filtered = items.slice();

    function selectedLabel() {
      var opt = select.options[select.selectedIndex];
      return opt ? (opt.textContent || '').trim() : '';
    }

    function syncInputFromSelect() {
      input.value = selectedLabel();
      if (select.value === '') {
        input.placeholder = selectedLabel() || 'Type to search…';
        if (selectedLabel().indexOf('—') === 0) input.value = '';
      }
    }

    function setExpanded(open) {
      list.hidden = !open;
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
      wrap.classList.toggle('ss-open', open);
    }

    function render(q) {
      q = (q || '').trim().toLowerCase();
      filtered = items.filter(function (it) {
        if (it.disabled && it.value === '' && q) return false;
        return matches(it, q);
      });
      list.innerHTML = '';
      activeIdx = -1;
      var lastGroup = null;
      filtered.forEach(function (it, i) {
        if (it.group && it.group !== lastGroup) {
          lastGroup = it.group;
          var g = document.createElement('div');
          g.className = 'ss-group';
          g.textContent = it.group;
          list.appendChild(g);
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ss-option' + (it.value === select.value ? ' is-selected' : '');
        btn.setAttribute('role', 'option');
        btn.dataset.index = String(i);
        btn.textContent = it.label;
        if (it.disabled) btn.disabled = true;
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(it);
        });
        list.appendChild(btn);
      });
      if (!filtered.length) {
        var empty = document.createElement('div');
        empty.className = 'ss-empty';
        empty.textContent = 'No matches';
        list.appendChild(empty);
      }
      setExpanded(true);
    }

    function pick(it) {
      if (!it || it.disabled) return;
      select.selectedIndex = it.index;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncInputFromSelect();
      setExpanded(false);
    }

    function moveActive(delta) {
      var opts = list.querySelectorAll('.ss-option:not([disabled])');
      if (!opts.length) return;
      activeIdx = (activeIdx + delta + opts.length) % opts.length;
      opts.forEach(function (el, i) {
        el.classList.toggle('is-active', i === activeIdx);
        if (i === activeIdx) el.scrollIntoView({ block: 'nearest' });
      });
    }

    input.addEventListener('focus', function () {
      items = optionList(select);
      input.select();
      render('');
    });
    input.addEventListener('input', function () {
      items = optionList(select);
      render(input.value);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (list.hidden) render(input.value);
        moveActive(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (list.hidden) render(input.value);
        moveActive(-1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (list.hidden) return;
        var pickIt = (activeIdx >= 0 && filtered[activeIdx]) ? filtered[activeIdx] : null;
        if (!pickIt) {
          var fallback = null;
          for (var i = 0; i < filtered.length; i++) {
            if (filtered[i].disabled) continue;
            if (filtered[i].value === '') {
              if (!fallback) fallback = filtered[i];
              continue;
            }
            pickIt = filtered[i];
            break;
          }
          if (!pickIt) pickIt = fallback;
        }
        if (pickIt) pick(pickIt);
      } else if (e.key === 'Escape') {
        setExpanded(false);
        syncInputFromSelect();
        input.blur();
      }
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        setExpanded(false);
        // If typed text matches an option exactly, select it; else revert
        var typed = input.value.trim().toLowerCase();
        var hit = null;
        items.forEach(function (it) {
          if (!it.disabled && it.label.toLowerCase() === typed) hit = it;
        });
        if (hit) pick(hit);
        else syncInputFromSelect();
      }, 120);
    });

    select.addEventListener('change', syncInputFromSelect);
    syncInputFromSelect();
  }

  function enhanceAll(root) {
    (root || document).querySelectorAll('select[data-searchable]').forEach(enhance);
  }

  /** Filter country folder cards by typing in [data-folder-search] */
  function enhanceFolderSearch(root) {
    (root || document).querySelectorAll('[data-folder-search]').forEach(function (input) {
      if (input.dataset.folderEnhanced === '1') return;
      input.dataset.folderEnhanced = '1';
      var scope = input.closest('[data-folder-scope]') || document;
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        scope.querySelectorAll('a.folder').forEach(function (a) {
          var text = (a.textContent || '').toLowerCase();
          var show = !q || text.indexOf(q) !== -1;
          a.style.display = show ? '' : 'none';
          var card = a.closest('.card');
          // hide empty region cards
          if (card) {
            var any = false;
            card.querySelectorAll('a.folder').forEach(function (f) {
              if (f.style.display !== 'none') any = true;
            });
            card.style.display = any ? '' : 'none';
          }
        });
      });
    });
  }

  function boot() {
    enhanceAll(document);
    enhanceFolderSearch(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.TechxSearchable = { enhanceAll: enhanceAll, enhanceFolderSearch: enhanceFolderSearch };
})();
