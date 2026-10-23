/**
 * Sitewide draft autosave — keeps typed form values in localStorage so refresh
 * does not lose work. Cleared after a successful server save (ok flash).
 */
(function () {
  if (window.__TXF_DRAFT_LOADED__) return;
  window.__TXF_DRAFT_LOADED__ = true;
  if (!window.localStorage) return;

  var PREFIX = 'txf-draft:v1:';
  var debounceMs = 350;
  var timers = {};
  var restoring = false;

  function liveCfg() {
    return window.TXF_DRAFT || {};
  }

  function panelName() {
    var cfg = liveCfg();
    var panel = String(cfg.panel || '');
    if (panel === 'admin' || panel === 'team') return panel;
    var main = document.querySelector('main.main');
    return main ? String(main.getAttribute('data-draft-panel') || '') : '';
  }

  function userId() {
    return String(liveCfg().userId || '0');
  }

  function pageScope() {
    var params = new URLSearchParams(window.location.search);
    return (
      PREFIX +
      panelName() +
      ':' +
      userId() +
      ':' +
      (params.get('page') || '') +
      ':' +
      (params.get('id') || '') +
      ':' +
      (params.get('client_id') || '') +
      ':' +
      (params.get('country') || '')
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
    if (el.hasAttribute('hidden') || el.getAttribute('aria-hidden') === 'true') return true;
    if (el.classList && (el.classList.contains('visually-hidden') || el.classList.contains('camp-draft-textarea-sync'))) {
      return true;
    }
    if (el.readOnly || el.disabled) return true;
    var name = el.name;
    if (name === 'action' || name === 'item_id' || name === 'csrf' || name === '_csrf' || name === 'password') {
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
    form.querySelectorAll('[data-typeahead-input]').forEach(function (el) {
      var id = String(el.id || '');
      if (!id) return;
      data['typeahead::' + id] = el.value;
    });
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
    form.querySelectorAll('[data-typeahead-input]').forEach(function (el) {
      var id = String(el.id || '');
      if (!id) return;
      var tkey = 'typeahead::' + id;
      if (!Object.prototype.hasOwnProperty.call(data, tkey)) return;
      if (String(el.value) !== String(data[tkey])) {
        el.value = data[tkey];
        changed++;
      }
    });
    form.querySelectorAll('[data-typeahead]').forEach(function (root) {
      var hidden = root.querySelector('[data-typeahead-value]');
      var vis = root.querySelector('[data-typeahead-input]');
      if (!hidden || !vis) return;
      if (hidden.value && vis.value !== hidden.value) {
        vis.value = hidden.value;
        changed++;
      } else if (!String(hidden.value || '').trim() && String(vis.value || '').trim()) {
        // Typed country (no Enter) lived only in the visible box.
        hidden.value = vis.value.trim();
        changed++;
      }
    });
    return changed;
  }

  function restoreBannerVisible() {
    var bar = document.getElementById('draft-restore-banner');
    return !!(bar && !bar.hidden);
  }

  function saveForm(form, index, silent) {
    if (restoring) return;
    try {
      var key = formStorageKey(form, index);
      var data = collect(form);
      var payload = {
        savedAt: Date.now(),
        data: data,
      };
      localStorage.setItem(key, JSON.stringify(payload));
      // Yellow restore banner is the only draft story until Save or Discard.
      if (!silent) markStatus(form, true);
    } catch (e) {
      // quota / private mode
    }
  }

  function clearPageDrafts() {
    var scope = pageScope();
    var params = new URLSearchParams(window.location.search);
    var page = String(params.get('page') || '');
    var addSitesSuffix = ':f:prospect-add-sites-form';
    var toRemove = [];
    for (var i = 0; i < localStorage.length; i++) {
      var k = localStorage.key(i);
      if (!k) continue;
      if (k.indexOf(scope) === 0) {
        toRemove.push(k);
        continue;
      }
      // Hub Add sites (no country in the URL) uses a different key than the
      // country folder. A successful save must wipe both so the paste stays empty.
      var userProspects = PREFIX + panelName() + ':' + userId() + ':admin_prospects:';
      if (page === 'admin_prospects' && k.indexOf(userProspects) === 0 && k.indexOf(addSitesSuffix) !== -1) {
        toRemove.push(k);
      }
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
    // Restore already wrote localStorage. Re-saving after restoring=false
    // would paint the in-form “Draft saved” chip next to the yellow banner.
    if (restoring) return;
    var key = formStorageKey(form, index);
    if (timers[key]) clearTimeout(timers[key]);
    timers[key] = setTimeout(function () {
      saveForm(form, index, false);
    }, debounceMs);
  }

  function markStatus(form, saved) {
    if (saved && restoreBannerVisible()) return;
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

  function shouldBindForm(form) {
    if (!form) return false;
    if (form.getAttribute('data-draft-bound') === '1') return false;
    if (form.hasAttribute('data-no-draft')) return false;
    // Row sheets already autosave their own way — binding hundreds of forms lags scrolling/typing.
    if (form.hasAttribute('data-swe-save')) return false;
    if (form.hasAttribute('data-stay-ajax')) return false;
    if (form.hasAttribute('data-swe-mark')
      || form.hasAttribute('data-swe-mark-upto')
      || form.hasAttribute('data-swe-clear-upto')
      || form.hasAttribute('data-swe-clear-all-emailed')
      || form.hasAttribute('data-swe-remove')
      || form.hasAttribute('data-swe-push')) {
      return false;
    }
    if (form.hasAttribute('hidden') || form.hidden) return false;
    // Action-only forms (hidden fields + button) have nothing useful to draft.
    var editable = form.querySelector(
      'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not(.visually-hidden):not([hidden]), textarea:not([hidden]):not(.visually-hidden):not(.camp-draft-textarea-sync), select'
    );
    return !!editable;
  }

  function bindForm(form, index) {
    form.setAttribute('data-draft-bound', '1');
    form.addEventListener('input', function (e) {
      if (restoring) return;
      if (!e.target) return;
      if (e.target.getAttribute && e.target.hasAttribute('data-typeahead-input')) {
        scheduleSave(form, index);
        return;
      }
      if (isSkippable(e.target)) return;
      scheduleSave(form, index);
    });
    form.addEventListener('change', function (e) {
      if (restoring) return;
      if (!e.target) return;
      if (e.target.getAttribute && e.target.hasAttribute('data-typeahead-input')) {
        scheduleSave(form, index);
        return;
      }
      if (isSkippable(e.target)) return;
      scheduleSave(form, index);
    });
    form.addEventListener('typeahead:select', function () {
      if (restoring) return;
      scheduleSave(form, index);
    });
    form.addEventListener('submit', function () {
      // Keep latest values if the POST fails. Do not paint the in-form chip
      // under the Saving overlay — the restore banner already covers this.
      saveForm(form, index, true);
    });
  }

  function shouldClearDraft() {
    var live = window.TXF_DRAFT || {};
    if (live.clearDraft) return true;
    var main = document.querySelector('main.main');
    if (main && main.getAttribute('data-draft-clear') === '1') return true;
    if (document.querySelector('.alert-box.alert-ok')) return true;
    var params = new URLSearchParams(window.location.search);
    if (parseInt(params.get('just_added') || '0', 10) > 0) return true;
    return false;
  }

  function init() {
    var panel = panelName();
    if (panel !== 'admin' && panel !== 'team') return;
    var clearNow = shouldClearDraft();
    if (clearNow) {
      clearPageDrafts();
    }

    var forms = document.querySelectorAll('main.main form');
    if (!forms.length) return;

    var restoredAny = false;
    restoring = true;
    forms.forEach(function (form, index) {
      if (!shouldBindForm(form)) return;
      bindForm(form, index);
      if (clearNow) return;
      try {
        var raw = localStorage.getItem(formStorageKey(form, index));
        if (!raw) return;
        var parsed = JSON.parse(raw);
        if (!parsed || !parsed.data) return;
        var n = apply(form, parsed.data);
        if (n > 0) {
          restoredAny = true;
          // Paste Ready/attention listens on the textarea. Keep restoring=true
          // so this does not paint “Draft saved” next to the yellow banner.
          form.querySelectorAll('textarea[name], input:not([type="hidden"]), select').forEach(function (el) {
            if (isSkippable(el)) return;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          });
        }
      } catch (e) {
        // ignore bad JSON
      }
    });

    if (restoredAny && !shouldClearDraft()) {
      showBanner();
      document.querySelectorAll('[data-draft-status]').forEach(function (el) {
        el.hidden = true;
        el.textContent = '';
      });
    }
    restoring = false;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
