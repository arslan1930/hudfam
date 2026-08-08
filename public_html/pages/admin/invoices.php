<?php
$user = require_admin();
ensure_invoice_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'delete') {
            $id = (int) post('id');
            $inv = get_invoice($id);
            if (!$inv) {
                flash('error', 'Invoice not found.');
            } else {
                delete_invoice($id);
                flash('ok', 'Deleted invoice ' . $inv['invoice_number'] . '.');
            }
            redirect('index.php?page=admin_invoices');
        }
        if ($action === 'mark_paid') {
            $id = (int) post('id');
            $inv = get_invoice($id);
            if (!$inv) {
                flash('error', 'Invoice not found.');
            } else {
                mark_invoice_payment_received($id);
                if (invoice_is_manual($inv)) {
                    flash('ok', 'Blank invoice ' . $inv['invoice_number'] . ' marked paid.');
                } else {
                    flash('ok', 'Invoice ' . $inv['invoice_number'] . ' marked paid — linked sheet rows set to Paid.');
                }
            }
            redirect('index.php?page=admin_invoices');
        }
        if ($action === 'save_note') {
            $id = (int) post('id');
            update_invoice_admin_note($id, (string) post('admin_note'));
            flash('ok', 'Note saved for invoice.');
            redirect('index.php?page=admin_invoices#inv-' . $id);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoices');
    }
}

$invoices = list_invoices();

