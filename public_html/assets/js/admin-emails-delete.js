(function () {
  'use strict';

  var root = document.querySelector('[data-swe-admin-delete]');
  if (!root) return;

  var input = document.getElementById('swe-admin-delete-q');
  var list = root.querySelector('[data-swe-suggest]');
  var statusEl = document.getElementById('swe-admin-delete-status');
  var selectedBox = document.querySelector('[data-swe-selected]');
  var selDomain = document.querySelector('[data-swe-sel-domain]');
  var selCountry = document.querySelector('[data-swe-sel-country]');
  var selEmails = document.querySelector('[data-swe-sel-emails]');
  var noEmails = document.querySelector('[data-swe-no-emails]');
  var deleteRowBtn = document.querySelector('[data-swe-delete-row]');
  var clearBtn = document.querySelector('[data-swe-clear-sel]');

  var suggestUrl = root.getAttribute('data-suggest-url') || '';
  var postUrl = root.getAttribute('data-post-url') || window.location.href;
  var suggestions = [];
  var activeIndex = -1;
  var selected = null;
  var timer = null;
  var abortCtrl = null;

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

  function hideSuggest() {
    if (!list) return;
    list.hidden = true;
    list.innerHTML = '';
    activeIndex = -1;
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
      meta.textContent = emails + ' · ' + (item.country || '');

      var tag = document.createElement('span');
      tag.className = 'swe-admin-delete-match';
      tag.textContent = item.match_type === 'email' ? 'email' : 'site';

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
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn secondary small';
        btn.textContent = 'Delete email only';
        btn.addEventListener('click', function () {
          deleteEmailOnly(email);
        });
        li.appendChild(span);
        li.appendChild(btn);
        selEmails.appendChild(li);
      });
    }
  }

  function selectSuggestion(idx) {
    var item = suggestions[idx];
    if (!item) return;
    selected = {
      id: item.id,
      domain: item.domain,
      country: item.country,
      emails: (item.emails || []).slice()
    };
    if (input) input.value = item.domain;
    hideSuggest();
    renderSelected();
    setStatus('Selected ' + item.domain + '. Choose delete site + emails, or delete one email only.');
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
    // suggestUrl already has ajax=suggest
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
          setStatus(suggestions.length + ' suggestion' + (suggestions.length === 1 ? '' : 's') + ' · Enter to select');
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        suggestions = [];
        hideSuggest();
      });
  }

  function postAction(body) {
    return fetch(postUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: new URLSearchParams(body).toString()
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data || data.ok === false) {
          throw new Error((data && data.error) || 'Request failed');
        }
        return data;
      });
    });
  }

  function deleteRow() {
    if (!selected) return;
    if (!window.confirm('Delete ' + selected.domain + ' and ALL its emails from Sites with emails - Admin?')) {
      return;
    }
    postAction({ ajax: '1', action: 'delete_row', site_id: String(selected.id) })
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

  function deleteEmailOnly(email) {
    if (!selected) return;
    if (!window.confirm('Remove only this email from ' + selected.domain + '?\n\n' + email + '\n\nSite name stays in Admin.')) {
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
        selected.emails = data.emails || [];
        renderSelected();
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not remove email.', true);
      });
  }

  if (input) {
    input.addEventListener('input', function () {
      var q = String(input.value || '').trim();
      selected = null;
      renderSelected();
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function () { fetchSuggest(q); }, 180);
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
        if (!list.hidden && suggestions.length && activeIndex >= 0) {
          selectSuggestion(activeIndex);
          return;
        }
        if (selected) {
          // Enter with selection: focus delete choice via status
          setStatus('Selected. Use “Delete site + all emails” or “Delete email only” on an email.');
        }
      }
    });
    input.addEventListener('blur', function () {
      window.setTimeout(hideSuggest, 150);
    });
  }

  if (deleteRowBtn) deleteRowBtn.addEventListener('click', deleteRow);
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
})();
