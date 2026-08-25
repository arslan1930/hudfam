<?php
$user = require_admin();
ensure_invoice_schema();

$clients = list_order_clients();
$useClientTypeahead = count($clients) >= invoice_generate_client_typeahead_min();
$clientId = (int) (get('client_id') ?: post('client_id'));
$client = $clientId > 0 ? get_order_client($clientId) : null;
$profile = $client ? get_invoice_client_profile($clientId) : null;
$invoiceable = $client ? list_invoiceable_order_items($clientId) : [];
$company = invoice_company_defaults();
$nextNumber = next_invoice_number();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'generate') {
    try {
        if (!$client) {
            throw new InvalidArgumentException('Select a client sheet first.');
        }
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
        $group = (string) post('group_same_amount') === '1';
        $lines = build_invoice_lines_from_orders($picked, $group);

        $header = [
            'invoice_date' => (string) post('invoice_date'),
            'client_id' => $clientId,
            'client_name' => (string) $client['name'],
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
        if (trim($header['bill_to_name']) === '') {
            $header['bill_to_name'] = (string) $client['name'];
        }

        $id = create_invoice($header, $lines, (int) ($user['id'] ?? 0));
        $created = get_invoice($id);
        flash('ok', 'Invoice ' . ($created['invoice_number'] ?? '') . ' generated.');
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoice_generate&client_id=' . $clientId);
    }
}

$billName = (string) ($profile['bill_to_name'] ?? ($client['name'] ?? ''));
$billAddress = (string) ($profile['bill_to_address'] ?? '');
$billHrb = (string) ($profile['bill_to_hrb'] ?? '');
$billVat = (string) ($profile['bill_to_vat'] ?? '');
$supplier = (string) ($profile['supplier_number'] ?? 'NEW');
$costCenter = (string) ($profile['cost_center'] ?? '');
$orderer = (string) ($profile['orderer'] ?? '');

render_header('Generate invoice', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices'],
    ['label' => 'Generate'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Generate invoice', 'Pick unpaid LIVE rows from a client sheet, fill bill-to details, then create a printable Topurlz invoice.') ?></h1>
    <p class="muted">Pick a client, tick unpaid completed articles (LIVE URL), fill bill-to details — layout matches your sample.</p>
  </div>
  <div class="actions">
    <a class="btn crystal" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
  </div>
</div>

<form method="get" class="card invoice-pick-client" action="index.php" data-no-draft>
  <input type="hidden" name="page" value="admin_invoice_generate">
  <label for="client_id">Client sheet</label>
  <div class="invoice-pick-row">
    <select id="client_id" name="client_id" required onchange="this.form.submit()"
            <?= $useClientTypeahead ? 'data-searchable' : '' ?>>
      <option value="">Select client…</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $clientId === (int) $c['id'] ? 'selected' : '' ?>>
          <?= h(invoice_generate_client_option_label($c)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn secondary" type="submit">Load</button></noscript>
  </div>
  <?php if ($useClientTypeahead): ?>
    <p class="help">Type to search — options show unpaid LIVE rows (ready to invoice) and completed count.</p>
  <?php endif; ?>
  <?php if (!$clients): ?>
    <p class="help">No client sheets yet — create one under <a href="index.php?page=admin_orders">Order management</a> first.</p>
  <?php endif; ?>
</form>

<?php if ($client): ?>
<form method="post" class="invoice-generate-form" action="index.php?page=admin_invoice_generate">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="generate">
  <input type="hidden" name="client_id" value="<?= (int) $clientId ?>">

  <div class="orders-layout">
    <section class="card">
      <h2><?= label_with_info('Articles to invoice', 'Only unpaid rows with a LIVE URL. Banner/Textlink rows show their yearly period text instead of Article Published.') ?></h2>
      <p class="muted" style="margin-top:0">
        Only <strong>unpaid</strong> rows with a LIVE URL from <strong><?= h($client['name']) ?></strong>.
        Paid rows are excluded.
      </p>
      <?php if (!$invoiceable): ?>
        <div class="empty-state">
          <p>No unpaid completed articles yet. Fill LIVE URL on the sheet, and leave those rows unpaid.</p>
          <a class="btn secondary" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $clientId ?>">Open sheet</a>
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
          Group lines that share the same amount (qty &gt; 1), like the sample
        </label>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?= label_with_info('Invoice details', 'Invoice number is assigned automatically and is always unique. Date and bill-to fields appear on the printable bill. Bank details default to Topurlz Ltd.') ?></h2>
      <div class="form-grid">
        <div>
          <label for="invoice_number"><?= label_with_info('Invoice No.', 'Generated automatically from the last invoice number. You cannot reuse or edit it.') ?></label>
          <input id="invoice_number" type="text" value="<?= h($nextNumber) ?>" readonly
                 class="invoice-number-auto" data-no-draft
                 title="Assigned automatically when you generate">
          <p class="help" style="margin:0.35rem 0 0">Next number — locked &amp; unique. Add notes later under the invoice number on All invoices.</p>
        </div>
        <div>
          <label for="invoice_date">Date</label>
          <input id="invoice_date" name="invoice_date" type="date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
      </div>

      <h3 class="invoice-subhead">Bill to</h3>
      <label for="bill_to_name">Client / company name</label>
      <input id="bill_to_name" name="bill_to_name" value="<?= h($billName) ?>" required placeholder="e.g. Autodoc SE">

      <label for="bill_to_address">Address</label>
      <textarea id="bill_to_address" name="bill_to_address" rows="2" placeholder="Street, postcode City"><?= h($billAddress) ?></textarea>

      <div class="form-grid">
        <div>
          <label for="bill_to_hrb">Company reg / HRB</label>
          <input id="bill_to_hrb" name="bill_to_hrb" value="<?= h($billHrb) ?>" placeholder="HRB 247677 B">
        </div>
        <div>
          <label for="bill_to_vat">VAT / Ust-IdNr</label>
          <input id="bill_to_vat" name="bill_to_vat" value="<?= h($billVat) ?>" placeholder="DE260634589">
        </div>
        <div>
          <label for="supplier_number">Supplier number</label>
          <input id="supplier_number" name="supplier_number" value="<?= h($supplier !== '' ? $supplier : 'NEW') ?>">
        </div>
        <div>
          <label for="cost_center">Cost center number</label>
          <input id="cost_center" name="cost_center" value="<?= h($costCenter) ?>" placeholder="1000600403-Linkbuilding">
        </div>
      </div>
      <label for="orderer">Orderer</label>
      <input id="orderer" name="orderer" value="<?= h($orderer) ?>" placeholder="m.walz@autodoc.eu">

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
<?php endif; ?>
<?php if ($useClientTypeahead): ?>
<script src="<?= h(script_asset_url('js/searchable-select.js')) ?>" defer></script>
<?php endif; ?>
<?php render_footer('admin'); ?>
