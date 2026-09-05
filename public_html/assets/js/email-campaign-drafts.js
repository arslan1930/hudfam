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

  function activeVariant(card) {
    if (!card) return null;
    return card.querySelector('[data-camp-draft-variant].is-on')
      || card.querySelector('[data-camp-draft-variant]')
      || card;
  }

  function cardSubject(card, variant) {
    if (!card) return '';
    var v = variant || activeVariant(card);
    var raw = '';
    if (v && v.getAttribute) {
      raw = String(v.getAttribute('data-camp-draft-subject') || '');
    }
    if (!raw) {
      raw = String(card.getAttribute('data-camp-draft-subject') || '');
    }
    return expandTokens(raw, card).trim();
  }

  function cardTitle(card, variant) {
    var v = variant || activeVariant(card);
    if (v && v.getAttribute) {
      var t = String(v.getAttribute('data-camp-draft-title') || '').trim();
      if (t) return t;
    }
    return String((card.querySelector('.camp-draft-title') || {}).textContent || 'draft').trim();
  }

  function variantHtml(card, variant) {
    var v = variant || activeVariant(card);
    var htmlEl = (v && v.querySelector)
      ? v.querySelector('[data-camp-draft-html]')
      : card.querySelector('[data-camp-draft-html]');
    return expandTokens(htmlEl ? String(htmlEl.innerHTML || '') : '', card);
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
        var variant = btn.closest('[data-camp-draft-variant]') || activeVariant(card);
        var html = variantHtml(card, variant);
        var plain = htmlToPlain(html);
        var subject = cardSubject(card, variant);
        if (subject) {
          plain = 'Subject: ' + subject + '\n\n' + plain;
          html = '<p><strong>Subject:</strong> ' + subject.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</p>' + html;
        }
        if (!editorHasContent(html) && !subject) {
          setStatus(card, 'This draft is empty.', true);
          return;
        }
        var title = cardTitle(card, variant);
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
        var variant = btn.closest('[data-camp-draft-variant]') || activeVariant(card);
        var html = variantHtml(card, variant);
        var plain = htmlToPlain(html);
        var subject = cardSubject(card, variant);
        if (subject) {
          plain = 'Subject: ' + subject + '\n\n' + plain;
        }
        if (!plain) {
          setStatus(card, 'This draft is empty.', true);
          return;
        }
        var title = cardTitle(card, variant);
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
  initAbcTabs();
  initExpandButtons();
  initDraftSearch();

  function initAbcTabs() {
    document.querySelectorAll('[data-camp-draft-abc]').forEach(function (bar) {
      var card = bar.closest('[data-camp-draft-card]');
      if (!card) return;
      bar.querySelectorAll('[data-camp-draft-abc-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          activateVariant(card, tab.getAttribute('data-camp-draft-abc-tab') || '');
        });
      });
    });
  }

  function activateVariant(card, letter) {
    if (!card || !letter) return;
    card.querySelectorAll('[data-camp-draft-variant]').forEach(function (v) {
      v.classList.toggle('is-on', v.getAttribute('data-camp-draft-variant') === letter);
    });
    var bar = card.querySelector('[data-camp-draft-abc]');
    if (!bar) return;
    bar.querySelectorAll('[data-camp-draft-abc-tab]').forEach(function (t) {
      var on = t.getAttribute('data-camp-draft-abc-tab') === letter;
      t.classList.toggle('is-on', on);
      t.classList.toggle('secondary', !on);
      t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function initExpandButtons() {
    document.querySelectorAll('[data-camp-draft-expand]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var variant = btn.closest('[data-camp-draft-variant]');
        var preview = variant
          ? variant.querySelector('[data-camp-draft-preview]')
          : null;
        if (!preview) return;
        var on = preview.classList.toggle('is-expanded');
        btn.textContent = on ? 'Show less' : 'Show full';
      });
    });
  }

  function initDraftSearch() {
    var input = document.querySelector('[data-camp-draft-search]');
    var form = document.querySelector('[data-camp-draft-search-form]');
    var suggestEl = document.querySelector('[data-camp-draft-suggest]');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-camp-draft-card]'));
    var empty = document.getElementById('camp-drafts-search-empty');
    var meta = document.querySelector('[data-camp-draft-search-meta]');
    var folderChips = Array.prototype.slice.call(document.querySelectorAll('[data-camp-draft-chip="folder"]'));
    var catChips = Array.prototype.slice.call(document.querySelectorAll('[data-camp-draft-chip="category"]'));
    if (!input) return;

    var suggestions = [];
    var activeIndex = -1;
    var matchIndex = -1;
    var matchCards = [];

    function activeChipValue(chips) {
      var active = chips.filter(function (c) { return !c.classList.contains('secondary'); })[0];
      return active ? String(active.getAttribute('data-camp-draft-chip-value') || '') : '';
    }

    function setChipActive(chips, value) {
      chips.forEach(function (c) {
        var on = String(c.getAttribute('data-camp-draft-chip-value') || '') === String(value);
        c.classList.toggle('secondary', !on);
      });
    }

    function hideSuggest() {
      if (!suggestEl) return;
      suggestEl.hidden = true;
      suggestEl.innerHTML = '';
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
    }

    function cardMatches(card, q, ignoreChips, folder, cat) {
      var hay = String(card.getAttribute('data-camp-draft-haystack') || '').toLowerCase();
      if (q && hay.indexOf(q) === -1) return false;
      if (ignoreChips) return true;
      if (cat && String(card.getAttribute('data-camp-draft-category') || '') !== cat) return false;
      if (folder === '-1' || folder === -1) {
        if (String(card.getAttribute('data-camp-draft-folder') || '0') !== '0') return false;
      } else if (folder && folder !== '0' && folder !== 0) {
        if (String(card.getAttribute('data-camp-draft-folder') || '') !== String(folder)) return false;
      }
      return true;
    }

    function firstMatchingLetter(card, q) {
      if (!q) return '';
      var variants = card.querySelectorAll('[data-camp-draft-variant]');
      for (var i = 0; i < variants.length; i++) {
        var v = variants[i];
        var bits = [
          v.getAttribute('data-camp-draft-title') || '',
          v.getAttribute('data-camp-draft-subject') || '',
          v.textContent || ''
        ].join(' ').toLowerCase();
        if (bits.indexOf(q) !== -1) {
          return String(v.getAttribute('data-camp-draft-variant') || '');
        }
      }
      return '';
    }

    function apply() {
      var q = String(input.value || '').trim().toLowerCase();
      var ignoreChips = q !== '';
      var folder = activeChipValue(folderChips);
      var cat = activeChipValue(catChips);
      var shown = 0;
      matchCards = [];
      cards.forEach(function (card) {
        var ok = cardMatches(card, q, ignoreChips, folder, cat);
        card.hidden = !ok;
        if (ok) {
          shown += 1;
          if (q) matchCards.push(card);
        }
      });
      if (empty) {
        empty.hidden = !(shown === 0 && cards.length > 0);
      }
      if (meta) {
        if (q) {
          meta.textContent = shown === 0
            ? '0 matches'
            : (shown + ' of ' + cards.length + (matchIndex >= 0 ? ' · ' + (matchIndex + 1) : ''));
        } else if (ignoreChips) {
          meta.textContent = '';
        } else {
          meta.textContent = shown === cards.length ? '' : (shown + ' shown');
        }
      }
      return shown;
    }

    function renderSuggest() {
      if (!suggestEl) return;
      suggestEl.innerHTML = '';
      if (!suggestions.length) {
        hideSuggest();
        return;
      }
      suggestions.forEach(function (item, idx) {
        var li = document.createElement('li');
        li.className = 'swe-admin-delete-item' + (idx === activeIndex ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('data-index', String(idx));
        var main = document.createElement('div');
        main.className = 'swe-admin-delete-item-main';
        main.textContent = item.title;
        var metaEl = document.createElement('div');
        metaEl.className = 'swe-admin-delete-item-meta';
        metaEl.textContent = item.meta;
        if (item.letters) {
          var tag = document.createElement('span');
          tag.className = 'swe-admin-delete-match';
          tag.textContent = item.letters;
          li.appendChild(tag);
        }
        li.appendChild(main);
        li.appendChild(metaEl);
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectSuggestion(idx);
        });
        suggestEl.appendChild(li);
      });
      suggestEl.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function buildSuggestions() {
      var q = String(input.value || '').trim().toLowerCase();
      suggestions = [];
      if (q.length < 1) {
        hideSuggest();
        return;
      }
      cards.forEach(function (card) {
        if (!cardMatches(card, q, true, '0', '')) return;
        var title = String(card.getAttribute('data-camp-draft-suggest-title') || '').trim()
          || String((card.querySelector('.camp-draft-title') || {}).textContent || '').trim();
        if (!title) return;
        var folderTag = card.querySelector('.camp-draft-folder-tag');
        var catTag = card.querySelector('.swe-status-badge');
        var letters = [];
        card.querySelectorAll('[data-camp-draft-abc-tab]').forEach(function (t) {
          letters.push(t.getAttribute('data-camp-draft-abc-tab') || '');
        });
        suggestions.push({
          card: card,
          title: title,
          meta: [folderTag ? folderTag.textContent : '', catTag ? catTag.textContent : '']
            .filter(Boolean).join(' · '),
          letters: letters.filter(Boolean).join(' / '),
          letter: firstMatchingLetter(card, q)
        });
      });
      suggestions = suggestions.slice(0, 12);
      activeIndex = suggestions.length ? 0 : -1;
      renderSuggest();
    }

    function clearHits() {
      cards.forEach(function (c) { c.classList.remove('sheet-search-hit'); });
    }

    function jumpTo(card, letter) {
      if (!card) return;
      if (letter) activateVariant(card, letter);
      clearHits();
      card.classList.add('sheet-search-hit');
      try {
        card.scrollIntoView({ block: 'center', behavior: 'auto' });
      } catch (err) {
        card.scrollIntoView(true);
      }
      matchIndex = matchCards.indexOf(card);
      if (meta && matchCards.length) {
        meta.textContent = (matchIndex >= 0 ? (matchIndex + 1) + ' of ' : '') + matchCards.length;
      }
    }

    function selectSuggestion(idx) {
      var item = suggestions[idx];
      if (!item) return;
      if (item.title) input.value = item.title;
      hideSuggest();
      matchIndex = -1;
      apply();
      jumpTo(item.card, item.letter);
    }

    function jump(dir) {
      var q = String(input.value || '').trim();
      apply();
      if (!matchCards.length) return;
      matchIndex = matchIndex < 0
        ? (dir > 0 ? 0 : matchCards.length - 1)
        : (matchIndex + dir + matchCards.length) % matchCards.length;
      var card = matchCards[matchIndex];
      jumpTo(card, firstMatchingLetter(card, q.toLowerCase()));
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
      });
    }

    folderChips.concat(catChips).forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        var kind = chip.getAttribute('data-camp-draft-chip');
        var value = chip.getAttribute('data-camp-draft-chip-value') || '';
        if (kind === 'folder') setChipActive(folderChips, value);
        else setChipActive(catChips, value);
        matchIndex = -1;
        apply();
        hideSuggest();
      });
    });

    input.addEventListener('input', function () {
      matchIndex = -1;
      apply();
      buildSuggestions();
    });
    input.addEventListener('search', function () {
      matchIndex = -1;
      apply();
      buildSuggestions();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        if (!suggestions.length || !suggestEl || suggestEl.hidden) return;
        e.preventDefault();
        activeIndex = (activeIndex + 1) % suggestions.length;
        renderSuggest();
        return;
      }
      if (e.key === 'ArrowUp') {
        if (!suggestions.length || !suggestEl || suggestEl.hidden) return;
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
        if (suggestEl && !suggestEl.hidden && suggestions.length && activeIndex >= 0) {
          selectSuggestion(activeIndex);
          return;
        }
        jump(e.shiftKey ? -1 : 1);
      }
    });
    input.addEventListener('blur', function () {
      window.setTimeout(hideSuggest, 150);
    });

    apply();
    if (String(input.value || '').trim()) {
      buildSuggestions();
    }
  }
})();
