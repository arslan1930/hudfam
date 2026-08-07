(function () {
  'use strict';

  var body = document.body;
  var toggle = document.querySelector('[data-nav-toggle]');
  var backdrop = document.querySelector('[data-nav-backdrop]');
  var sidebar = document.getElementById('app-sidebar');
  if (!toggle || !sidebar) return;

  function setOpen(open) {
    body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (backdrop) {
      backdrop.hidden = !open;
    }
  }

  function close() {
    setOpen(false);
  }

  toggle.addEventListener('click', function () {
    setOpen(!body.classList.contains('nav-open'));
  });

  if (backdrop) {
    backdrop.addEventListener('click', close);
  }

  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.matchMedia('(max-width: 900px)').matches) {
        close();
      }
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && body.classList.contains('nav-open')) {
      close();
    }
  });

  window.addEventListener('resize', function () {
    if (!window.matchMedia('(max-width: 900px)').matches) {
      close();
    }
  });
})();
