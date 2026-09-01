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
  var keepOpenStatus = false;
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
    if (countLabel) {
      countLabel.textContent = n + ' site' + (n === 1 ? '' : 's');
    }
    if (copyBtn) copyBtn.disabled = n === 0;
    shell.setAttribute('data-live-count', String(n));
    syncOpenBulkButton();
    syncOpenContinueButton();
  }

  function applyRemoteList(data, statusMsg) {
    if (!data || data.domains == null) return;
    var savedRaw = Array.isArray(data.domains) ? data.domains.join('\n') : String(data.domains || '');
    applyingHistory = true;
    ta.value = savedRaw;
    lastSnapshot = normalizeText(savedRaw);
    lastSavedText = lastSnapshot;
    applyingHistory = false;
    var n = typeof data.site_count === 'number' ? data.site_count : linesOf(ta.value).length;
    updateCounts(n);
    if (data.writer_at) shell.setAttribute('data-writer-at', data.writer_at);
    shell.setAttribute('data-live-count', String(n));
    undoStack = [];
    redoStack = [];
    clearOpenBatchState();
    syncHistoryButtons();
    if (data.writer_name || data.writer_at) {
      setAutosaveLabel(lastWriterText(data.writer_name, data.writer_at) || 'Saved');
    }
    if (statusMsg) setStatus(statusMsg);
  }

  function liveUrl(action) {
    var url = String(postUrl || window.location.href);
    var join = url.indexOf('?') >= 0 ? '&' : '?';
    return url + join + 'ajax=1&action=' + encodeURIComponent(action);
  }

  function isDirty() {
    return normalizeText(ta.value) !== lastSavedText;
  }

  function pullRemoteList(statusMsg) {
    return fetch(liveUrl('sites_snapshot'), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || data.ok === false) return;
        // Re-check after the round-trip so a poll started while idle cannot
        // wipe a list the user started editing (or that autosave is writing).
        if (saveInFlight) return;
        if (isDirty()) {
          var n = typeof data.site_count === 'number' ? data.site_count : linesOf(ta.value).length;
          setStatus(
            'Shared list is now ' + n + ' site' + (n === 1 ? '' : 's')
              + '. Reload or undo your edit so you match your teammate.',
            true
          );
          return;
        }
        applyRemoteList(data, statusMsg);
      });
  }

  function pollSharedList() {
    if (document.hidden || saveInFlight) return;
    fetch(liveUrl('sites_live'), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || data.ok === false) return;
        if (saveInFlight) return;
        var remoteN = typeof data.site_count === 'number' ? data.site_count : -1;
        var remoteAt = String(data.writer_at || '');
        var localAt = String(shell.getAttribute('data-writer-at') || '');
        var localN = parseInt(String(shell.getAttribute('data-live-count') || ''), 10);
        if (isNaN(localN)) localN = linesOf(ta.value).length;
        if (remoteN === localN && remoteAt === localAt) return;
        if (isDirty()) {
          setStatus(
            'Shared list is now ' + remoteN + ' site' + (remoteN === 1 ? '' : 's')
              + '. Reload or undo your edit so you match your teammate.',
            true
          );
          return;
        }
        pullRemoteList(
          'Shared list updated · ' + remoteN + ' site' + (remoteN === 1 ? '' : 's')
            + (data.writer_name ? ' · ' + data.writer_name : '')
        );
      })
      .catch(function () { /* ignore */ });
  }

  window.setInterval(pollSharedList, 4000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) pollSharedList();
  });

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
    clearOpenBatchState();
    var current = normalizeText(ta.value);
    var prev = undoStack.pop();
    redoStack.push(current);
    setText(prev, prev.length, prev.length);
    setStatus('Undo');
  }

  function redo() {
    if (!redoStack.length) return;
    clearOpenBatchState();
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
        if (data.writer_at) shell.setAttribute('data-writer-at', data.writer_at);
        // Open next / Undo / typing can change the box while this save is in flight.
        // Do not write the older snapshot back over the current list.
        if (normalizeText(ta.value) !== text) {
          return;
        }
        if (data.domains != null) {
          var savedRaw = Array.isArray(data.domains) ? data.domains.join('\n') : String(data.domains || '');
          var saved = normalizeText(savedRaw);
          if (saved !== text) {
            applyingHistory = true;
            ta.value = savedRaw;
            lastSnapshot = saved;
            applyingHistory = false;
            setStatus('Autosaved — invalid lines were removed so the box matches the Sites list.');
          }
        }
        var n = typeof data.site_count === 'number' ? data.site_count : linesOf(ta.value).length;
        updateCounts(n);
        if (data.writer_name || data.writer_at) {
          setAutosaveLabel(lastWriterText(data.writer_name, data.writer_at) || 'Saved');
        } else {
          setAutosaveLabel('Saved');
        }
        if (keepOpenStatus) {
          keepOpenStatus = false;
          return;
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
          applyRemoteList(data, err.message || 'Reload to avoid overwriting.');
          return;
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

  // --- Open & remove first 10–50 from the Sites list (batches of 10) ---
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

  function removeHostsFromText(text, hosts) {
    var drop = {};
    (hosts || []).forEach(function (h) {
      var key = String(h || '').toLowerCase();
      if (key) drop[key] = true;
    });
    return linesOf(text).filter(function (line) {
      var host = normalizeSiteHost(line);
      return !host || !drop[host];
    }).join('\n');
  }

  function applyOpenRemove(openedItems) {
    var hosts = [];
    for (var i = 0; i < openedItems.length; i++) {
      hosts.push(openedItems[i].host);
    }
    var nextText = removeHostsFromText(ta.value, hosts);
    if (normalizeText(nextText) === normalizeText(ta.value)) return;
    undoStack.push(lastSnapshot);
    if (undoStack.length > MAX_UNDO) undoStack.shift();
    redoStack = [];
    setText(nextText);
  }

  function syncOpenContinueButton() {
    var cont = document.querySelector('[data-extract-open-continue]');
    if (!cont) return;
    if (openBatchState && openBatchState.remaining > 0) {
      var next = Math.min(OPEN_BATCH_SIZE, openBatchState.remaining, listEligibleOpenHosts().length);
      if (next >= 1) {
        cont.hidden = false;
        cont.disabled = false;
        cont.textContent = 'Open next ' + next;
        cont.title = 'Open & remove the next ' + next + ' of '
          + openBatchState.goal + ' sites (' + openBatchState.done
          + ' already opened and removed). Undo puts them back.';
        return;
      }
      openBatchState = null;
    }
    cont.hidden = true;
    cont.disabled = true;
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
      return;
    }
    btn.disabled = false;
    if (take < choice) {
      btn.textContent = 'Open & remove all ' + take;
      btn.title = 'Open all ' + take + ' openable site' + (take === 1 ? '' : 's')
        + ' in new tabs and remove them from this list'
        + (take > OPEN_BATCH_SIZE ? ' (in batches of ' + OPEN_BATCH_SIZE + ')' : '')
        + '. Undo puts them back.';
    } else {
      btn.textContent = 'Open & remove first ' + choice;
      btn.title = 'Open the first ' + choice + ' sites in new tabs and remove them from this list'
        + (choice > OPEN_BATCH_SIZE ? ' (batches of ' + OPEN_BATCH_SIZE + ')' : '')
        + '. Undo puts them back.';
    }
  }

  function openUrlBatch(items) {
    var opened = [];
    for (var i = 0; i < items.length; i++) {
      var w = window.open(items[i].url, '_blank');
      if (w) {
        try { w.opener = null; } catch (err) {}
        opened.push(items[i]);
      }
    }
    return opened;
  }

  function reportOpenBatch(opened, attempted, goal, done, remaining, isContinue) {
    if (opened > 0) keepOpenStatus = true;
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
          + ' in this batch and removed those from the list — allow popups, then use Open next.',
        true
      );
      return;
    }
    if (remaining > 0) {
      setStatus(
        'Opened ' + done + ' of ' + goal
          + ' and removed them · click Open next ' + Math.min(OPEN_BATCH_SIZE, remaining)
          + ' to continue. Undo puts them back.'
      );
    } else if (goal <= OPEN_BATCH_SIZE && !isContinue) {
      setStatus(
        attempted === 1
          ? 'Opened 1 site in a new tab and removed it from this list. Undo puts it back.'
          : ('Opened ' + attempted + ' sites in new tabs and removed them from this list. Undo puts them back.')
      );
    } else {
      setStatus('Opened all ' + goal + ' sites in new tabs and removed them from this list. Undo puts them back.');
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
        remaining: take,
        done: 0
      };
    }

    if (!openBatchState || openBatchState.remaining <= 0) {
      clearOpenBatchState();
      syncOpenBulkButton();
      return;
    }

    eligible = listEligibleOpenHosts();
    var sliceN = Math.min(OPEN_BATCH_SIZE, openBatchState.remaining, eligible.length);
    if (sliceN < 1) {
      setStatus('No more sites to open in this Sites list.', true);
      clearOpenBatchState();
      syncOpenBulkButton();
      return;
    }

    var slice = eligible.slice(0, sliceN);
    var openedItems = openUrlBatch(slice);
    var opened = openedItems.length;
    if (opened === 0) {
      reportOpenBatch(0, slice.length, openBatchState.goal, openBatchState.done, openBatchState.remaining, !!fromContinue);
      return;
    }

    openBatchState.done += opened;
    openBatchState.remaining = Math.max(0, openBatchState.goal - openBatchState.done);
    var reportGoal = openBatchState.goal;
    var reportDone = openBatchState.done;
    var reportRemaining = openBatchState.remaining;

    applyOpenRemove(openedItems);

    if (openBatchState) {
      var leftEligible = listEligibleOpenHosts().length;
      if (openBatchState.remaining > leftEligible) {
        openBatchState.remaining = leftEligible;
      }
      reportRemaining = openBatchState.remaining;
    } else {
      reportRemaining = 0;
    }

    reportOpenBatch(
      opened,
      slice.length,
      reportGoal,
      reportDone,
      reportRemaining,
      !!fromContinue
    );
    if (!openBatchState || openBatchState.remaining <= 0) {
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
