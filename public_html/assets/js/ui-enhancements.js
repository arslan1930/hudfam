/**
 * Visual-only UI extras for the teal overlay.
 * Does not replace nav-shell.js (mobile Menu). Does not bind forms or sheet actions.
 */
(function () {
  'use strict';
  if (window.__TXF_UI_ENHANCE__) return;
  window.__TXF_UI_ENHANCE__ = true;

  document.documentElement.classList.add('ui-v2');

  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.length < 2) return;
    if (a.hasAttribute('download') || a.getAttribute('target') === '_blank') return;
    var id = href.slice(1);
    if (!/^[A-Za-z][\w:-]*$/.test(id)) return;
    var el = document.getElementById(id);
    if (!el) return;
    e.preventDefault();
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }
  if (!('IntersectionObserver' in window)) return;

  var nodes = document.querySelectorAll('.main > .card, .login-card');
  if (!nodes.length) return;
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) {
        en.target.classList.add('ui-in');
        io.unobserve(en.target);
      }
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
  nodes.forEach(function (el) {
    if (el.closest('.swe-sheet-wrap, .table-wrap, [data-tld-workspace]')) return;
    el.classList.add('ui-enter');
    io.observe(el);
  });
})();
