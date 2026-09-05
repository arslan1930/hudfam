/**
 * Communication Team · campaign drafts
 * Rich text + compressed inline images + HTML clipboard copy for email paste.
 */
(function () {
  'use strict';

  var MAX_IMG_WIDTH = 1200;
  var MAX_IMG_BYTES = 900 * 1024; // target compressed binary size
  var DEFAULT_MAX_IMAGES = 6;

  function htmlToPlain(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    tmp.querySelectorAll('img').forEach(function (img) {
      img.replaceWith(document.createTextNode('\n[image]\n'));
    });
    var text = tmp.innerText || tmp.textContent || '';
    return String(text).replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  }

  function countImages(root) {
    if (!root) return 0;
    if (typeof root === 'string') {
      var m = root.match(/<img\b/gi);
      return m ? m.length : 0;
    }
    return root.querySelectorAll ? root.querySelectorAll('img').length : 0;
  }

  function isSafeDataImageSrc(src) {
    if (!src || typeof src !== 'string') return false;
    var clean = src.replace(/\s+/g, '');
    return /^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$/i.test(clean)
      && clean.length <= 2500000;
  }

  function normalizeEditorHtml(html, maxImages) {
    maxImages = maxImages || DEFAULT_MAX_IMAGES;
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    tmp.querySelectorAll('script,style,meta,link,iframe,object').forEach(function (n) {
      n.remove();
    });

    var keptImgs = 0;
    tmp.querySelectorAll('*').forEach(function (el) {
      var tag = el.tagName.toLowerCase();
      var keep = {
        p: 1, br: 1, strong: 1, b: 1, em: 1, i: 1, u: 1,
        h1: 1, h2: 1, h3: 1, div: 1, span: 1, img: 1,
        a: 1, ul: 1, ol: 1, li: 1
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

      if (tag === 'img') {
        var src = el.getAttribute('src') || '';
        var alt = el.getAttribute('alt') || '';
        while (el.attributes.length) {
          el.removeAttribute(el.attributes[0].name);
        }
        if (!isSafeDataImageSrc(src) || keptImgs >= maxImages) {
          el.remove();
          return;
        }
        el.setAttribute('src', src.replace(/\s+/g, ''));
        el.setAttribute('alt', String(alt).slice(0, 120));
        keptImgs += 1;
        return;
      }

      if (tag === 'a') {
        var href = String(el.getAttribute('href') || '').trim();
        while (el.attributes.length) {
          el.removeAttribute(el.attributes[0].name);
        }
        if (!/^https?:\/\//i.test(href) || /^(javascript|data|vbscript):/i.test(href)) {
          var linkParent = el.parentNode;
          if (!linkParent) return;
          while (el.firstChild) {
            linkParent.insertBefore(el.firstChild, el);
          }
          linkParent.removeChild(el);
          return;
        }
        el.setAttribute('href', href);
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

  function editorHasContent(html) {
    return !!htmlToPlain(html) || countImages(html) > 0;
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

  function setEditorStatus(editor, msg, isError) {
    var el = editor.querySelector('[data-camp-draft-editor-status]');
    if (!el) {
      el = document.createElement('p');
      el.className = 'help camp-draft-copy-status';
      el.setAttribute('data-camp-draft-editor-status', '');
      var hint = editor.querySelector('.camp-draft-image-hint');
      if (hint && hint.parentNode) {
        hint.parentNode.insertBefore(el, hint.nextSibling);
      } else {
        editor.appendChild(el);
      }
    }
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
    var maxImages = parseInt(editor.getAttribute('data-max-images') || '', 10) || DEFAULT_MAX_IMAGES;
    var html = normalizeEditorHtml(surface.innerHTML, maxImages);
    if (!editorHasContent(html)) {
      html = '';
      surface.innerHTML = '';
    } else if (html !== surface.innerHTML) {
      // Only rewrite when sanitizer changed markup (avoid caret jumps on every keystroke).
      var plainBefore = htmlToPlain(surface.innerHTML);
      var plainAfter = htmlToPlain(html);
      var imgsBefore = countImages(surface);
      var imgsAfter = countImages(html);
      if (plainBefore !== plainAfter || imgsBefore !== imgsAfter || /on\w+=|javascript:/i.test(surface.innerHTML)) {
        surface.innerHTML = html;
      }
    }
    sync.value = html;
    surface.classList.toggle('is-empty', !editorHasContent(html));
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
      ul: function () { document.execCommand('insertUnorderedList', false, null); },
      ol: function () { document.execCommand('insertOrderedList', false, null); },
      link: function () {
        var href = window.prompt('Link URL (https://…)', 'https://');
        if (!href) return;
        href = String(href).trim();
        if (!/^https?:\/\//i.test(href)) {
          window.alert('Only http:// or https:// links are allowed.');
          return;
        }
        document.execCommand('createLink', false, href);
      },
    };
    if (map[cmd]) map[cmd]();
  }

  function loadImageFromFile(file) {
    return new Promise(function (resolve, reject) {
      if (!file || !/^image\//i.test(file.type || '')) {
        reject(new Error('Not an image'));
        return;
      }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('Could not read image'));
      };
      img.src = url;
    });
  }

  function canvasToBlob(canvas, type, quality) {
    return new Promise(function (resolve) {
      if (canvas.toBlob) {
        canvas.toBlob(function (blob) { resolve(blob); }, type, quality);
        return;
      }
      try {
        var dataUrl = canvas.toDataURL(type, quality);
        var parts = dataUrl.split(',');
        var bin = atob(parts[1] || '');
        var arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        resolve(new Blob([arr], { type: type }));
      } catch (err) {
        resolve(null);
      }
    });
  }

  function blobToDataUrl(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(String(reader.result || '')); };
      reader.onerror = function () { reject(new Error('Read failed')); };
      reader.readAsDataURL(blob);
    });
  }

  /**
   * Resize + JPEG-compress an image File/Blob for email-sized data URIs.
   */
  function compressImageFile(file) {
    return loadImageFromFile(file).then(function (img) {
      var w = img.naturalWidth || img.width || 1;
      var h = img.naturalHeight || img.height || 1;
      var scale = Math.min(1, MAX_IMG_WIDTH / w);
      var cw = Math.max(1, Math.round(w * scale));
      var ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw;
      canvas.height = ch;
      var ctx = canvas.getContext('2d');
      if (!ctx) return Promise.reject(new Error('No canvas'));
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, cw, ch);
      ctx.drawImage(img, 0, 0, cw, ch);

      var qualities = [0.82, 0.72, 0.62, 0.5, 0.4];
      var i = 0;

      function next() {
        if (i >= qualities.length) {
          return canvasToBlob(canvas, 'image/jpeg', 0.35).then(function (blob) {
            if (!blob) return Promise.reject(new Error('Compress failed'));
            return blobToDataUrl(blob);
          });
        }
        var q = qualities[i++];
        return canvasToBlob(canvas, 'image/jpeg', q).then(function (blob) {
          if (!blob) return next();
          if (blob.size <= MAX_IMG_BYTES || i >= qualities.length) {
            return blobToDataUrl(blob);
          }
          return next();
        });
      }

      return next().then(function (dataUrl) {
        if (!isSafeDataImageSrc(dataUrl)) {
          throw new Error('Compressed image still too large');
        }
        return dataUrl;
      });
    });
  }

  function insertHtmlAtCursor(surface, html) {
    surface.focus();
    try {
      if (document.execCommand('insertHTML', false, html)) {
        return;
      }
    } catch (err) {
      // fall through
    }
    surface.innerHTML = (surface.innerHTML || '') + html;
  }

  function insertCompressedImages(editor, files) {
    var surface = editor.querySelector('[data-camp-draft-surface]');
    if (!surface) return Promise.resolve();
    var maxImages = parseInt(editor.getAttribute('data-max-images') || '', 10) || DEFAULT_MAX_IMAGES;
    var list = Array.prototype.slice.call(files || []).filter(function (f) {
      return f && /^image\//i.test(f.type || '');
    });
    if (!list.length) {
      setEditorStatus(editor, 'Choose an image file (PNG, JPG, WebP, GIF).', true);
      return Promise.resolve();
    }

    var existing = countImages(surface);
    var room = Math.max(0, maxImages - existing);
    if (room < 1) {
      setEditorStatus(editor, 'This draft already has ' + maxImages + ' images (max).', true);
      return Promise.resolve();
    }
    if (list.length > room) {
      list = list.slice(0, room);
      setEditorStatus(editor, 'Only ' + room + ' more image(s) allowed — compressing…');
    } else {
      setEditorStatus(editor, 'Compressing image' + (list.length > 1 ? 's' : '') + '…');
    }

    var chain = Promise.resolve();
    list.forEach(function (file) {
      chain = chain.then(function () {
        return compressImageFile(file).then(function (dataUrl) {
          var alt = String(file.name || 'image').replace(/\.[^.]+$/, '').slice(0, 80);
          var safeAlt = alt.replace(/[<>&"]/g, '');
          insertHtmlAtCursor(
            surface,
            '<p><img src="' + dataUrl + '" alt="' + safeAlt + '"></p>'
          );
          syncEditor(editor);
        });
      });
    });

    return chain
      .then(function () {
        setEditorStatus(editor, 'Image added — Copy will include it for email paste.');
        window.setTimeout(function () { setEditorStatus(editor, ''); }, 2200);
      })
      .catch(function () {
        setEditorStatus(editor, 'Could not add image. Try a smaller file.', true);
      });
  }

  function initEditors() {
    document.querySelectorAll('[data-camp-draft-editor]').forEach(function (editor) {
      var surface = editor.querySelector('[data-camp-draft-surface]');
      if (!surface) return;
      var maxImages = parseInt(editor.getAttribute('data-max-images') || '', 10) || DEFAULT_MAX_IMAGES;
      var fileInput = editor.querySelector('[data-camp-draft-image-input]');
      var imageBtn = editor.querySelector('[data-camp-draft-image]');

      syncEditor(editor);

      editor.querySelectorAll('[data-camp-draft-cmd]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
        });
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          surface.focus();
          runCommand(btn.getAttribute('data-camp-draft-cmd') || '');
          syncEditor(editor);
        });
      });

      if (imageBtn && fileInput) {
        imageBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        imageBtn.addEventListener('click', function (e) {
          e.preventDefault();
          fileInput.click();
        });
        fileInput.addEventListener('change', function () {
          var files = fileInput.files;
          insertCompressedImages(editor, files).then(function () {
            fileInput.value = '';
          });
        });
      }

      surface.addEventListener('input', function () {
        // Lightweight empty-state toggle without full rewrite.
        var sync = editor.querySelector('[data-camp-draft-sync]');
        var html = surface.innerHTML;
        if (sync) sync.value = normalizeEditorHtml(html, maxImages);
        surface.classList.toggle('is-empty', !editorHasContent(html));
      });
      surface.addEventListener('blur', function () {
        syncEditor(editor);
      });

      surface.addEventListener('paste', function (e) {
        var clip = e.clipboardData || window.clipboardData;
        if (!clip) return;

        var imageFiles = [];
        if (clip.items) {
          for (var i = 0; i < clip.items.length; i++) {
            var item = clip.items[i];
            if (item && item.kind === 'file' && /^image\//i.test(item.type || '')) {
              var f = item.getAsFile();
              if (f) imageFiles.push(f);
            }
          }
        }
        if (!imageFiles.length && clip.files && clip.files.length) {
          for (var j = 0; j < clip.files.length; j++) {
            if (/^image\//i.test(clip.files[j].type || '')) {
              imageFiles.push(clip.files[j]);
            }
          }
        }

        if (imageFiles.length) {
          e.preventDefault();
          insertCompressedImages(editor, imageFiles);
          return;
        }

        e.preventDefault();
        var html = clip.getData('text/html') || '';
        var text = clip.getData('text/plain') || '';
        var insert = '';
        if (html && /<[a-zA-Z]/.test(html)) {
          // Drop remote/file images from HTML paste; keep data URIs only (already compressed ideally).
          insert = normalizeEditorHtml(html, maxImages);
          // If pasted HTML had only unsafe images, fall back to text.
          if (!editorHasContent(insert) && text) {
            insert = '';
          }
        }
        if (!insert && text) {
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
        insertHtmlAtCursor(surface, insert);
        syncEditor(editor);
      });

      // Drag & drop images onto the editor.
      surface.addEventListener('dragover', function (e) {
        if (e.dataTransfer && e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) {
          e.preventDefault();
          surface.classList.add('is-drop-target');
        }
      });
      surface.addEventListener('dragleave', function () {
        surface.classList.remove('is-drop-target');
      });
      surface.addEventListener('drop', function (e) {
        surface.classList.remove('is-drop-target');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        var imgs = [];
        for (var k = 0; k < files.length; k++) {
          if (/^image\//i.test(files[k].type || '')) imgs.push(files[k]);
        }
        if (!imgs.length) return;
        e.preventDefault();
        insertCompressedImages(editor, imgs);
      });

      var form = editor.closest('form');
      if (form && !form.dataset.campDraftBound) {
        form.dataset.campDraftBound = '1';
        form.addEventListener('submit', function (e) {
          var html = syncEditor(editor);
          if (!editorHasContent(html)) {
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

  function expandTokens(text, card) {
    var domain = '';
    var country = '';
    var language = '';
    var name = '';
    if (card) {
      domain = String(card.getAttribute('data-token-domain') || '');
      country = String(card.getAttribute('data-token-country') || '');
      language = String(card.getAttribute('data-token-language') || '');
      name = String(card.getAttribute('data-token-name') || '');
    }
    return String(text || '')
      .replace(/\{domain\}/gi, domain)
      .replace(/\{site\}/gi, domain)
      .replace(/\{country\}/gi, country)
      .replace(/\{language\}/gi, language)
      .replace(/\{name\}/gi, name);
  }

  function cardSubject(card) {
    if (!card) return '';
    return expandTokens(String(card.getAttribute('data-camp-draft-subject') || ''), card).trim();
  }

  function copyPlainText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        if (!document.execCommand('copy')) {
          reject(new Error('Copy failed'));
          return;
        }
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  function initCopyButtons() {
    document.querySelectorAll('[data-camp-draft-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = btn.closest('[data-camp-draft-card]');
        if (!card) return;
        var htmlEl = card.querySelector('[data-camp-draft-html]');
        var html = expandTokens(htmlEl ? String(htmlEl.innerHTML || '') : '', card);
        var plain = htmlToPlain(html);
        var subject = cardSubject(card);
        if (subject) {
          plain = 'Subject: ' + subject + '\n\n' + plain;
          html = '<p><strong>Subject:</strong> ' + subject.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</p>' + html;
        }
        if (!editorHasContent(html) && !subject) {
          setStatus(card, 'This draft is empty.', true);
          return;
        }
        var title = String((card.querySelector('.camp-draft-title') || {}).textContent || 'draft').trim();
        var hasImg = countImages(html) > 0;
        btn.disabled = true;
        copyHtmlAndPlain(html, plain)
          .then(function () {
            setStatus(
              card,
              hasImg
                ? 'Copied “' + title + '” with images — paste into your email client.'
                : 'Copied “' + title + '” with formatting — paste into your email client.'
            );
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

    document.querySelectorAll('[data-camp-draft-copy-plain]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = btn.closest('[data-camp-draft-card]');
        if (!card) return;
        var htmlEl = card.querySelector('[data-camp-draft-html]');
        var html = expandTokens(htmlEl ? String(htmlEl.innerHTML || '') : '', card);
        var plain = htmlToPlain(html);
        var subject = cardSubject(card);
        if (subject) {
          plain = 'Subject: ' + subject + '\n\n' + plain;
        }
        if (!plain) {
          setStatus(card, 'This draft is empty.', true);
          return;
        }
        var title = String((card.querySelector('.camp-draft-title') || {}).textContent || 'draft').trim();
        btn.disabled = true;
        copyPlainText(plain)
          .then(function () {
            setStatus(card, 'Copied “' + title + '” as plain text.');
            var prev = btn.textContent;
            btn.textContent = 'Copied';
            window.setTimeout(function () {
              btn.textContent = prev || 'Copy plain';
              btn.disabled = false;
            }, 1400);
          })
          .catch(function () {
            setStatus(card, 'Could not copy plain text.', true);
            btn.disabled = false;
          });
      });
    });
  }

  function insertTokenAtCursor(target, token) {
    if (!target || !token) return;
    if (target.getAttribute && target.getAttribute('contenteditable') === 'true') {
      target.focus();
      try {
        if (document.execCommand('insertText', false, token)) return;
      } catch (err) { /* fall through */ }
      target.innerHTML = (target.innerHTML || '') + token;
      return;
    }
    if (typeof target.selectionStart === 'number') {
      var start = target.selectionStart;
      var end = target.selectionEnd;
      var val = String(target.value || '');
      target.value = val.slice(0, start) + token + val.slice(end);
      var pos = start + token.length;
      target.setSelectionRange(pos, pos);
      target.focus();
      try {
        target.dispatchEvent(new Event('input', { bubbles: true }));
      } catch (e2) { /* ignore */ }
      return;
    }
    target.value = String(target.value || '') + token;
  }

  function initTokenButtons() {
    document.querySelectorAll('[data-camp-draft-token]').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var token = btn.getAttribute('data-camp-draft-token') || '';
        var which = btn.getAttribute('data-camp-draft-token-target') || '';
        var target = null;
        if (which === 'body') {
          var editor = btn.closest('form') && btn.closest('form').querySelector('[data-camp-draft-editor]');
          target = editor && editor.querySelector('[data-camp-draft-surface]');
        } else if (which) {
          target = document.getElementById(which)
            || (btn.closest('form') && btn.closest('form').querySelector('[data-camp-draft-subject-input]'));
        }
        if (!target) {
          target = btn.closest('form') && btn.closest('form').querySelector('[data-camp-draft-subject-input]');
        }
        insertTokenAtCursor(target, token);
        if (target && target.closest) {
          var ed = target.closest('[data-camp-draft-editor]');
          if (ed) syncEditor(ed);
        }
      });
    });
  }

  initEditors();
  initCopyButtons();
  initTokenButtons();
  initDraftSearch();

  function initDraftSearch() {
    var input = document.querySelector('[data-camp-draft-search]');
    var cards = document.querySelectorAll('[data-camp-draft-card]');
    var empty = document.getElementById('camp-drafts-search-empty');
    if (!input) return;

    function apply() {
      var q = String(input.value || '').trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (card) {
        var hay = String(card.getAttribute('data-camp-draft-haystack') || '').toLowerCase();
        var ok = !q || hay.indexOf(q) !== -1;
        card.hidden = !ok;
        if (ok) shown += 1;
      });
      if (empty) {
        empty.hidden = !(q && shown === 0 && cards.length > 0);
      }
    }

    input.addEventListener('input', apply);
    apply();
  }
})();
