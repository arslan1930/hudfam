<?php
$user = require_admin();
ensure_invoice_schema();

$rawIds = trim((string) (get('ids') ?: post('ids')));
$selectedFromSheet = parse_order_item_ids($rawIds);
$clientId = (int) (get('client_id') ?: post('client_id'));

if ($selectedFromSheet) {
    $invoiceable = list_invoiceable_order_items_by_ids($selectedFromSheet);
} elseif ($clientId > 0) {
    $invoiceable = list_invoiceable_order_items($clientId);
} else {
    $invoiceable = list_invoiceable_order_items(0);
}

$company = invoice_company_defaults();
$nextNumber = next_invoice_number();
$billAsDefault = invoice_bill_as_from_orders($invoiceable);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'generate') {
    try {
        $selectedIds = array_map('intval', (array) ($_POST['item_ids'] ?? []));
        $selectedIds = array_values(array_filter($selectedIds, static fn ($id) => $id > 0));
        if (!$selectedIds) {
            throw new InvalidArgumentException('Tick at least one completed article.');
        }
        $byId = [];
        foreach ($invoiceable as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $picked = [];
        foreach ($selectedIds as $id) {
            if (isset($byId[$id])) {
                $picked[] = $byId[$id];
            }
        }
        if (!$picked) {
            $picked = list_invoiceable_order_items_by_ids($selectedIds);
        }
        if (!$picked) {
            throw new InvalidArgumentException('Tick at least one unpaid LIVE row.');
        }
        $group = (string) post('group_same_amount') === '1';
        $lines = build_invoice_lines_from_orders($picked, $group);
        $billAs = trim((string) post('bill_to_name'));
        if ($billAs === '') {
            $billAs = invoice_bill_as_from_orders($picked);
        }

        $header = [
            'invoice_date' => (string) post('invoice_date'),
            'client_id' => 0,
            'client_name' => $billAs,
            'bill_to_name' => $billAs,
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
        flash('ok', 'Invoice ' . ($created['invoice_number'] ?? '') . ' generated.');
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        $back = 'index.php?page=admin_invoice_generate';
        if ($selectedFromSheet) {
            $back .= '&ids=' . rawurlencode(implode(',', $selectedFromSheet));
        } elseif ($clientId > 0) {
            $back .= '&client_id=' . $clientId;
        }
        redirect($back);
    }
}

render_header('Generate invoice', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices'],
    ['label' => 'Generate'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Generate invoice', 'Tick unpaid LIVE rows from Order management and create a printable bill. No client folder or extra details required — bill-as is the email or name from the order.') ?></h1>
    <p class="muted">Push unpaid LIVE orders here for payment. Bill-as can be the client email or name from the sheet. Address and company details are optional.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders">Order management</a>
    <a class="btn secondary" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
  </div>
</div>

<form method="post" class="invoice-generate-form" action="index.php?page=admin_invoice_generate">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="generate">
  <?php if ($selectedFromSheet): ?>
    <input type="hidden" name="ids" value="<?= h(implode(',', $selectedFromSheet)) ?>">
  <?php endif; ?>

  <div class="orders-layout">
    <section class="card">
      <h2><?= label_with_info('Orders to invoice', 'Only unpaid rows with a LIVE URL. Banner/Textlink rows show their yearly period text instead of Article Published.') ?></h2>
      <p class="muted" style="margin-top:0">
        <?php if ($selectedFromSheet): ?>
          Rows you pushed from Order management. Untick any you do not want on this bill.
        <?php else: ?>
          All unpaid LIVE rows from Order management. Tick the ones to bill.
        <?php endif; ?>
      </p>
      <?php if (!$invoiceable): ?>
        <div class="empty-state">
          <p>No unpaid completed orders yet. Fill LIVE URL on Order management, leave those rows unpaid, then push them here.</p>
          <a class="btn secondary" href="index.php?page=admin_orders">Open Order management</a>
        </div>
      <?php else: ?>
        <label class="invoice-check-all">
          <input type="checkbox" id="toggle-all-items" checked>
          Select all (<?= count($invoiceable) ?>)
        </label>
        <ul class="invoice-item-pick">
          <?php foreach ($invoiceable as $row): ?>
            <li>
              <label>
                <input type="checkbox" name="item_ids[]" value="<?= (int) $row['id'] ?>" checked>
                <span class="invoice-pick-main">
                  <strong><?= h($row['site_name'] !== '' ? $row['site_name'] : 'Site') ?></strong>
                  <?php
                    $who = trim((string) ($row['client_label'] ?? ''));
                    $country = trim((string) ($row['country'] ?? ''));
                    $meta = trim($who . ($country !== '' ? ($who !== '' ? ' · ' : '') . $country : ''));
                  ?>
                  <?php if ($meta !== ''): ?>
                    <span class="muted"><?= h($meta) ?></span>
                  <?php endif; ?>
                  <?php if (order_is_placement($row)): ?>
                    <span class="muted"><?= h(order_invoice_description($row)) ?></span>
                  <?php else: ?>
                    <span class="muted invoice-pick-url"><?= h($row['live_url']) ?></span>
                  <?php endif; ?>
                </span>
                <span class="invoice-pick-price"><?= h(format_euro($row['decided_price'])) ?></span>
              </label>
            </li>
          <?php endforeach; ?>
        </ul>
        <label class="invoice-group-opt">
          <input type="checkbox" name="group_same_amount" value="1" checked>
          Group lines that share the same amount (qty &gt; 1)
        </label>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?= label_with_info('Invoice details', 'Invoice number is assigned automatically. Date appears on the bill. Bill-as is optional free text from the order (email or name).') ?></h2>
      <div class="form-grid">
        <div>
          <label for="invoice_number"><?= label_with_info('Invoice No.', 'Generated automatically from the last invoice number. You cannot reuse or edit it.') ?></label>
          <input id="invoice_number" type="text" value="<?= h($nextNumber) ?>" readonly
                 class="invoice-number-auto" data-no-draft
                 title="Assigned automatically when you generate">
          <p class="help" style="margin:0.35rem 0 0">Next number — locked &amp; unique.</p>
        </div>
        <div>
          <label for="invoice_date">Date</label>
          <input id="invoice_date" name="invoice_date" type="date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
      </div>

      <h3 class="invoice-subhead">Bill as</h3>
      <label for="bill_to_name">Client email or name <span class="help">(optional)</span></label>
      <input id="bill_to_name" name="bill_to_name" value="<?= h($billAsDefault) ?>"
             placeholder="email or name from the order">
      <p class="help">Copied from the order sheet. Leave blank if you do not need a name on the bill.</p>

      <details class="invoice-company-details">
        <summary>Optional address / VAT (not required)</summary>
        <div style="margin-top:0.75rem">
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
              <input id="cost_center" name="cost_center" placeholder="">
            </div>
          </div>
          <label for="orderer">Orderer</label>
          <input id="orderer" name="orderer" placeholder="">
        </div>
      </details>

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

      <p class="actions" style="margin-top:1.1rem">
        <button class="btn large" type="submit" <?= !$invoiceable ? 'disabled' : '' ?>>Generate invoice</button>
      </p>
    </section>
  </div>
</form>
<script>
(function () {
  var all = document.getElementById('toggle-all-items');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('input[name="item_ids[]"]').forEach(function (cb) {
      cb.checked = all.checked;
    });
  });
})();
</script>
<?php render_footer('admin'); ?>
