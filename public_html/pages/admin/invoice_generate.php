<?php
$user = require_admin();
ensure_invoice_schema();

$rawIds = trim((string) (get('ids') ?: post('ids')));
$selectedFromSheet = parse_order_item_ids($rawIds);
$clientId = (int) (get('client_id') ?: post('client_id'));
$precheck = $selectedFromSheet !== [];

if ($selectedFromSheet) {
    $invoiceable = list_invoiceable_order_items_by_ids($selectedFromSheet);
} elseif ($clientId > 0) {
    $invoiceable = list_invoiceable_order_items($clientId);
} else {
    $invoiceable = list_invoiceable_order_items(0);
}

$company = invoice_company_defaults();
$nextNumber = next_invoice_number();
$billAsDefault = $precheck ? invoice_bill_as_from_orders($invoiceable) : '';
$billAsLabels = invoice_bill_as_labels($invoiceable);
$pickCap = invoice_generate_pick_cap();
$pickTotal = count($invoiceable);
$pickTruncated = false;
if (!$selectedFromSheet && $pickTotal > $pickCap) {
    $invoiceable = array_slice($invoiceable, 0, $pickCap);
    $pickTruncated = true;
}
$emptyStats = (!$invoiceable && !$selectedFromSheet) ? invoice_generate_empty_stats() : null;
$openInvoices = list_invoices_open_for_append(50);

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
        invoice_assert_single_bill_as($picked);
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

        $destination = (string) post('destination') === 'existing' ? 'existing' : 'new';
        if ($destination === 'existing') {
            $existingId = (int) post('existing_invoice_id');
            if ($existingId < 1) {
                throw new InvalidArgumentException('Pick an unpaid invoice to add these rows to.');
            }
            $result = append_orders_to_invoice($existingId, $lines, $picked);
            $n = (int) ($result['added'] ?? 0);
            flash(
                'ok',
                'Added ' . $n . ' site' . ($n === 1 ? '' : 's')
                . ' to invoice ' . (string) ($result['invoice_number'] ?? '') . '.'
            );
            redirect('index.php?page=admin_invoice_view&id=' . (int) $result['id']);
        }

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
    <h1><?= label_with_info('Generate invoice', 'Tick unpaid LIVE rows from Order management. New invoice gets the next number. Add to existing only for unpaid bills with the same bill-as. No client folder required.') ?></h1>
    <p class="muted">Push unpaid LIVE orders here for payment. Create a new bill, or add them onto an unpaid invoice. Bill-as can be the client email or name from the sheet.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Order management</a>
    <a class="btn secondary" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
  </div>
</div>

