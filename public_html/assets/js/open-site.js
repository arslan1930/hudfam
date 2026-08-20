/**
 * Shared Open-site helpers: normalize host, toggle Open links, bind editable cells.
 * Markup:
 *   <div class="open-site-cell" data-open-site-cell>
 *     <input data-open-site-host …>
 *     <a data-open-site href="…" target="_blank" rel="noopener noreferrer">Open</a>
 *   </div>
 * Or a static link with data-open-site + data-open-host="example.com".
 */
(function (global) {
  'use strict';

  function normalizeSiteHost(raw) {
    var s = String(raw || '').trim();
    if (!s) return '';
    s = s.replace(/^[\s'"\[<\(]+/, '').replace(/[\s'"\]>\)]+$/, '');
    try {
      var probe = s;
      if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(probe) && probe.indexOf('.') !== -1) {
        if (/^[a-z0-9.-]+(\/|\?|#|$)/i.test(probe)) probe = 'https://' + probe;
      }
      probe = probe.replace(/^(?:h?ttps?|tps?):\/\//i, 'https://');
      var u = new URL(probe);
      if (u.hostname) s = u.hostname;
    } catch (err) {
      s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
      if (s.indexOf('//') === 0) s = s.slice(2);
      s = s.split('/')[0].split('?')[0].split('#')[0];
    }
    if (s.indexOf('@') !== -1) s = s.split('@').pop() || '';
    s = String(s).toLowerCase();
    if (s.indexOf(':') !== -1 && s.indexOf(']') === -1) s = s.split(':')[0];
    s = s.replace(/^www\./i, '').replace(/\.$/, '');
    return s;
  }

  function isOpenableSite(host) {
    host = String(host || '').toLowerCase();
    if (!host || host.indexOf('.') === -1) return false;
    if (/\s/.test(host)) return false;
    if (!/^[a-z0-9.-]+$/.test(host)) return false;
    if (host.charAt(0) === '-' || host.slice(-1) === '-' || host.indexOf('..') !== -1) return false;
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return false;
    for (var i = 0; i < parts.length; i++) {
      var label = parts[i];
      if (!label || label.length > 63) return false;
      if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label)) return false;
    }
    return true;
  }

  function siteOpenUrl(hostOrUrl) {
    var s = String(hostOrUrl || '').trim();
    if (/^https?:\/\//i.test(s)) return s.replace(/\s+/g, '');
    return 'https://' + s;
  }

  function setOpenLinkState(link, hostOrUrl) {
    if (!link) return;
    var raw = String(hostOrUrl || '').trim();
    var host = normalizeSiteHost(raw);
    var ok = isOpenableSite(host) || /^https?:\/\//i.test(raw);
    var openTarget = /^https?:\/\//i.test(raw) ? siteOpenUrl(raw) : (ok ? siteOpenUrl(host) : '');
    var cell = link.closest ? link.closest('[data-open-site-cell], .swe-site-cell, .open-site-cell') : null;
    if (cell) cell.classList.toggle('is-invalid-site', !ok);
    if (ok && openTarget) {
      link.href = openTarget;
      link.removeAttribute('aria-disabled');
      link.classList.remove('is-disabled');
      link.removeAttribute('tabindex');
      var labelHost = host || openTarget;
      link.title = 'Open ' + labelHost + ' in a new tab';
      link.setAttribute('aria-label', 'Open ' + labelHost + ' in a new tab');
    } else {
      link.href = '#';
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-disabled');
      link.setAttribute('tabindex', '-1');
      link.title = 'Fix the site name (needs a valid domain) before opening';
      link.setAttribute('aria-label', 'Site name invalid — cannot open');
    }
  }

  function refreshOpenCell(root) {
    if (!root) return;
    var input = root.querySelector('[data-open-site-host], .swe-domain');
    var link = root.querySelector('[data-open-site], [data-swe-open-site]');
    if (!link) return;
    var raw = '';
    if (input) raw = String(input.value || '').trim();
    else raw = String(link.getAttribute('data-open-host') || '').trim();
    setOpenLinkState(link, raw);
  }

  function refreshAll(scope) {
    var root = scope || document;
    root.querySelectorAll('[data-open-site-cell], .swe-site-cell, .open-site-cell').forEach(refreshOpenCell);
    root.querySelectorAll('[data-open-site][data-open-host]').forEach(function (link) {
      if (link.closest && link.closest('[data-open-site-cell], .swe-site-cell, .open-site-cell')) return;
      setOpenLinkState(link, normalizeSiteHost(link.getAttribute('data-open-host') || ''));
    });
  }

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches) return;
    if (!input.matches('[data-open-site-host], .swe-domain')) return;
    var cell = input.closest('[data-open-site-cell], .swe-site-cell, .open-site-cell');
    if (cell) refreshOpenCell(cell);
  });

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest
      ? e.target.closest('[data-open-site], [data-swe-open-site]')
      : null;
    if (!link) return;
    if (link.getAttribute('aria-disabled') === 'true' || link.classList.contains('is-disabled')) {
      e.preventDefault();
    }
  });

  function boot() {
    refreshAll(document);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.OpenSite = {
    normalizeSiteHost: normalizeSiteHost,
    isOpenableSite: isOpenableSite,
    siteOpenUrl: siteOpenUrl,
    refreshOpenCell: refreshOpenCell,
    refreshAll: refreshAll
  };
})(window);
