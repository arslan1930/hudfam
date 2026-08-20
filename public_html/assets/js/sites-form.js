(function () {
  'use strict';

  var MULTI_TLDS = {
    'co.uk': 1, 'org.uk': 1, 'me.uk': 1, 'ac.uk': 1, 'gov.uk': 1, 'ltd.uk': 1, 'plc.uk': 1, 'net.uk': 1,
    'com.au': 1, 'net.au': 1, 'org.au': 1, 'edu.au': 1, 'gov.au': 1, 'asn.au': 1, 'id.au': 1,
    'co.nz': 1, 'org.nz': 1, 'net.nz': 1, 'govt.nz': 1, 'ac.nz': 1,
    'co.za': 1, 'org.za': 1, 'web.za': 1, 'net.za': 1,
    'com.br': 1, 'net.br': 1, 'org.br': 1, 'gov.br': 1,
    'co.jp': 1, 'or.jp': 1, 'ne.jp': 1, 'ac.jp': 1, 'go.jp': 1,
    'com.mx': 1, 'org.mx': 1, 'gob.mx': 1,
    'com.sg': 1, 'com.hk': 1, 'com.tw': 1, 'com.tr': 1, 'com.my': 1, 'com.ph': 1,
    'co.in': 1, 'firm.in': 1, 'gen.in': 1, 'ind.in': 1, 'net.in': 1, 'org.in': 1,
    'com.ar': 1, 'com.co': 1, 'com.pe': 1, 'com.ve': 1, 'com.ec': 1,
    'co.kr': 1, 'co.th': 1, 'co.il': 1, 'org.il': 1, 'ac.il': 1,
    'com.cn': 1, 'net.cn': 1, 'org.cn': 1,
    'co.id': 1, 'or.id': 1, 'web.id': 1
  };

  function publicSuffix(host) {
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return '';
    var two = parts[parts.length - 2] + '.' + parts[parts.length - 1];
    if (MULTI_TLDS[two]) return two;
    return parts[parts.length - 1];
  }

  function isRootDomain(host) {
    if (!host || host.indexOf('.') === -1) return false;
    if (!/^[a-z0-9.-]+$/.test(host)) return false;
    if (host.charAt(0) === '-' || host.slice(-1) === '-' || host.indexOf('..') !== -1) return false;
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return false;
    for (var i = 0; i < parts.length; i++) {
      var label = parts[i];
      if (!label || label.length > 63) return false;
      if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label)) return false;
    }
    var suffix = publicSuffix(host);
    if (!suffix) return false;
    var suffixParts = suffix.split('.').length;
    return (parts.length - suffixParts) === 1;
  }

  function analyzeLine(line) {
    var raw = String(line || '').trim();
    if (!raw) return { ok: false, domain: '', reason: 'empty', raw: raw };
    if (/https?:\/\//i.test(raw) || raw.indexOf('://') !== -1 || raw.indexOf('//') === 0) {
      return { ok: false, domain: '', reason: 'has_scheme', raw: raw };
    }
    if (raw.indexOf('/') !== -1 || raw.indexOf('?') !== -1 || raw.indexOf('#') !== -1) {
      return { ok: false, domain: '', reason: 'has_path', raw: raw };
    }
    if (/\s/.test(raw)) {
      return { ok: false, domain: '', reason: 'has_spaces', raw: raw };
    }
    var host = raw.toLowerCase().replace(/^www\./i, '');
    if (host.indexOf(':') !== -1 && host.indexOf(']') === -1) {
      host = host.split(':')[0];
    }
    host = host.replace(/\.$/, '');
    if (!host || host.indexOf('.') === -1) {
      return { ok: false, domain: '', reason: 'invalid', raw: raw };
    }
    if (!isRootDomain(host)) {
      var suffix = publicSuffix(host);
      var suffixParts = suffix ? suffix.split('.').length : 1;
      var parts = host.split('.').filter(Boolean);
      if (parts.length - suffixParts > 1) {
        return { ok: false, domain: '', reason: 'subdomain', raw: raw };
      }
      return { ok: false, domain: '', reason: 'invalid', raw: raw };
    }
    return { ok: true, domain: host, reason: '', raw: raw };
  }

  function parseDomains(raw) {
    var text = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var lines = text.split(/\n+/);
    var validMap = {};
    var validOrder = [];
    var invalid = [];
    lines.forEach(function (line) {
      line = line.trim();
      if (!line) return;
      line.split(/\s*,\s*/).forEach(function (chunk) {
        chunk = chunk.trim();
        if (!chunk) return;
        var a = analyzeLine(chunk);
        if (a.ok) {
          if (!validMap[a.domain]) {
            validMap[a.domain] = true;
            validOrder.push(a.domain);
          }
        } else {
          invalid.push(a);
        }
      });
    });
    return { valid: validOrder, invalid: invalid, validText: validOrder.join('\n') };
  }

  function initDomainsPaste(root) {
    var ta = root.querySelector('[data-domains-input]');
    var btn = root.querySelector('[data-clean-domains]');
    var status = root.querySelector('[data-domains-status]');
    if (!ta) return;

    function updateStatus() {
      var parsed = parseDomains(ta.value);
      if (!status) return;
      if (parsed.invalid.length === 0) {
        status.hidden = true;
        status.textContent = '';
        status.classList.remove('domains-paste-warn');
        return;
      }
      status.hidden = false;
      status.classList.add('domains-paste-warn');
      status.textContent = parsed.invalid.length + ' invalid line' +
        (parsed.invalid.length === 1 ? '' : 's') +
        ' — click Clean errors to remove them.';
    }

    if (btn) {
      btn.addEventListener('click', function () {
        var parsed = parseDomains(ta.value);
        ta.value = parsed.validText;
        updateStatus();
        ta.focus();
      });
    }
    ta.addEventListener('input', updateStatus);
    updateStatus();

    var form = ta.closest('form');
    if (form && !form.__domainsPasteGuard) {
      form.__domainsPasteGuard = true;
      form.addEventListener('submit', function (e) {
        var blocks = form.querySelectorAll('[data-domains-paste] [data-domains-input]');
        for (var i = 0; i < blocks.length; i++) {
          var field = blocks[i];
          var parsed = parseDomains(field.value);
          if (parsed.invalid.length > 0) {
            e.preventDefault();
            field.focus();
            var wrap = field.closest('[data-domains-paste]');
            var st = wrap && wrap.querySelector('[data-domains-status]');
            if (st) {
              st.hidden = false;
              st.classList.add('domains-paste-warn');
              st.textContent = 'Fix or Clean errors before continuing (' +
                parsed.invalid.length + ' invalid).';
            }
            return;
          }
          field.value = parsed.validText;
        }
      });
    }
  }

  function norm(s) {
    return String(s || '').toLowerCase().trim();
  }

  function filterItems(items, q) {
    q = norm(q);
    if (!q) return items.slice(0, 40);
    var starts = [];
    var contains = [];
    items.forEach(function (it) {
      var label = norm(it.label || it.value);
      if (label.indexOf(q) === 0) starts.push(it);
      else if (label.indexOf(q) !== -1) contains.push(it);
    });
    return starts.concat(contains).slice(0, 40);
  }

  function initTypeahead(root) {
    var input = root.querySelector('[data-typeahead-input]');
    var hidden = root.querySelector('[data-typeahead-value]');
    var list = root.querySelector('[data-typeahead-list]');
    var jsonEl = root.querySelector('[data-typeahead-items]');
    if (!input || !hidden || !list || !jsonEl) return;

    var items = [];
    try {
      items = JSON.parse(jsonEl.textContent || '[]') || [];
    } catch (e) {
      items = [];
    }

    var open = false;
    var active = -1;
    var filtered = [];
    var required = root.getAttribute('data-required') === '1';
    var fillLangSel = root.getAttribute('data-fill-language') || '';
    var fillRegionSel = root.getAttribute('data-fill-region') || '';

    function closeList() {
      open = false;
      active = -1;
      list.hidden = true;
      list.innerHTML = '';
    }

    function renderList() {
      list.innerHTML = '';
      if (!filtered.length) {
        list.hidden = true;
        open = false;
        return;
      }
      filtered.forEach(function (it, idx) {
        var li = document.createElement('li');
        li.className = 'typeahead-option' + (idx === active ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.textContent = it.label || it.value;
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectItem(it);
        });
        list.appendChild(li);
      });
      list.hidden = false;
      open = true;
    }

    function selectItem(it) {
      if (!it) return;
      hidden.value = it.value;
      input.value = it.label || it.value;
      closeList();
      if (fillLangSel) {
        var langRoot = document.querySelector(fillLangSel);
        if (langRoot) {
          var langHidden = null;
          var langInput = null;
          if (langRoot.matches('[data-typeahead]')) {
            langHidden = langRoot.querySelector('[data-typeahead-value]');
            langInput = langRoot.querySelector('[data-typeahead-input]');
          } else if (langRoot.tagName === 'INPUT') {
            langHidden = langRoot;
            var wrap = langRoot.closest('[data-typeahead]');
            if (wrap) langInput = wrap.querySelector('[data-typeahead-input]');
          }
          if (langHidden && it.lang && !String(langHidden.value || '').trim()) {
            langHidden.value = it.lang;
            if (langInput) langInput.value = it.lang;
          }
        }
      }
      if (fillRegionSel && it.region) {
        var regionEl = document.querySelector(fillRegionSel);
        if (regionEl && 'value' in regionEl) regionEl.value = it.region;
      }
      root.dispatchEvent(new CustomEvent('typeahead:select', { detail: it, bubbles: true }));
    }

    function pickFirstOrExact() {
      var q = norm(input.value);
      if (!q) {
        hidden.value = '';
        return false;
      }
      for (var i = 0; i < items.length; i++) {
        if (norm(items[i].value) === q || norm(items[i].label) === q) {
          selectItem(items[i]);
          return true;
        }
      }
      filtered = filterItems(items, input.value);
      if (filtered.length) {
        selectItem(filtered[0]);
        return true;
      }
      if (required) {
        hidden.value = '';
        return false;
      }
      // optional: keep typed free text
      hidden.value = input.value.trim();
      closeList();
      return true;
    }

    input.addEventListener('input', function () {
      hidden.value = '';
      filtered = filterItems(items, input.value);
      active = filtered.length ? 0 : -1;
      renderList();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!open) {
          filtered = filterItems(items, input.value);
          active = 0;
          renderList();
          return;
        }
        active = Math.min(filtered.length - 1, active + 1);
        renderList();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(0, active - 1);
        renderList();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (open && active >= 0 && filtered[active]) {
          selectItem(filtered[active]);
        } else {
          pickFirstOrExact();
        }
      } else if (e.key === 'Escape') {
        closeList();
      }
    });

    input.addEventListener('blur', function () {
      setTimeout(function () {
        closeList();
        if (required) {
          var q = norm(input.value);
          var match = null;
          for (var i = 0; i < items.length; i++) {
            if (norm(items[i].value) === q || norm(items[i].label) === q) {
              match = items[i];
              break;
            }
          }
          if (match) {
            selectItem(match);
          } else if (!hidden.value) {
            input.value = '';
            hidden.value = '';
          } else {
            input.value = hidden.value;
          }
        } else if (!hidden.value && input.value.trim()) {
          pickFirstOrExact();
        }
      }, 120);
    });

    input.addEventListener('focus', function () {
      filtered = filterItems(items, input.value);
      active = filtered.length ? 0 : -1;
      renderList();
    });

    var form = input.closest('form');
    if (form && required && !form.__typeaheadRequiredGuard) {
      form.__typeaheadRequiredGuard = true;
      form.addEventListener('submit', function (e) {
        var blocks = form.querySelectorAll('[data-typeahead][data-required="1"]');
        for (var i = 0; i < blocks.length; i++) {
          var h = blocks[i].querySelector('[data-typeahead-value]');
          var q = blocks[i].querySelector('[data-typeahead-input]');
          if (!h || !String(h.value || '').trim()) {
            e.preventDefault();
            if (q) {
              q.focus();
              if (q.setCustomValidity) {
                q.setCustomValidity('Select from the list (type, then press Enter).');
                q.reportValidity();
                q.setCustomValidity('');
              }
            }
            return;
          }
        }
      });
    }
  }

  function boot() {
    document.querySelectorAll('[data-typeahead]').forEach(initTypeahead);
    document.querySelectorAll('[data-domains-paste]').forEach(initDomainsPaste);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
