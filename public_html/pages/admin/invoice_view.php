<?php
$user = require_admin();
ensure_invoice_schema();

$id = (int) get('id');
$invoice = get_invoice($id);
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('index.php?page=admin_invoices');
}

$isPaid = invoice_is_paid($invoice);
$isManual = invoice_is_manual($invoice);
$print = (string) get('print') === '1';

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
        if ($action === 'save_blank') {
            if (!$isManual) {
                throw new InvalidArgumentException('Only blank invoices can be edited.');
            }
            $descs = (array) ($_POST['line_desc'] ?? []);
            $amounts = (array) ($_POST['line_amount'] ?? []);
            $qtys = (array) ($_POST['line_qty'] ?? []);
            $lines = [];
            foreach ($descs as $i => $desc) {
                $lines[] = [
                    'description' => (string) $desc,
                    'amount' => $amounts[$i] ?? 0,
                    'qty' => $qtys[$i] ?? 1,
                ];
            }
            $header = [
                'invoice_date' => (string) post('invoice_date'),
                'admin_note' => (string) post('admin_note'),
                'bill_to_name' => (string) post('bill_to_name'),
                'bill_to_address' => (string) post('bill_to_address'),
                'bill_to_hrb' => (string) post('bill_to_hrb'),
                'bill_to_vat' => (string) post('bill_to_vat'),
                'supplier_number' => (string) post('supplier_number'),
                'cost_center' => (string) post('cost_center'),
                'orderer' => (string) post('orderer'),
                'company_name' => (string) post('company_name'),
                'company_bic' => (string) post('company_bic'),
                'company_iban' => (string) post('company_iban'),
                'company_phone' => (string) post('company_phone'),
                'company_address' => (string) post('company_address'),
                'company_reg_no' => (string) post('company_reg_no'),
                'vat_note' => (string) post('vat_note'),
            ];
            update_blank_invoice($id, $header, $lines);
            flash('ok', 'Blank invoice saved.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    }
}

$invoice = get_invoice($id);
$items = list_invoice_items($id);
$isPaid = invoice_is_paid($invoice);
$isManual = invoice_is_manual($invoice);
$editable = $isManual && !$isPaid && !$print;

if ($print) {
    $editable = false;
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
      <?php if ($editable): ?>
        · Edit on the bill — Save needs a total above €0. Leave or Print / PDF anytime (even at €0).
      <?php elseif (invoice_admin_note($invoice) !== ''): ?>
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
    <?php if ($editable): ?>
      <button class="btn" type="submit" form="blank-invoice-form" id="blank-invoice-save"
              title="Requires a bill total greater than zero">Save invoice</button>
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
        <button class="btn<?= $editable ? ' secondary' : '' ?>" type="submit">Mark payment received</button>
      </form>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>&amp;print=1" target="_blank" rel="noopener"
       title="Download or print even with a zero total">Print / PDF</a>
  </div>
  <?php if ($editable): ?>
    <p class="help no-print" id="blank-invoice-save-hint" style="margin:0.35rem 0 0;text-align:right" hidden>
      Add line amounts so the total is above €0 to save. You can still leave or use Print / PDF with a zero total.
    </p>
  <?php endif; ?>
</div>

<?php if ($editable): ?>
<form method="post" id="blank-invoice-form" class="invoice-blank-edit-form"
      action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>" data-no-draft>
  <input type="hidden" name="action" value="save_blank">
  <div class="invoice-preview-wrap">
    <?php include __DIR__ . '/_invoice_document.php'; ?>
  </div>
</form>
<script>
(function () {
  var form = document.getElementById('blank-invoice-form');
  if (!form) return;
  var tbody = document.getElementById('invoice-edit-items');
  var addBtn = document.getElementById('invoice-edit-add');
  var saveBtn = document.getElementById('blank-invoice-save');
  var saveHint = document.getElementById('blank-invoice-save-hint');

  function money(n) {
    return '€' + (Math.round(n * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function parseNum(v) {
    var s = String(v || '').replace(/,/g, '').replace(/[^\d.-]/g, '').trim();
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function renumber() {
    tbody.querySelectorAll('.invoice-edit-row').forEach(function (row, i) {
      var num = row.querySelector('.invoice-edit-num');
      if (num) num.textContent = String(i + 1);
    });
  }
  function syncPaybox() {
    var name = (form.querySelector('[name="company_name"]') || {}).value || '';
    var iban = (form.querySelector('[name="company_iban"]') || {}).value || '';
    var bic = (form.querySelector('[name="company_bic"]') || {}).value || '';
    var vat = (form.querySelector('[name="vat_note"]') || {}).value || '';
    var el;
    el = form.querySelector('.invoice-pay-company'); if (el) el.textContent = name;
    el = form.querySelector('.invoice-pay-iban'); if (el) el.textContent = iban;
    el = form.querySelector('.invoice-pay-bic'); if (el) el.textContent = bic;
    el = form.querySelector('.invoice-pay-vat'); if (el) el.textContent = vat;
    el = form.querySelector('.invoice-footer-company'); if (el) el.textContent = name || 'Topurlz Ltd';
  }
  function syncSaveState(grand) {
    var canSave = grand > 0;
    if (saveBtn) {
      saveBtn.disabled = !canSave;
      saveBtn.setAttribute('aria-disabled', canSave ? 'false' : 'true');
    }
    if (saveHint) saveHint.hidden = canSave;
  }
  function refreshTotals() {
    var grand = 0;
    tbody.querySelectorAll('.invoice-edit-row').forEach(function (row) {
      var amount = parseNum((row.querySelector('.invoice-edit-amount') || {}).value);
      var qty = Math.max(1, parseInt((row.querySelector('.invoice-edit-qty') || {}).value, 10) || 1);
      var line = amount * qty;
      grand += line;
      var cell = row.querySelector('.invoice-edit-line-total');
      if (cell) cell.textContent = money(line);
    });
    var g = form.querySelector('[data-invoice-grand-total]');
    if (g) g.textContent = money(grand);
    syncPaybox();
    syncSaveState(grand);
  }
  function syncRemove() {
    var rows = tbody.querySelectorAll('.invoice-edit-row');
    rows.forEach(function (row) {
      var btn = row.querySelector('.invoice-edit-remove');
      if (btn) btn.disabled = rows.length <= 1;
    });
  }

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var first = tbody.querySelector('.invoice-edit-row');
      if (!first) return;
      var clone = first.cloneNode(true);
      clone.querySelectorAll('input, textarea').forEach(function (el) {
        if (el.classList.contains('invoice-edit-qty')) el.value = '1';
        else el.value = '';
      });
      var tot = clone.querySelector('.invoice-edit-line-total');
      if (tot) tot.textContent = money(0);
      tbody.appendChild(clone);
      renumber();
      syncRemove();
      refreshTotals();
      var focus = clone.querySelector('.invoice-edit-desc');
      if (focus) focus.focus();
    });
  }

  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.invoice-edit-remove');
    if (!btn || btn.disabled) return;
    var row = btn.closest('.invoice-edit-row');
    if (!row) return;
    if (tbody.querySelectorAll('.invoice-edit-row').length <= 1) return;
    row.remove();
    renumber();
    syncRemove();
    refreshTotals();
  });

  form.addEventListener('input', refreshTotals);
  form.addEventListener('change', refreshTotals);
  form.addEventListener('submit', function (e) {
    var grand = 0;
    tbody.querySelectorAll('.invoice-edit-row').forEach(function (row) {
      var amount = parseNum((row.querySelector('.invoice-edit-amount') || {}).value);
      var qty = Math.max(1, parseInt((row.querySelector('.invoice-edit-qty') || {}).value, 10) || 1);
      grand += amount * qty;
    });
    if (!(grand > 0)) {
      e.preventDefault();
      syncSaveState(0);
      if (saveHint) {
        saveHint.hidden = false;
        saveHint.focus && saveHint.focus();
      }
      alert('Cannot save with a zero total. Add amounts first, or leave / Print without saving.');
    }
  });
  syncRemove();
  refreshTotals();
})();
</script>
<?php else: ?>
<div class="invoice-preview-wrap">
  <?php include __DIR__ . '/_invoice_document.php'; ?>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
