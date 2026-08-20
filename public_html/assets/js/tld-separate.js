/**
 * Site Finding — Separate all by public suffix (TLD columns).
 * Copy / Delete column (UI). Send uses a normal POST form (option A on server).
 */
(function () {
  'use strict';

  function initRoot(root) {
    var grid = root.querySelector('[data-tld-grid]');
    var statusEl = root.querySelector('[data-tld-status]');
    var separateBtn = root.querySelector('[data-tld-separate-btn]');
    var sourceSel = root.getAttribute('data-source') || '';
    var groupUrl = root.getAttribute('data-group-url') || '';
    var csrf = root.getAttribute('data-csrf') || '';
    var country = root.getAttribute('data-country') || '';
    var language = root.getAttribute('data-language') || '';
    var region = root.getAttribute('data-region') || '';
    var niche = root.getAttribute('data-niche') || '';
    var notes = root.getAttribute('data-notes') || '';
    var canSend = root.getAttribute('data-can-send') === '1';
    var sendLabel = root.getAttribute('data-send-label') || 'Send to Extracting';
    var preloaded = null;
    var pristine = null;

    try {
      var rawPre = root.getAttribute('data-groups-json');
      if (rawPre) {
        preloaded = JSON.parse(rawPre);
        pristine = JSON.parse(rawPre);
      }
    } catch (e) {
      preloaded = null;
      pristine = null;
    }

    function setStatus(msg, isError) {
      if (!statusEl) return;
      if (!msg) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error', 'is-ok');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.classList.toggle('is-error', !!isError);
      statusEl.classList.toggle('is-ok', !isError);
    }

    function sourceText() {
      if (!sourceSel) return '';
      var el = document.querySelector(sourceSel);
      if (!el) return '';
      return String(el.value || el.textContent || '');
    }

    function syncAddAllHidden() {
      var hidden = document.querySelector('#add_unique_form [name="domains"]');
      var btn = document.getElementById('add_unique_btn');
      if (!hidden || !grid) return;
      // Only sync from the results-panel separator (source is unique preview).
      if (sourceSel !== '#unique_domains_preview') return;
      var parts = [];
      grid.querySelectorAll('[data-tld-col] textarea[data-tld-domains]').forEach(function (ta) {
        var t = String(ta.value || '').trim();
        if (t) parts.push(t);
      });
      var joined = parts.join('\n');
      hidden.value = joined;
      if (btn) {
        var n = joined ? joined.split(/\n+/).filter(function (l) { return l.trim(); }).length : 0;
        var countryLabel = country || 'country';
        btn.textContent = n > 0
          ? ('Add ' + n + ' new site' + (n === 1 ? '' : 's') + ' to ' + countryLabel)
          : ('Add 0 new sites to ' + countryLabel);
        btn.disabled = n < 1;
      }
    }

    function renderGroups(groups) {
      if (!grid) return;
      grid.innerHTML = '';
      var keys = Object.keys(groups || {});
      if (!keys.length) {
        grid.hidden = true;
        setStatus('No domains to separate.', true);
        return;
      }
      keys.forEach(function (tld) {
        var list = groups[tld] || [];
        if (!list.length) return;
        var col = document.createElement('div');
        col.className = 'tld-separate-col';
        col.setAttribute('data-tld-col', tld);

        var title = document.createElement('h3');
        var label = document.createElement('span');
        label.textContent = tld === 'other' ? '(other)' : ('.' + tld);
        var count = document.createElement('span');
        count.className = 'tld-count';
        count.textContent = '(' + list.length + ')';
        title.appendChild(label);
        title.appendChild(count);
        col.appendChild(title);

        var ta = document.createElement('textarea');
        ta.setAttribute('data-tld-domains', '1');
        ta.setAttribute('readonly', 'readonly');
        ta.setAttribute('spellcheck', 'false');
        ta.value = list.join('\n');
        col.appendChild(ta);

        var actions = document.createElement('div');
        actions.className = 'tld-col-actions';

        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn secondary small';
        copyBtn.setAttribute('data-tld-copy', '1');
        copyBtn.textContent = 'Copy';
        actions.appendChild(copyBtn);

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn secondary small';
        delBtn.setAttribute('data-tld-delete', '1');
        delBtn.textContent = 'Delete column';
        actions.appendChild(delBtn);

        if (canSend && country) {
          var form = document.createElement('form');
          form.method = 'post';
          form.className = 'tld-send-form';
          form.setAttribute('data-show-processing', 'Sending sites…');

          var csrfInput = document.createElement('input');
          csrfInput.type = 'hidden';
          csrfInput.name = '_csrf';
          csrfInput.value = csrf;
          form.appendChild(csrfInput);

          [['action', 'send_tld_column'], ['country', country], ['language', language],
            ['region', region], ['niche', niche]].forEach(function (pair) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = pair[0];
            inp.value = pair[1];
            form.appendChild(inp);
          });

          var notesTa = document.createElement('textarea');
          notesTa.name = 'notes';
          notesTa.hidden = true;
          notesTa.value = notes;
          form.appendChild(notesTa);

          var domainsTa = document.createElement('textarea');
          domainsTa.name = 'domains';
          domainsTa.hidden = true;
          domainsTa.value = list.join('\n');
          form.appendChild(domainsTa);

          var sendBtn = document.createElement('button');
          sendBtn.type = 'submit';
          sendBtn.className = 'btn small';
          sendBtn.textContent = sendLabel;
          sendBtn.title = 'Filter against country, add unique sites, push to Extracting Sites list';
          form.appendChild(sendBtn);

          form.addEventListener('submit', function (e) {
            var live = col.querySelector('textarea[data-tld-domains]');
            if (live) domainsTa.value = live.value;
            var n = String(domainsTa.value || '').split(/\n+/).filter(function (l) {
              return l.trim();
            }).length;
            if (!n) {
              e.preventDefault();
              setStatus('This column is empty.', true);
              return;
            }
            var suffix = tld === 'other' ? 'other' : ('.' + tld);
            if (!window.confirm(
              'Send ' + n + ' ' + suffix + ' site(s) to ' + country
                + '?\n\nAlready-known sites are skipped. Unique sites are added and go to Extracting Sites list.'
                + '\n\nIf this ending looks wrong for ' + country + ', you are confirming you still want to add them.'
            )) {
              e.preventDefault();
              return;
            }
            // Option A path may soft-warn on country/TLD mismatch — confirm dialog is the ack.
            var ack = form.querySelector('input[name="confirm_tld_mismatch"]');
            if (!ack) {
              ack = document.createElement('input');
              ack.type = 'hidden';
              ack.name = 'confirm_tld_mismatch';
              form.appendChild(ack);
            }
            ack.value = '1';
          });
          actions.appendChild(form);
        }

        col.appendChild(actions);
        grid.appendChild(col);
      });
      grid.hidden = false;
      root.setAttribute('data-separated', '1');
      setStatus(keys.length + ' column' + (keys.length === 1 ? '' : 's') + ' by domain ending. Copy, delete, or send each column.');
      syncAddAllHidden();
    }

    function separateFromSource() {
      if (pristine && typeof pristine === 'object' && Object.keys(pristine).length) {
        preloaded = JSON.parse(JSON.stringify(pristine));
        renderGroups(preloaded);
        return;
      }
      var text = sourceText().trim();
      if (!text) {
        setStatus('Paste or filter sites first.', true);
        return;
      }
      if (!groupUrl) {
        setStatus('Separate is not available.', true);
        return;
      }
      setStatus('Separating…', false);
      if (separateBtn) separateBtn.disabled = true;
      var body = new URLSearchParams();
      body.set('action', 'group_tlds');
      body.set('ajax', '1');
      body.set('domains', text);
      if (csrf) body.set('_csrf', csrf);
      fetch(groupUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          Accept: 'application/json',
          'X-CSRF-Token': csrf
        },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok || !data || data.ok === false) {
              throw new Error((data && data.error) || 'Could not separate.');
            }
            return data;
          });
        })
        .then(function (data) {
          preloaded = data.groups || {};
          pristine = JSON.parse(JSON.stringify(preloaded));
          renderGroups(preloaded);
        })
        .catch(function (err) {
          setStatus(err.message || 'Could not separate.', true);
        })
        .finally(function () {
          if (separateBtn) separateBtn.disabled = false;
        });
    }

    if (separateBtn) {
      separateBtn.addEventListener('click', function () {
        separateFromSource();
      });
    }

    root.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var col = t.closest('[data-tld-col]');
      if (!col || !root.contains(col)) return;

      if (t.closest('[data-tld-copy]')) {
        e.preventDefault();
        var ta = col.querySelector('textarea[data-tld-domains]');
        var text = ta ? String(ta.value || '') : '';
        if (!text.trim()) {
          setStatus('Column is empty.', true);
          return;
        }
        var done = function () {
          setStatus('Copied ' + text.split(/\n+/).filter(Boolean).length + ' site(s).');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {
            ta.focus();
            ta.select();
            try {
              document.execCommand('copy');
              done();
            } catch (err) {
              setStatus('Could not copy.', true);
            }
          });
        } else {
          ta.focus();
          ta.select();
          try {
            document.execCommand('copy');
            done();
          } catch (err2) {
            setStatus('Could not copy.', true);
          }
        }
        return;
      }

      if (t.closest('[data-tld-delete]')) {
        e.preventDefault();
        var tld = col.getAttribute('data-tld-col') || '';
        var label = tld === 'other' ? '(other)' : ('.' + tld);
        if (!window.confirm('Delete the ' + label + ' column from this view? (Does not change the country database.)')) {
          return;
        }
        col.remove();
        if (preloaded && preloaded[tld]) {
          delete preloaded[tld];
        }
        if (!grid.querySelector('[data-tld-col]')) {
          grid.hidden = true;
          setStatus('All columns removed.');
        } else {
          setStatus('Column deleted.');
        }
        syncAddAllHidden();
      }
    });
  }

  document.querySelectorAll('[data-tld-separate]').forEach(initRoot);
})();
