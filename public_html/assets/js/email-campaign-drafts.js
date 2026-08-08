/**
 * Communication Team · campaign drafts — one-click copy of outreach text.
 */
(function () {
  'use strict';

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        if (!document.execCommand('copy')) {
          reject(new Error('Copy failed'));
          return;
        }
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  function setStatus(card, msg, isError) {
    if (!card) return;
    var el = card.querySelector('[data-camp-draft-status]');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.classList.remove('is-error');
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
  }

  document.querySelectorAll('[data-camp-draft-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('[data-camp-draft-card]');
      if (!card) return;
      var bodyEl = card.querySelector('[data-camp-draft-body]');
      var text = bodyEl ? String(bodyEl.value || '') : '';
      if (!text.trim()) {
        setStatus(card, 'This draft is empty.', true);
        return;
      }
      var title = String((card.querySelector('.camp-draft-title') || {}).textContent || 'draft').trim();
      btn.disabled = true;
      copyText(text)
        .then(function () {
          setStatus(card, 'Copied “' + title + '” — paste into your email client.');
          var prev = btn.textContent;
          btn.textContent = 'Copied';
          window.setTimeout(function () {
            btn.textContent = prev || 'Copy';
            btn.disabled = false;
          }, 1200);
        })
        .catch(function () {
          setStatus(card, 'Could not copy. Select the text manually.', true);
          btn.disabled = false;
        });
    });
  });
})();
