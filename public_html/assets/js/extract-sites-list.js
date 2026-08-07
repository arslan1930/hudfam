(function () {
  'use strict';

  var shell = document.getElementById('sites_list_shell');
  var ta = document.getElementById('sites_list_text');
  if (!shell || !ta) return;

  // Drop any old browser-only drafts — server list is the source of truth on refresh.
  try {
    var batchId = String(shell.getAttribute('data-batch-id') || '');
    if (batchId) {
      localStorage.removeItem('txf-extract-sites-draft-' + batchId);
      localStorage.removeItem('txf-extract-sel-' + batchId);
      localStorage.removeItem('txf-extract-hist-' + batchId);
    }
  } catch (e) { /* ignore */ }

  var postUrl = shell.getAttribute('data-post-url') || window.location.href;
  var copyBtn = document.getElementById('sites_copy_all');
  var undoBtn = document.getElementById('sites_undo_btn');
  var redoBtn = document.getElementById('sites_redo_btn');
  var statusEl = document.getElementById('sites_list_status');
  var footerCount = document.getElementById('sites_footer_count');
  var countLabel = document.getElementById('sites_count_label');
  var autosaveLabel = document.getElementById('sites_autosave_label');

  var undoStack = [];
  var redoStack = [];
  var lastSnapshot = normalizeText(ta.value);
  var lastSavedText = lastSnapshot;
  var applyingHistory = false;
  var saveTimer = null;
  var saveInFlight = false;
  var saveAgain = false;
  var MAX_UNDO = 80;
  var SAVE_DELAY_MS = 550;

  function normalizeText(text) {
    return String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  }

  function linesOf(text) {
    return normalizeText(text).split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
  }

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

  function setAutosaveLabel(msg) {
    if (!autosaveLabel) return;
    autosaveLabel.textContent = msg || '';
  }

  function updateCounts(n) {
    if (typeof n !== 'number') n = linesOf(ta.value).length;
    if (footerCount) {
      footerCount.textContent = n + ' site' + (n === 1 ? '' : 's');
    }
    if (countLabel) countLabel.textContent = String(n);
    if (copyBtn) copyBtn.disabled = n === 0;
  }

  function syncHistoryButtons() {
    if (undoBtn) undoBtn.disabled = undoStack.length === 0;
    if (redoBtn) redoBtn.disabled = redoStack.length === 0;
  }

  function applySelection(start, end) {
    var len = String(ta.value || '').length;
    var s = Math.max(0, Math.min(len, start | 0));
    var e = Math.max(0, Math.min(len, end | 0));
    try {
      ta.focus({ preventScroll: true });
    } catch (err) {
      try { ta.focus(); } catch (err2) { /* ignore */ }
    }
    try {
      ta.setSelectionRange(s, e);
    } catch (err3) { /* ignore */ }
  }

  function setText(nextText, selStart, selEnd) {
    applyingHistory = true;
    ta.value = normalizeText(nextText);
    lastSnapshot = normalizeText(ta.value);
    updateCounts();
    if (typeof selStart === 'number' && typeof selEnd === 'number') {
      applySelection(selStart, selEnd);
    }
    applyingHistory = false;
    syncHistoryButtons();
    scheduleAutosave();
  }

  function undo() {
    if (!undoStack.length) return;
    var current = normalizeText(ta.value);
    var prev = undoStack.pop();
    redoStack.push(current);
    setText(prev, prev.length, prev.length);
    setStatus('Undo');
  }

  function redo() {
    if (!redoStack.length) return;
    var current = normalizeText(ta.value);
    var next = redoStack.pop();
    undoStack.push(current);
    setText(next, next.length, next.length);
    setStatus('Redo');
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var start = ta.selectionStart;
      var end = ta.selectionEnd;
      ta.focus();
      ta.select();
      try {
        if (!document.execCommand('copy')) reject(new Error('Copy failed'));
        else resolve();
      } catch (e) {
        reject(e);
      } finally {
        applySelection(start, end);
      }
    });
  }

  function scheduleAutosave() {
    if (saveTimer) window.clearTimeout(saveTimer);
    setAutosaveLabel('Saving…');
    saveTimer = window.setTimeout(runAutosave, SAVE_DELAY_MS);
  }

  function runAutosave() {
    saveTimer = null;
    var text = normalizeText(ta.value);
    if (text === lastSavedText) {
      setAutosaveLabel('Saved');
      return;
    }
    if (saveInFlight) {
      saveAgain = true;
      return;
    }
    saveInFlight = true;
    setAutosaveLabel('Saving…');

    var body = new URLSearchParams();
    body.set('action', 'autosave_sites');
    body.set('ajax', '1');
    body.set('sites_text', text);

    fetch(postUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Autosave failed');
          }
          return data;
        });
      })
      .then(function (data) {
        lastSavedText = text;
        var n = typeof data.site_count === 'number' ? data.site_count : linesOf(text).length;
        updateCounts(n);
        setAutosaveLabel('Saved');
        if (data.redirect) {
          setStatus(data.message || 'No sites left — returning to Extracting sites…');
          window.setTimeout(function () {
            window.location.href = data.redirect;
          }, 350);
          return;
        }
        if (data.removed > 0 || data.added > 0) {
          setStatus(
            'Autosaved · ' + n + ' site' + (n === 1 ? '' : 's')
            + (data.removed ? ' · removed ' + data.removed : '')
            + (data.added ? ' · added ' + data.added : '')
          );
        }
      })
      .catch(function (err) {
        setAutosaveLabel('Save failed');
        setStatus(err.message || 'Could not autosave Sites list.', true);
      })
      .then(function () {
        saveInFlight = false;
        if (saveAgain) {
          saveAgain = false;
          scheduleAutosave();
        }
      });
  }

  updateCounts();
  syncHistoryButtons();
  setAutosaveLabel('Saved');

  ta.addEventListener('input', function () {
    if (applyingHistory) return;
    var now = normalizeText(ta.value);
    if (now !== lastSnapshot) {
      undoStack.push(lastSnapshot);
      if (undoStack.length > MAX_UNDO) undoStack.shift();
      redoStack = [];
      lastSnapshot = now;
    }
    updateCounts();
    syncHistoryButtons();
    scheduleAutosave();
  });

  ta.addEventListener('keydown', function (e) {
    if (applyingHistory) return;
    var mod = e.metaKey || e.ctrlKey;
    var key = e.key;
    if (mod && key.toLowerCase() === 'z' && !e.shiftKey) {
      e.preventDefault();
      undo();
      return;
    }
    if (mod && (key.toLowerCase() === 'y' || (key.toLowerCase() === 'z' && e.shiftKey))) {
      e.preventDefault();
      redo();
    }
  });

  ta.addEventListener('blur', function () {
    if (saveTimer) {
      window.clearTimeout(saveTimer);
      saveTimer = null;
    }
    runAutosave();
  });

  if (undoBtn) undoBtn.addEventListener('click', undo);
  if (redoBtn) redoBtn.addEventListener('click', redo);

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = normalizeText(ta.value).trim();
      if (!text) {
        setStatus('No sites to copy.', true);
        return;
      }
      var lines = linesOf(text);
      copyBtn.disabled = true;
      copyText(text)
        .then(function () {
          setStatus('Copied ' + lines.length + ' site name' + (lines.length === 1 ? '' : 's') + '.');
        })
        .catch(function () {
          setStatus('Copy failed — select the text in the box and copy manually (Ctrl/Cmd+C).', true);
        })
        .then(function () {
          copyBtn.disabled = linesOf(ta.value).length === 0;
        });
    });
  }
})();
