/**
 * Global Processing / Loading overlay for long-running form posts and fetches.
 *
 * Usage:
 *   AppProcessing.show('Importing…')
 *   AppProcessing.hide()
 *   <form data-show-processing="Paste sites…">…</form>
 */
(function () {
  'use strict';
  if (window.__HF_APP_PROCESSING__) return;
  window.__HF_APP_PROCESSING__ = true;

  var overlay = null;
  var msgEl = null;
  var depth = 0;

  function ensure() {
    overlay = document.getElementById('app-processing');
    if (!overlay) {
      return false;
    }
    msgEl = overlay.querySelector('[data-processing-msg]');
    return true;
  }

  function show(msg) {
    if (!ensure()) {
      return;
    }
    depth += 1;
    if (msgEl) {
      msgEl.textContent = msg && String(msg).trim() !== '' ? String(msg) : 'Processing…';
    }
    overlay.hidden = false;
    overlay.setAttribute('aria-busy', 'true');
    document.documentElement.classList.add('is-app-processing');
  }

  function hide() {
    if (!ensure()) {
      return;
    }
    depth = Math.max(0, depth - 1);
    if (depth > 0) {
      return;
    }
    overlay.hidden = true;
    overlay.setAttribute('aria-busy', 'false');
    document.documentElement.classList.remove('is-app-processing');
  }

  function hideAll() {
    depth = 0;
    if (!ensure()) {
      return;
    }
    overlay.hidden = true;
    overlay.setAttribute('aria-busy', 'false');
    document.documentElement.classList.remove('is-app-processing');
  }

  window.AppProcessing = {
    show: show,
    hide: hide,
    hideAll: hideAll,
  };

  // Auto-show for forms marked data-show-processing (after confirms / other preventDefault).
  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target;
      if (!form || !form.getAttribute || !form.hasAttribute('data-show-processing')) {
        return;
      }
      var msg = form.getAttribute('data-show-processing') || 'Processing…';
      window.setTimeout(function () {
        if (e.defaultPrevented) {
          return;
        }
        show(msg);
        Array.prototype.forEach.call(
          form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'),
          function (btn) {
            btn.disabled = true;
          }
        );
      }, 0);
    },
    true
  );

  // Escape never closes a real in-flight process; page unload clears naturally.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      hideAll();
    }
  });
})();
