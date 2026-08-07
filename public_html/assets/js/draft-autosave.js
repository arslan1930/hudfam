/**
 * Admin draft autosave — keeps typed form values in localStorage so refresh
 * does not lose work. Cleared after a successful server save (ok flash).
 */
(function () {
  if (window.__TXF_DRAFT_LOADED__) return;
  window.__TXF_DRAFT_LOADED__ = true;
  if (!window.localStorage) return;

  var cfg = window.TXF_DRAFT || {};
  if (cfg.panel !== 'admin') return;

  var PREFIX = 'txf-draft:v1:';
  var userId = String(cfg.userId || '0');
  var debounceMs = 350;
  var timers = {};
  var restoring = false;

  function pageScope() {
    var params = new URLSearchParams(window.location.search);
    return (
      PREFIX +
      userId +
      ':' +
      (params.get('page') || '') +
      ':' +
      (params.get('id') || '') +
      ':' +
      (params.get('client_id') || '')
    );
  }

  function formStorageKey(form, index) {
    return pageScope() + ':f:' + (form.getAttribute('id') || 'form' + index);
  }

  function isSkippable(el) {
    if (!el || !el.name) return true;
    var type = (el.type || '').toLowerCase();
    if (type === 'password' || type === 'file' || type === 'submit' || type === 'button' || type === 'image' || type === 'reset') {
      return true;
    }
    if (el.id === 'sheet-search' || el.id === 'sheet-action' || el.id === 'delete-item-id') {
      return true;
    }
    if (el.getAttribute('data-no-draft') !== null) return true;
    var name = el.name;
    if (name === 'action' || name === 'item_id' || name === 'csrf' || name === 'password') {
      return true;
    }
    if (el.classList && el.classList.contains('btn-paid')) return true;
    return false;
  }

  function fieldKey(el) {
    if (el.type === 'checkbox' || el.type === 'radio') {
      return el.name + '::' + (el.value || 'on') + '::' + el.type;
    }
    return el.name + '::' + (el.type || el.tagName.toLowerCase());
  }

  function collect(form) {
    var data = {};
    var fields = form.querySelectorAll('input, textarea, select');
    for (var i = 0; i < fields.length; i++) {
      var el = fields[i];
      if (isSkippable(el)) continue;
      var key = fieldKey(el);
      if (el.type === 'checkbox') {
        data[key] = !!el.checked;
      } else if (el.type === 'radio') {
        if (el.checked) data[el.name + '::radio'] = el.value;
      } else {
        data[key] = el.value;
      }
    }
    return data;
  }

  function apply(form, data) {
    if (!data || typeof data !== 'object') return 0;
    var changed = 0;
    var fields = form.querySelectorAll('input, textarea, select');
    for (var i = 0; i < fields.length; i++) {
      var el = fields[i];
      if (isSkippable(el)) continue;
      if (el.type === 'radio') {
        var radioVal = data[el.name + '::radio'];
        if (typeof radioVal === 'undefined') continue;
        var should = String(radioVal) === String(el.value);
        if (el.checked !== should) {
          el.checked = should;
          changed++;
        }
        continue;
      }
      var key = fieldKey(el);
      if (!Object.prototype.hasOwnProperty.call(data, key)) continue;
      if (el.type === 'checkbox') {
        var on = !!data[key];
        if (el.checked !== on) {
          el.checked = on;
          changed++;
        }
      } else if (String(el.value) !== String(data[key])) {
        el.value = data[key];
        changed++;
      }
    }
    return changed;
  }

  function saveForm(form, index) {
    if (restoring) return;
    try {
      var key = formStorageKey(form, index);
      var data = collect(form);
      var payload = {
        savedAt: Date.now(),
        data: data,
      };
      localStorage.setItem(key, JSON.stringify(payload));
      markStatus(form, true);
    } catch (e) {
      // quota / private mode
    }
  }

  function clearPageDrafts() {
    var scope = pageScope();
    var toRemove = [];
    for (var i = 0; i < localStorage.length; i++) {
      var k = localStorage.key(i);
      if (k && k.indexOf(scope) === 0) toRemove.push(k);
    }
    toRemove.forEach(function (k) {
      localStorage.removeItem(k);
    });
    hideBanner();
    document.querySelectorAll('[data-draft-status]').forEach(function (el) {
      el.hidden = true;
      el.textContent = '';
    });
  }

  function scheduleSave(form, index) {
    var key = formStorageKey(form, index);
    if (timers[key]) clearTimeout(timers[key]);
    timers[key] = setTimeout(function () {
      saveForm(form, index);
    }, debounceMs);
  }

  function markStatus(form, saved) {
    var status = form.querySelector('[data-draft-status]');
    if (!status) {
      status = document.createElement('div');
      status.className = 'draft-status';
      status.setAttribute('data-draft-status', '1');
      form.insertBefore(status, form.firstChild);
    }
    if (saved) {
      status.hidden = false;
      status.textContent = 'Draft saved on this device — safe to refresh';
    }
  }

  function ensureBanner() {
    var existing = document.getElementById('draft-restore-banner');
    if (existing) return existing;
    var bar = document.createElement('div');
    bar.id = 'draft-restore-banner';
    bar.className = 'draft-restore-banner';
    bar.hidden = true;
    bar.innerHTML =
      '<div class="draft-restore-banner__text">' +
      '<strong>Unsaved draft restored</strong>' +
      '<span>Your last typed changes were kept after refresh. Save when ready, or discard the draft.</span>' +
      '</div>' +
      '<div class="draft-restore-banner__actions">' +
      '<button type="button" class="btn secondary small" data-draft-discard>Discard draft</button>' +
      '</div>';
    var main = document.querySelector('main.main');
    if (main) {
      main.insertBefore(bar, main.firstChild);
    } else {
      document.body.insertBefore(bar, document.body.firstChild);
    }
    bar.querySelector('[data-draft-discard]').addEventListener('click', function () {
      clearPageDrafts();
      window.location.reload();
    });
    return bar;
  }

  function hideBanner() {
    var bar = document.getElementById('draft-restore-banner');
    if (bar) bar.hidden = true;
  }

  function showBanner() {
    var bar = ensureBanner();
    bar.hidden = false;
  }

  function bindForm(form, index) {
    form.setAttribute('data-draft-bound', '1');
    form.addEventListener('input', function (e) {
      if (!e.target || isSkippable(e.target)) return;
      scheduleSave(form, index);
    });
    form.addEventListener('change', function (e) {
      if (!e.target || isSkippable(e.target)) return;
      scheduleSave(form, index);
    });
    form.addEventListener('submit', function () {
      // Keep latest values until the next page confirms save via ok flash.
      saveForm(form, index);
    });
  }

  function init() {
    if (cfg.clearDraft) {
      clearPageDrafts();
    }

    var forms = document.querySelectorAll('main.main form');
    if (!forms.length) return;

    var restoredAny = false;
    restoring = true;
    forms.forEach(function (form, index) {
      bindForm(form, index);
      try {
        var raw = localStorage.getItem(formStorageKey(form, index));
        if (!raw) return;
        var parsed = JSON.parse(raw);
        if (!parsed || !parsed.data) return;
        var n = apply(form, parsed.data);
        if (n > 0) {
          restoredAny = true;
          markStatus(form, true);
          // Notify page scripts (e.g. order sheet totals) that values changed.
          form.dispatchEvent(new Event('input', { bubbles: true }));
          form.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } catch (e) {
        // ignore bad JSON
      }
    });
    restoring = false;

    if (restoredAny && !cfg.clearDraft) {
      showBanner();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
