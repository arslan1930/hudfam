/**
 * In-app confirm / alert (dark overlay). Replaces native window.confirm / alert.
 * Forms with data-confirm (or a submitter with data-confirm) are intercepted.
 */
(function () {
  if (window.__TXF_APP_DIALOG__) return;
  window.__TXF_APP_DIALOG__ = true;

  var queue = Promise.resolve();

  function ensureDialog() {
    var dlg = document.getElementById('app-dialog');
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.id = 'app-dialog';
    dlg.className = 'app-dialog';
    dlg.setAttribute('aria-labelledby', 'app-dialog-title');
    dlg.innerHTML =
      '<form method="dialog" class="app-dialog-card">'
      + '<h2 id="app-dialog-title" class="app-dialog-title">Confirm</h2>'
      + '<p id="app-dialog-body" class="app-dialog-body"></p>'
      + '<div class="app-dialog-actions">'
      + '<button type="button" class="btn secondary" data-app-dialog-cancel>Cancel</button>'
      + '<button type="submit" class="btn" value="ok" data-app-dialog-ok>Continue</button>'
      + '</div></form>';
    document.body.appendChild(dlg);
    return dlg;
  }

  function show(opts) {
    opts = opts || {};
    var isAlert = opts.mode === 'alert';
    return new Promise(function (resolve) {
      var dlg = ensureDialog();
      var title = dlg.querySelector('#app-dialog-title');
      var body = dlg.querySelector('#app-dialog-body');
      var cancel = dlg.querySelector('[data-app-dialog-cancel]');
      var ok = dlg.querySelector('[data-app-dialog-ok]');
      var settled = false;

      function finish(val) {
        if (settled) return;
        settled = true;
        dlg.removeEventListener('cancel', onCancel);
        dlg.removeEventListener('close', onClose);
        if (ok) ok.onclick = null;
        if (cancel) cancel.onclick = null;
        try {
          if (dlg.open) dlg.close();
        } catch (err) { /* ignore */ }
        resolve(val);
      }

      function onCancel(e) {
        e.preventDefault();
        finish(false);
      }
      function onClose() {
        finish(false);
      }

      if (title) title.textContent = opts.title || (isAlert ? 'Notice' : 'Confirm');
      if (body) body.textContent = String(opts.message || '');
      if (cancel) cancel.hidden = !!isAlert;
      if (ok) {
        ok.textContent = opts.okLabel || (isAlert ? 'OK' : 'Continue');
        ok.className = 'btn' + (opts.danger && !isAlert ? ' danger' : '');
      }

      dlg.addEventListener('cancel', onCancel);
      dlg.addEventListener('close', onClose);
      if (ok) {
        ok.onclick = function (e) {
          e.preventDefault();
          finish(true);
        };
      }
      if (cancel) {
        cancel.onclick = function (e) {
          e.preventDefault();
          finish(false);
        };
      }

      try {
        if (typeof dlg.showModal === 'function') {
          dlg.showModal();
        } else {
          finish(!isAlert ? window.confirm(String(opts.message || '')) : (window.alert(String(opts.message || '')), true));
          return;
        }
      } catch (err) {
        finish(!isAlert ? window.confirm(String(opts.message || '')) : (window.alert(String(opts.message || '')), true));
        return;
      }
      if (ok) {
        try { ok.focus(); } catch (err2) { /* ignore */ }
      }
    });
  }

  function enqueue(opts) {
    var next = queue.then(function () {
      return show(opts);
    });
    queue = next.then(function () { return undefined; }, function () { return undefined; });
    return next;
  }

  window.txfConfirm = function (message, opts) {
    opts = opts || {};
    opts.mode = 'confirm';
    opts.message = message;
    if (opts.danger == null) opts.danger = true;
    return enqueue(opts);
  };

  window.txfAlert = function (message, opts) {
    opts = opts || {};
    opts.mode = 'alert';
    opts.message = message;
    opts.danger = false;
    return enqueue(opts);
  };

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    if (form.getAttribute('data-confirm-ok') === '1') {
      form.removeAttribute('data-confirm-ok');
      return;
    }
    var submitter = e.submitter || null;
    var msg = '';
    if (submitter && submitter.getAttribute) {
      msg = String(submitter.getAttribute('data-confirm') || '');
    }
    if (!msg) msg = String(form.getAttribute('data-confirm') || '');
    if (!msg) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    window.txfConfirm(msg).then(function (ok) {
      if (!ok) return;
      form.setAttribute('data-confirm-ok', '1');
      try {
        if (submitter && typeof form.requestSubmit === 'function') {
          form.requestSubmit(submitter);
        } else if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      } catch (err) {
        form.submit();
      }
    });
  }, true);
})();
