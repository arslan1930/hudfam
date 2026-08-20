/**
 * Site Finding — Separate all by public suffix.
 * Tab + one main list (no multi-column scroll cages).
 * Send uses a normal POST form outside #filter_form (option A on server).
 */
(function () {
  'use strict';

  function initRoot(root) {
    var rail = root.querySelector('[data-tld-rail]');
    var panel = root.querySelector('[data-tld-panel]');
    var statusEl = root.querySelector('[data-tld-status]');
    var separateBtn = root.querySelector('[data-tld-separate-btn]');
    var workspace = root.querySelector('[data-tld-workspace]');
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
    var activeTld = '';

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

    function liveMeta() {
      // Prefer live form fields when workspace sits outside #filter_form.
      var langEl = document.querySelector('#language, #filter_form [name="language"]');
      var regionEl = document.querySelector('#filter_form [name="region"], select[name="region"]');
      var nicheEl = document.querySelector('#filter_form [name="niche"], input[name="niche"]');
      var notesEl = document.querySelector('#filter_form [name="notes"], textarea[name="notes"]');
      var countryEl = document.querySelector('#filter_form [data-typeahead-value], [name="country"]');
      return {
        country: (countryEl && countryEl.value) ? String(countryEl.value).trim() : country,
        language: langEl ? String(langEl.value || '').trim() : language,
        region: regionEl ? String(regionEl.value || '').trim() : region,
        niche: nicheEl ? String(nicheEl.value || '').trim() : niche,
        notes: notesEl ? String(notesEl.value || '').trim() : notes
      };
    }

    function orderedKeys(groups) {
      return Object.keys(groups || {}).filter(function (k) {
        return Array.isArray(groups[k]) && groups[k].length > 0;
      });
    }

    function syncAddAllHidden() {
      var hidden = document.querySelector('#add_unique_form [name="domains"]');
      var btn = document.getElementById('add_unique_btn');
      if (!hidden || !preloaded) return;
      if (sourceSel !== '#unique_domains_preview') return;
      var parts = [];
      orderedKeys(preloaded).forEach(function (tld) {
        var list = preloaded[tld] || [];
        if (list.length) parts.push(list.join('\n'));
      });
      var joined = parts.join('\n');
      hidden.value = joined;
      if (btn) {
        var n = joined ? joined.split(/\n+/).filter(function (l) { return l.trim(); }).length : 0;
        var countryLabel = liveMeta().country || country || 'country';
        btn.textContent = n > 0
          ? ('Add ' + n + ' new site' + (n === 1 ? '' : 's') + ' to ' + countryLabel)
          : ('Add 0 new sites to ' + countryLabel);
        btn.disabled = n < 1;
      }
    }

    function stripDomainsFromSource(list) {
      if (sourceSel !== '#domains' || !list || !list.length) return;
      var el = document.querySelector(sourceSel);
      if (!el || el.readOnly) return;
      var drop = {};
      list.forEach(function (d) {
        drop[String(d).toLowerCase()] = true;
      });
      var kept = String(el.value || '').split(/\n+/).filter(function (line) {
        var t = String(line || '').trim().toLowerCase();
        if (!t) return false;
        return !drop[t];
      });
      el.value = kept.join('\n');
      try {
        el.dispatchEvent(new Event('input', { bubbles: true }));
      } catch (e) { /* ignore */ }
    }

    function renderActivePanel(tld) {
      if (!panel) return;
      panel.innerHTML = '';
      if (!tld || !preloaded || !preloaded[tld] || !preloaded[tld].length) {
        panel.hidden = true;
        return;
      }
      activeTld = tld;
      var list = preloaded[tld];
      var col = document.createElement('div');
      col.className = 'tld-workspace-active';
      col.setAttribute('data-tld-col', tld);

      var title = document.createElement('h3');
      title.className = 'tld-workspace-title';
      var label = document.createElement('span');
      label.textContent = tld === 'other' ? '(other)' : ('.' + tld);
      var count = document.createElement('span');
      count.className = 'tld-count';
      count.textContent = list.length + ' site' + (list.length === 1 ? '' : 's');
      title.appendChild(label);
      title.appendChild(count);
      col.appendChild(title);

      var ta = document.createElement('textarea');
      ta.className = 'tld-workspace-list';
      ta.setAttribute('data-tld-domains', '1');
      ta.setAttribute('readonly', 'readonly');
      ta.setAttribute('spellcheck', 'false');
      ta.rows = Math.min(28, Math.max(8, list.length + 1));
      ta.value = list.join('\n');
      col.appendChild(ta);

      var actions = document.createElement('div');
      actions.className = 'tld-col-actions';

      var copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'btn secondary';
      copyBtn.setAttribute('data-tld-copy', '1');
      copyBtn.textContent = 'Copy';
      actions.appendChild(copyBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn secondary';
      delBtn.setAttribute('data-tld-delete', '1');
      delBtn.textContent = 'Delete ending';
      actions.appendChild(delBtn);

      var meta = liveMeta();
      var sendCountry = meta.country || country;
      if (canSend && sendCountry) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = 'index.php?page=team_prospect_check';
        form.className = 'tld-send-form';
        form.setAttribute('data-show-processing', 'Sending sites…');

        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_csrf';
        csrfInput.value = csrf;
        form.appendChild(csrfInput);

        [
          ['action', 'send_tld_column'],
          ['country', sendCountry],
          ['language', meta.language],
          ['region', meta.region],
          ['niche', meta.niche]
        ].forEach(function (pair) {
          var inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = pair[0];
          inp.value = pair[1];
          form.appendChild(inp);
        });

        var notesTa = document.createElement('textarea');
        notesTa.name = 'notes';
        notesTa.hidden = true;
        notesTa.value = meta.notes;
        form.appendChild(notesTa);

        var domainsTa = document.createElement('textarea');
        domainsTa.name = 'domains';
        domainsTa.hidden = true;
        domainsTa.value = list.join('\n');
        form.appendChild(domainsTa);

        var sendBtn = document.createElement('button');
        sendBtn.type = 'submit';
        sendBtn.className = 'btn';
        sendBtn.textContent = sendLabel;
        sendBtn.title = 'Filter against country, add unique sites, push to Extracting Sites list';
        form.appendChild(sendBtn);

        form.addEventListener('submit', function (e) {
          var live = col.querySelector('textarea[data-tld-domains]');
          if (live) domainsTa.value = live.value;
          // Refresh country/meta from form at submit time.
          var m = liveMeta();
          var cInput = form.querySelector('input[name="country"]');
          var lInput = form.querySelector('input[name="language"]');
          var rInput = form.querySelector('input[name="region"]');
          var nInput = form.querySelector('input[name="niche"]');
          if (cInput) cInput.value = m.country || sendCountry;
          if (lInput) lInput.value = m.language;
          if (rInput) rInput.value = m.region;
          if (nInput) nInput.value = m.niche;
          notesTa.value = m.notes;
          if (!(cInput && cInput.value)) {
            e.preventDefault();
            setStatus('Select a country database first.', true);
            return;
          }
          var n = String(domainsTa.value || '').split(/\n+/).filter(function (line) {
            return line.trim();
          }).length;
          if (!n) {
            e.preventDefault();
            setStatus('This ending list is empty.', true);
            return;
          }
          var suffix = tld === 'other' ? 'other' : ('.' + tld);
          if (!window.confirm(
            'Send ' + n + ' ' + suffix + ' site(s) to ' + cInput.value
              + '?\n\nAlready-known sites are skipped. Unique sites are added and go to Extracting Sites list.'
              + '\n\nIf this ending looks wrong for ' + cInput.value + ', you are confirming you still want to add them.'
          )) {
            e.preventDefault();
            return;
          }
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
      panel.appendChild(col);
      panel.hidden = false;
    }

    function renderRail(groups) {
      if (!rail) return;
      rail.innerHTML = '';
      var keys = orderedKeys(groups);
      if (!keys.length) {
        if (workspace) workspace.hidden = true;
        rail.hidden = true;
        return;
      }
      keys.forEach(function (tld) {
        var list = groups[tld] || [];
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tld-rail-btn' + (tld === activeTld ? ' is-active' : '');
        btn.setAttribute('data-tld-tab', tld);
        btn.textContent = (tld === 'other' ? '(other)' : ('.' + tld)) + ' (' + list.length + ')';
        rail.appendChild(btn);
      });
      rail.hidden = false;
      if (workspace) workspace.hidden = false;
    }

    function renderGroups(groups) {
      preloaded = groups || {};
      var keys = orderedKeys(preloaded);
      if (!keys.length) {
        if (workspace) workspace.hidden = true;
        if (rail) {
          rail.innerHTML = '';
          rail.hidden = true;
        }
        if (panel) {
          panel.innerHTML = '';
          panel.hidden = true;
        }
        setStatus('No domains to separate.', true);
        syncAddAllHidden();
        return;
      }
      if (!activeTld || !preloaded[activeTld] || !preloaded[activeTld].length) {
        activeTld = keys[0];
      }
      root.setAttribute('data-separated', '1');
      renderRail(preloaded);
      renderActivePanel(activeTld);
      setStatus(
        keys.length + ' ending' + (keys.length === 1 ? '' : 's')
          + ' — pick one below. Copy, delete, or send that list only.'
      );
      syncAddAllHidden();
    }

    function separateFromSource() {
      if (pristine && typeof pristine === 'object' && Object.keys(pristine).length) {
        preloaded = JSON.parse(JSON.stringify(pristine));
        activeTld = '';
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
          activeTld = '';
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

      var tab = t.closest('[data-tld-tab]');
      if (tab && root.contains(tab)) {
        e.preventDefault();
        activeTld = tab.getAttribute('data-tld-tab') || '';
        renderRail(preloaded || {});
        renderActivePanel(activeTld);
        return;
      }

      var col = t.closest('[data-tld-col]');
      if (!col || !root.contains(col)) return;

      if (t.closest('[data-tld-copy]')) {
        e.preventDefault();
        var ta = col.querySelector('textarea[data-tld-domains]');
        var text = ta ? String(ta.value || '') : '';
        if (!text.trim()) {
          setStatus('List is empty.', true);
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
        if (!window.confirm('Delete the ' + label + ' list from this view? (Does not change the country database.)')) {
          return;
        }
        var removed = (preloaded && preloaded[tld]) ? preloaded[tld].slice() : [];
        if (preloaded && preloaded[tld]) {
          delete preloaded[tld];
        }
        if (pristine && pristine[tld]) {
          delete pristine[tld];
        }
        stripDomainsFromSource(removed);
        activeTld = '';
        renderGroups(preloaded || {});
        if (!orderedKeys(preloaded || {}).length) {
          setStatus('All endings removed.');
        } else {
          setStatus('Ending deleted.');
        }
        syncAddAllHidden();
      }
    });
  }

  document.querySelectorAll('[data-tld-separate]').forEach(initRoot);
})();
