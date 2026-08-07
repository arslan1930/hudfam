<?php
$user = require_admin();
ensure_invoice_schema();

$company = invoice_company_defaults();
$nextNumber = next_invoice_number();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'generate_manual') {
    try {
        $descs = (array) ($_POST['line_desc'] ?? []);
        $amounts = (array) ($_POST['line_amount'] ?? []);
        $qtys = (array) ($_POST['line_qty'] ?? []);
        $lines = [];
        foreach ($descs as $i => $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $qty = max(1, (int) ($qtys[$i] ?? 1));
            $amount = parse_money($amounts[$i] ?? 0);
            $lines[] = [
                'description' => $desc,
                'amount' => $amount,
                'qty' => $qty,
                'line_total' => round($amount * $qty, 2),
            ];
        }
        if (!$lines) {
            throw new InvalidArgumentException('Add at least one item with a description.');
        }
        $billName = trim((string) post('bill_to_name'));
        if ($billName === '') {
            throw new InvalidArgumentException('Bill-to name is required.');
        }

        $header = [
            'is_manual' => 1,
            'admin_note' => (string) post('admin_note'),
            'invoice_date' => (string) post('invoice_date'),
            'bill_to_name' => $billName,
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

        $id = create_invoice($header, $lines, (int) ($user['id'] ?? 0));
        $created = get_invoice($id);
        flash('ok', 'Manual invoice ' . ($created['invoice_number'] ?? '') . ' generated.');
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoice_manual');
    }
}

render_header('Manual invoice', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices'],
    ['label' => 'Manual'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Manual invoice', 'Standalone bill — not linked to Order management. Appears in All invoices as (manual). Can still be marked Paid.') ?></h1>
    <p class="muted">Create a printable invoice with your own line items. Not connected to client sheets or LIVE URL rows.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
    <a class="btn secondary" href="index.php?page=admin_invoice_generate">From sheet</a>
  </div>
</div>

<form method="post" class="invoice-generate-form" action="index.php?page=admin_invoice_manual" data-draft-form="invoice-manual">
  <input type="hidden" name="action" value="generate_manual">

  <section class="card">
    <h2><?= label_with_info('Invoice details', 'Number is auto-assigned and unique. Optional note sits under the invoice number on the printable bill.') ?></h2>
    <div class="form-grid">
      <div>
        <label for="invoice_number"><?= label_with_info('Invoice No.', 'Generated automatically. You cannot edit it.') ?></label>
        <input id="invoice_number" type="text" value="<?= h($nextNumber) ?>" readonly
               class="invoice-number-auto" data-no-draft
               title="Assigned automatically when you generate">
        <label class="invoice-note-field-label" for="admin_note">Note</label>
        <input id="admin_note" name="admin_note" type="text" maxlength="255"
               placeholder="note.."
               title="Optional short detail under the invoice number">
        <p class="help" style="margin:0.35rem 0 0">Optional — leave blank if you do not need a note.</p>
      </div>
      <div>
        <label for="invoice_date">Date</label>
        <input id="invoice_date" name="invoice_date" type="date" value="<?= h(date('Y-m-d')) ?>" required>
      </div>
    </div>

    <h3 class="invoice-subhead">Bill to</h3>
    <label for="bill_to_name">Client / company name</label>
    <input id="bill_to_name" name="bill_to_name" required placeholder="e.g. Autodoc SE">

    <label for="bill_to_address">Address</label>
    <textarea id="bill_to_address" name="bill_to_address" rows="2" placeholder="Street, postcode City"></textarea>

    <div class="form-grid">
      <div>
        <label for="bill_to_hrb">Company reg / HRB</label>
        <input id="bill_to_hrb" name="bill_to_hrb" placeholder="HRB 247677 B">
      </div>
      <div>
        <label for="bill_to_vat">VAT / Ust-IdNr</label>
        <input id="bill_to_vat" name="bill_to_vat" placeholder="DE260634589">
      </div>
      <div>
        <label for="supplier_number">Supplier number</label>
        <input id="supplier_number" name="supplier_number" value="NEW">
      </div>
      <div>
        <label for="cost_center">Cost center number</label>
        <input id="cost_center" name="cost_center" placeholder="1000600403-Linkbuilding">
      </div>
    </div>
    <label for="orderer">Orderer</label>
    <input id="orderer" name="orderer" placeholder="m.walz@autodoc.eu">

    <details class="invoice-company-details">
      <summary>Bank / supplier details (Topurlz)</summary>
      <div class="form-grid" style="margin-top:0.75rem">
        <div>
          <label for="company_name">Company</label>
          <input id="company_name" name="company_name" value="<?= h($company['company_name']) ?>">
        </div>
        <div>
          <label for="company_reg_no">Registration No.</label>
          <input id="company_reg_no" name="company_reg_no" value="<?= h($company['company_reg_no']) ?>">
        </div>
        <div>
          <label for="company_bic">BIC (SWIFT)</label>
          <input id="company_bic" name="company_bic" value="<?= h($company['company_bic']) ?>">
        </div>
        <div>
          <label for="company_iban">IBAN</label>
          <input id="company_iban" name="company_iban" value="<?= h($company['company_iban']) ?>">
        </div>
        <div>
          <label for="company_phone">Phone</label>
          <input id="company_phone" name="company_phone" value="<?= h($company['company_phone']) ?>">
        </div>
        <div class="full">
          <label for="company_address">Address</label>
          <input id="company_address" name="company_address" value="<?= h($company['company_address']) ?>">
        </div>
        <div class="full">
          <label for="vat_note">VAT note</label>
          <input id="vat_note" name="vat_note" value="<?= h($company['vat_note']) ?>">
        </div>
      </div>
    </details>
  </section>

  <section class="card">
    <div class="invoice-manual-items-head">
      <h2 style="margin:0"><?= label_with_info('Items', 'Free-form lines — description, amount, qty. Not tied to Order management.') ?></h2>
      <button class="btn secondary small" type="button" id="manual-add-line">+ Add item</button>
    </div>
    <div class="table-wrap" style="margin-top:0.75rem">
      <table class="invoice-manual-items" id="manual-items-table">
        <thead>
          <tr>
            <th>Description</th>
            <th class="num">Amount</th>
            <th class="num">Qty</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr class="manual-item-row">
            <td>
              <input type="text" name="line_desc[]" placeholder="e.g. Banner per year (site.com) starting May ending April" required>
            </td>
            <td class="num">
              <input type="text" name="line_amount[]" inputmode="decimal" placeholder="0.00" required>
            </td>
            <td class="num">
              <input type="number" name="line_qty[]" min="1" step="1" value="1" required>
            </td>
            <td>
              <button class="btn secondary small manual-remove-line" type="button" title="Remove" hidden>Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="actions" style="margin-top:1.1rem">
      <button class="btn" type="submit">Generate manual invoice</button>
      <a class="btn secondary" href="index.php?page=admin_invoices">Cancel</a>
    </p>
  </section>
</form>

<script>
(function () {
  var table = document.getElementById('manual-items-table');
  var addBtn = document.getElementById('manual-add-line');
  if (!table || !addBtn) return;
  var tbody = table.querySelector('tbody');

  function syncRemove() {
    var rows = tbody.querySelectorAll('.manual-item-row');
    rows.forEach(function (row) {
      var btn = row.querySelector('.manual-remove-line');
      if (btn) btn.hidden = rows.length <= 1;
    });
  }

  addBtn.addEventListener('click', function () {
    var first = tbody.querySelector('.manual-item-row');
    if (!first) return;
    var clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function (inp) {
      if (inp.name === 'line_qty[]') inp.value = '1';
      else inp.value = '';
    });
    tbody.appendChild(clone);
    syncRemove();
    var focus = clone.querySelector('input[name="line_desc[]"]');
    if (focus) focus.focus();
  });

  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.manual-remove-line');
    if (!btn) return;
    var row = btn.closest('.manual-item-row');
    if (!row) return;
    if (tbody.querySelectorAll('.manual-item-row').length <= 1) return;
    row.remove();
    syncRemove();
  });

  syncRemove();
})();
</script>
<?php render_footer('admin'); ?>
