/**
 * Sitewide show/hide for every password field.
 */
(function () {
  'use strict';
  if (window.__TXF_PASSWORD_TOGGLE__) return;
  window.__TXF_PASSWORD_TOGGLE__ = true;

  function enhance(input) {
    if (!input || input.getAttribute('data-password-enhanced') === '1') return;
    if ((input.type || '').toLowerCase() !== 'password') return;

    input.setAttribute('data-password-enhanced', '1');

    var wrap = document.createElement('div');
    wrap.className = 'password-field';

    var parent = input.parentNode;
    if (!parent) return;
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.textContent = 'Show';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      try {
        input.focus({ preventScroll: true });
      } catch (e) {
        input.focus();
      }
    });
  }

  function scan(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('input[type="password"]').forEach(enhance);
  }

  function boot() {
    scan(document);
    if (typeof MutationObserver === 'undefined') return;
    var obs = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var nodes = mutations[i].addedNodes;
        for (var j = 0; j < nodes.length; j++) {
          var n = nodes[j];
          if (!n || n.nodeType !== 1) continue;
          if (n.matches && n.matches('input[type="password"]')) {
            enhance(n);
          } else if (n.querySelectorAll) {
            scan(n);
          }
        }
      }
    });
    obs.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
