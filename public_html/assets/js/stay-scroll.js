/**
 * Sitewide: keep scroll position after same-page POST actions.
 *
 * When a normal form POST reloads the same list URL, restore the previous
 * scrollY so the page does not jump to the top. AJAX forms that call
 * preventDefault are ignored.
 *
 * Opt out: <form data-stay-scroll="0">
 */
(function () {
  'use strict';

  var PATH_KEY = 'hf_stay_path';
  var Y_KEY = 'hf_stay_y';
  var FOCUS_KEY = 'hf_stay_focus';

  function pageKey() {
    return String(location.pathname || '') + String(location.search || '');
  }

  function readSaved() {
    try {
      return {
        path: sessionStorage.getItem(PATH_KEY),
        y: parseInt(sessionStorage.getItem(Y_KEY) || '', 10),
        focus: sessionStorage.getItem(FOCUS_KEY) || '',
      };
    } catch (e) {
      return { path: null, y: NaN, focus: '' };
    }
  }

  function clearSaved() {
    try {
      sessionStorage.removeItem(PATH_KEY);
      sessionStorage.removeItem(Y_KEY);
      sessionStorage.removeItem(FOCUS_KEY);
    } catch (e) { /* ignore */ }
  }

  function restore() {
    var saved = readSaved();
    if (!saved.path || saved.path !== pageKey() || !isFinite(saved.y) || saved.y < 1) {
      return false;
    }
    if ('scrollRestoration' in history) {
      try { history.scrollRestoration = 'manual'; } catch (e) { /* ignore */ }
    }
    var top = saved.y;
    window.scrollTo(0, top);
    if (saved.focus) {
      var el = document.getElementById(saved.focus);
      if (el && typeof el.focus === 'function') {
        try { el.focus({ preventScroll: true }); } catch (err) {
          try { el.focus(); window.scrollTo(0, top); } catch (err2) { /* ignore */ }
        }
      }
    }
    return true;
  }

  // Restore as early as this deferred/async script can (and again after load).
  var restored = restore();
  if (restored) {
    document.addEventListener('DOMContentLoaded', function () {
      restore();
    });
    window.addEventListener('load', function () {
      restore();
      clearSaved();
    });
  } else {
    clearSaved();
  }

  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target;
      if (!form || !form.getAttribute) return;
      if (String(form.getAttribute('data-stay-scroll') || '') === '0') return;
      var method = String(form.method || 'get').toLowerCase();
      if (method !== 'post') return;
      var target = String(form.getAttribute('target') || '');
      if (target && target !== '_self') return;

      var y = window.scrollY || window.pageYOffset || 0;
      var path = pageKey();
      var active = document.activeElement;
      var focusId = active && active.id ? String(active.id) : '';
      // Wait a tick so AJAX handlers can preventDefault first.
      window.setTimeout(function () {
        if (e.defaultPrevented) return;
        try {
          sessionStorage.setItem(PATH_KEY, path);
          sessionStorage.setItem(Y_KEY, String(y));
          if (focusId) sessionStorage.setItem(FOCUS_KEY, focusId);
          else sessionStorage.removeItem(FOCUS_KEY);
        } catch (err) { /* ignore */ }
      }, 0);
    },
    true
  );

  // --- Optional in-place AJAX for forms marked data-stay-ajax ---
  function postStayAjax(form) {
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Update failed');
          }
          return data;
        });
      })
      .catch(function (err) {
        window.alert(err.message || 'Update failed');
        form.removeAttribute('data-busy');
        return null;
      });
  }

  function applyStayAjaxSuccess(form, data) {
    if (!form || !data) return;

    if (form.hasAttribute('data-stay-remove-row')) {
      var row = form.closest('tr');
      if (row) row.remove();
    }

    if (form.hasAttribute('data-stay-team-toggle')) {
      var visible = !!data.team_search_visible;
      var input = form.querySelector('[name="team_search_visible"]');
      if (input) input.value = visible ? '0' : '1';
      var btn = form.querySelector('button[type="submit"], button:not([type])');
      if (btn) {
        btn.classList.toggle('is-on', visible);
        btn.classList.toggle('is-off', !visible);
        btn.title = visible ? 'Hide from Communication Team' : 'Show to Communication Team';
        var label = visible ? 'Shown to team' : 'Hidden from team';
        var dot = btn.querySelector('.camp-hub-team-dot');
        btn.textContent = '';
        if (dot) {
          btn.appendChild(dot);
          btn.appendChild(document.createTextNode(' ' + label));
        } else {
          var span = document.createElement('span');
          span.className = 'camp-hub-team-dot';
          span.setAttribute('aria-hidden', 'true');
          btn.appendChild(span);
          btn.appendChild(document.createTextNode(' ' + label));
        }
      }
    }

    if (form.hasAttribute('data-stay-mark-paid')) {
      var cell = form.closest('td') || form.parentElement;
      if (cell) {
        var badge = document.createElement('span');
        badge.className = 'invoice-pay-badge is-paid';
        badge.title = 'Payment already received';
        badge.textContent = 'Paid';
        form.replaceWith(badge);
      }
    }

    form.removeAttribute('data-busy');
    form.dispatchEvent(new CustomEvent('stayajax:success', { bubbles: true, detail: data }));
  }

  document.addEventListener('focusin', function (e) {
    var sel = e.target;
    if (!sel || !sel.matches || !sel.matches('select[data-stay-ajax-change]')) return;
    sel.setAttribute('data-prev-value', String(sel.value || ''));
  });

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.matches || !sel.matches('select[data-stay-ajax-change]')) return;
    var form = sel.form;
    if (!form || !form.matches || !form.matches('[data-stay-ajax]')) return;
    var prev = sel.getAttribute('data-prev-value');
    sel.disabled = true;
    postStayAjax(form).then(function (data) {
      sel.disabled = false;
      if (!data) {
        if (prev !== null && prev !== undefined) sel.value = prev;
        return;
      }
      sel.setAttribute('data-prev-value', String(sel.value || ''));
      applyStayAjaxSuccess(form, data);
    });
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('[data-stay-ajax]')) return;
    // Change-driven selects use the change handler; skip double-post on Enter.
    if (form.querySelector('select[data-stay-ajax-change]') && !form.querySelector('button[type="submit"], input[type="submit"]')) {
      e.preventDefault();
      return;
    }
    e.preventDefault();
    postStayAjax(form).then(function (data) {
      if (!data) return;
      applyStayAjaxSuccess(form, data);
    });
  });
})();
