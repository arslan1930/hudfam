/**
 * In-app confirm / alert (replaces native window.confirm / alert).
 *
 *   txfConfirm({ title, body, confirmLabel, danger }) → Promise<boolean>
 *   txfAlert({ title, body }) → Promise<void>
 *
 * Forms: data-confirm, data-confirm-title, data-confirm-label, data-confirm-danger.
 * After the user confirms, data-stay-ajax and data-show-processing still run.
 *
 * window.confirm / window.alert are wrapped so leftover call sites keep working.
 */
(function () {
  'use strict';
  if (window.__HF_APP_CONFIRM__) return;
  window.__HF_APP_CONFIRM__ = true;

  var nativeConfirm = window.confirm.bind(window);
  var nativeAlert = window.alert.bind(window);
  var overlay = null;
  var titleEl = null;
  var bodyEl = null;
  var actionsEl = null;
  var cancelBtn = null;
  var okBtn = null;
  var lastEvent = null;
  var answersThisEvent = [];
  var replayIndex = 0;
  var queue = [];
  var open = false;
  var activeOpts = null;
  var previousFocus = null;

  function ensure() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'txf-dialog';
    overlay.className = 'txf-dialog-overlay';
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="txf-dialog" role="dialog" aria-modal="true" aria-labelledby="txf-dialog-title" aria-describedby="txf-dialog-body">' +
        '<h2 class="txf-dialog-title" id="txf-dialog-title"></h2>' +
        '<div class="txf-dialog-body" id="txf-dialog-body"></div>' +
        '<div class="txf-dialog-actions">' +
          '<button type="button" class="btn secondary" data-txf-cancel>Cancel</button>' +
          '<button type="button" class="btn" data-txf-ok>Continue</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    titleEl = overlay.querySelector('#txf-dialog-title');
    bodyEl = overlay.querySelector('#txf-dialog-body');
    actionsEl = overlay.querySelector('.txf-dialog-actions');
    cancelBtn = overlay.querySelector('[data-txf-cancel]');
    okBtn = overlay.querySelector('[data-txf-ok]');
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) finish(false);
    });
    cancelBtn.addEventListener('click', function () { finish(false); });
    okBtn.addEventListener('click', function () { finish(true); });
    return overlay;
  }

  function inferDanger(msg) {
    return /cannot be undone|remove all|delete (this|project|invoice|draft|the )|clear all|clear country|remove listed|remove matching|remove paid mark/i.test(String(msg || ''));
  }

  function inferTitle(msg, danger, isAlert) {
    var m = String(msg || '');
    if (isAlert) return 'Notice';
    if (/^remove all /i.test(m) || /from .+\?$/i.test(m) && /remove /i.test(m) && /all /i.test(m)) {
      if (/url/i.test(m)) return 'Remove all URLs?';
      return 'Remove all sites?';
    }
    if (/^push /i.test(m)) return 'Push to Admin?';
    if (/clear all/i.test(m)) return 'Clear this list?';
    if (/delete project/i.test(m)) return 'Delete project?';
    if (/delete /i.test(m)) return 'Delete?';
    return danger ? 'Please confirm' : 'Please confirm';
  }

  function inferLabel(msg, danger, isAlert) {
    if (isAlert) return 'Continue';
    var m = String(msg || '');
    if (/^push /i.test(m)) return 'Push';
    if (/^clear /i.test(m)) return 'Clear';
    if (/^delete /i.test(m)) return 'Delete';
    if (danger) return 'Remove';
    return 'Continue';
  }

  function optsFromSource(el, msg, isAlert) {
    var danger = !!(el && el.hasAttribute && el.hasAttribute('data-confirm-danger')) || (!isAlert && inferDanger(msg));
    var title = (el && el.getAttribute && el.getAttribute('data-confirm-title')) || inferTitle(msg, danger, isAlert);
    var label = (el && el.getAttribute && el.getAttribute('data-confirm-label')) || inferLabel(msg, danger, isAlert);
    return {
      title: title,
      body: String(msg || ''),
      confirmLabel: label,
      danger: danger,
      alert: !!isAlert
    };
  }

  function focusables() {
    if (!overlay) return [];
    return Array.prototype.slice.call(overlay.querySelectorAll('button:not([hidden])'));
  }

  function trapFocus(e) {
    if (!open || e.key !== 'Tab') return;
    var list = focusables();
    if (!list.length) return;
    var first = list[0];
    var last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function onKey(e) {
    if (!open) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      finish(false);
      return;
    }
    trapFocus(e);
  }

  function showDialog(opts) {
    ensure();
    activeOpts = opts;
    open = true;
    previousFocus = document.activeElement;
    titleEl.textContent = opts.title || 'Please confirm';
    bodyEl.textContent = opts.body || '';
    okBtn.textContent = opts.confirmLabel || (opts.alert ? 'Continue' : 'Continue');
    okBtn.className = opts.danger ? 'btn danger' : 'btn';
    cancelBtn.hidden = !!opts.alert;
    overlay.classList.toggle('is-danger', !!opts.danger);
    overlay.hidden = false;
    document.addEventListener('keydown', onKey, true);
    window.requestAnimationFrame(function () {
      var focusEl = opts.danger && !opts.alert ? cancelBtn : okBtn;
      try { focusEl.focus(); } catch (err) { /* ignore */ }
    });
  }

  function hideDialog() {
    if (!overlay) return;
    overlay.hidden = true;
    open = false;
    activeOpts = null;
    document.removeEventListener('keydown', onKey, true);
    if (previousFocus && typeof previousFocus.focus === 'function') {
      try { previousFocus.focus(); } catch (err) { /* ignore */ }
    }
    previousFocus = null;
  }

  function finish(ok) {
    if (!open || !activeOpts) return;
    var resolve = activeOpts._resolve;
    hideDialog();
    if (typeof resolve === 'function') resolve(!!ok);
    window.setTimeout(runQueue, 0);
  }

  function runQueue() {
    if (open || !queue.length) return;
    var item = queue.shift();
    item.opts._resolve = item.resolve;
    showDialog(item.opts);
  }

  function enqueue(opts) {
    return new Promise(function (resolve) {
      queue.push({ opts: opts, resolve: resolve });
      runQueue();
    });
  }

  function txfConfirm(opts) {
    opts = opts || {};
    var body = opts.body != null ? String(opts.body) : '';
    return enqueue({
      title: opts.title || inferTitle(body, !!opts.danger, false),
      body: body,
      confirmLabel: opts.confirmLabel || inferLabel(body, !!opts.danger, false),
      danger: !!opts.danger,
      alert: false
    });
  }

  function txfAlert(opts) {
    opts = opts || {};
    var body = opts.body != null ? String(opts.body) : '';
    return enqueue({
      title: opts.title || 'Notice',
      body: body,
      confirmLabel: opts.confirmLabel || 'Continue',
      danger: false,
      alert: true
    }).then(function () { return true; });
  }

  function sourceElFromEvent(ev) {
    if (!ev) return null;
    var t = ev.target;
    if (t && t.closest) {
      var tagged = t.closest('[data-confirm], [data-confirm-title], [data-confirm-danger], [data-confirm-push-all]');
      if (tagged) return tagged;
    }
    if (t && t.form) return t.form;
    return t;
  }

  function replayEvent(ev) {
    if (!ev) return;
    var target = ev.target;
    if (!target) return;
    replayIndex = 0;
    try {
      if (ev.type === 'submit' && target.tagName === 'FORM') {
        if (typeof target.requestSubmit === 'function') {
          target.requestSubmit();
        } else {
          HTMLFormElement.prototype.submit.call(target);
        }
        return;
      }
      if (ev.type === 'click') {
        var clickTarget = ev.target;
        if (clickTarget && clickTarget.closest) {
          var btn = clickTarget.closest('button, input[type="submit"], input[type="button"], a');
          if (btn) clickTarget = btn;
        }
        if (typeof clickTarget.click === 'function') {
          clickTarget.click();
          return;
        }
        clickTarget.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
      }
    } finally {
      window.setTimeout(function () {
        answersThisEvent = [];
        replayIndex = 0;
      }, 0);
    }
  }

  window.txfConfirm = txfConfirm;
  window.txfAlert = txfAlert;

  window.confirm = function (message) {
    if (answersThisEvent.length && replayIndex < answersThisEvent.length) {
      return answersThisEvent[replayIndex++];
    }
    var msg = String(message == null ? '' : message);
    var ev = lastEvent;
    var src = sourceElFromEvent(ev);
    txfConfirm(optsFromSource(src, msg, false)).then(function (ok) {
      if (!ok) {
        answersThisEvent = [];
        replayIndex = 0;
        return;
      }
      answersThisEvent.push(true);
      replayEvent(ev);
    });
    return false;
  };

  window.alert = function (message) {
    var msg = String(message == null ? '' : message);
    txfAlert({ body: msg });
  };

  document.addEventListener('click', function (e) { lastEvent = e; }, true);
  document.addEventListener('submit', function (e) { lastEvent = e; }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) return;
    if (form.getAttribute('data-txf-confirmed') === '1') {
      form.removeAttribute('data-txf-confirmed');
      return;
    }
    var msg = form.getAttribute('data-confirm');
    if (!msg) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    txfConfirm(optsFromSource(form, msg, false)).then(function (ok) {
      if (!ok) return;
      form.setAttribute('data-txf-confirmed', '1');
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        HTMLFormElement.prototype.submit.call(form);
      }
    });
  }, true);

  window.txfNativeConfirm = nativeConfirm;
  window.txfNativeAlert = nativeAlert;
})();