render_header('Invoices', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Invoices', 'Build printable Topurlz bills from unpaid sheet rows that have a LIVE URL. Mark payment received to set those rows Paid.') ?></h1>
    <p class="muted">Generate from unpaid LIVE sheet rows, or open a blank invoice and fill items on the bill. Blank invoices can be <strong>Draft</strong> (still needs data) or <strong>Done</strong> (sent, waiting for payment). Mark Paid when payment arrives.</p>
  </div>
  <div class="actions">
    <a class="btn crystal" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
  </div>
</div>

<section class="card">
  <div class="invoice-list-toolbar">
    <h2 style="margin:0" class="with-info-heading"><?= label_with_info('All invoices', 'Open, mark Paid, or delete. Add a short note under the invoice number — it also appears on the printable bill.') ?></h2>
    <?php if ($invoices): ?>
      <label class="sheet-search invoice-list-search" for="invoice-search">
        <span class="visually-hidden">Search invoices</span>
        <input id="invoice-search" type="search" placeholder="Search…"
               autocomplete="off" spellcheck="false" data-no-draft
               title="Type to filter · Enter = next match · Shift+Enter = previous">
        <span class="sheet-search-meta muted" data-invoice-search-meta hidden></span>
      </label>
    <?php endif; ?>
  </div>
  <?php if (!$invoices): ?>
    <div class="empty-state">
      <p>No invoices yet. Generate one from unpaid completed articles on a client sheet.</p>
      <a class="btn crystal" href="index.php?page=admin_invoice_manual">Blank invoice</a>
      <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="invoice-list-table" id="invoice-list-table">
        <thead>
          <tr>
            <th>Invoice No.</th>
            <th>Date</th>
            <th>Client</th>
            <th>Items</th>
            <th class="num">Total</th>
            <th>Payment</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
          <?php
            $paid = invoice_is_paid($inv);
            $manual = invoice_is_manual($inv);
            $draft = invoice_is_draft($inv);
            $clientLabel = $inv['bill_to_name'] !== '' ? $inv['bill_to_name'] : $inv['client_name'];
            $note = invoice_admin_note($inv);
            $statusBits = $paid ? 'paid payment received' : ($draft ? 'draft needs data' : 'done unpaid waiting');
          ?>
          <tr id="inv-<?= (int) $inv['id'] ?>" data-invoice-row
              data-search="<?= h(mb_strtolower(trim(
                  (string) $inv['invoice_number'] . ' '
                  . ($manual ? 'blank manual ' : '')
                  . format_invoice_date((string) $inv['invoice_date']) . ' '
                  . (string) $clientLabel . ' '
                  . (string) $inv['item_count'] . ' '
                  . format_euro($inv['total_amount']) . ' '
                  . $note . ' '
                  . $statusBits
              ))) ?>">
            <td data-invoice-cell>
              <strong><?= h($inv['invoice_number']) ?></strong>
              <?php if ($manual): ?>
                <span class="invoice-manual-tag">(blank)</span>
              <?php endif; ?>
              <?php if ($manual && $draft): ?>
                <span class="invoice-pay-badge is-draft">Draft</span>
              <?php elseif ($manual && !$paid): ?>
                <span class="invoice-pay-badge is-done">Done</span>
              <?php endif; ?>
              <div class="invoice-note-box<?= $note !== '' ? ' has-note' : '' ?>" data-invoice-note-box>
                <button type="button" class="invoice-note-preview" data-note-open
                        aria-expanded="false"
                        title="<?= $note !== '' ? 'Click to read or edit note' : 'Add a note' ?>">
                  <?php if ($note !== ''): ?>
                    <span class="invoice-note-preview-text"><?= h($note) ?></span>
                  <?php else: ?>
                    <span class="invoice-note-preview-empty">note…</span>
                  <?php endif; ?>
                </button>
                <form method="post" class="invoice-list-note-form" action="index.php?page=admin_invoices"
                      data-note-panel hidden>
                  <input type="hidden" name="action" value="save_note">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <label class="visually-hidden" for="inv-note-<?= (int) $inv['id'] ?>">
                    Note for invoice <?= h($inv['invoice_number']) ?>
                  </label>
                  <textarea id="inv-note-<?= (int) $inv['id'] ?>"
                            name="admin_note" maxlength="255" rows="3"
                            placeholder="Write a note…" data-no-draft data-note-input
                            aria-label="Note for invoice <?= h($inv['invoice_number']) ?>"><?= h($note) ?></textarea>
                  <div class="invoice-note-actions">
                    <button class="btn secondary small" type="button" data-note-close>Hide</button>
                    <button class="btn small" type="submit" title="Save note">Save</button>
                  </div>
                </form>
              </div>
            </td>
            <td data-invoice-cell><?= h(format_invoice_date((string) $inv['invoice_date'])) ?></td>
            <td data-invoice-cell>
              <?= h($clientLabel) ?>
            </td>
            <td data-invoice-cell><?= (int) $inv['item_count'] ?></td>
            <td class="num" data-invoice-cell><?= h(format_euro($inv['total_amount'])) ?></td>
            <td data-invoice-cell>
              <?php if ($paid): ?>
                <span class="invoice-pay-badge is-paid" title="Payment already received">Paid</span>
              <?php elseif ($draft): ?>
                <span class="invoice-pay-badge is-draft" title="Still needs data — open and Save as done when ready">Draft</span>
              <?php else: ?>
                <form method="post" class="inline" action="index.php?page=admin_invoices"
                      onsubmit="return confirm(<?= h(json_encode(
                          'Confirm this invoice is paid?' . "\n\n"
                          . 'Invoice ' . $inv['invoice_number'] . ($manual ? ' (blank)' : '') . "\n"
                          . ($manual
                              ? 'This will mark the blank invoice as Paid.'
                              : 'This will mark the invoice as Paid and set linked sheet rows to Paid.'),
                          JSON_UNESCAPED_UNICODE
                      )) ?>);">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="btn-paid invoice-list-pay-btn" type="submit" title="Mark invoice as paid">
                    Paid
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td class="invoice-list-actions">
              <div class="invoice-list-actions-row">
                <a class="btn small" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $inv['id'] ?>">Open</a>
                <form method="post" class="inline" action="index.php?page=admin_invoices"
                      onsubmit="return confirm(<?= h(json_encode('Delete invoice ' . $inv['invoice_number'] . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="btn secondary small" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr class="sheet-search-empty" data-invoice-search-empty hidden>
            <td colspan="7" class="muted">No invoices match your search.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <script>
    (function () {
      var input = document.getElementById('invoice-search');
      if (!input) return;
      var matchRows = [];
      var matchIndex = -1;
      var meta = document.querySelector('[data-invoice-search-meta]');
      var empty = document.querySelector('[data-invoice-search-empty]');

      function clearHits() {
        document.querySelectorAll('.sheet-search-hit').forEach(function (el) {
          el.classList.remove('sheet-search-hit');
        });
      }

      function filterInvoices() {
        var q = String(input.value || '').trim().toLowerCase();
        var rows = document.querySelectorAll('[data-invoice-row]');
        var shown = 0;
        matchRows = [];
        clearHits();
        rows.forEach(function (row) {
          var hay = String(row.getAttribute('data-search') || '');
          var hit = !q || hay.indexOf(q) !== -1;
          row.hidden = !hit;
          if (hit) {
            shown++;
            if (q) matchRows.push(row);
          }
        });
        if (empty) empty.hidden = !(q && shown === 0);
        if (matchIndex >= matchRows.length) matchIndex = matchRows.length ? 0 : -1;
        if (meta) {
          if (q) {
            meta.hidden = false;
            if (!matchRows.length) {
              meta.textContent = '0 · Enter = next';
            } else if (matchIndex >= 0) {
              meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
            } else {
              meta.textContent = matchRows.length + (matchRows.length === 1 ? ' match' : ' matches')
                + ' · Enter = next';
            }
          } else {
            meta.hidden = true;
            meta.textContent = '';
            matchIndex = -1;
          }
        }
      }

      function jumpToMatch(dir) {
        var q = String(input.value || '').trim();
        if (!q) return;
        filterInvoices();
        if (!matchRows.length) return;
        if (matchIndex < 0) {
          matchIndex = dir > 0 ? 0 : matchRows.length - 1;
        } else {
          matchIndex = (matchIndex + dir + matchRows.length) % matchRows.length;
        }
        var row = matchRows[matchIndex];
        if (!row) return;
        clearHits();
        row.hidden = false;
        row.classList.add('sheet-search-hit');
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (meta) {
          meta.hidden = false;
          meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
        }
        window.setTimeout(function () {
          try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
          try {
            var len = String(input.value || '').length;
            input.setSelectionRange(len, len);
          } catch (err2) {}
        }, 0);
      }

      input.addEventListener('input', function () {
        matchIndex = -1;
        filterInvoices();
      });
      input.addEventListener('search', function () {
        matchIndex = -1;
        filterInvoices();
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          jumpToMatch(e.shiftKey ? -1 : 1);
        }
      });
    })();
    </script>
    <script>
    (function () {
      function fitTextarea(ta) {
        if (!ta) return;
        ta.style.height = 'auto';
        var next = Math.min(Math.max(ta.scrollHeight, 72), 192);
        ta.style.height = next + 'px';
      }

      function setPreview(box, text) {
        var openBtn = box.querySelector('[data-note-open]');
        if (!openBtn) return;
        text = String(text || '').trim();
        box.classList.toggle('has-note', text !== '');
        if (text !== '') {
          openBtn.innerHTML = '<span class="invoice-note-preview-text"></span>';
          openBtn.querySelector('.invoice-note-preview-text').textContent = text;
          openBtn.title = 'Click to read or edit note';
        } else {
          openBtn.innerHTML = '<span class="invoice-note-preview-empty">note…</span>';
          openBtn.title = 'Add a note';
        }
      }

      function openBox(box) {
        var panel = box.querySelector('[data-note-panel]');
        var openBtn = box.querySelector('[data-note-open]');
        var ta = box.querySelector('[data-note-input]');
        if (!panel) return;
        document.querySelectorAll('[data-invoice-note-box].is-open').forEach(function (other) {
          if (other !== box) closeBox(other, false);
        });
        box.classList.add('is-open');
        panel.hidden = false;
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
        fitTextarea(ta);
        if (ta) {
          window.setTimeout(function () {
            try { ta.focus(); } catch (err) {}
          }, 0);
        }
      }

      function closeBox(box, syncPreview) {
        if (box.getAttribute('data-note-always-open') === 'true') return;
        var panel = box.querySelector('[data-note-panel]');
        var openBtn = box.querySelector('[data-note-open]');
        var ta = box.querySelector('[data-note-input]');
        if (syncPreview !== false && ta) setPreview(box, ta.value);
        box.classList.remove('is-open');
        if (panel) panel.hidden = true;
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
      }

      document.querySelectorAll('[data-invoice-note-box]').forEach(function (box) {
        var openBtn = box.querySelector('[data-note-open]');
        var closeBtn = box.querySelector('[data-note-close]');
        var ta = box.querySelector('[data-note-input]');
        if (openBtn) {
          openBtn.addEventListener('click', function () { openBox(box); });
        }
        if (closeBtn) {
          closeBtn.addEventListener('click', function () { closeBox(box, true); });
        }
        if (ta) {
          ta.addEventListener('input', function () { fitTextarea(ta); });
          fitTextarea(ta);
        }
        if (box.getAttribute('data-note-always-open') === 'true') {
          box.classList.add('is-open');
        }
      });

      document.addEventListener('pointerdown', function (e) {
        var open = document.querySelector('[data-invoice-note-box].is-open:not([data-note-always-open])');
        if (!open) return;
        if (open.contains(e.target)) return;
        closeBox(open, true);
      });

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('[data-invoice-note-box].is-open:not([data-note-always-open])');
        if (open) closeBox(open, true);
      });

      // After saving a note, keep that row's note open and readable.
      if (location.hash && /^#inv-\d+$/.test(location.hash)) {
        var row = document.querySelector(location.hash);
        var box = row && row.querySelector('[data-invoice-note-box]');
        if (box) openBox(box);
      }
    })();
    </script>
  <?php endif; ?>
</section>
<?php render_footer('admin'); ?>
