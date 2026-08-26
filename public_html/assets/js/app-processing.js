/**
 * Global Processing / Loading overlay for page navigation, form posts, and fetches.
 *
 * Usage:
 *   AppProcessing.show('Importing…')
 *   AppProcessing.hide()
 *   <form data-show-processing="Paste sites…">…</form>
 *
 * Page-load: html.is-page-loading shows the overlay until the document is ready.
 * In-app link clicks and unmarked form submits show "Loading…" until the next page paints.
 */
(function () {
  'use strict';
  if (window.__HF_APP_PROCESSING__) return;
  window.__HF_APP_PROCESSING__ = true;

  var overlay = null;
  var msgEl = null;
  var subEl = null;
  var depth = 0;
  var pageLoadOpen = true;
  var navArmed = false;

  function ensure() {
    overlay = document.getElementById('app-processing');
    if (!overlay) {
      return false;
    }
    msgEl = overlay.querySelector('[data-processing-msg]');
    subEl = overlay.querySelector('[data-processing-sub]');
    return true;
  }

  function setCopy(msg, sub) {
    if (msgEl) {
      msgEl.textContent = msg && String(msg).trim() !== '' ? String(msg) : 'Loading…';
    }
    if (subEl) {
      subEl.textContent = sub && String(sub).trim() !== ''
        ? String(sub)
        : (String(msg || '').indexOf('Loading') === 0
          ? 'Please wait.'
          : 'Please wait — do not close this page.');
    }
  }

  function showOverlay() {
    if (!ensure()) {
      return;
    }
    overlay.hidden = false;
    overlay.removeAttribute('hidden');
    overlay.setAttribute('aria-busy', 'true');
    document.documentElement.classList.add('is-app-processing');
  }

  function hideOverlay() {
    if (!ensure()) {
      return;
    }
    overlay.hidden = true;
    overlay.setAttribute('aria-busy', 'false');
    document.documentElement.classList.remove('is-app-processing');
    document.documentElement.classList.remove('is-page-loading');
  }

  function show(msg) {
    if (!ensure()) {
      return;
    }
    depth += 1;
    pageLoadOpen = false;
    setCopy(msg || 'Processing…', 'Please wait — do not close this page.');
    showOverlay();
  }

  function hide() {
    depth = Math.max(0, depth - 1);
    if (depth > 0 || pageLoadOpen) {
      return;
    }
    hideOverlay();
  }

  function hideAll() {
    depth = 0;
    pageLoadOpen = false;
    hideOverlay();
  }

  function finishPageLoad() {
    if (!pageLoadOpen && depth < 1) {
      document.documentElement.classList.remove('is-page-loading');
      return;
    }
    pageLoadOpen = false;
    if (depth > 0) {
      document.documentElement.classList.remove('is-page-loading');
      return;
    }
    hideOverlay();
  }

  function sameOriginUrl(href) {
    try {
      return new URL(href, window.location.href);
    } catch (e) {
      return null;
    }
  }

  function shouldShowNav(url) {
    if (!url) return false;
    if (url.origin !== window.location.origin) return false;
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
    if (url.pathname === window.location.pathname
      && url.search === window.location.search
      && url.hash !== '') {
      return false;
    }
    return true;
  }

  window.AppProcessing = {
    show: show,
    hide: hide,
    hideAll: hideAll,
  };

  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target;
      if (!form || !form.getAttribute) {
        return;
      }
      var marked = form.hasAttribute('data-show-processing');
      var msg = marked
        ? (form.getAttribute('data-show-processing') || 'Processing…')
        : 'Loading…';
      window.setTimeout(function () {
        if (e.defaultPrevented) {
          return;
        }
        show(msg);
        if (marked) {
          Array.prototype.forEach.call(
            form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'),
            function (btn) {
              btn.disabled = true;
            }
          );
        }
      }, 0);
    },
    true
  );

  document.addEventListener(
    'click',
    function (e) {
      if (e.defaultPrevented || e.button !== 0) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      if (a.hasAttribute('download')) return;
      var tgt = String(a.getAttribute('target') || '');
      if (tgt && tgt !== '_self') return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0) {
        return;
      }
      var url = sameOriginUrl(a.href);
      if (!shouldShowNav(url)) return;
      navArmed = true;
      show('Loading…');
    },
    true
  );

  window.addEventListener('pageshow', function (ev) {
    navArmed = false;
    if (ev.persisted) {
      hideAll();
    }
  });

  window.addEventListener('pagehide', function () {
    if (navArmed) {
      return;
    }
  });

  function onReady() {
    window.requestAnimationFrame(function () {
      finishPageLoad();
    });
  }
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    onReady();
  } else {
    document.addEventListener('DOMContentLoaded', onReady);
  }
  window.setTimeout(finishPageLoad, 12000);
})();
