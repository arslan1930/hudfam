/**
 * Global Processing / Loading overlay for page navigation, form posts, and fetches.
 *
 * Usage:
 *   AppProcessing.show('Importing…')
 *   AppProcessing.hide()
 *   <form data-show-processing="Paste sites…">…</form>
 *
 * First paint: overlay stays hidden (no flash). Same-origin navigations and
 * unmarked POST forms show "Loading…" only after NAV_DELAY_MS. GET forms do
 * not arm the overlay unless they set data-show-processing. Long posts with
 * data-show-processing / AppProcessing.show() appear immediately.
 */
(function () {
  'use strict';
  if (window.__HF_APP_PROCESSING__) return;
  window.__HF_APP_PROCESSING__ = true;

  var NAV_DELAY_MS = 200;
  var overlay = null;
  var msgEl = null;
  var subEl = null;
  var depth = 0;
  var pageLoadOpen = false;
  var navArmed = false;
  var navTimer = null;

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

  function clearNavTimer() {
    if (navTimer) {
      window.clearTimeout(navTimer);
      navTimer = null;
    }
  }

  function show(msg) {
    if (!ensure()) {
      return;
    }
    clearNavTimer();
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
    clearNavTimer();
    navArmed = false;
    hideOverlay();
  }

  function finishPageLoad() {
    pageLoadOpen = false;
    if (depth > 0) {
      document.documentElement.classList.remove('is-page-loading');
      return;
    }
    hideOverlay();
  }

  function armDelayedLoading(msg) {
    clearNavTimer();
    navArmed = true;
    navTimer = window.setTimeout(function () {
      navTimer = null;
      show(msg || 'Loading…');
    }, NAV_DELAY_MS);
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
      var method = String(form.method || 'get').toLowerCase();
      var msg = marked
        ? (form.getAttribute('data-show-processing') || 'Processing…')
        : 'Loading…';
      if (marked) {
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
        return;
      }
      // Super search and other GET filters already show results on the next
      // paint. Do not put Loading… over a page that is already visible.
      if (method === 'get') {
        return;
      }
      window.setTimeout(function () {
        if (e.defaultPrevented) {
          return;
        }
        armDelayedLoading(msg);
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
      // CSV / .txt / email-list exports stay on this page (attachment). Overlay would stick.
      var resolved = String(a.href || href);
      if (/(?:[?&])(?:export|download)=/i.test(href) || /(?:[?&])(?:export|download)=/i.test(resolved)) {
        return;
      }
      var url = sameOriginUrl(a.href);
      if (!shouldShowNav(url)) return;
      armDelayedLoading('Loading…');
    },
    true
  );

  window.addEventListener('pageshow', function (ev) {
    navArmed = false;
    clearNavTimer();
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
