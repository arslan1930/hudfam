/**
 * Live sheet search: match as you type, Enter jumps to the next hit, Shift+Enter previous.
 * Ctrl/Cmd+Enter submits the search form (all pages) when the input is in a GET form.
 */
(function (global) {
  'use strict';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function bind(opts) {
    opts = opts || {};
    var input = typeof opts.input === 'string' ? qs(opts.input) : opts.input;
    if (!input) return null;

    var rowSel = opts.rows || '[data-search-row]';
    var hideNonMatches = opts.hideNonMatches !== false;
    var hitClass = opts.hitClass || 'sheet-search-hit';
    var meta = typeof opts.meta === 'string' ? qs(opts.meta) : opts.meta;
    var empty = typeof opts.empty === 'string' ? qs(opts.empty) : opts.empty;
    var matchRows = [];
    var matchIndex = -1;

    function rowList() {
      return Array.prototype.slice.call(document.querySelectorAll(rowSel));
    }

    function clearHits() {
      document.querySelectorAll('.' + hitClass).forEach(function (el) {
        el.classList.remove(hitClass);
      });
    }

    function hay(row) {
      return String(row.getAttribute('data-search') || '').toLowerCase();
    }

    function setHidden(row, hide) {
      if (!hideNonMatches) return;
      if (opts.hideStyle === 'display') {
        row.style.display = hide ? 'none' : '';
      } else {
        row.hidden = !!hide;
      }
    }

    function isVisible(row) {
      if (typeof opts.isVisible === 'function') {
        return opts.isVisible(row);
      }
      if (row.hidden) return false;
      if (row.style && row.style.display === 'none') return false;
      return true;
    }

    function filter() {
      var q = String(input.value || '').trim().toLowerCase();
      matchRows = [];
      clearHits();
      var shown = 0;
      rowList().forEach(function (row) {
        var hit = !q || hay(row).indexOf(q) !== -1;
        setHidden(row, !hit);
        if (hit && isVisible(row)) {
          shown++;
          if (q) matchRows.push(row);
        }
      });
      if (empty) empty.hidden = !(q && shown === 0);
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
      return matchRows;
    }

    function jump(dir) {
      if (!String(input.value || '').trim()) return;
      filter();
      if (!matchRows.length) return;
      matchIndex = matchIndex < 0
        ? (dir > 0 ? 0 : matchRows.length - 1)
        : (matchIndex + dir + matchRows.length) % matchRows.length;
      var row = matchRows[matchIndex];
      clearHits();
      row.classList.add(hitClass);
      try {
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
      } catch (err) {
        row.scrollIntoView(true);
      }
      if (meta) {
        meta.hidden = false;
        meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
      }
    }

    function searchAllPages() {
      if (typeof opts.onSearchAll === 'function') {
        opts.onSearchAll();
        return;
      }
      if (input.form) input.form.submit();
    }

    input.addEventListener('input', function () {
      matchIndex = -1;
      filter();
    });
    input.addEventListener('search', function () {
      matchIndex = -1;
      filter();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) {
        searchAllPages();
        return;
      }
      jump(e.shiftKey ? -1 : 1);
    });

    filter();
    return { filter: filter, jump: jump };
  }

  global.SheetSearchJump = { bind: bind };
})(window);
