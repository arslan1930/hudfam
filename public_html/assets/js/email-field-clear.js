/**
 * In-box × clear control for email fields (Sites with emails, Email campaign sheets, …).
 * Markup: .email-field > input[data-email-input] + button[data-email-clear]
 */
(function () {
  'use strict';

  function syncEmailClearButton(input) {
    if (!input) return;
    var wrap = input.closest('.email-field, .swe-email-field');
    if (!wrap) return;
    var btn = wrap.querySelector('[data-email-clear], [data-swe-email-clear]');
    var val = String(input.value || '').trim();
    var has = val !== '';
    wrap.classList.toggle('has-value', has);
    if (btn) btn.hidden = !has;
    if (has) {
      input.title = val;
    } else {
      input.removeAttribute('title');
    }
  }

  function syncAll(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-email-input], [data-swe-email]').forEach(syncEmailClearButton);
  }

  window.EmailFieldClear = {
    sync: syncEmailClearButton,
    syncAll: syncAll
  };

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest
      ? e.target.closest('[data-email-clear], [data-swe-email-clear]')
      : null;
    if (!btn) return;
    e.preventDefault();
    var wrap = btn.closest('.email-field, .swe-email-field');
    var input = wrap && wrap.querySelector('[data-email-input], [data-swe-email]');
    if (!input) return;
    input.value = '';
    syncEmailClearButton(input);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  });

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches) return;
    if (!input.matches('[data-email-input], [data-swe-email]')) return;
    syncEmailClearButton(input);
  });

  document.addEventListener('paste', function (e) {
    var input = e.target;
    if (!input || !input.matches) return;
    if (!input.matches('[data-email-input], [data-swe-email]')) return;
    window.setTimeout(function () { syncEmailClearButton(input); }, 0);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { syncAll(document); });
  } else {
    syncAll(document);
  }
})();
