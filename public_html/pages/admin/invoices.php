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
                flash('ok', 'Invoice ' . $inv['invoice_number'] . ' marked paid — linked sheet rows set to Paid.');
            }
            redirect('index.php?page=admin_invoices');
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
    <p class="muted">Generate printable invoices from unpaid completed articles (LIVE URL filled). Mark payment received to set those sheet rows Paid.</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
  </div>
</div>

<section class="card">
  <h2><?= label_with_info('All invoices', 'Open an invoice to print it or mark payment received.') ?></h2>
  <?php if (!$invoices): ?>
    <div class="empty-state">
      <p>No invoices yet. Generate one from unpaid completed articles on a client sheet.</p>
      <a class="btn" href="index.php?page=admin_invoice_generate">Generate invoice</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="invoice-list-table">
        <thead>
          <tr>
            <th>Invoice No.</th>
            <th>Date</th>
            <th>Client</th>
            <th>Lines</th>
            <th class="num">Total</th>
            <th><?= label_with_info('Payment', 'Click Paid on an unpaid invoice and confirm — that marks the invoice paid and sets linked sheet rows to Paid.') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
          <?php $paid = invoice_is_paid($inv); ?>
          <tr>
            <td><strong><?= h($inv['invoice_number']) ?></strong></td>
            <td><?= h(format_invoice_date((string) $inv['invoice_date'])) ?></td>
            <td>
              <?= h($inv['bill_to_name'] !== '' ? $inv['bill_to_name'] : $inv['client_name']) ?>
            </td>
            <td><?= (int) $inv['item_count'] ?></td>
            <td class="num"><?= h(format_euro($inv['total_amount'])) ?></td>
            <td>
              <?php if ($paid): ?>
                <span class="invoice-pay-badge is-paid" title="Payment already received">Paid</span>
              <?php else: ?>
                <form method="post" class="inline" action="index.php?page=admin_invoices"
                      onsubmit="return confirm(<?= h(json_encode(
                          'Confirm this invoice is paid?' . "\n\n"
                          . 'Invoice ' . $inv['invoice_number'] . "\n"
                          . 'This will mark the invoice as Paid and set linked sheet rows to Paid.',
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
              <a class="btn small" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $inv['id'] ?>">Open</a>
              <form method="post" class="inline" action="index.php?page=admin_invoices"
                    onsubmit="return confirm(<?= h(json_encode('Delete invoice ' . $inv['invoice_number'] . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php render_footer('admin'); ?>
