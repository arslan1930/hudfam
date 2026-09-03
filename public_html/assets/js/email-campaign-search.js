/**
 * Communication Team · Campaign search
 * Copy emails first; remove-from-sheet stays behind confirm.
 */
(function () {
  'use strict';

  var MIN_Q = 2;

  function copyText(text) {
    var value = String(text || '');
    if (!value) {
      return Promise.reject(new Error('Nothing to copy'));
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(value);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = value;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        if (document.execCommand('copy')) {
          resolve();
        } else {
          reject(new Error('Copy failed'));
        }
      } catch (err) {
        reject(err);
      } finally {
        ta.remove();
      }
    });
  }

  function initCard(root) {
    var input = root.querySelector('[data-camp-q]');
    var list = root.querySelector('[data-camp-suggest]');
    var statusEl = root.querySelector('[data-camp-status]');
    var selectedBox = root.querySelector('[data-camp-selected]');
    var selDomain = root.querySelector('[data-camp-sel-domain]');
    var selCountry = root.querySelector('[data-camp-sel-country]');
    var selEmails = root.querySelector('[data-camp-sel-emails]');
    var selSent = root.querySelector('[data-camp-sel-sent]');
    var noEmails = root.querySelector('[data-camp-no-emails]');
    var emailPick = root.querySelector('[data-camp-email-pick]');
    var emailSelect = root.querySelector('[data-camp-email-select]');
    var applyBtn = root.querySelector('[data-camp-apply]');
    var clearBtn = root.querySelector('[data-camp-clear]');
    var draftsLink = root.querySelector('[data-camp-open-drafts]');
    var copyBtn = root.querySelector('[data-camp-copy-emails]');
    var markBtn = root.querySelector('[data-camp-mark-sent]');
    var modeInputs = root.querySelectorAll('[data-camp-mode]');

    var sheetId = root.getAttribute('data-sheet-id') || '';
    var sheetName = root.getAttribute('data-sheet-name') || 'sheet';
    var suggestUrl = root.getAttribute('data-suggest-url') || '';
    var postUrl = root.getAttribute('data-post-url') || window.location.href;
    var draftsBase = root.getAttribute('data-drafts-url') || 'index.php?page=team_email_campaigns_drafts';
    var projectId = root.getAttribute('data-project-id') || '';

    var suggestions = [];
    var activeIndex = -1;
    var selected = null;
    var timer = null;
    var abortCtrl = null;
    var listPorted = false;

    function csrfToken() {
      var field = root.querySelector('input[name="_csrf"]');
      if (field && field.value) return field.value;
      var meta = document.querySelector('meta[name="csrf-token"]');
      return meta ? String(meta.getAttribute('content') || '') : '';
    }

    function setStatus(msg, isError) {
      if (!statusEl) return;
      if (!msg) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        statusEl.classList.remove('is-ok');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.classList.toggle('is-error', !!isError);
      statusEl.classList.toggle('is-ok', !isError);
    }

    function currentMode() {
      var checked = root.querySelector('[data-camp-mode]:checked');
      return checked ? checked.value : 'row';
    }

    function placeSuggest() {
      if (!list || list.hidden || !input) return;
      var r = input.getBoundingClientRect();
      list.style.position = 'fixed';
      list.style.left = Math.max(8, r.left) + 'px';
      list.style.top = (r.bottom + 4) + 'px';
      list.style.width = Math.max(180, r.width) + 'px';
      list.style.right = 'auto';
      list.style.zIndex = '80';
    }

    function hideSuggest() {
      if (!list) return;
      list.hidden = true;
      list.innerHTML = '';
      activeIndex = -1;
    }

    function syncEmailPick() {
      var mode = currentMode();
      var emails = (selected && selected.emails) || [];
      if (emailPick) {
        emailPick.hidden = !(mode === 'email' && emails.length > 0);
      }
      if (emailSelect && mode === 'email') {
        emailSelect.innerHTML = '';
        emails.forEach(function (email) {
          var opt = document.createElement('option');
          opt.value = email;
          opt.textContent = email;
          emailSelect.appendChild(opt);
        });
        if (selected && selected.focusEmail) {
          emailSelect.value = selected.focusEmail;
        }
      }
    }

    function syncMarkBtn() {
      if (!markBtn) return;
      if (!selected) {
        markBtn.hidden = true;
        return;
      }
      markBtn.hidden = false;
      markBtn.textContent = selected.emailSent ? 'Undo emailed' : 'Mark emailed';
    }

    function renderSuggest() {
      if (!list) return;
      list.innerHTML = '';
      if (!suggestions.length) {
        hideSuggest();
        return;
      }
      if (!listPorted && list.parentNode !== document.body) {
        document.body.appendChild(list);
        listPorted = true;
      }
      suggestions.forEach(function (item, idx) {
        var li = document.createElement('li');
        li.className = 'swe-admin-delete-item' + (idx === activeIndex ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('data-index', String(idx));

        var main = document.createElement('div');
        main.className = 'swe-admin-delete-item-main';
        main.textContent = item.domain;

        var meta = document.createElement('div');
        meta.className = 'swe-admin-delete-item-meta';
        var emails = (item.emails || []).join(', ') || '(no emails)';
        var bits = [emails];
        if (item.country) bits.push(item.country);
        if (item.project_name && item.project_name !== item.country) bits.push(item.project_name);
        if (item.email_sent) bits.push('emailed');
        meta.textContent = bits.join(' · ');

        var tag = document.createElement('span');
        tag.className = 'swe-admin-delete-match';
        tag.textContent = item.email_sent
          ? 'emailed'
          : (item.match_type === 'email' ? 'email'
            : (item.match_type === 'country' ? 'country' : 'site'));
        if (item.email_sent) {
          tag.classList.add('is-emailed');
        }

        li.appendChild(main);
        li.appendChild(meta);
        li.appendChild(tag);
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectSuggestion(idx);
        });
        list.appendChild(li);
      });
      list.hidden = false;
      placeSuggest();
    }

    function syncDraftsLink() {
      if (!draftsLink) return;
      if (!selected || !selected.domain) {
        draftsLink.hidden = true;
        draftsLink.setAttribute('href', '#');
        return;
      }
      var url = draftsBase;
      var pid = selected.projectId || projectId;
      if (pid && String(pid) !== '0' && url.indexOf('project=') === -1) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'project=' + encodeURIComponent(pid);
      }
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'domain=' + encodeURIComponent(selected.domain);
      if (selected.country) {
        url += '&country=' + encodeURIComponent(selected.country);
      }
      if (selected.language) {
        url += '&language=' + encodeURIComponent(selected.language);
      }
      draftsLink.href = url;
      draftsLink.hidden = false;
    }

    function renderSelected() {
      if (!selectedBox || !selected) {
        if (selectedBox) selectedBox.hidden = true;
        syncDraftsLink();
        syncMarkBtn();
        return;
      }
      selectedBox.hidden = false;
      if (selDomain) selDomain.textContent = selected.domain || '';
      if (selCountry) {
        var bits = [];
        if (selected.projectName) bits.push(selected.projectName);
        if (selected.country && selected.country !== selected.projectName) bits.push(selected.country);
        selCountry.textContent = bits.join(' · ');
      }
      if (selSent) {
        selSent.hidden = !selected.emailSent;
      }
      if (selEmails) {
        selEmails.innerHTML = '';
        var emails = selected.emails || [];
        if (noEmails) noEmails.hidden = emails.length > 0;
        emails.forEach(function (email) {
          var li = document.createElement('li');
          li.className = 'swe-admin-delete-email-row';
          var span = document.createElement('span');
          span.textContent = email;
          var one = document.createElement('button');
          one.type = 'button';
          one.className = 'btn secondary small';
          one.setAttribute('data-camp-copy-one', email);
          one.textContent = 'Copy';
          one.addEventListener('click', function () {
            copyText(email).then(function () {
              setStatus('Copied ' + email + ' — paste into your email client.', false);
            }).catch(function () {
              setStatus('Could not copy ' + email + '.', true);
            });
          });
          li.appendChild(span);
          li.appendChild(one);
          selEmails.appendChild(li);
        });
      }
      if (selected.matchType === 'email' && (selected.emails || []).length) {
        modeInputs.forEach(function (el) {
          if (el.value === 'email') el.checked = true;
        });
      } else {
        modeInputs.forEach(function (el) {
          if (el.value === 'row') el.checked = true;
        });
      }
      syncEmailPick();
      syncDraftsLink();
      syncMarkBtn();
    }

    function selectSuggestion(idx) {
      var item = suggestions[idx];
      if (!item) return;
      selected = {
        id: item.id,
        sheetId: item.sheet_id || sheetId,
        domain: item.domain,
        country: item.country,
        language: item.language || '',
        projectId: item.project_id || projectId,
        projectName: item.project_name || sheetName || '',
        emails: (item.emails || []).slice(),
        matchType: item.match_type || 'domain',
        focusEmail: item.match_type === 'email' ? item.matched_value : null,
        emailSent: !!item.email_sent
      };
      if (input) input.value = item.domain;
      hideSuggest();
      renderSelected();
      setStatus(
        'Selected ' + item.domain + ' · ' +
        ((item.emails || []).join(', ') || 'no emails') +
        (item.email_sent ? ' · already emailed' : '') +
        '. Copy emails, then Open drafts for site.'
      );
    }

    function fetchSuggest(q) {
      if (abortCtrl) {
        try { abortCtrl.abort(); } catch (e) {}
      }
      if (!suggestUrl) {
        suggestions = [];
        hideSuggest();
        return;
      }
      if (q.length < MIN_Q) {
        suggestions = [];
        hideSuggest();
        if (q.length) {
          setStatus('Type ' + MIN_Q + '+ characters to search.');
        } else if (!selected) {
          setStatus('');
        }
        return;
      }
      abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
      fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: abortCtrl ? abortCtrl.signal : undefined
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          suggestions = (data && data.suggestions) || [];
          activeIndex = suggestions.length ? 0 : -1;
          renderSuggest();
          if (!suggestions.length && q.length >= MIN_Q) {
            setStatus('No matches in “' + sheetName + '”.', true);
          } else if (suggestions.length) {
            setStatus(
              suggestions.length + ' result' + (suggestions.length === 1 ? '' : 's') +
              ' · site + email · Enter to select'
            );
          }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          suggestions = [];
          hideSuggest();
        });
    }

    function postAction(body) {
      var payload = body || {};
      var tok = csrfToken();
      if (tok && payload._csrf == null) payload._csrf = tok;
      return fetch(postUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          Accept: 'application/json'
        },
        body: new URLSearchParams(payload).toString()
      }).then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Request failed');
          }
          return data;
        });
      });
    }

    function copySelectedEmails() {
      if (!selected) return;
      var emails = selected.emails || [];
      if (!emails.length) {
        setStatus('No emails on this site to copy.', true);
        return;
      }
      copyText(emails.join('\n')).then(function () {
        setStatus(
          'Copied ' + emails.length + ' email' + (emails.length === 1 ? '' : 's') +
          ' — paste into your email client, then Open drafts for site.',
          false
        );
      }).catch(function () {
        setStatus('Could not copy emails.', true);
      });
    }

    function toggleEmailed() {
      if (!selected) return;
      var sid = String(selected.sheetId || sheetId || '');
      var next = !selected.emailSent;
      postAction({
        ajax: '1',
        action: 'mark_email_sent',
        sheet_id: sid,
        row_id: String(selected.id),
        email_sent: next ? '1' : '0'
      })
        .then(function (data) {
          selected.emailSent = !!(data.email_sent);
          renderSelected();
          setStatus(data.message || (selected.emailSent
            ? 'Marked ' + selected.domain + ' as emailed.'
            : 'Cleared emailed mark on ' + selected.domain + '.'));
        })
        .catch(function (err) {
          setStatus(err.message || 'Could not update emailed mark.', true);
        });
    }

    function applyUpdate() {
      if (!selected) return;
      var mode = currentMode();
      if (mode === 'email') {
        var email = emailSelect ? String(emailSelect.value || '') : '';
        if (!email && (selected.emails || []).length === 1) {
          email = selected.emails[0];
        }
        if (!email) {
          setStatus('Pick an email to remove, or choose Delete both.', true);
          return;
        }
        var sid = String(selected.sheetId || sheetId || '');
        var countryLabel = selected.country || sheetName;
        var isLastEmail = (selected.emails || []).length <= 1;
        var confirmMsg = isLastEmail
          ? 'Remove this email from ' + countryLabel + ' sheet?\n\n'
            + 'Site: ' + selected.domain + '\nEmail: ' + email
            + '\n\nThis is the only email — the site row will also be deleted (no empty-email sites).'
          : 'Remove only this email from ' + countryLabel + ' sheet?\n\n'
            + 'Site: ' + selected.domain + '\nEmail: ' + email
            + '\n\nSite name stays; other emails remain.';
        if (!window.confirm(confirmMsg)) {
          return;
        }
        postAction({
          ajax: '1',
          action: 'delete_email',
          sheet_id: sid,
          row_id: String(selected.id),
          email: email
        })
          .then(function (data) {
            setStatus(data.message || 'Email removed.');
            if (data.row_deleted) {
              selected = null;
              renderSelected();
              if (input) {
                input.value = '';
                input.focus();
              }
              suggestions = [];
              hideSuggest();
              return;
            }
            selected.emails = data.emails || [];
            selected.focusEmail = null;
            renderSelected();
          })
          .catch(function (err) {
            setStatus(err.message || 'Could not remove email.', true);
          });
        return;
      }

      var sid = String(selected.sheetId || sheetId || '');
      var countryLabel = selected.country || sheetName;
      if (!window.confirm(
        'Delete BOTH site name and all emails from ' + countryLabel + ' sheet?\n\n' +
        'Site: ' + selected.domain + '\nEmails: ' +
        ((selected.emails || []).join(', ') || '(none)')
      )) {
        return;
      }
      postAction({
        ajax: '1',
        action: 'delete_row',
        sheet_id: sid,
        row_id: String(selected.id)
      })
        .then(function (data) {
          setStatus(data.message || 'Deleted.');
          selected = null;
          renderSelected();
          if (input) {
            input.value = '';
            input.focus();
          }
          suggestions = [];
          hideSuggest();
        })
        .catch(function (err) {
          setStatus(err.message || 'Could not delete.', true);
        });
    }

    if (input) {
      input.addEventListener('input', function () {
        var q = String(input.value || '').trim();
        selected = null;
        renderSelected();
        if (timer) window.clearTimeout(timer);
        timer = window.setTimeout(function () { fetchSuggest(q); }, 280);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
          if (!suggestions.length) return;
          e.preventDefault();
          activeIndex = (activeIndex + 1) % suggestions.length;
          renderSuggest();
          return;
        }
        if (e.key === 'ArrowUp') {
          if (!suggestions.length) return;
          e.preventDefault();
          activeIndex = activeIndex <= 0 ? suggestions.length - 1 : activeIndex - 1;
          renderSuggest();
          return;
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          if (list && !list.hidden) {
            hideSuggest();
            return;
          }
          selected = null;
          renderSelected();
          input.value = '';
          setStatus('');
          hideSuggest();
          return;
        }
        if (e.key === 'Enter') {
          e.preventDefault();
          if (list && !list.hidden && suggestions.length && activeIndex >= 0 && !selected) {
            selectSuggestion(activeIndex);
            return;
          }
          if (selected) {
            copySelectedEmails();
          }
        }
      });
      input.addEventListener('blur', function () {
        window.setTimeout(hideSuggest, 150);
      });
    }

    window.addEventListener('scroll', placeSuggest, true);
    window.addEventListener('resize', placeSuggest);

    modeInputs.forEach(function (el) {
      el.addEventListener('change', syncEmailPick);
    });
    if (applyBtn) applyBtn.addEventListener('click', applyUpdate);
    if (copyBtn) copyBtn.addEventListener('click', copySelectedEmails);
    if (markBtn) markBtn.addEventListener('click', toggleEmailed);
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        selected = null;
        renderSelected();
        if (input) {
          input.value = '';
          input.focus();
        }
        setStatus('');
        hideSuggest();
      });
    }
  }

  document.querySelectorAll('[data-camp-search]').forEach(initCard);
})();
