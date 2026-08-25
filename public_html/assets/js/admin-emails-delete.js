(function () {
  'use strict';

  function initCard(root) {
    var input = root.querySelector('[data-swe-q]') || document.getElementById('swe-admin-delete-q');
    var list = root.querySelector('[data-swe-suggest]');
    var statusEl = root.querySelector('[data-swe-status]') || document.getElementById('swe-admin-delete-status');
    var selectedBox = root.querySelector('[data-swe-selected]');
    var selDomain = root.querySelector('[data-swe-sel-domain]');
    var selCountry = root.querySelector('[data-swe-sel-country]');
    var selEmails = root.querySelector('[data-swe-sel-emails]');
    var noEmails = root.querySelector('[data-swe-no-emails]');
    var emailPick = root.querySelector('[data-swe-email-pick]');
    var emailSelect = root.querySelector('[data-swe-email-select]');
    var applyBtn = root.querySelector('[data-swe-apply]') || root.querySelector('[data-swe-delete-row]');
    var clearBtn = root.querySelector('[data-swe-clear]') || root.querySelector('[data-swe-clear-sel]');
    var modeInputs = root.querySelectorAll('[data-swe-mode]');

    var suggestUrl = root.getAttribute('data-suggest-url') || '';
    var postUrl = root.getAttribute('data-post-url') || window.location.href;

    var suggestions = [];
    var activeIndex = -1;
    var selected = null;
    var timer = null;
    var abortCtrl = null;

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
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.classList.toggle('is-error', !!isError);
    }

    function currentMode() {
      var checked = root.querySelector('[data-swe-mode]:checked');
      return checked ? checked.value : 'row';
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

    function renderSuggest() {
      if (!list) return;
      list.innerHTML = '';
      if (!suggestions.length) {
        list.hidden = true;
        return;
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
        meta.textContent = emails + (item.country ? ' · ' + item.country : '');

        var tag = document.createElement('span');
        tag.className = 'swe-admin-delete-match';
        tag.textContent = item.match_type === 'email' ? 'email'
          : (item.match_type === 'country' ? 'country' : 'site');

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
    }

    function renderSelected() {
      if (!selectedBox || !selected) {
        if (selectedBox) selectedBox.hidden = true;
        return;
      }
      selectedBox.hidden = false;
      if (selDomain) selDomain.textContent = selected.domain || '';
      if (selCountry) selCountry.textContent = selected.country || '';
      if (selEmails) {
        selEmails.innerHTML = '';
        var emails = selected.emails || [];
        if (noEmails) noEmails.hidden = emails.length > 0;
        emails.forEach(function (email) {
          var li = document.createElement('li');
          li.className = 'swe-admin-delete-email-row';
          var span = document.createElement('span');
          span.textContent = email;
          li.appendChild(span);
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
    }

    function selectSuggestion(idx) {
      var item = suggestions[idx];
      if (!item) return;
      selected = {
        id: item.id,
        domain: item.domain,
        country: item.country,
        emails: (item.emails || []).slice(),
        matchType: item.match_type || 'domain',
        focusEmail: item.match_type === 'email' ? item.matched_value : null
      };
      if (input) input.value = item.domain;
      hideSuggest();
      renderSelected();
      setStatus(
        'Selected ' + item.domain + ' · ' +
        ((item.emails || []).join(', ') || 'no emails') +
        (item.country ? ' · ' + item.country : '') +
        '. Choose action, then press Enter to confirm.'
      );
    }

    function fetchSuggest(q) {
      if (abortCtrl) {
        try { abortCtrl.abort(); } catch (e) {}
      }
      if (!suggestUrl || q.length < 2) {
        suggestions = [];
        hideSuggest();
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
          if (!suggestions.length && q.length >= 2) {
            setStatus('No matches in Sites with emails - Admin.', true);
          } else if (suggestions.length) {
            setStatus(
              suggestions.length + ' result' + (suggestions.length === 1 ? '' : 's') +
              ' · site + email + country · Enter to select'
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

    function applyUpdate() {
      if (!selected) return;
      var mode = currentMode();
      var countryLabel = selected.country || 'Admin';

      if (mode === 'email') {
        var email = emailSelect ? String(emailSelect.value || '') : '';
        if (!email && (selected.emails || []).length === 1) {
          email = selected.emails[0];
        }
        if (!email) {
          setStatus('Pick an email to remove, or choose Delete both.', true);
          return;
        }
        var isLastEmail = (selected.emails || []).length <= 1;
        var confirmMsg = isLastEmail
          ? 'Remove this email from Sites with emails - Admin?\n\n'
            + 'Country: ' + countryLabel + '\nSite: ' + selected.domain + '\nEmail: ' + email
            + '\n\nThis is the only email — the Admin working-list row is deleted. Final keeps its archive copy.'
          : 'Remove only this email from Sites with emails - Admin?\n\n'
            + 'Country: ' + countryLabel + '\nSite: ' + selected.domain + '\nEmail: ' + email
            + '\n\nSite name stays; other emails remain.';
        if (!window.confirm(confirmMsg)) {
          return;
        }
        postAction({
          ajax: '1',
          action: 'delete_email',
          site_id: String(selected.id),
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

      if (!window.confirm(
        'Delete BOTH site name and all emails from Sites with emails - Admin?\n\n' +
        'Country: ' + countryLabel + '\nSite: ' + selected.domain + '\nEmails: ' +
        ((selected.emails || []).join(', ') || '(none)')
      )) {
        return;
      }
      postAction({
        ajax: '1',
        action: 'delete_row',
        site_id: String(selected.id)
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
          hideSuggest();
          return;
        }
        if (e.key === 'Enter') {
          e.preventDefault();
          if (!list.hidden && suggestions.length && activeIndex >= 0 && !selected) {
            selectSuggestion(activeIndex);
            return;
          }
          if (selected) {
            applyUpdate();
          }
        }
      });
      input.addEventListener('blur', function () {
        window.setTimeout(hideSuggest, 150);
      });
    }

    modeInputs.forEach(function (el) {
      el.addEventListener('change', syncEmailPick);
    });
    if (applyBtn) applyBtn.addEventListener('click', applyUpdate);
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

  document.querySelectorAll('[data-swe-admin-delete]').forEach(initCard);
})();
