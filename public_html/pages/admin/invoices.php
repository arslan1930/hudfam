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

$chipOpts = [];
if ($invoiceClientId > 0) {
    $chipOpts['client_id'] = $invoiceClientId;
}
$chipAll = count_invoices($chipOpts);
$chipDraft = count_invoices(array_merge($chipOpts, ['filter' => 'draft']));
$chipUnpaid = count_invoices(array_merge($chipOpts, ['filter' => 'unpaid']));
$chipPaid = count_invoices(array_merge($chipOpts, ['filter' => 'paid']));

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
    <p class="muted">Generate from unpaid LIVE rows, or a blank invoice. Mark paid when payment arrives.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Completed unpaid</a>
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
              'Leftover client folder',
              'Older invoices that were linked to a client profile (client_id=). New bills use Bill as (email or name) and show on All invoices.'
          );
      } elseif ($invoiceFilter === 'draft') {
          echo label_with_info('Draft invoices', 'Not sent yet. Mark as sent on the bill when they are ready.');
      } elseif ($invoiceFilter === 'unpaid') {
          echo label_with_info('Waiting invoices', 'Sent bills still unpaid. Add sites keeps the same invoice number. Mark paid when it arrives.');
      } elseif ($invoiceFilter === 'paid') {
          echo label_with_info('Paid invoices', 'Payment received. Linked Order management rows stay Paid if you delete the bill.');
      } else {
          echo label_with_info('All invoices', 'Open, mark Paid, or delete. Add a short note under the invoice number — it also appears on the printable bill.');
      }
    ?></h2>
    <nav class="invoice-list-chips" aria-label="Invoice status">
      <?php
        $chipDefs = [
            '' => ['All', $chipAll],
            'draft' => ['Draft', $chipDraft],
            'unpaid' => ['Waiting', $chipUnpaid],
            'paid' => ['Paid', $chipPaid],
        ];
        foreach ($chipDefs as $chipKey => $chipRow):
            $chipHref = invoice_list_query([
                'q' => $invoiceQ,
                'filter' => $chipKey,
                'client_id' => $invoiceClientId,
                'p' => 1,
            ]);
            $chipOn = $invoiceFilter === $chipKey;
      ?>
        <a class="btn secondary small<?= $chipOn ? ' active-soft' : '' ?>"
           href="<?= h($chipHref) ?>"<?= $chipOn ? ' aria-current="page"' : '' ?>>
          <?= h($chipRow[0]) ?> (<?= (int) $chipRow[1] ?>)
        </a>
      <?php endforeach; ?>
    </nav>
    <form method="get" action="index.php" class="sheet-search invoice-list-search">
      <input type="hidden" name="page" value="admin_invoices">
      <?php if ($invoiceClientId > 0): ?>
        <input type="hidden" name="client_id" value="<?= (int) $invoiceClientId ?>">
      <?php endif; ?>
      <input type="hidden" name="filter" value="<?= h($invoiceFilter) ?>">
      <label class="visually-hidden" for="invoice-search">Search invoices</label>
      <input id="invoice-search" type="search" name="q" value="<?= h($invoiceQ) ?>"
             placeholder="Invoice no., bill as, note, or line" autocomplete="off" spellcheck="false" data-no-draft
             title="Type to match this page · Enter = next hit · Ctrl+Enter = all pages">
      <span class="sheet-search-meta muted" data-invoice-search-meta hidden></span>
      <button class="btn secondary small" type="submit">Search</button>
      <?php if ($invoiceQ !== ''): ?>
        <a class="btn secondary small" href="<?= h(invoice_list_query([
            'filter' => $invoiceFilter,
            'client_id' => $invoiceClientId,
            'p' => 1,
        ])) ?>">Clear</a>
      <?php endif; ?>
      <?php if ($invoiceClientId > 0): ?>
        <a class="btn secondary small" href="index.php?page=admin_invoices">All invoices</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if ($invoiceClientId > 0): ?>
    <p class="muted" style="margin:0 0 0.65rem">
      Leftover <code>client_id=<?= (int) $invoiceClientId ?></code> link<?= $clientScopeLabel !== '' ? ' · ' . h($clientScopeLabel) : '' ?>.
      New bills are listed on All invoices by Bill as.
    </p>
  <?php endif; ?>
  <?php if (!$invoices && $totalInvoices < 1): ?>
    <div class="empty-state">
      <p><?php
        if ($invoiceClientId > 0 && $invoiceQ === '' && $invoiceFilter === '') {
            echo 'No invoices linked to this leftover client folder.';
        } elseif ($invoiceFilter === 'unpaid' && $invoiceQ === '') {
            echo 'No waiting invoices. Open Completed unpaid in Order management to generate a bill.';
        } elseif ($invoiceQ !== '' || $invoiceFilter !== '' || $invoiceClientId > 0) {
            echo 'No invoices match this filter.';
        } else {
            echo 'No invoices yet. Generate one from unpaid LIVE rows on Order management.';
        }
      ?></p>
      <?php if ($invoiceFilter === 'unpaid' && $invoiceQ === ''): ?>
      <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Completed unpaid</a>
      <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
      <?php elseif ($invoiceQ === '' && $invoiceFilter === '' && $invoiceClientId < 1): ?>
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
        · Waiting
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
            <th class="num">Items</th>
            <th class="num">Total</th>
            <th>Payment</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <tr data-invoice-search-empty hidden>
          <td colspan="7" class="muted" style="padding:1rem">
            No invoices on this page match that search. Search still looks at all pages.
          </td>
        </tr>
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
            $incomplete = invoice_list_is_incomplete($inv);
            $statusBits = $paid ? 'paid payment received' : ($draft ? 'draft needs data' : 'done unpaid waiting');
          ?>
          <tr id="inv-<?= (int) $inv['id'] ?>"<?= $incomplete ? ' class="is-incomplete"' : '' ?> data-invoice-row
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
                <span class="invoice-manual-tag is-kind">(blank)</span>
              <?php endif; ?>
              <div class="invoice-note-box<?= $note !== '' ? ' has-note' : '' ?>" data-invoice-note-box>
                <button type="button" class="invoice-note-preview" data-note-open
                        aria-expanded="false"
                        title="<?= $note !== '' ? 'Click to read or edit note' : 'Add a note' ?>">
                  <?php if ($note !== ''): ?>
                    <span class="invoice-note-preview-text"><?= h($note) ?></span>
                  <?php else: ?>
                    <span class="invoice-note-preview-empty">Add note</span>
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
            <td class="num" data-invoice-cell><?= (int) $inv['item_count'] ?></td>
            <td class="num" data-invoice-cell><?= h(format_euro($inv['total_amount'])) ?></td>
            <td data-invoice-cell>
              <?php if ($paid): ?>
                <span class="invoice-pay-badge is-paid" title="Payment already received">Paid</span>
              <?php elseif ($draft): ?>
                <span class="invoice-pay-badge is-draft" title="Still needs data — open and Mark as sent when ready">Draft</span>
              <?php else: ?>
                <div class="invoice-pay-stack">
                  <span class="invoice-pay-badge" title="Sent — waiting for payment">Waiting</span>
                  <form method="post" class="inline" action="<?= h($listUrl) ?>"
                        data-stay-ajax data-stay-mark-paid
                        <?= confirm_attrs(
                            'Confirm this invoice is paid?' . "\n\n"
                            . 'Invoice ' . $inv['invoice_number'] . ($manual ? ' (blank)' : '') . "\n"
                            . ($manual
                                ? 'This will mark the blank invoice as Paid.'
                                : 'This will mark the invoice as Paid and set linked sheet rows to Paid.'),
                            ['title' => 'Mark as paid?', 'confirm_label' => 'Mark paid']
                        ) ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                    <button class="btn-paid btn-paid-mark invoice-list-pay-btn" type="submit" title="Mark this invoice as paid">
                      Mark paid
                    </button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
            <td class="invoice-list-actions">
              <div class="invoice-list-actions-row">
                <a class="btn small" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $inv['id'] ?>">Open</a>
                <?php if (!$paid && invoice_can_append_orders($inv) && !$manual): ?>
                  <a class="btn secondary small" href="<?= h(invoice_generate_append_href((int) $inv['id'])) ?>">Add sites</a>
                <?php endif; ?>
                <form method="post" class="inline" action="<?= h($listUrl) ?>"
                      <?= confirm_attrs(
                          $paid
                              ? ('This invoice is Paid. Delete anyway?' . "\n\n"
                                  . 'Invoice ' . $inv['invoice_number'] . ' will be removed. Linked sheet rows stay Paid.')
                              : ('Delete invoice ' . $inv['invoice_number'] . '?'),
                          ['title' => 'Delete invoice?', 'confirm_label' => 'Delete', 'danger' => true]
                      ) ?>>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="invoice-list-delete<?= $paid ? ' is-paid' : '' ?>" type="submit"
                          title="<?= $paid ? 'Delete a Paid invoice' : 'Delete invoice' ?>">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="pagination invoice-list-pager" aria-label="Invoice pages">
      <?php if ($pageNum > 1): ?>
        <a href="<?= h($invoiceListQs(['p' => (string) ($pageNum - 1)])) ?>">Previous</a>
      <?php endif; ?>
      <?php foreach (invoice_list_page_numbers((int) $pageNum, $totalPages) as $pageLink): ?>
        <?php if ($pageLink < 1): ?>
          <span class="pagination-gap" aria-hidden="true">…</span>
        <?php elseif ($pageLink === $pageNum): ?>
          <span class="is-current" aria-current="page"><?= (int) $pageLink ?></span>
        <?php else: ?>
          <a href="<?= h($invoiceListQs(['p' => (string) $pageLink])) ?>"><?= (int) $pageLink ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($pageNum < $totalPages): ?>
        <a href="<?= h($invoiceListQs(['p' => (string) ($pageNum + 1)])) ?>">Next</a>
      <?php endif; ?>
    </nav>
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
          openBtn.innerHTML = '<span class="invoice-note-preview-empty">Add note</span>';
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
<?= sheet_search_jump_script_tag() ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.SheetSearchJump) return;
  SheetSearchJump.bind({
    input: '#invoice-search',
    rows: '[data-invoice-row]',
    meta: '[data-invoice-search-meta]',
    empty: '[data-invoice-search-empty]'
  });
});
</script>
<?php render_footer('admin'); ?>
