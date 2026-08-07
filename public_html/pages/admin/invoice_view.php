<?php
$user = require_admin();
ensure_invoice_schema();

$id = (int) get('id');
$invoice = get_invoice($id);
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('index.php?page=admin_invoices');
}

$items = list_invoice_items($id);
$print = (string) get('print') === '1';
$isPaid = invoice_is_paid($invoice);
$isManual = invoice_is_manual($invoice);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'mark_paid') {
            mark_invoice_payment_received($id);
            flash('ok', $isManual
                ? 'Payment marked received.'
                : 'Payment marked received — linked sheet rows set to Paid.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    }
}

if ($print) {
    // Standalone print document — no app chrome
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice <?= h($invoice['invoice_number']) ?></title>
  <link rel="stylesheet" href="asset.php?f=css/app.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <style>body{background:#fff;margin:0;padding:1.25rem;}</style>
</head>
<body class="invoice-print-body" onload="window.print()">
<?php include __DIR__ . '/_invoice_document.php'; ?>
</body>
</html>
    <?php
    exit;
}

render_header('Invoice ' . $invoice['invoice_number'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices'],
    ['label' => $invoice['invoice_number']],
]); ?>

<div class="topbar no-print">
  <div>
    <h1>
      Invoice <?= h($invoice['invoice_number']) ?>
      <?php if ($isManual): ?>
        <span class="invoice-manual-tag">(blank)</span>
      <?php endif; ?>
    </h1>
    <p class="muted">
      <?= h(format_invoice_date((string) $invoice['invoice_date'])) ?>
      · <?= h(format_euro($invoice['total_amount'])) ?>
      ·
      <?php if ($isPaid): ?>
        <span class="invoice-pay-badge is-paid">Payment received</span>
      <?php else: ?>
        <span class="invoice-pay-badge">Unpaid</span>
      <?php endif; ?>
      <?php if (invoice_admin_note($invoice) !== ''): ?>
        · <?= h(invoice_admin_note($invoice)) ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
    <?php if ($isManual): ?>
      <a class="btn crystal" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=admin_invoice_generate&amp;client_id=<?= (int) ($invoice['client_id'] ?? 0) ?>">Generate another</a>
    <?php endif; ?>
    <?php if (!$isPaid): ?>
      <form method="post" class="inline" action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>"
            onsubmit="return confirm(<?= h(json_encode(
                $isManual
                    ? 'Mark this blank invoice as payment received?'
                    : 'Mark this invoice as payment received? Linked unpaid sheet rows will be marked Paid.',
                JSON_UNESCAPED_UNICODE
            )) ?>);">
        <input type="hidden" name="action" value="mark_paid">
        <button class="btn" type="submit">Mark payment received</button>
      </form>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>&amp;print=1" target="_blank" rel="noopener">Print / PDF</a>
  </div>
</div>

<div class="invoice-preview-wrap">
  <?php include __DIR__ . '/_invoice_document.php'; ?>
</div>
<?php render_footer('admin'); ?>
