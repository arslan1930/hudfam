(function () {
  'use strict';

  var shell = document.getElementById('sites_list_shell');
  var ta = document.getElementById('sites_list_text');
  if (!shell || !ta) return;

  var batchId = String(shell.getAttribute('data-batch-id') || '');
  var storeKey = 'txf-extract-sites-draft-' + batchId;

  var serverText = '';
  var serverJson = document.getElementById('sites_list_server_json');
  if (serverJson) {
    try {
      serverText = normalizeText(JSON.parse(serverJson.textContent || '""'));
    } catch (e) {
      serverText = normalizeText(ta.value);
    }
  } else {
    serverText = normalizeText(ta.value);
  }

  var copyBtn = document.getElementById('sites_copy_all');
  var undoBtn = document.getElementById('sites_undo_btn');
  var redoBtn = document.getElementById('sites_redo_btn');
  var statusEl = document.getElementById('sites_list_status');
  var footerCount = document.getElementById('sites_footer_count');
  var countLabel = document.getElementById('sites_count_label');

  var undoStack = [];
  var redoStack = [];
  var lastSnapshot = '';
  var applyingHistory = false;
  var saveTimer = null;
  var MAX_UNDO = 80;

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

  function updateCounts() {
    var n = linesOf(ta.value).length;
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

  function currentSelection() {
    return {
      start: typeof ta.selectionStart === 'number' ? ta.selectionStart : 0,
      end: typeof ta.selectionEnd === 'number' ? ta.selectionEnd : 0
    };
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

  function loadDraft() {
    try {
      var raw = localStorage.getItem(storeKey);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || typeof data !== 'object') return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function saveDraftSoon() {
    if (saveTimer) window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveDraftNow, 80);
  }

  function saveDraftNow() {
    saveTimer = null;
    var sel = currentSelection();
    var payload = {
      text: normalizeText(ta.value),
      undo: undoStack.slice(-MAX_UNDO),
      redo: redoStack.slice(-MAX_UNDO),
      selStart: sel.start,
      selEnd: sel.end,
      serverText: serverText,
      updatedAt: Date.now()
    };
    try {
      localStorage.setItem(storeKey, JSON.stringify(payload));
    } catch (e) { /* ignore quota */ }
  }

  function mergeNewServerSites(draftText, currentServerText) {
    var draftLines = linesOf(draftText);
    var serverLines = linesOf(currentServerText);
    if (!serverLines.length) return normalizeText(draftText);
    var seen = {};
    draftLines.forEach(function (d) { seen[d.toLowerCase()] = true; });
    var extras = [];
    serverLines.forEach(function (d) {
      var key = d.toLowerCase();
      if (!seen[key]) {
        seen[key] = true;
        extras.push(d);
      }
    });
    if (!extras.length) return normalizeText(draftText);
    var base = normalizeText(draftText).replace(/\s+$/g, '');
    return (base ? base + '\n' : '') + extras.join('\n');
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
    saveDraftNow();
  }

  function undo() {
    if (!undoStack.length) return;
    var current = normalizeText(ta.value);
    var prev = undoStack.pop();
    redoStack.push(current);
    var caret = prev.length;
    setText(prev, caret, caret);
    setStatus('Undo');
  }

  function redo() {
    if (!redoStack.length) return;
    var current = normalizeText(ta.value);
    var next = redoStack.pop();
    undoStack.push(current);
    var caret = next.length;
    setText(next, caret, caret);
    setStatus('Redo');
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var sel = currentSelection();
      ta.focus();
      ta.select();
      try {
        if (!document.execCommand('copy')) reject(new Error('Copy failed'));
        else resolve();
      } catch (e) {
        reject(e);
      } finally {
        applySelection(sel.start, sel.end);
      }
    });
  }

  // --- restore temporary history ---
  var draft = loadDraft();
  var initialText = serverText;
  var initialSelStart = 0;
  var initialSelEnd = 0;

  if (draft && typeof draft.text === 'string') {
    initialText = mergeNewServerSites(draft.text, serverText);
    if (Array.isArray(draft.undo)) {
      undoStack = draft.undo.map(normalizeText).filter(function (t) { return typeof t === 'string'; });
    }
    if (Array.isArray(draft.redo)) {
      redoStack = draft.redo.map(normalizeText).filter(function (t) { return typeof t === 'string'; });
    }
    if (typeof draft.selStart === 'number') initialSelStart = draft.selStart;
    if (typeof draft.selEnd === 'number') initialSelEnd = draft.selEnd;
  }

  ta.value = initialText;
  lastSnapshot = normalizeText(ta.value);
  updateCounts();
  syncHistoryButtons();

  window.requestAnimationFrame(function () {
    applySelection(initialSelStart, initialSelEnd);
    saveDraftNow();
  });

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
    saveDraftSoon();
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

  function persistSelection() {
    saveDraftSoon();
  }
  ta.addEventListener('keyup', persistSelection);
  ta.addEventListener('mouseup', persistSelection);
  ta.addEventListener('select', persistSelection);
  ta.addEventListener('blur', saveDraftNow);

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
