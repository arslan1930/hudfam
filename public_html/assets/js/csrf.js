/**
 * Inject CSRF into HTML forms and same-origin fetch/XHR POSTs.
 * Token comes from <meta name="csrf-token"> set in layout.
 */
(function () {
  'use strict';

  function token() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  function ensureFormField(form) {
    if (!form || form.method.toLowerCase() !== 'post') return;
    var t = token();
    if (!t) return;
    var existing = form.querySelector('input[name="_csrf"]');
    if (existing) {
      existing.value = t;
      return;
    }
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_csrf';
    input.value = t;
    form.appendChild(input);
  }

  function scanForms(root) {
    (root || document).querySelectorAll('form').forEach(ensureFormField);
  }

  document.addEventListener('DOMContentLoaded', function () {
    scanForms(document);
  });
  scanForms(document);

  if (typeof MutationObserver !== 'undefined') {
    try {
      var obs = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) return;
            if (node.tagName === 'FORM') ensureFormField(node);
            else if (node.querySelectorAll) scanForms(node);
          });
        });
      });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    } catch (e) {}
  }

  document.addEventListener('submit', function (e) {
    if (e.target && e.target.tagName === 'FORM') {
      ensureFormField(e.target);
    }
  }, true);

  function appendTokenToBody(body, t) {
    if (!t) return body;
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
      if (!body.has('_csrf')) body.set('_csrf', t);
      return body;
    }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      if (!body.has('_csrf')) body.append('_csrf', t);
      return body;
    }
    if (typeof body === 'string') {
      if (body.indexOf('_csrf=') >= 0) return body;
      return body + (body.length ? '&' : '') + '_csrf=' + encodeURIComponent(t);
    }
    return body;
  }

  if (typeof window.fetch === 'function') {
    var originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      init = init ? Object.assign({}, init) : {};
      var method = String(init.method || 'GET').toUpperCase();
      if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
        var t = token();
        if (t) {
          var headers = new Headers(init.headers || {});
          if (!headers.has('X-CSRF-Token')) {
            headers.set('X-CSRF-Token', t);
          }
          init.headers = headers;
          if (init.body != null) {
            init.body = appendTokenToBody(init.body, t);
          }
        }
      }
      return originalFetch(input, init);
    };
  }

  if (typeof XMLHttpRequest !== 'undefined') {
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method) {
      this._txfMethod = String(method || 'GET').toUpperCase();
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
      if (this._txfMethod === 'POST' || this._txfMethod === 'PUT'
          || this._txfMethod === 'PATCH' || this._txfMethod === 'DELETE') {
        var t = token();
        if (t) {
          try {
            this.setRequestHeader('X-CSRF-Token', t);
          } catch (e) {}
          body = appendTokenToBody(body, t);
        }
      }
      return send.call(this, body);
    };
  }
})();
