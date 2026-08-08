/**
 * Communication Team · campaign drafts
 * Rich text (bold / italic / underline / headings) + HTML clipboard copy for email paste.
 */
(function () {
  'use strict';

  function htmlToPlain(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    var text = tmp.innerText || tmp.textContent || '';
    return String(text).replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  }

  function normalizeEditorHtml(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    // Drop scripts/styles if any slipped in via paste.
    tmp.querySelectorAll('script,style,meta,link,iframe,object').forEach(function (n) {
      n.remove();
    });
    // Strip attributes from remaining nodes (keep tags only).
    tmp.querySelectorAll('*').forEach(function (el) {
      var tag = el.tagName.toLowerCase();
      var keep = {
        p: 1, br: 1, strong: 1, b: 1, em: 1, i: 1, u: 1, h1: 1, h2: 1, h3: 1, div: 1, span: 1
      };
      if (!keep[tag]) {
        var parent = el.parentNode;
        if (!parent) return;
        while (el.firstChild) {
          parent.insertBefore(el.firstChild, el);
        }
        parent.removeChild(el);
        return;
      }
      while (el.attributes.length) {
        el.removeAttribute(el.attributes[0].name);
      }
      if (tag === 'b') {
        var strong = document.createElement('strong');
        while (el.firstChild) strong.appendChild(el.firstChild);
        el.parentNode.replaceChild(strong, el);
      } else if (tag === 'i') {
        var em = document.createElement('em');
        while (el.firstChild) em.appendChild(el.firstChild);
        el.parentNode.replaceChild(em, el);
      }
    });
    // Unwrap bare div/span wrappers.
    tmp.querySelectorAll('div,span').forEach(function (el) {
      var parent = el.parentNode;
      if (!parent) return;
      while (el.firstChild) {
        parent.insertBefore(el.firstChild, el);
      }
      parent.removeChild(el);
    });
    return tmp.innerHTML.trim();
  }

  function copyHtmlAndPlain(html, plain) {
    var cleanHtml = normalizeEditorHtml(html);
    var cleanPlain = plain || htmlToPlain(cleanHtml);
    if (navigator.clipboard && window.ClipboardItem) {
      try {
        return navigator.clipboard.write([
          new ClipboardItem({
            'text/html': new Blob([cleanHtml], { type: 'text/html' }),
            'text/plain': new Blob([cleanPlain], { type: 'text/plain' }),
          }),
        ]);
      } catch (err) {
        // fall through
      }
    }
    return new Promise(function (resolve, reject) {
      var host = document.createElement('div');
      host.setAttribute('contenteditable', 'true');
      host.style.position = 'fixed';
      host.style.left = '-9999px';
      host.style.top = '0';
      host.innerHTML = cleanHtml;
      document.body.appendChild(host);
      var range = document.createRange();
      range.selectNodeContents(host);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      try {
        if (!document.execCommand('copy')) {
          reject(new Error('Copy failed'));
          return;
        }
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        sel.removeAllRanges();
        document.body.removeChild(host);
      }
    });
  }

  function setStatus(card, msg, isError) {
    if (!card) return;
    var el = card.querySelector('[data-camp-draft-status]');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.classList.remove('is-error');
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
  }

  function syncEditor(editor) {
    var surface = editor.querySelector('[data-camp-draft-surface]');
    var sync = editor.querySelector('[data-camp-draft-sync]');
    if (!surface || !sync) return '';
    var html = normalizeEditorHtml(surface.innerHTML);
    var plain = htmlToPlain(html);
    if (!plain) {
      html = '';
      surface.innerHTML = '';
    }
    sync.value = html;
    surface.classList.toggle('is-empty', !plain);
    return html;
  }

  function runCommand(cmd) {
    var map = {
      bold: function () { document.execCommand('bold', false, null); },
      italic: function () { document.execCommand('italic', false, null); },
      underline: function () { document.execCommand('underline', false, null); },
      h2: function () { document.execCommand('formatBlock', false, 'h2'); },
      h3: function () { document.execCommand('formatBlock', false, 'h3'); },
      p: function () { document.execCommand('formatBlock', false, 'p'); },
    };
    if (map[cmd]) map[cmd]();
  }

  function initEditors() {
    document.querySelectorAll('[data-camp-draft-editor]').forEach(function (editor) {
      var surface = editor.querySelector('[data-camp-draft-surface]');
      if (!surface) return;

      syncEditor(editor);

      editor.querySelectorAll('[data-camp-draft-cmd]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) {
          // Keep selection in the editor.
          e.preventDefault();
        });
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          surface.focus();
          runCommand(btn.getAttribute('data-camp-draft-cmd') || '');
          syncEditor(editor);
        });
      });

      surface.addEventListener('input', function () {
        syncEditor(editor);
      });
      surface.addEventListener('blur', function () {
        syncEditor(editor);
      });

      surface.addEventListener('paste', function (e) {
        e.preventDefault();
        var clip = e.clipboardData || window.clipboardData;
        var html = clip ? clip.getData('text/html') : '';
        var text = clip ? clip.getData('text/plain') : '';
        var insert = '';
        if (html && /<[a-zA-Z]/.test(html)) {
          insert = normalizeEditorHtml(html);
        } else if (text) {
          insert = text
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n')
            .split(/\n{2,}/)
            .map(function (block) {
              var safe = block
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\n/g, '<br>');
              return '<p>' + safe + '</p>';
            })
            .join('');
        }
        if (!insert) return;
        document.execCommand('insertHTML', false, insert);
        syncEditor(editor);
      });

      var form = editor.closest('form');
      if (form && !form.dataset.campDraftBound) {
        form.dataset.campDraftBound = '1';
        form.addEventListener('submit', function (e) {
          var html = syncEditor(editor);
          if (!htmlToPlain(html)) {
            e.preventDefault();
            surface.focus();
            surface.classList.add('is-invalid');
            window.setTimeout(function () {
              surface.classList.remove('is-invalid');
            }, 1200);
          }
        });
      }
    });
  }

  function initCopyButtons() {
    document.querySelectorAll('[data-camp-draft-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = btn.closest('[data-camp-draft-card]');
        if (!card) return;
        var htmlEl = card.querySelector('[data-camp-draft-html]');
        var html = htmlEl ? String(htmlEl.innerHTML || '') : '';
        var plain = htmlToPlain(html);
        if (!plain) {
          setStatus(card, 'This draft is empty.', true);
          return;
        }
        var title = String((card.querySelector('.camp-draft-title') || {}).textContent || 'draft').trim();
        btn.disabled = true;
        copyHtmlAndPlain(html, plain)
          .then(function () {
            setStatus(card, 'Copied “' + title + '” with formatting — paste into your email client.');
            var prev = btn.textContent;
            btn.textContent = 'Copied';
            window.setTimeout(function () {
              btn.textContent = prev || 'Copy';
              btn.disabled = false;
            }, 1400);
          })
          .catch(function () {
            setStatus(card, 'Could not copy. Select the text manually.', true);
            btn.disabled = false;
          });
      });
    });
  }

  initEditors();
  initCopyButtons();
})();
