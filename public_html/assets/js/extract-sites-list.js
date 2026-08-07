(function () {
  'use strict';

  var ta = document.getElementById('sites_list_text');
  var copyBtn = document.getElementById('sites_copy_all');
  var statusEl = document.getElementById('sites_list_status');
  if (!ta || !copyBtn) return;

  function setStatus(msg, isError) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('is-error');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      ta.focus();
      ta.select();
      try {
        if (!document.execCommand('copy')) reject(new Error('Copy failed'));
        else resolve();
      } catch (e) {
        reject(e);
      }
    });
  }

  copyBtn.addEventListener('click', function () {
    var text = String(ta.value || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!text) {
      setStatus('No sites to copy.', true);
      return;
    }
    var lines = text.split('\n').filter(Boolean);
    copyBtn.disabled = true;
    copyText(text)
      .then(function () {
        setStatus('Copied ' + lines.length + ' site name' + (lines.length === 1 ? '' : 's') + '.');
        try {
          ta.focus();
          ta.select();
        } catch (e) { /* ignore */ }
      })
      .catch(function () {
        setStatus('Copy failed — select the text in the box and copy manually (Ctrl/Cmd+C).', true);
      })
      .then(function () {
        copyBtn.disabled = false;
      });
  });
})();
