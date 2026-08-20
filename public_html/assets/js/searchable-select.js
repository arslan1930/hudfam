/**
 * Enhance <select data-searchable> into type-to-search comboboxes.
 * Keeps the native <select> in the form for submit + change events.
 *
 * data-allow-custom="1" lets the user keep a typed value that is not in the
 * list (used for Language, which is optional and free text).
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
    if (item.value === '') return false;
    var hay = (item.label + ' ' + item.value + ' ' + item.group).toLowerCase();
    return hay.indexOf(q) !== -1;
  }

  function enhance(select) {
    if (!select || select.dataset.searchEnhanced === '1') return;
    select.dataset.searchEnhanced = '1';
    var allowCustom = select.dataset.allowCustom === '1';

    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('ss-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'ss-input';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    if (select.dataset.placeholder) input.placeholder = select.dataset.placeholder;
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
      var label = selectedLabel();
      input.value = select.value === '' ? '' : label;
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
        empty.textContent = allowCustom ? 'No match — press Enter to keep what you typed' : 'No matches';
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

    /** Keep a typed value that is not in the list (Language). */
    function commitCustom(text) {
      text = text.trim();
      if (text === '') {
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
      var existing = null;
      items.forEach(function (it) {
        if (!it.disabled && it.label.toLowerCase() === text.toLowerCase()) existing = it;
      });
      if (existing) {
        pick(existing);
        return;
      }
      var opt = document.createElement('option');
      opt.value = text;
      opt.textContent = text;
      opt.dataset.custom = '1';
      select.appendChild(opt);
      select.value = text;
      items = optionList(select);
      select.dispatchEvent(new Event('change', { bubbles: true }));
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
        if (!list.hidden && activeIdx >= 0 && filtered[activeIdx]) {
          e.preventDefault();
          pick(filtered[activeIdx]);
        } else if (allowCustom) {
          e.preventDefault();
          commitCustom(input.value);
          setExpanded(false);
        }
      } else if (e.key === 'Escape') {
        setExpanded(false);
        syncInputFromSelect();
        input.blur();
      }
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        setExpanded(false);
        var typed = input.value.trim().toLowerCase();
        var hit = null;
        items.forEach(function (it) {
          if (!it.disabled && it.label.toLowerCase() === typed) hit = it;
        });
        if (hit) {
          pick(hit);
        } else if (allowCustom) {
          commitCustom(input.value);
        } else {
          syncInputFromSelect();
        }
      }, 120);
    });

    select.addEventListener('change', syncInputFromSelect);
    syncInputFromSelect();
  }

  function enhanceAll(root) {
    (root || document).querySelectorAll('select[data-searchable]').forEach(enhance);
  }

  /** Live-filter country folder cards by typing in [data-folder-search]. */
  function enhanceFolderSearch(root) {
    (root || document).querySelectorAll('[data-folder-search]').forEach(function (input) {
      if (input.dataset.folderEnhanced === '1') return;
      input.dataset.folderEnhanced = '1';
      var scope = document.querySelector('[data-folder-scope]') || document;
      var counter = document.querySelector('[data-folder-count]');

      function apply() {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        scope.querySelectorAll('a.folder').forEach(function (a) {
          var hay = ((a.dataset.search || '') + ' ' + (a.textContent || '')).toLowerCase();
          var show = !q || hay.indexOf(q) !== -1;
          a.hidden = !show;
          if (show) shown++;
        });
        scope.querySelectorAll('[data-folder-group]').forEach(function (card) {
          var any = false;
          card.querySelectorAll('a.folder').forEach(function (f) {
            if (!f.hidden) any = true;
          });
          card.hidden = !any;
        });
        var empty = document.querySelector('[data-folder-empty]');
        if (empty) empty.hidden = shown !== 0;
        if (counter) counter.textContent = String(shown);
      }

      input.addEventListener('input', apply);
      apply();
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