<form method="post" class="invoice-generate-form" action="index.php?page=admin_invoice_generate" data-no-draft autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="generate">
  <?php if ($selectedFromSheet): ?>
    <input type="hidden" name="ids" value="<?= h(implode(',', $selectedFromSheet)) ?>">
  <?php endif; ?>
  <?php if ($clientId > 0): ?>
    <p class="help invoice-legacy-client">
      Leftover <code>client_id=<?= (int) $clientId ?></code> filter — older client folders.
      New bills use Bill as.
      <a href="index.php?page=admin_invoice_generate">Show all unpaid LIVE</a>
    </p>
    <input type="hidden" name="client_id" value="<?= (int) $clientId ?>">
  <?php endif; ?>

  <div class="orders-layout">
    <section class="card">
      <h2><?= label_with_info('Orders to invoice', 'Only unpaid rows with a LIVE URL. Banner/Textlink rows show their yearly period text instead of Article Published.') ?></h2>
      <p class="muted" style="margin-top:0">
        <?php if ($selectedFromSheet): ?>
          Rows you pushed from Order management — already ticked. Untick any you do not want on this bill.
        <?php else: ?>
          Tick the ones to bill — nothing is selected until you choose. Or Push from Completed.
        <?php endif; ?>
      </p>
      <?php if ($pickTruncated): ?>
        <p class="help">Showing <?= (int) $pickCap ?> of <?= (int) $pickTotal ?> unpaid LIVE rows. Use Order management <strong>Push unpaid</strong> to tick a smaller set.</p>
      <?php endif; ?>
      <?php if (!$invoiceable): ?>
        <div class="empty-state">
          <?php if ($selectedFromSheet): ?>
            <p>Those pushed rows are not unpaid completed with a country and client, or they are already on a draft or unpaid invoice.</p>
          <?php else: ?>
            <?php $emptyStats = $emptyStats ?: invoice_generate_empty_stats(); ?>
            <p>Nothing to tick yet:</p>
            <ul class="invoice-empty-reasons">
              <?php if ((int) ($emptyStats['completed_unpaid'] ?? 0) < 1): ?>
                <li>No unpaid completed rows with a LIVE URL.</li>
              <?php endif; ?>
              <?php if ((int) ($emptyStats['missing_country_client'] ?? 0) > 0): ?>
                <li><?= (int) $emptyStats['missing_country_client'] ?> completed unpaid <?= (int) $emptyStats['missing_country_client'] === 1 ? 'row is' : 'rows are' ?> missing country or client email/name.</li>
              <?php endif; ?>
              <?php if ((int) ($emptyStats['on_open_invoice'] ?? 0) > 0): ?>
                <li><?= (int) $emptyStats['on_open_invoice'] ?> completed unpaid <?= (int) $emptyStats['on_open_invoice'] === 1 ? 'row is' : 'rows are' ?> already on a draft or unpaid invoice.</li>
              <?php endif; ?>
              <?php if ((int) ($emptyStats['completed_unpaid'] ?? 0) > 0
                  && (int) ($emptyStats['missing_country_client'] ?? 0) < 1
                  && (int) ($emptyStats['on_open_invoice'] ?? 0) < 1): ?>
                <li>No unpaid completed rows with a LIVE URL, country, and client.</li>
              <?php endif; ?>
            </ul>
          <?php endif; ?>
          <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Open Completed orders</a>
        </div>
      <?php else: ?>
        <label class="sheet-search invoice-pick-search" for="invoice-pick-search" style="margin:0 0 0.65rem;display:flex">
          <span class="visually-hidden">Filter unpaid LIVE rows</span>
          <input id="invoice-pick-search" type="search" placeholder="Filter by site, email, country…"
                 autocomplete="off" spellcheck="false" data-no-draft>
        </label>
        <label class="invoice-check-all">
          <input type="checkbox" id="toggle-all-items" <?= $precheck ? 'checked' : '' ?>>
          Select all visible (<span data-invoice-pick-visible><?= count($invoiceable) ?></span>)
        </label>
        <p class="help" id="invoice-pick-mixed"<?= count($billAsLabels) > 1 && $precheck ? '' : ' hidden' ?>>
          Ticked rows have different emails/names. Untick until they match — they cannot share one invoice.
        </p>
        <ul class="invoice-item-pick" id="invoice-item-pick">
          <?php foreach ($invoiceable as $row): ?>
            <?php
              $who = trim((string) ($row['client_label'] ?? ''));
              $country = trim((string) ($row['country'] ?? ''));
              $meta = trim($who . ($country !== '' ? ($who !== '' ? ' · ' : '') . $country : ''));
              $pickSearch = mb_strtolower(trim(
                  (string) ($row['site_name'] ?? '') . ' ' . $who . ' ' . $country . ' '
                  . (string) ($row['live_url'] ?? '')
              ));
            ?>
            <li data-invoice-pick-row
                data-search="<?= h($pickSearch) ?>"
                data-bill-as="<?= h($who) ?>"
                data-amount="<?= h(number_format((float) parse_money($row['decided_price'] ?? 0), 2, '.', '')) ?>">
              <label>
                <input type="checkbox" name="item_ids[]" value="<?= (int) $row['id'] ?>"
                       <?= $precheck ? 'checked' : '' ?> data-invoice-pick-item>
                <span class="invoice-pick-main">
                  <strong><?= h($row['site_name'] !== '' ? $row['site_name'] : 'Site') ?></strong>
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
        <p class="help" data-invoice-pick-empty hidden>No unpaid LIVE rows match that filter.</p>
        <p class="help"><span data-invoice-pick-count>0</span> selected</p>
        <label class="invoice-group-opt">
          <input type="checkbox" name="group_same_amount" value="1" data-no-draft autocomplete="off">
          Group lines that share the same amount (qty &gt; 1)
        </label>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?= label_with_info('Invoice details', 'New invoice: number is assigned automatically. Add to existing: pick an unpaid bill with the same bill-as. Date and extra details apply to new invoices only.') ?></h2>

      <fieldset class="invoice-dest-mode">
        <legend class="visually-hidden">Invoice destination</legend>
        <label>
          <input type="radio" name="destination" value="new" checked data-invoice-dest>
          New invoice
        </label>
        <label>
          <input type="radio" name="destination" value="existing" data-invoice-dest
                 <?= $openInvoices ? '' : 'disabled' ?>>
          Add to existing
        </label>
      </fieldset>

      <div id="invoice-dest-existing" hidden>
        <?php if (!$openInvoices): ?>
          <p class="help">No unpaid invoices yet — generate a new one first.</p>
        <?php else: ?>
          <label for="invoice-existing-search">Find unpaid invoice</label>
          <input id="invoice-existing-search" type="search" placeholder="Number or bill-as…"
                 autocomplete="off" spellcheck="false" data-no-draft>
          <label for="existing_invoice_id" class="visually-hidden">Unpaid invoice</label>
          <select name="existing_invoice_id" id="existing_invoice_id" size="7" data-no-draft>
            <option value="">— pick an unpaid invoice —</option>
            <?php foreach ($openInvoices as $openInv): ?>
              <?php
                $openNum = (string) ($openInv['invoice_number'] ?? '');
                $openBill = invoice_display_bill_as($openInv);
                $openTotal = (float) ($openInv['total_amount'] ?? 0);
                $openSearch = mb_strtolower($openNum . ' ' . $openBill);
              ?>
              <option value="<?= (int) $openInv['id'] ?>"
                      data-number="<?= h($openNum) ?>"
                      data-total="<?= h(number_format($openTotal, 2, '.', '')) ?>"
                      data-bill-as="<?= h($openBill) ?>"
                      data-search="<?= h($openSearch) ?>">
                <?= h($openNum) ?>
                <?= $openBill !== '' ? ' · ' . h($openBill) : '' ?>
                · <?= h(format_euro($openTotal)) ?>
                · <?= (int) ($openInv['item_count'] ?? 0) ?> line<?= (int) ($openInv['item_count'] ?? 0) === 1 ? '' : 's' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="help" id="invoice-append-preview" hidden></p>
        <?php endif; ?>
      </div>

      <div id="invoice-dest-new">
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
          <input id="invoice_date" name="invoice_date" type="date" value="<?= h(date('Y-m-d')) ?>">
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

      </div>

      <p class="actions" style="margin-top:1.1rem">
        <button class="btn large" type="submit" id="invoice-generate-submit" <?= ($invoiceable && $precheck && count($billAsLabels) <= 1) ? '' : 'disabled' ?>>Generate invoice</button>
      </p>
    </section>
  </div>
