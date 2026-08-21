/**
 * CSRF helper — inject token into forms and same-origin POST fetch/XHR (Admin + Team).
 * Requires <meta name="csrf-token" content="..."> in the document head.
 */
(function () {
  'use strict';
  if (window.__TXF_CSRF__) return;
  window.__TXF_CSRF__ = true;

  function token() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  function ensureFormField(form) {
    if (!form || !form.tagName || form.tagName.toLowerCase() !== 'form') return;
    var method = String(form.getAttribute('method') || 'get').toLowerCase();
    if (method === 'get') return;
    if (form.querySelector('input[name="_csrf"]')) return;
    var t = token();
    if (!t) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_csrf';
    input.value = t;
    form.appendChild(input);
  }

  function scan(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('form').forEach(ensureFormField);
  }

  function boot() {
    scan(document);
    if (typeof MutationObserver !== 'undefined') {
      var scheduled = false;
      var pending = [];
      var obs = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var nodes = mutations[i].addedNodes;
          for (var j = 0; j < nodes.length; j++) {
            var n = nodes[j];
            if (!n || n.nodeType !== 1) continue;
            if ((n.matches && n.matches('form')) || (n.querySelector && n.querySelector('form'))) {
              pending.push(n);
            }
          }
        }
        if (!pending.length || scheduled) return;
        scheduled = true;
        var run = function () {
          scheduled = false;
          var batch = pending;
          pending = [];
          for (var k = 0; k < batch.length; k++) {
            var node = batch[k];
            if (node.matches && node.matches('form')) ensureFormField(node);
            else if (node.querySelectorAll) scan(node);
          }
        };
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run);
        else setTimeout(run, 0);
      });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  function appendTokenToBody(body) {
    var t = token();
    if (!t) return body;
    if (body instanceof URLSearchParams) {
      if (!body.has('_csrf')) body.set('_csrf', t);
      return body;
    }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      if (!body.has('_csrf')) body.append('_csrf', t);
      return body;
    }
    if (typeof body === 'string') {
      var trimmed = body.replace(/^\s+/, '');
      // Do not corrupt JSON request bodies — header token is enough.
      if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
        return body;
      }
      if (body.indexOf('_csrf=') !== -1) return body;
      return body + (body ? '&' : '') + '_csrf=' + encodeURIComponent(t);
    }
    return body;
  }

  function withCsrfHeaders(headers) {
    var t = token();
    if (!t) return headers;
    var h = headers;
    if (typeof Headers !== 'undefined' && !(h instanceof Headers)) {
      h = new Headers(h || {});
    } else if (!h) {
      h = typeof Headers !== 'undefined' ? new Headers() : {};
    }
    if (typeof Headers !== 'undefined' && h instanceof Headers) {
      if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', t);
      return h;
    }
    if (!h['X-CSRF-Token'] && !h['x-csrf-token']) {
      h['X-CSRF-Token'] = t;
    }
    return h;
  }

  if (typeof window.fetch === 'function') {
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
      init = init ? Object.assign({}, init) : {};
      var method = String(init.method || 'GET').toUpperCase();
      if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
        init.headers = withCsrfHeaders(init.headers);
        if (init.body != null) {
          init.body = appendTokenToBody(init.body);
        } else {
          init.body = appendTokenToBody('');
          if (!init.headers) init.headers = withCsrfHeaders({});
          if (typeof Headers !== 'undefined' && init.headers instanceof Headers) {
            if (!init.headers.has('Content-Type')) {
              init.headers.set('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            }
          }
        }
      }
      return origFetch.call(this, input, init);
    };
  }

  if (typeof XMLHttpRequest !== 'undefined') {
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method) {
      this.__txfMethod = String(method || 'GET').toUpperCase();
      return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
      if (this.__txfMethod === 'POST' || this.__txfMethod === 'PUT'
          || this.__txfMethod === 'PATCH' || this.__txfMethod === 'DELETE') {
        var t = token();
        if (t) {
          try {
            this.setRequestHeader('X-CSRF-Token', t);
          } catch (e) { /* ignore */ }
          body = appendTokenToBody(body == null ? '' : body);
        }
      }
      return origSend.call(this, body);
    };
  }

  document.addEventListener('submit', function (e) {
    ensureFormField(e.target);
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
