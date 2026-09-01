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

    function fallbackCopy(text, done) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        document.execCommand('copy');
        done();
      } catch (err) {
        setStatus('Could not copy.', true);
      }
      document.body.removeChild(ta);
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

    function remainingUniqueText() {
      var parts = [];
      orderedKeys(preloaded).forEach(function (tld) {
        var list = preloaded[tld] || [];
        if (list.length) parts.push(list.join('\n'));
      });
      return parts.join('\n');
    }

    function syncAddAllHidden() {
      var hidden = document.querySelector('#add_unique_form [name="domains"]');
      var btn = document.getElementById('add_unique_btn');
      var preview = document.querySelector('#unique_domains_preview');
      if (sourceSel === '#unique_domains_preview' && preview) {
        preview.value = remainingUniqueText();
      }
      if (!hidden || !preloaded) return;
      if (sourceSel !== '#unique_domains_preview') return;
      var joined = remainingUniqueText();
      hidden.value = joined;
      if (btn) {
        var n = joined ? joined.split(/\n+/).filter(function (l) { return l.trim(); }).length : 0;
        btn.textContent = n > 0
          ? ('Add ' + n + ' unique site' + (n === 1 ? '' : 's'))
          : 'Add 0 unique sites';
        btn.disabled = n < 1;
      }
    }

    /** Host/root hint for one paste line (https/path/www → example.com). */
    function domainKey(line) {
      var s = String(line || '').trim().toLowerCase();
      if (!s) return '';
      var md = s.match(/\((https?:\/\/[^)\s]+)\)/i);
      if (md) {
        s = md[1].toLowerCase();
      } else {
        var href = s.match(/https?:\/\/[^\s<>"']+/i);
        if (href) s = href[0].toLowerCase();
      }
      s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/^\/\//, '');
      if (s.indexOf('@') !== -1) s = s.split('@').pop() || '';
      s = s.split('/')[0].split('?')[0].split('#')[0];
      if (s.indexOf('\t') !== -1) s = s.split('\t')[0];
      if (s.indexOf(':') !== -1 && s.indexOf(']') === -1) s = s.split(':')[0];
      s = s.replace(/^www\./, '').replace(/\.$/, '').trim();
      return s;
    }

    function lineHitsDrop(line, drop) {
      var raw = String(line || '').trim().toLowerCase();
      if (!raw) return true;
      if (drop[raw]) return true;
      var host = domainKey(line);
      if (host && drop[host]) return true;
      if (!host) return false;
      for (var d in drop) {
        if (!Object.prototype.hasOwnProperty.call(drop, d) || !d) continue;
        if (host === d) return true;
        if (host.length > d.length && host.slice(-(d.length + 1)) === '.' + d) return true;
      }
      return false;
    }

    function stripLinesFromTextarea(el, list) {
      if (!el || !list || !list.length) return;
      var drop = {};
      list.forEach(function (d) {
        var k = String(d || '').trim().toLowerCase();
        if (k) drop[k] = true;
      });
      var kept = String(el.value || '').split(/\n+/).filter(function (line) {
        if (!String(line || '').trim()) return false;
        return !lineHitsDrop(line, drop);
      });
      el.value = kept.join('\n');
      try {
        el.dispatchEvent(new Event('input', { bubbles: true }));
      } catch (e) { /* ignore */ }
    }

    function dropDomainsFromMap(groups, list) {
      if (!groups || !list || !list.length) return;
      var drop = {};
      list.forEach(function (d) {
        var k = String(d || '').trim().toLowerCase();
        if (k) drop[k] = true;
      });
      Object.keys(groups).forEach(function (tld) {
        var kept = (groups[tld] || []).filter(function (d) {
          return !drop[String(d || '').trim().toLowerCase()];
        });
        if (kept.length) groups[tld] = kept;
        else delete groups[tld];
      });
    }

    function stripSharedColumns(list) {
      if (!list || !list.length) return;
      stripLinesFromTextarea(document.querySelector('#domains'), list);
      var preview = document.querySelector('#unique_domains_preview');
      if (preview) stripLinesFromTextarea(preview, list);
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

      var listEl = document.createElement('div');
      listEl.className = 'tld-workspace-list';
      listEl.setAttribute('data-tld-domains', '1');
      list.forEach(function (domain) {
        var d = String(domain || '').trim();
        if (!d) return;
        var row = document.createElement('div');
        row.className = 'tld-site-row';
        row.setAttribute('data-tld-site', d);
        var name = document.createElement('span');
        name.className = 'tld-site-name';
        name.textContent = d;
        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'tld-site-drop';
        drop.setAttribute('data-tld-drop-site', '1');
        drop.setAttribute('aria-label', 'Remove ' + d);
        drop.setAttribute('title', 'Remove ' + d + ' from this list');
        drop.textContent = '×';
        row.appendChild(name);
        row.appendChild(drop);
        listEl.appendChild(row);
      });
      col.appendChild(listEl);

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
        sendBtn.title = 'Add unique sites that already passed Filter unique sites';
        form.appendChild(sendBtn);

        form.addEventListener('submit', function (e) {
          var liveList = (preloaded && preloaded[tld]) ? preloaded[tld] : [];
          domainsTa.value = liveList.join('\n');
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
          var n = liveList.filter(function (line) {
            return String(line || '').trim();
          }).length;
          if (!n) {
            e.preventDefault();
            setStatus('This ending list is empty.', true);
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

    document.addEventListener('txf-tld-ending-removed', function (ev) {
      if (!ev || !ev.detail || ev.detail.origin === root) return;
      var list = ev.detail.domains || [];
      if (!list.length) return;
      dropDomainsFromMap(preloaded, list);
      dropDomainsFromMap(pristine, list);
      if (root.getAttribute('data-separated') === '1') {
        activeTld = '';
        renderGroups(preloaded || {});
      } else {
        syncAddAllHidden();
      }
    });

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

      if (t.closest('[data-tld-drop-site]')) {
        e.preventDefault();
        var row = t.closest('[data-tld-site]');
        var domain = row ? String(row.getAttribute('data-tld-site') || '').trim() : '';
        if (!domain) return;
        var removed = [domain];
        dropDomainsFromMap(preloaded, removed);
        dropDomainsFromMap(pristine, removed);
        stripSharedColumns(removed);
        try {
          document.dispatchEvent(new CustomEvent('txf-tld-ending-removed', {
            detail: { domains: removed, origin: root }
          }));
        } catch (errDrop) { /* ignore */ }
        var tldNow = col.getAttribute('data-tld-col') || '';
        if (preloaded && preloaded[tldNow] && preloaded[tldNow].length) {
          renderRail(preloaded);
          renderActivePanel(tldNow);
        } else {
          activeTld = '';
          renderGroups(preloaded || {});
        }
        setStatus('Removed ' + domain + '.');
        syncAddAllHidden();
        return;
      }

      if (t.closest('[data-tld-copy]')) {
        e.preventDefault();
        var tldCopy = col.getAttribute('data-tld-col') || '';
        var copyList = (preloaded && preloaded[tldCopy]) ? preloaded[tldCopy] : [];
        var text = copyList.join('\n');
        if (!text.trim()) {
          setStatus('List is empty.', true);
          return;
        }
        var done = function () {
          setStatus('Copied ' + copyList.filter(Boolean).length + ' site(s).');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {
            fallbackCopy(text, done);
          });
        } else {
          fallbackCopy(text, done);
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
        stripSharedColumns(removed);
        try {
          document.dispatchEvent(new CustomEvent('txf-tld-ending-removed', {
            detail: { domains: removed, origin: root }
          }));
        } catch (err3) { /* ignore */ }
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
