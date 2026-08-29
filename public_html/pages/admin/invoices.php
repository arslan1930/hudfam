<?php
$user = require_admin();
ensure_invoice_schema();

$invoiceQ = trim((string) get('q'));
$invoiceFilter = normalize_invoice_list_filter((string) get('filter'));
$invoiceClientId = max(0, (int) get('client_id'));
$pageNum = max(1, (int) get('p', 1));
$listUrl = invoice_list_query([
    'q' => $invoiceQ,
    'filter' => $invoiceFilter,
    'client_id' => $invoiceClientId,
    'p' => $pageNum,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson): void {
        if (!$wantsJson) {
            return;
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    };
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
            redirect($listUrl);
        }
        if ($action === 'mark_paid') {
            $id = (int) post('id');
            $inv = get_invoice($id);
            if (!$inv) {
                $jsonOut(['ok' => false, 'error' => 'Invoice not found.'], 404);
                flash('error', 'Invoice not found.');
            } else {
                mark_invoice_payment_received($id);
                $msg = invoice_is_manual($inv)
                    ? ('Blank invoice ' . $inv['invoice_number'] . ' marked paid.')
                    : ('Invoice ' . $inv['invoice_number'] . ' marked paid — linked sheet rows set to Paid.');
                $jsonOut([
                    'ok' => true,
                    'id' => $id,
                    'paid' => true,
                    'message' => $msg,
                ]);
                flash('ok', $msg);
            }
            redirect($listUrl);
        }
        if ($action === 'save_note') {
            $id = (int) post('id');
            update_invoice_admin_note($id, (string) post('admin_note'));
            flash('ok', 'Note saved for invoice.');
            redirect($listUrl . '#inv-' . $id);
        }
    } catch (Throwable $e) {
        $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
        flash('error', $e->getMessage());
        redirect($listUrl);
    }
}

