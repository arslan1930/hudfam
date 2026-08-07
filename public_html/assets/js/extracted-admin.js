(function () {
  'use strict';

  var btn = document.getElementById('extracted_copy_all');
  var status = document.getElementById('extracted_copy_status');
  if (!btn) return;

  function setStatus(msg, isError) {
    if (!status) return;
    if (!msg) {
      status.hidden = true;
      status.textContent = '';
      status.classList.remove('is-error');
      return;
    }
    status.hidden = false;
    status.textContent = msg;
    status.classList.toggle('is-error', !!isError);
  }

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
      ta.focus();
      ta.select();
      try {
        if (!document.execCommand('copy')) reject(new Error('Copy failed'));
        else resolve();
      } catch (e) {
        reject(e);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-export-url');
    var count = parseInt(btn.getAttribute('data-count') || '0', 10) || 0;
    if (!url) return;

    btn.disabled = true;
    setStatus('Loading ' + (count ? count.toLocaleString() : '') + ' site names…');

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'text/plain' }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('Could not load site list (' + res.status + ').');
        return res.text();
      })
      .then(function (text) {
        text = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
        if (!text) throw new Error('No sites to copy.');
        var lines = text.split('\n').filter(Boolean);
        return copyText(text).then(function () {
          setStatus('Copied ' + lines.length.toLocaleString() + ' site name' + (lines.length === 1 ? '' : 's') + ' as text.');
        });
      })
      .catch(function (err) {
        setStatus(
          (err && err.message ? err.message : 'Copy failed.') +
          ' Use Download .txt if the list is very large.',
          true
        );
      })
      .then(function () {
        btn.disabled = count < 1;
      });
  });
})();
