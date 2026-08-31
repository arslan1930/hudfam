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
  var countTimer = null;
  var OPEN_BATCH_SIZE = 10;
  var openBatchState = null;
  var OPEN_COUNT_STORAGE_KEY = 'extract-open-count';

  function scheduleCounts() {
    if (countTimer) window.clearTimeout(countTimer);
    countTimer = window.setTimeout(function () {
      countTimer = null;
      updateCounts();
    }, 80);
  }

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

  function lastWriterText(name, at) {
    name = String(name || '').trim();
    at = String(at || '').slice(0, 16);
    if (!name && !at) return '';
    return 'Last saved by ' + (name || 'Someone') + (at ? ' · ' + at : '');
  }

  function updateCounts(n) {
    if (typeof n !== 'number') n = linesOf(ta.value).length;
    if (footerCount) {
      footerCount.textContent = n + ' site' + (n === 1 ? '' : 's');
    }
    if (countLabel) countLabel.textContent = n + ' site' + (n === 1 ? '' : 's');
    if (copyBtn) copyBtn.disabled = n === 0;
    syncOpenBulkButton();
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
    var writerAt = shell.getAttribute('data-writer-at') || '';
    if (writerAt) body.set('writer_at', writerAt);

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
          if (data && data.conflict) {
            throw Object.assign(new Error(data.error || 'Reload to avoid overwriting.'), { conflict: true, data: data });
          }
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Autosave failed');
          }
          return data;
        });
      })
      .then(function (data) {
        lastSavedText = text;
        if (data.domains != null) {
          var savedRaw = Array.isArray(data.domains) ? data.domains.join('\n') : String(data.domains || '');
          var saved = normalizeText(savedRaw);
          if (saved !== normalizeText(ta.value)) {
            applyingHistory = true;
            ta.value = savedRaw;
            lastSnapshot = saved;
            applyingHistory = false;
            setStatus('Autosaved — invalid lines were removed so the box matches the Sites list.');
          }
        }
        var n = typeof data.site_count === 'number' ? data.site_count : linesOf(ta.value).length;
        updateCounts(n);
        if (data.writer_at) shell.setAttribute('data-writer-at', data.writer_at);
        if (data.writer_name || data.writer_at) {
          setAutosaveLabel(lastWriterText(data.writer_name, data.writer_at) || 'Saved');
        } else {
          setAutosaveLabel('Saved');
        }
        if (data.empty) {
          setStatus(
            data.message ||
            'Sites list empty — stays open here; hides on Extracting sites; removed after 1 hour unless new sites are added.'
          );
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
        if (err && err.conflict) {
          saveAgain = false;
          var data = err.data || {};
          if (data.writer_at) shell.setAttribute('data-writer-at', data.writer_at);
          if (data.domains != null) {
            var savedRaw = Array.isArray(data.domains) ? data.domains.join('\n') : String(data.domains || '');
            applyingHistory = true;
            ta.value = savedRaw;
            lastSnapshot = normalizeText(savedRaw);
            lastSavedText = lastSnapshot;
            applyingHistory = false;
            updateCounts();
          }
          undoStack = [];
          redoStack = [];
          syncHistoryButtons();
          if (data.writer_name || data.writer_at) {
            setAutosaveLabel(lastWriterText(data.writer_name, data.writer_at) || 'Saved');
          }
        } else {
          setAutosaveLabel('Save failed');
        }
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
  if (autosaveLabel && !String(autosaveLabel.textContent || '').trim()) {
    setAutosaveLabel('Saved');
  }

  ta.addEventListener('input', function () {
    if (applyingHistory) return;
    var now = normalizeText(ta.value);
    if (now !== lastSnapshot) {
      undoStack.push(lastSnapshot);
      if (undoStack.length > MAX_UNDO) undoStack.shift();
      redoStack = [];
      lastSnapshot = now;
    }
    scheduleCounts();
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

  // --- Open first 10–50 from the Sites list (batches of 10) ---
  function normalizeSiteHost(raw) {
    var s = String(raw || '').trim();
    if (!s) return '';
    s = s.replace(/^[\s'"\[<\(]+/, '').replace(/[\s'"\]>\)]+$/, '');
    try {
      var probe = s;
      if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(probe) && probe.indexOf('.') !== -1) {
        if (/^[a-z0-9.-]+(\/|\?|#|$)/i.test(probe)) probe = 'https://' + probe;
      }
      probe = probe.replace(/^(?:h?ttps?|tps?):\/\//i, 'https://');
      var u = new URL(probe);
      if (u.hostname) s = u.hostname;
    } catch (err) {
      s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
      if (s.indexOf('//') === 0) s = s.slice(2);
      s = s.split('/')[0].split('?')[0].split('#')[0];
    }
    if (s.indexOf('@') !== -1) s = s.split('@').pop() || '';
    s = String(s).toLowerCase();
    if (s.indexOf(':') !== -1 && s.indexOf(']') === -1) s = s.split(':')[0];
    s = s.replace(/^www\./i, '').replace(/\.$/, '');
    return s;
  }

  function isOpenableSite(host) {
    host = String(host || '').toLowerCase();
    if (!host || host.indexOf('.') === -1) return false;
    if (/\s/.test(host)) return false;
    if (!/^[a-z0-9.-]+$/.test(host)) return false;
    if (host.charAt(0) === '-' || host.slice(-1) === '-' || host.indexOf('..') !== -1) return false;
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return false;
    for (var i = 0; i < parts.length; i++) {
      var label = parts[i];
      if (!label || label.length > 63) return false;
      if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label)) return false;
    }
    return true;
  }

  function siteOpenUrl(host) {
    return 'https://' + host;
  }

  function listEligibleOpenHosts() {
    var out = [];
    var seen = {};
    linesOf(ta.value).forEach(function (line) {
      var host = normalizeSiteHost(line);
      if (!isOpenableSite(host) || seen[host]) return;
      seen[host] = true;
      out.push({ host: host, url: siteOpenUrl(host) });
    });
    return out;
  }

  function clearOpenBatchState() {
    openBatchState = null;
    syncOpenContinueButton();
  }

  function syncOpenContinueButton() {
    var cont = document.querySelector('[data-extract-open-continue]');
    if (!cont) return;
    if (!openBatchState || openBatchState.offset >= openBatchState.goal) {
      cont.hidden = true;
      cont.disabled = true;
      return;
    }
    var left = openBatchState.goal - openBatchState.offset;
    var next = Math.min(OPEN_BATCH_SIZE, left);
    cont.hidden = false;
    cont.disabled = false;
    cont.textContent = 'Open next ' + next;
    cont.title = 'Open the next ' + next + ' of '
      + openBatchState.goal + ' sites (' + openBatchState.offset + ' already opened)';
  }

  function syncOpenBulkButton() {
    var select = document.querySelector('[data-extract-open-count]');
    var btn = document.querySelector('[data-extract-open-bulk]');
    if (!btn) return;
    var choice = select ? (parseInt(select.value, 10) || 10) : 10;
    var eligible = listEligibleOpenHosts();
    var take = Math.min(choice, eligible.length);
    if (eligible.length === 0) {
      btn.disabled = true;
      btn.textContent = 'No sites to open';
      btn.title = 'No openable sites in this Sites list';
      clearOpenBatchState();
      return;
    }
    btn.disabled = false;
    if (take < choice) {
      btn.textContent = 'Open all ' + take;
      btn.title = 'Open all ' + take + ' openable site' + (take === 1 ? '' : 's')
        + ' in this Sites list'
        + (take > OPEN_BATCH_SIZE ? ' (in batches of ' + OPEN_BATCH_SIZE + ')' : '');
    } else {
      btn.textContent = 'Open first ' + choice;
      btn.title = 'Open the first ' + choice + ' sites in this Sites list in new tabs'
        + (choice > OPEN_BATCH_SIZE ? ' (batches of ' + OPEN_BATCH_SIZE + ')' : '');
    }
    if (openBatchState) {
      openBatchState.goal = Math.min(openBatchState.goal, eligible.length);
      openBatchState.items = eligible.slice(0, openBatchState.goal);
      if (openBatchState.offset > openBatchState.goal) {
        openBatchState.offset = openBatchState.goal;
      }
      if (openBatchState.offset >= openBatchState.goal || openBatchState.goal < 1) {
        clearOpenBatchState();
      } else {
        syncOpenContinueButton();
      }
    }
  }

  function openUrlBatch(urls) {
    var opened = 0;
    for (var i = 0; i < urls.length; i++) {
      var w = window.open(urls[i], '_blank');
      if (w) {
        try { w.opener = null; } catch (err) {}
        opened++;
      }
    }
    return opened;
  }

  function reportOpenBatch(opened, attempted, goal, offsetAfter, isContinue) {
    var remaining = Math.max(0, goal - offsetAfter);
    if (opened === 0) {
      setStatus(
        'Could not open tabs — allow popups for this site, then try again.',
        true
      );
      return;
    }
    if (opened < attempted) {
      setStatus(
        'Opened ' + opened + ' of ' + attempted
          + ' in this batch — allow popups, then use Open next.',
        true
      );
      return;
    }
    if (remaining > 0) {
      setStatus(
        'Opened ' + offsetAfter + ' of ' + goal
          + ' · click Open next ' + Math.min(OPEN_BATCH_SIZE, remaining) + ' to continue.'
      );
    } else if (goal <= OPEN_BATCH_SIZE && !isContinue) {
      setStatus(
        goal === attempted
          ? (attempted === 1 ? 'Opened 1 site in a new tab.' : ('Opened ' + attempted + ' sites in new tabs.'))
          : ('Opened ' + offsetAfter + ' sites in new tabs.')
      );
    } else {
      setStatus('Opened all ' + goal + ' sites in new tabs.');
    }
  }

  function startOrContinueOpen(fromContinue) {
    var select = document.querySelector('[data-extract-open-count]');
    var choice = select ? (parseInt(select.value, 10) || 10) : 10;
    var eligible = listEligibleOpenHosts();

    if (!fromContinue) {
      var take = Math.min(choice, eligible.length);
      if (take < 1) {
        setStatus('No sites to open in this Sites list.', true);
        clearOpenBatchState();
        syncOpenBulkButton();
        return;
      }
      openBatchState = {
        goal: take,
        offset: 0,
        items: eligible.slice(0, take)
      };
    }

    if (!openBatchState || openBatchState.offset >= openBatchState.goal) {
      clearOpenBatchState();
      syncOpenBulkButton();
      return;
    }

    var start = openBatchState.offset;
    var end = Math.min(start + OPEN_BATCH_SIZE, openBatchState.goal);
    var slice = (openBatchState.items || []).slice(start, end);
    var urls = slice.map(function (item) { return item.url; });
    var opened = openUrlBatch(urls);
    openBatchState.offset = end;
    reportOpenBatch(opened, slice.length, openBatchState.goal, openBatchState.offset, !!fromContinue);
    if (openBatchState.offset >= openBatchState.goal) {
      clearOpenBatchState();
    } else {
      syncOpenContinueButton();
    }
    syncOpenBulkButton();
  }

  var openCountSelect = document.querySelector('[data-extract-open-count]');
  var openBulkBtn = document.querySelector('[data-extract-open-bulk]');
  var openContinueBtn = document.querySelector('[data-extract-open-continue]');
  if (openCountSelect) {
    try {
      var saved = window.sessionStorage && sessionStorage.getItem(OPEN_COUNT_STORAGE_KEY);
      if (saved && openCountSelect.querySelector('option[value="' + saved + '"]')) {
        openCountSelect.value = saved;
      }
    } catch (err) {}
    openCountSelect.addEventListener('change', function () {
      clearOpenBatchState();
      try {
        if (window.sessionStorage) {
          sessionStorage.setItem(OPEN_COUNT_STORAGE_KEY, String(openCountSelect.value || '10'));
        }
      } catch (err2) {}
      syncOpenBulkButton();
    });
  }
  if (openBulkBtn) {
    openBulkBtn.addEventListener('click', function () {
      startOrContinueOpen(false);
    });
  }
  if (openContinueBtn) {
    openContinueBtn.addEventListener('click', function () {
      startOrContinueOpen(true);
    });
  }
  syncOpenBulkButton();
  syncOpenContinueButton();
})();
