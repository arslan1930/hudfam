(function () {
  'use strict';

  var form = document.getElementById('sites_list_form');
  if (!form) return;

  var selectAll = document.getElementById('sites_select_all');
  var openBtn = document.getElementById('sites_open_btn');
  var removeBtn = document.getElementById('sites_remove_btn');
  var countEl = document.getElementById('sites_selected_count');
  var boxes = function () {
    return Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name="domains[]"]'));
  };

  function selectedBoxes() {
    return boxes().filter(function (cb) { return cb.checked; });
  }

  function selectedDomains() {
    return selectedBoxes().map(function (cb) {
      return cb.getAttribute('data-site-domain') || cb.value;
    }).filter(Boolean);
  }

  function syncUi() {
    var all = boxes();
    var selected = selectedBoxes();
    var n = selected.length;
    if (countEl) {
      countEl.textContent = n + ' selected';
    }
    if (openBtn) openBtn.disabled = n === 0;
    if (removeBtn) removeBtn.disabled = n === 0;
    if (selectAll) {
      selectAll.checked = all.length > 0 && n === all.length;
      selectAll.indeterminate = n > 0 && n < all.length;
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      var on = selectAll.checked;
      boxes().forEach(function (cb) { cb.checked = on; });
      syncUi();
    });
  }

  form.addEventListener('change', function (e) {
    if (e.target && e.target.matches('input[type="checkbox"][name="domains[]"]')) {
      syncUi();
    }
  });

  form.addEventListener('submit', function (e) {
    var n = selectedBoxes().length;
    if (n === 0) {
      e.preventDefault();
      return;
    }
    if (!window.confirm('Remove ' + n + ' selected site' + (n === 1 ? '' : 's') + ' from the Sites list?\n\nYou can Undo after.')) {
      e.preventDefault();
    }
  });

  /**
   * Open selected domains as https://… in new tabs (user’s browser / Chrome if default).
   * Triggered by a click so the browser allows multiple tabs.
   */
  function openSelected() {
    var domains = selectedDomains();
    if (!domains.length) return;

    var maxOpen = 25;
    if (domains.length > maxOpen) {
      if (!window.confirm(
        'Open the first ' + maxOpen + ' of ' + domains.length +
        ' selected sites in new tabs?\n\n(Browsers may block very large batches.)'
      )) {
        return;
      }
      domains = domains.slice(0, maxOpen);
    } else if (domains.length > 8) {
      if (!window.confirm('Open ' + domains.length + ' sites in new browser tabs?')) {
        return;
      }
    }

    var blocked = 0;
    domains.forEach(function (domain, i) {
      var url = 'https://' + domain.replace(/^https?:\/\//i, '');
      // Stagger slightly to reduce popup-blocker friction
      window.setTimeout(function () {
        var w = window.open(url, '_blank');
        if (!w) blocked++;
        if (i === domains.length - 1 && blocked > 0) {
          window.alert(
            'Opened some tabs, but the browser blocked ' + blocked +
            '. Allow pop-ups for this site, or open fewer at a time.'
          );
        }
      }, i * 80);
    });
  }

  if (openBtn) {
    openBtn.addEventListener('click', openSelected);
  }

  syncUi();
})();