$perPage = 50;
$listOpts = [
    'q' => $invoiceQ,
    'filter' => $invoiceFilter,
];
if ($invoiceClientId > 0) {
    $listOpts['client_id'] = $invoiceClientId;
}
$totalInvoices = count_invoices($listOpts);
$totalPages = max(1, (int) ceil($totalInvoices / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$invoices = list_invoices(array_merge($listOpts, [
    'limit' => $perPage,
    'offset' => ($pageNum - 1) * $perPage,
]));

$invoiceListQs = static function (array $overrides) use ($invoiceQ, $invoiceFilter, $invoiceClientId, $pageNum): string {
    return invoice_list_query(array_merge([
        'q' => $invoiceQ,
        'filter' => $invoiceFilter,
        'client_id' => $invoiceClientId,
        'p' => $pageNum,
    ], $overrides));
};

$clientScopeLabel = '';
if ($invoiceClientId > 0) {
    $scopeClient = get_order_client($invoiceClientId);
    $clientScopeLabel = $scopeClient
        ? (string) $scopeClient['name']
        : ('Client #' . $invoiceClientId);
}

render_header('Invoices', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Invoices', 'Build printable Topurlz bills from unpaid Order management rows that have a LIVE URL. Mark paid to set those rows Paid.') ?></h1>
    <p class="muted">Generate from unpaid LIVE sheet rows, or open a blank invoice and fill items on the bill. Blank invoices can be <strong>Draft</strong> (still needs data) or <strong>Done</strong> (sent, waiting for payment). Mark paid when payment arrives.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Order management</a>
    <a class="btn secondary" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
  </div>
</div>

<?= guide_invoices() ?>

<section class="card">
  <div class="invoice-list-toolbar">
    <h2 style="margin:0" class="with-info-heading"><?php
      if ($invoiceClientId > 0) {
          echo label_with_info(
              'Invoices billed as ' . $clientScopeLabel,
              'Older invoices that were linked to a client profile. New bills use Bill as (email or name) and show on All invoices.'
          );
      } else {
          echo label_with_info('All invoices', 'Open, mark Paid, or delete. Add a short note under the invoice number — it also appears on the printable bill.');
      }
    ?></h2>
    <form method="get" action="index.php" class="sheet-search invoice-list-search" style="display:flex;gap:0.4rem;align-items:center;margin:0;flex-wrap:wrap">
      <input type="hidden" name="page" value="admin_invoices">
      <?php if ($invoiceClientId > 0): ?>
        <input type="hidden" name="client_id" value="<?= (int) $invoiceClientId ?>">
      <?php endif; ?>
      <label class="visually-hidden" for="invoice-filter">Filter invoices</label>
      <select id="invoice-filter" name="filter" aria-label="Filter invoices" onchange="this.form.submit()">
        <option value="" <?= $invoiceFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="draft" <?= $invoiceFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="unpaid" <?= $invoiceFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
        <option value="paid" <?= $invoiceFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
      </select>
      <label class="visually-hidden" for="invoice-search">Search invoices</label>
      <input id="invoice-search" type="search" name="q" value="<?= h($invoiceQ) ?>"
             placeholder="Search…" autocomplete="off" spellcheck="false" data-no-draft
             title="Search invoice number, bill as, or note">
      <button class="btn secondary small" type="submit">Search</button>
      <?php if ($invoiceQ !== '' || $invoiceFilter !== ''): ?>
        <a class="btn secondary small" href="<?= h($invoiceClientId > 0
            ? invoice_list_query(['client_id' => $invoiceClientId])
            : 'index.php?page=admin_invoices') ?>">Clear</a>
      <?php endif; ?>
      <?php if ($invoiceClientId > 0): ?>
        <a class="btn secondary small" href="index.php?page=admin_invoices">All invoices</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if ($invoiceClientId > 0): ?>
    <p class="muted" style="margin:0 0 0.65rem">
      Linked to an older client profile. New bills are listed on All invoices by Bill as.
    </p>
  <?php endif; ?>
  <?php if (!$invoices && $totalInvoices < 1): ?>
    <div class="empty-state">
      <p><?php
        if ($invoiceClientId > 0 && $invoiceQ === '' && $invoiceFilter === '') {
            echo 'No invoices linked to this older client profile.';
        } elseif ($invoiceQ !== '' || $invoiceFilter !== '' || $invoiceClientId > 0) {
            echo 'No invoices match this filter.';
        } else {
            echo 'No invoices yet. Generate one from unpaid LIVE rows on Order management.';
        }
      ?></p>
      <?php if ($invoiceQ === '' && $invoiceFilter === '' && $invoiceClientId < 1): ?>
      <a class="btn secondary" href="index.php?page=admin_invoice_manual">Blank invoice</a>
      <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
      <?php else: ?>
      <p><a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 0.65rem">
      <?= (int) $totalInvoices ?> invoice<?= $totalInvoices === 1 ? '' : 's' ?>
      <?php if ($invoiceClientId > 0): ?>
        · <?= h($clientScopeLabel) ?>
      <?php endif; ?>
      <?php if ($invoiceFilter === 'draft'): ?>
        · Draft
      <?php elseif ($invoiceFilter === 'unpaid'): ?>
        · Unpaid
      <?php elseif ($invoiceFilter === 'paid'): ?>
        · Paid
      <?php endif; ?>
      <?php if ($totalPages > 1): ?>
        · page <?= (int) $pageNum ?> / <?= (int) $totalPages ?>
        · showing <?= count($invoices) ?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table class="invoice-list-table" id="invoice-list-table">
        <thead>
          <tr>
            <th>Invoice No.</th>
            <th>Date</th>
            <th>Bill as</th>
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
            $clientLabel = invoice_display_bill_as($inv);
            if ($clientLabel === '') {
                $clientLabel = '—';
            }
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
                <form method="post" class="invoice-list-note-form" action="<?= h($listUrl) ?>"
                      data-note-panel hidden>
                  <?= csrf_field() ?>
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
                <form method="post" class="inline" action="<?= h($listUrl) ?>"
                      data-stay-ajax data-stay-mark-paid
                      onsubmit="return confirm(<?= h(json_encode(
                          'Confirm this invoice is paid?' . "\n\n"
                          . 'Invoice ' . $inv['invoice_number'] . ($manual ? ' (blank)' : '') . "\n"
                          . ($manual
                              ? 'This will mark the blank invoice as Paid.'
                              : 'This will mark the invoice as Paid and set linked sheet rows to Paid.'),
                          JSON_UNESCAPED_UNICODE
                      )) ?>);">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="btn-paid btn-paid-mark invoice-list-pay-btn" type="submit" title="Mark this invoice as paid">
                    Mark paid
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td class="invoice-list-actions">
              <div class="invoice-list-actions-row">
                <a class="btn secondary small" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $inv['id'] ?>">Open</a>
                <form method="post" class="inline" action="<?= h($listUrl) ?>"
                      onsubmit="return confirm(<?= h(json_encode('Delete invoice ' . $inv['invoice_number'] . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="btn secondary small" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <p class="muted" style="margin-top:0.85rem">
      Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
      <?php if ($pageNum > 1): ?>
        · <a href="<?= h($invoiceListQs(['p' => (string) ($pageNum - 1)])) ?>">Previous</a>
      <?php endif; ?>
      <?php if ($pageNum < $totalPages): ?>
        · <a href="<?= h($invoiceListQs(['p' => (string) ($pageNum + 1)])) ?>">Next</a>
      <?php endif; ?>
    </p>
    <?php endif; ?>
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