</form>
<script>
(function () {
  var all = document.getElementById('toggle-all-items');
  var search = document.getElementById('invoice-pick-search');
  var submit = document.getElementById('invoice-generate-submit');
  var bill = document.getElementById('bill_to_name');
  var mixed = document.getElementById('invoice-pick-mixed');
  var countEl = document.querySelector('[data-invoice-pick-count]');
  var visibleEl = document.querySelector('[data-invoice-pick-visible]');
  var emptyEl = document.querySelector('[data-invoice-pick-empty]');
  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-invoice-pick-row]'));
  var group = document.querySelector('input[name="group_same_amount"]');
  var destNew = document.getElementById('invoice-dest-new');
  var destExist = document.getElementById('invoice-dest-existing');
  var destRadios = document.querySelectorAll('[data-invoice-dest]');
  var existSelect = document.getElementById('existing_invoice_id');
  var existSearch = document.getElementById('invoice-existing-search');
  var appendPreview = document.getElementById('invoice-append-preview');
  var dateEl = document.getElementById('invoice_date');
  var form = document.querySelector('.invoice-generate-form');
  if (group) group.checked = false;

  function destMode() {
    var checked = document.querySelector('[data-invoice-dest]:checked');
    return checked ? String(checked.value || 'new') : 'new';
  }
  function selectedAmount() {
    var total = 0;
    rows.forEach(function (row) {
      var cb = row.querySelector('[data-invoice-pick-item]');
      if (!cb || !cb.checked) return;
      total += parseFloat(row.getAttribute('data-amount') || '0') || 0;
    });
    return total;
  }
  function euro(n) {
    return '€' + (Math.round(n * 100) / 100).toFixed(2);
  }
  function applyDest() {
    var existing = destMode() === 'existing';
    if (destNew) destNew.hidden = existing;
    if (destExist) destExist.hidden = !existing;
    if (dateEl) {
      dateEl.required = !existing;
      dateEl.disabled = existing;
    }
    if (existSelect) existSelect.required = existing;
    sync();
  }
  function applyExistingSearch() {
    if (!existSelect || !existSearch) return;
    var q = String(existSearch.value || '').trim().toLowerCase();
    Array.prototype.forEach.call(existSelect.options, function (opt) {
      if (!opt.value) {
        opt.hidden = false;
        return;
      }
      var hay = String(opt.getAttribute('data-search') || '');
      opt.hidden = !!(q && hay.indexOf(q) === -1);
    });
  }
  function checkedCount() {
    return rows.filter(function (row) {
      var cb = row.querySelector('[data-invoice-pick-item]');
      return cb && cb.checked;
    }).length;
  }
  function uniqueBillAs(checked) {
    var seen = {};
    var out = [];
    checked.forEach(function (cb) {
      var row = cb.closest('[data-invoice-pick-row]');
      var v = row ? String(row.getAttribute('data-bill-as') || '').trim() : '';
      if (v && !seen[v]) {
        seen[v] = true;
        out.push(v);
      }
    });
    return out;
  }
  function visibleRows() {
    return rows.filter(function (row) { return row.style.display !== 'none'; });
  }
  function boxesIn(list) {
    return list.map(function (row) {
      return row.querySelector('[data-invoice-pick-item]');
    }).filter(Boolean);
  }
  function sync() {
    var vis = visibleRows();
    var visBoxes = boxesIn(vis);
    var checked = boxesIn(rows).filter(function (cb) { return cb.checked; });
    if (visibleEl) visibleEl.textContent = String(vis.length);
    if (countEl) countEl.textContent = String(checked.length);
    if (emptyEl) emptyEl.hidden = vis.length > 0;
    if (all) {
      all.checked = visBoxes.length > 0 && visBoxes.every(function (cb) { return cb.checked; });
      all.indeterminate = visBoxes.some(function (cb) { return cb.checked; }) && visBoxes.length > 0 && !all.checked;
    }
    var labels = uniqueBillAs(checked);
    var existing = destMode() === 'existing';
    var pickedInv = existSelect && existSelect.value;
    if (submit) {
      submit.disabled = checked.length < 1 || labels.length > 1 || (existing && !pickedInv);
      submit.textContent = existing && pickedInv
        ? ('Add to invoice ' + (existSelect.options[existSelect.selectedIndex].getAttribute('data-number') || pickedInv))
        : 'Generate invoice';
    }
    if (mixed) mixed.hidden = labels.length < 2;
    if (bill && document.activeElement !== bill) {
      bill.value = labels.join(', ');
    }
    if (appendPreview) {
      if (existing && pickedInv && checked.length > 0) {
        var opt = existSelect.options[existSelect.selectedIndex];
        var cur = parseFloat(opt.getAttribute('data-total') || '0') || 0;
        var add = selectedAmount();
        appendPreview.hidden = false;
        appendPreview.textContent = 'Current ' + euro(cur) + ' + selected ' + euro(add)
          + ' → ' + euro(cur + add) + '.';
      } else {
        appendPreview.hidden = true;
      }
    }
  }
  function applySearch() {
    var q = search ? String(search.value || '').trim().toLowerCase() : '';
    rows.forEach(function (row) {
      var hay = String(row.getAttribute('data-search') || '');
      row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });
    sync();
  }

  destRadios.forEach(function (r) {
    r.addEventListener('change', applyDest);
  });
  if (existSelect) existSelect.addEventListener('change', sync);
  if (existSearch) existSearch.addEventListener('input', applyExistingSearch);
  if (form) {
    form.addEventListener('submit', function (e) {
      if (destMode() !== 'existing') return;
      var n = checkedCount();
      var opt = existSelect ? existSelect.options[existSelect.selectedIndex] : null;
      var num = opt ? String(opt.getAttribute('data-number') || '') : '';
      if (!num || n < 1) return;
      if (!window.confirm('Add ' + n + ' site' + (n === 1 ? '' : 's') + ' to invoice ' + num + '?')) {
        e.preventDefault();
      }
    });
  }
  if (all) {
    all.addEventListener('change', function () {
      boxesIn(visibleRows()).forEach(function (cb) { cb.checked = all.checked; });
      sync();
    });
  }
  document.querySelectorAll('[data-invoice-pick-item]').forEach(function (cb) {
    cb.addEventListener('change', sync);
  });
  if (search) {
    search.addEventListener('input', applySearch);
  }
  applyDest();
})();
</script>
<?php render_footer('admin'); ?>
