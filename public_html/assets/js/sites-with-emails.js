(function () {
  'use strict';

  var statusEl = document.getElementById('swe_status');
  var copyBtn = document.getElementById('swe_copy_emails');
  var totalLabel = document.getElementById('swe_total_label');
  var sentLabel = document.getElementById('swe_sent_label');
  var unsentLabel = document.getElementById('swe_unsent_label');
  var searchInput = document.getElementById('swe-row-search');
  var pushBtn = document.getElementById('swe-push-btn');
  var readyLabel = document.getElementById('swe_ready_label');
  var autosaveTimers = new WeakMap();

  function setStatus(msg, isError, isLoading) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('is-error', 'is-ok', 'is-loading');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
    statusEl.classList.toggle('is-ok', !isError && !isLoading);
    statusEl.classList.toggle('is-loading', !!isLoading && !isError);
  }

  function showProcessing(msg) {
    if (window.AppProcessing && typeof window.AppProcessing.show === 'function') {
      window.AppProcessing.show(msg);
    }
  }

  function hideProcessing() {
    if (window.AppProcessing && typeof window.AppProcessing.hide === 'function') {
      window.AppProcessing.hide();
    }
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

  function bindCopyEmailsButton(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-export-url');
      if (!url) return;
      var label = String(btn.getAttribute('data-copy-label') || 'all');
      var wasDisabled = btn.disabled;
      btn.disabled = true;
      var loading =
        label === 'not emailed' ? 'Loading not-emailed emails…'
          : label === 'emailed' ? 'Loading emailed emails…'
            : 'Loading emails…';
      setStatus(loading, false, true);
      showProcessing(loading);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/plain' } })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not load emails.');
          return res.text();
        })
        .then(function (text) {
          text = String(text || '').replace(/\r\n/g, '\n').trim();
          if (!text) {
            throw new Error(
              label === 'not emailed' ? 'No not-emailed emails to copy.'
                : label === 'emailed' ? 'No emailed emails to copy.'
                  : 'No emails to copy yet.'
            );
          }
          var lines = text.split('\n').filter(Boolean);
          return copyText(text).then(function () {
            var kind =
              label === 'not emailed' ? ' not-emailed'
                : label === 'emailed' ? ' emailed'
                  : '';
            setStatus(
              'Copied ' + lines.length + kind + ' email' + (lines.length === 1 ? '' : 's') + '.'
            );
          });
        })
        .catch(function (err) {
          setStatus(err.message || 'Copy failed.', true);
        })
        .then(function () {
          hideProcessing();
          btn.disabled = wasDisabled;
        });
    });
  }

  document.querySelectorAll('[data-swe-copy-emails]').forEach(bindCopyEmailsButton);
  // Legacy single-button id still present on some pages
  if (copyBtn && !copyBtn.hasAttribute('data-swe-copy-emails')) {
    bindCopyEmailsButton(copyBtn);
  }

  // Search: keep Site + Emails columns together (whole row)
  var matchRows = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-swe-row-search-meta]');
  var empty = document.querySelector('[data-swe-row-search-empty]');

  function clearHits() {
    document.querySelectorAll('[data-swe-row].sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function emailInputsIn(root) {
    if (!root) return [];
    return Array.prototype.slice.call(root.querySelectorAll('[data-swe-email]'));
  }

  function syncEmailClearButton(input) {
    if (window.EmailFieldClear && typeof window.EmailFieldClear.sync === 'function') {
      window.EmailFieldClear.sync(input);
      return;
    }
    if (!input) return;
    var wrap = input.closest('.email-field, .swe-email-field');
    if (!wrap) return;
    var btn = wrap.querySelector('[data-email-clear], [data-swe-email-clear]');
    var has = String(input.value || '').trim() !== '';
    wrap.classList.toggle('has-value', has);
    if (btn) btn.hidden = !has;
  }

  function parseEmailPaste(text) {
    var raw = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!raw) return [];
    var parts = raw.split(/[\n,;]+|\s+/);
    var out = [];
    var seen = {};
    for (var i = 0; i < parts.length; i++) {
      var e = String(parts[i] || '').trim().replace(/^<|>$/g, '');
      if (!e || seen[e.toLowerCase()]) continue;
      // Keep plausible emails; also keep other tokens so user can fix typos after paste
      if (e.indexOf('@') === -1 && out.length === 0 && parts.length === 1) {
        out.push(e);
        break;
      }
      if (e.indexOf('@') === -1) continue;
      seen[e.toLowerCase()] = true;
      out.push(e);
      if (out.length >= 4) break;
    }
    return out;
  }

  /**
   * Paste into any email box: if clipboard has multiple emails, fill all 4 fields.
   * Single value pastes into the focused box only.
   */
  function applyEmailPaste(targetInput, clipboardText) {
    var group = targetInput.closest('[data-swe-emails]') || targetInput.closest('form');
    var inputs = emailInputsIn(group);
    if (!inputs.length) return false;
    var emails = parseEmailPaste(clipboardText);
    if (!emails.length) return false;

    if (emails.length === 1) {
      targetInput.value = emails[0];
      syncEmailClearButton(targetInput);
      return true;
    }

    for (var i = 0; i < inputs.length; i++) {
      inputs[i].value = emails[i] || '';
      syncEmailClearButton(inputs[i]);
    }
    return true;
  }

  function countReadyRows() {
    var n = 0;
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
      if (row.getAttribute('data-has-email') === '1') n++;
    });
    return n;
  }

  function syncRowPushButton(row) {
    if (!row) return;
    var btn = row.querySelector('[data-swe-push-btn]');
    if (!btn) return;
    var hasEmail = row.getAttribute('data-has-email') === '1';
    btn.disabled = !hasEmail;
    btn.title = hasEmail ? 'Push this site to Admin' : 'Add at least one email first';
  }

  function syncPushButton(readyOverride) {
    var ready = typeof readyOverride === 'number' ? readyOverride : countReadyRows();
    if (readyLabel && typeof readyOverride !== 'number') {
      // Page-local count only — keep label in sync with visible rows when possible
      readyLabel.textContent = String(ready);
    } else if (readyLabel && typeof readyOverride === 'number') {
      readyLabel.textContent = String(ready);
    }
    if (!pushBtn) return;
    if (ready > 0) {
      pushBtn.disabled = false;
      pushBtn.setAttribute('data-server-ready', '1');
      pushBtn.title = 'Push every site on this country that has at least one email';
    } else if (pushBtn.getAttribute('data-server-ready') === '1' && typeof readyOverride !== 'number') {
      // Other pages may still have ready rows
      pushBtn.disabled = false;
    } else {
      pushBtn.disabled = true;
      pushBtn.removeAttribute('data-server-ready');
      pushBtn.title = 'Add at least one email on a site first';
    }
  }

  function saveFormOf(el) {
    if (!el) return null;
    if (el.form && el.form.matches && el.form.matches('[data-swe-save]')) return el.form;
    return el.closest ? el.closest('[data-swe-save]') : null;
  }

  function refreshRowSearchIndex(row) {
    if (!row) return;
    var domainEl = row.querySelector('.swe-domain, [name="domain"]');
    var domain = String((domainEl && domainEl.value) || '').trim().toLowerCase();
    var lang = String((row.querySelector('.swe-td-lang .swe-cell-text') || {}).textContent || '')
      .trim().toLowerCase();
    var emails = Array.prototype.map.call(row.querySelectorAll('[data-swe-email]'), function (el) {
      return String(el.value || '').trim().toLowerCase();
    });
    var hasEmail = emails.some(function (e) { return e !== ''; });
    row.setAttribute('data-search', [domain, lang].concat(emails).filter(Boolean).join(' '));
    row.setAttribute('data-has-email', hasEmail ? '1' : '0');
    if (hasEmail) {
      clearRowOpened(row);
    } else {
      applyOpenedClass(row);
    }
    syncRowPushButton(row);
    syncPushButton();
  }

  function filterRows() {
    if (!searchInput) return;
    var q = String(searchInput.value || '').trim().toLowerCase();
    matchRows = [];
    clearHits();
    var shown = 0;
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
      var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
      row.hidden = !hit;
      if (hit) {
        shown++;
        if (q) matchRows.push(row);
      }
    });
    if (empty) empty.hidden = !(q && shown === 0);
    if (meta) {
      if (!q) {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
        return;
      }
      meta.hidden = false;
      meta.textContent = !matchRows.length
        ? '0 · Enter = next · Ctrl+Enter = all pages'
        : (matchIndex >= 0
          ? (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails'
          : matchRows.length + ' · site + emails · Enter = next');
    }
    document.querySelectorAll('[data-swe-q]').forEach(function (el) {
      el.value = String(searchInput.value || '');
    });
    if (typeof syncOpenBulkButton === 'function') syncOpenBulkButton();
  }

  function jump(dir) {
    if (!searchInput || !String(searchInput.value || '').trim()) return;
    filterRows();
    if (!matchRows.length) return;
    matchIndex = matchIndex < 0
      ? (dir > 0 ? 0 : matchRows.length - 1)
      : (matchIndex + dir + matchRows.length) % matchRows.length;
    var row = matchRows[matchIndex];
    clearHits();
    row.classList.add('sheet-search-hit');
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (meta) {
      meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · site + emails';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      matchIndex = -1;
      filterRows();
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) {
        var url = new URL(window.location.href);
        var q = String(searchInput.value || '').trim();
        if (q) url.searchParams.set('q', q);
        else url.searchParams.delete('q');
        url.searchParams.delete('p');
        window.location.href = url.toString();
        return;
      }
      jump(e.shiftKey ? -1 : 1);
    });
    filterRows();
  }

  if (pushBtn && !pushBtn.disabled) {
    pushBtn.setAttribute('data-server-ready', '1');
  }

  function saveRowForm(form, opts) {
    opts = opts || {};
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    // Manual "Add site row" form still does a normal submit
    if (!form.matches('[data-swe-save]')) {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    if (searchInput) body.set('q', String(searchInput.value || ''));
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Save failed');
          }
          return data;
        });
      })
      .then(function (data) {
        form.removeAttribute('data-swe-dirty');
        var row = form.closest('[data-swe-row]');
        refreshRowSearchIndex(row);
        if (!opts.quiet) {
          setStatus('Saved.');
        } else {
          setStatus('Autosaved.');
        }
        filterRows();
        syncPushButton();
        return data;
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not save.', true);
        return null;
      })
      .then(function (data) {
        form.removeAttribute('data-busy');
        return data;
      });
  }

  function scheduleAutosave(form) {
    if (!form || !form.matches('[data-swe-save]')) return;
    form.setAttribute('data-swe-dirty', '1');
    var prev = autosaveTimers.get(form);
    if (prev) window.clearTimeout(prev);
    var timer = window.setTimeout(function () {
      autosaveTimers.delete(form);
      saveRowForm(form, { quiet: true });
    }, 400);
    autosaveTimers.set(form, timer);
  }

  /** Flush every row that still has pending edits so Push sees all 4 emails. */
  function flushPendingAutosaves() {
    var forms = document.querySelectorAll('form[data-swe-save]');
    var jobs = [];
    forms.forEach(function (form) {
      var t = autosaveTimers.get(form);
      if (t) {
        window.clearTimeout(t);
        autosaveTimers.delete(form);
      }
      if (t || form.getAttribute('data-swe-dirty') === '1' || form.getAttribute('data-busy') === '1') {
        jobs.push(saveRowForm(form, { quiet: true }));
      }
    });
    return Promise.all(jobs);
  }

  // × clear is handled by email-field-clear.js; its input event triggers autosave below.

  // Paste: fill up to 4 email boxes at once (no need to click each box)
  document.addEventListener('paste', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email]')) return;
    var clip = (e.clipboardData || window.clipboardData);
    var text = clip ? clip.getData('text') : '';
    if (!text) return;
    var multi = parseEmailPaste(text);
    if (multi.length <= 1) return; // email-field-clear.js syncs ×; let normal paste through
    e.preventDefault();
    applyEmailPaste(input, text);
    var form = saveFormOf(input);
    var row = input.closest('[data-swe-row]');
    refreshRowSearchIndex(row);
    filterRows();
    setStatus('Pasted ' + Math.min(multi.length, 4) + ' email' + (multi.length === 1 ? '' : 's') + '.');
    // Save immediately — do not wait for debounce, or Push can miss emails 2–4.
    if (form) {
      var prev = autosaveTimers.get(form);
      if (prev) {
        window.clearTimeout(prev);
        autosaveTimers.delete(form);
      }
      form.setAttribute('data-swe-dirty', '1');
      saveRowForm(form, { quiet: true });
    }
  });

  // Autosave on every email / domain edit (also runs after × clear)
  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = saveFormOf(input);
    if (!form) return;
    refreshRowSearchIndex(input.closest('[data-swe-row]'));
    if (input.matches('.swe-domain')) {
      refreshOpenLink(input.closest('[data-swe-row]'));
    }
    filterRows();
    scheduleAutosave(form);
    if (input.matches('[data-swe-email]') && String(input.value || '').trim() === '') {
      setStatus('Email cleared.');
    }
  });

  document.addEventListener('blur', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('[data-swe-email], .swe-domain')) return;
    var form = saveFormOf(input);
    if (!form) return;
    var prev = autosaveTimers.get(form);
    if (prev) {
      window.clearTimeout(prev);
      autosaveTimers.delete(form);
    }
    saveRowForm(form, { quiet: true });
  }, true);

  function postAjaxForm(form, failLabel) {
    if (!form || form.getAttribute('data-busy') === '1') {
      return Promise.resolve(null);
    }
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    if (searchInput) body.set('q', String(searchInput.value || ''));
    form.setAttribute('data-busy', '1');
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || failLabel);
          }
          return { data: data, siteId: body.get('site_id') };
        });
      })
      .catch(function (err) {
        setStatus(err.message || failLabel, true);
        form.removeAttribute('data-busy');
        return null;
      });
  }

  function updateSentStats(data) {
    if (!data) return;
    if (typeof data.sent === 'number' && sentLabel) {
      sentLabel.textContent = String(data.sent);
    }
    if (typeof data.unsent === 'number' && unsentLabel) {
      unsentLabel.textContent = String(data.unsent);
    }
  }

  /** Update one Admin row's emailed UI without reloading (keeps scroll position). */
  function setRowEmailedState(row, emailed) {
    if (!row) return;
    var sent = !!emailed;
    row.setAttribute('data-email-sent', sent ? '1' : '0');
    row.classList.toggle('swe-row-emailed', sent);

    var status = row.querySelector('[data-swe-status]');
    if (status) {
      status.classList.toggle('is-emailed', sent);
      status.classList.toggle('is-open', !sent);
      status.classList.remove('is-ready', 'is-archive');
      status.textContent = sent ? 'Emailed' : 'Not emailed';
    }

    var markBtn = row.querySelector('button[form^="swe-mark-"]');
    if (markBtn) {
      markBtn.textContent = sent ? 'Clear emailed' : 'Mark emailed';
      markBtn.title = sent
        ? 'Clear emailed mark on this site only'
        : 'Mark this site as emailed';
      markBtn.classList.toggle('secondary', sent);
    }

    var markForm = document.getElementById('swe-mark-' + row.getAttribute('data-site-id'));
    if (markForm) {
      var sentInput = markForm.querySelector('[name="email_sent"]');
      if (sentInput) sentInput.value = sent ? '0' : '1';
    }
  }

  function applyEmailedUpTo(siteId, emailed) {
    var maxId = parseInt(siteId, 10) || 0;
    document.querySelectorAll('[data-swe-row][data-site-id]').forEach(function (row) {
      var id = parseInt(row.getAttribute('data-site-id') || '0', 10);
      if (id > 0 && id <= maxId) {
        setRowEmailedState(row, emailed);
      }
    });
  }

  // Push all: flush every pending autosave first (otherwise only email1 may be on the server)
  var pushAllForm = document.getElementById('swe-push-form');
  if (pushAllForm) {
    pushAllForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = pushAllForm.getAttribute('data-confirm-push-all')
        || 'Push all sites with emails to Admin? Those rows will leave the Team working copy.';
      if (readyLabel) {
        msg = msg.replace(/ALL \d+ site\(s\)/, 'ALL ' + String(readyLabel.textContent || '').trim() + ' site(s)');
      }
      if (!window.confirm(msg)) return;
      var overwriteField = document.getElementById('swe-push-confirm-overwrite');
      if (overwriteField) {
        var conflicts = parseInt(pushAllForm.getAttribute('data-conflict-count') || '0', 10) || 0;
        overwriteField.value = conflicts > 0 ? '1' : '0';
      }
      var btn = document.getElementById('swe-push-btn');
      if (btn) btn.disabled = true;
      setStatus('Saving emails before push…', false, true);
      showProcessing('Pushing sites to Admin…');
      flushPendingAutosaves().then(function () {
        // Native submit skips this listener — avoids a second confirm.
        // Overlay stays up through the full-page navigation.
        HTMLFormElement.prototype.submit.call(pushAllForm);
      }).catch(function () {
        hideProcessing();
        if (btn) btn.disabled = false;
        setStatus('Could not save emails before push.', true);
      });
    });
  }

  // Push one / Remove row (ajax); row forms no longer have a Save submit
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches) return;

    if (form.matches('[data-swe-save]')) {
      // No Save button — keep Enter-in-field from full page reload; autosave instead
      e.preventDefault();
      var prev = autosaveTimers.get(form);
      if (prev) {
        window.clearTimeout(prev);
        autosaveTimers.delete(form);
      }
      saveRowForm(form, { quiet: true });
      return;
    }

    if (form.matches('[data-swe-push]')) {
      e.preventDefault();
      // User already confirmed via the Push button onclick (includes overwrite warning when needed).
      var overwriteInput = form.querySelector('[data-swe-confirm-overwrite], [name="confirm_overwrite"]');
      if (overwriteInput && form.getAttribute('data-admin-conflict') === '1') {
        overwriteInput.value = '1';
      }
      // Flush pending autosave so emails are on the server before push
      var row = document.querySelector('[data-swe-row][data-site-id="' + form.querySelector('[name="site_id"]').value + '"]');
      var saveForm = row && row.querySelector('[data-swe-save]');
      var flush = Promise.resolve(null);
      if (saveForm) {
        var t = autosaveTimers.get(saveForm);
        if (t) {
          window.clearTimeout(t);
          autosaveTimers.delete(saveForm);
        }
        flush = saveRowForm(saveForm, { quiet: true });
      }
      setStatus('Pushing site…', false, true);
      showProcessing('Pushing site to Admin…');
      flush.then(function () {
        return postAjaxForm(form, 'Push failed');
      }).then(function (result) {
        if (!result) {
          hideProcessing();
          return;
        }
        var data = result.data;
        var id = result.siteId;
        var gone = document.querySelector('[data-swe-row][data-site-id="' + id + '"]');
        if (gone) gone.remove();
        if (typeof data.site_count === 'number' && totalLabel) {
          totalLabel.textContent = String(data.site_count);
        }
        if (typeof data.ready_count === 'number') {
          syncPushButton(data.ready_count);
        } else {
          syncPushButton();
        }
        setStatus('Pushed ' + (data.domain || 'site') + ' to Admin · cleared from Team.');
        filterRows();
        if (data.redirect) {
          showProcessing('Loading…');
          window.setTimeout(function () { window.location.href = data.redirect; }, 250);
        } else {
          hideProcessing();
          form.removeAttribute('data-busy');
        }
      });
      return;
    }

    if (form.matches('[data-swe-clear-all-emailed]')) {
      e.preventDefault();
      setStatus('Clearing all emailed marks…', false, true);
      postAjaxForm(form, 'Could not clear emailed marks').then(function (result) {
        if (!result) return;
        var data = result.data;
        document.querySelectorAll('[data-swe-row][data-site-id]').forEach(function (row) {
          setRowEmailedState(row, false);
        });
        updateSentStats(data);
        setStatus(
          'Cleared all emailed marks'
          + (typeof data.cleared === 'number' ? ' · ' + data.cleared + ' sites' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
        // Hide the clear-all control when nothing is emailed anymore.
        if (typeof data.sent === 'number' && data.sent < 1) {
          form.hidden = true;
        }
      });
      return;
    }

    if (form.matches('[data-swe-mark]')) {
      e.preventDefault();
      var markSent = String((form.querySelector('[name="email_sent"]') || {}).value || '') === '1';
      setStatus(markSent ? 'Marking emailed…' : 'Clearing emailed mark…', false, true);
      postAjaxForm(form, 'Could not update emailed mark').then(function (result) {
        if (!result) return;
        var data = result.data;
        var id = result.siteId;
        var rowEl = document.querySelector('[data-swe-row][data-site-id="' + id + '"]');
        var nextSent = typeof data.email_sent === 'boolean' ? data.email_sent : markSent;
        setRowEmailedState(rowEl, nextSent);
        updateSentStats(data);
        setStatus(
          (nextSent ? 'Marked emailed: ' : 'Cleared emailed mark: ')
          + (data.domain || 'site')
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (form.matches('[data-swe-mark-upto]')) {
      e.preventDefault();
      setStatus('Marking emailed up to here…', false, true);
      postAjaxForm(form, 'Could not mark checkpoint').then(function (result) {
        if (!result) return;
        var data = result.data;
        applyEmailedUpTo(result.siteId, true);
        updateSentStats(data);
        setStatus(
          'Marked emailed up to ' + (data.domain || 'site')
          + (typeof data.marked === 'number' ? ' · ' + data.marked + ' newly marked' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (form.matches('[data-swe-clear-upto]')) {
      e.preventDefault();
      setStatus('Clearing emailed up to here…', false, true);
      postAjaxForm(form, 'Could not clear checkpoint').then(function (result) {
        if (!result) return;
        var data = result.data;
        applyEmailedUpTo(result.siteId, false);
        updateSentStats(data);
        setStatus(
          'Cleared emailed up to ' + (data.domain || 'site')
          + (typeof data.cleared === 'number' ? ' · ' + data.cleared + ' cleared' : '')
          + '.'
        );
        form.removeAttribute('data-busy');
      });
      return;
    }

    if (!form.matches('[data-swe-remove]')) return;
    e.preventDefault();
    setStatus('Removing site…', false, true);
    showProcessing('Removing site…');
    postAjaxForm(form, 'Remove failed').then(function (result) {
      if (!result) {
        hideProcessing();
        return;
      }
      var data = result.data;
      var id = result.siteId;
      var rowEl = document.querySelector('[data-swe-row][data-site-id="' + id + '"]');
      if (rowEl) rowEl.remove();
      if (typeof data.site_count === 'number' && totalLabel) {
        totalLabel.textContent = String(data.site_count);
      }
      setStatus('Removed complete row for ' + (data.domain || 'site') + '.');
      filterRows();
      syncPushButton();
      if (data.redirect) {
        showProcessing('Loading…');
        window.setTimeout(function () { window.location.href = data.redirect; }, 250);
      } else {
        hideProcessing();
        form.removeAttribute('data-busy');
      }
    });
  });

  // --- Open site(s) in new tabs (row Open + Open first 10–50) ---
  // Team: highlight opened rows until any email is entered.
  var sweTable = document.getElementById('swe-table');
  var openTrackEnabled = !!(sweTable && sweTable.getAttribute('data-swe-open-track') === '1');
  var OPENED_STORAGE_PREFIX = 'swe-opened:';

  function openedStorageKey() {
    var country = (sweTable && sweTable.getAttribute('data-swe-country')) || '';
    return OPENED_STORAGE_PREFIX + String(country || 'sheet');
  }

  function readOpenedIds() {
    if (!openTrackEnabled || !window.sessionStorage) return {};
    try {
      var raw = sessionStorage.getItem(openedStorageKey());
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (err) {
      return {};
    }
  }

  function writeOpenedIds(map) {
    if (!openTrackEnabled || !window.sessionStorage) return;
    try {
      sessionStorage.setItem(openedStorageKey(), JSON.stringify(map || {}));
    } catch (err) { /* ignore quota */ }
  }

  function applyOpenedClass(row) {
    if (!openTrackEnabled || !row) return;
    if (row.getAttribute('data-has-email') === '1') {
      row.classList.remove('swe-row-opened');
      return;
    }
    var id = String(row.getAttribute('data-site-id') || '');
    var map = readOpenedIds();
    row.classList.toggle('swe-row-opened', !!(id && map[id]));
  }

  function markRowOpened(row) {
    if (!openTrackEnabled || !row) return;
    if (row.getAttribute('data-has-email') === '1') return;
    var id = String(row.getAttribute('data-site-id') || '');
    if (!id) return;
    var map = readOpenedIds();
    map[id] = 1;
    writeOpenedIds(map);
    row.classList.add('swe-row-opened');
  }

  function clearRowOpened(row) {
    if (!row) return;
    var id = String(row.getAttribute('data-site-id') || '');
    row.classList.remove('swe-row-opened');
    if (!openTrackEnabled || !id) return;
    var map = readOpenedIds();
    if (map[id]) {
      delete map[id];
      writeOpenedIds(map);
    }
  }

  function syncAllOpenedHighlights() {
    if (!openTrackEnabled) return;
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
      if (row.getAttribute('data-has-email') === '1') {
        clearRowOpened(row);
      } else {
        applyOpenedClass(row);
      }
    });
  }

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

  function refreshOpenLink(row) {
    if (!row) return;
    var domainEl = row.querySelector('.swe-domain, [data-swe-domain]');
    var link = row.querySelector('[data-swe-open-site]');
    var cell = row.querySelector('.swe-site-cell');
    if (!domainEl || !link) return;
    var host = normalizeSiteHost(domainEl.value);
    var ok = isOpenableSite(host);
    if (cell) cell.classList.toggle('is-invalid-site', !ok);
    if (ok) {
      link.href = siteOpenUrl(host);
      link.removeAttribute('aria-disabled');
      link.classList.remove('is-disabled');
      link.removeAttribute('tabindex');
      link.title = 'Open ' + host + ' in a new tab';
      link.setAttribute('aria-label', 'Open ' + host + ' in a new tab');
    } else {
      link.href = '#';
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-disabled');
      link.setAttribute('tabindex', '-1');
      link.title = 'Fix the site name (needs a valid domain) before opening';
      link.setAttribute('aria-label', 'Site name invalid — cannot open');
    }
  }

  function listEligibleOpenRows() {
    var out = [];
    document.querySelectorAll('[data-swe-row]').forEach(function (row) {
      if (row.hidden) return;
      var domainEl = row.querySelector('.swe-domain, [data-swe-domain]');
      if (!domainEl) return;
      var host = normalizeSiteHost(domainEl.value);
      if (!isOpenableSite(host)) return;
      out.push({ row: row, host: host, url: siteOpenUrl(host) });
    });
    return out;
  }

  /** Open large goals in batches of 10 to reduce popup-blocker friction. */
  var OPEN_BATCH_SIZE = 10;
  var openBatchState = null; // { goal: number, offset: number, items: {row,url,host}[] }
  var OPEN_COUNT_STORAGE_KEY = 'swe-open-count';

  function clearOpenBatchState() {
    openBatchState = null;
    syncOpenContinueButton();
  }

  function syncOpenContinueButton() {
    var cont = document.querySelector('[data-swe-open-continue]');
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
    var select = document.querySelector('[data-swe-open-count]');
    var btn = document.querySelector('[data-swe-open-bulk]');
    if (!btn) return;
    var choice = select ? (parseInt(select.value, 10) || 10) : 10;
    var eligible = listEligibleOpenRows();
    var take = Math.min(choice, eligible.length);
    if (eligible.length === 0) {
      btn.disabled = true;
      btn.textContent = 'No sites to open';
      btn.title = 'No openable sites on this page';
      clearOpenBatchState();
      return;
    }
    btn.disabled = false;
    if (take < choice) {
      btn.textContent = 'Open all ' + take;
      btn.title = 'Open all ' + take + ' openable site' + (take === 1 ? '' : 's')
        + ' on this page'
        + (take > OPEN_BATCH_SIZE ? ' (in batches of ' + OPEN_BATCH_SIZE + ')' : '');
    } else {
      btn.textContent = 'Open first ' + choice;
      btn.title = 'Open the first ' + choice + ' sites on this page in new tabs'
        + (choice > OPEN_BATCH_SIZE ? ' (batches of ' + OPEN_BATCH_SIZE + ')' : '');
    }
    // Keep an in-progress batch aligned with the current visible eligible list.
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
    var select = document.querySelector('[data-swe-open-count]');
    var choice = select ? (parseInt(select.value, 10) || 10) : 10;
    var eligible = listEligibleOpenRows();

    if (!fromContinue) {
      var take = Math.min(choice, eligible.length);
      if (take < 1) {
        setStatus('No sites to open on this page.', true);
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
    slice.forEach(function (item) {
      if (item && item.row) markRowOpened(item.row);
    });
    openBatchState.offset = end;
    reportOpenBatch(opened, slice.length, openBatchState.goal, openBatchState.offset, !!fromContinue);
    if (openBatchState.offset >= openBatchState.goal) {
      clearOpenBatchState();
    } else {
      syncOpenContinueButton();
    }
    syncOpenBulkButton();
  }

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest ? e.target.closest('[data-swe-open-site]') : null;
    if (!link) return;
    if (link.getAttribute('aria-disabled') === 'true' || link.classList.contains('is-disabled')) {
      e.preventDefault();
      return;
    }
    var row = link.closest('[data-swe-row]');
    if (row) markRowOpened(row);
  });

  var openCountSelect = document.querySelector('[data-swe-open-count]');
  var openBulkBtn = document.querySelector('[data-swe-open-bulk]');
  var openContinueBtn = document.querySelector('[data-swe-open-continue]');
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

  document.querySelectorAll('[data-swe-row]').forEach(refreshOpenLink);
  syncOpenBulkButton();
  syncOpenContinueButton();
  syncAllOpenedHighlights();
})();
