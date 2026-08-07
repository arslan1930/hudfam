/**
 * Info-tip icons: tap/click to pin open on touch devices; hover/focus also works.
 */
(function () {
  if (window.__TXF_INFO_TIPS__) return;
  window.__TXF_INFO_TIPS__ = true;

  document.addEventListener('click', function (e) {
    var tip = e.target && e.target.closest ? e.target.closest('.info-tip') : null;
    document.querySelectorAll('.info-tip.is-open').forEach(function (el) {
      if (el !== tip) el.classList.remove('is-open');
    });
    if (!tip) return;
    e.preventDefault();
    e.stopPropagation();
    tip.classList.toggle('is-open');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.info-tip.is-open').forEach(function (el) {
        el.classList.remove('is-open');
      });
    }
  });
})();
