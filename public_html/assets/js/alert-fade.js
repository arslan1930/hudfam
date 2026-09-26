/**
 * Auto-hide temporary flash notices (e.g. “N duplicates found and removed”).
 */
(function () {
  'use strict';
  function fadeAlerts() {
    document.querySelectorAll('[data-alert-fade="1"]').forEach(function (el) {
      window.setTimeout(function () {
        el.classList.add('is-fading');
        window.setTimeout(function () {
          if (el.parentNode) el.parentNode.removeChild(el);
        }, 600);
      }, 4200);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fadeAlerts);
  } else {
    fadeAlerts();
  }
})();
