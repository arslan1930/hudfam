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
        if (
            empty($_POST)
            && empty($_FILES)
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        ) {
            throw new InvalidArgumentException(
                'The save was too large for this server. Use a smaller logo (under 1 MB) and try again.'
            );
        }
        if ($action === 'mark_paid') {
            mark_invoice_payment_received($id);
            flash('ok', $isManual
                ? 'Payment marked received.'
                : 'Payment marked received — linked sheet rows set to Paid.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
        if ($action === 'mark_sent') {
            mark_invoice_sent($id);
            flash('ok', 'Invoice marked as sent — waiting for payment. You can still add more unpaid sites to this bill.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
        if ($action === 'save_party') {
            $logoPlan = invoice_resolve_logo_for_save(
                $invoice,
                isset($_FILES['company_logo']) && is_array($_FILES['company_logo']) ? $_FILES['company_logo'] : null,
                (string) post('company_logo_reset') === '1',
                (string) post('company_logo_data')
            );
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
                'currency' => (string) post('currency'),
                'company_logo' => $logoPlan['value'],
            ];
            try {
                update_invoice_party_fields($id, $header);
            } catch (Throwable $partySaveEx) {
                $staged = (string) ($logoPlan['value'] ?? '');
                $prev = basename(trim((string) ($invoice['company_logo'] ?? '')));
                if ($staged !== '' && $staged !== $prev) {
                    invoice_delete_logo_file($staged);
                }
                throw $partySaveEx;
            }
            invoice_finalize_logo_cleanup($logoPlan['delete_after'] ?? null);
            $logoChanged = ($logoPlan['value'] ?? '') !== basename(trim((string) ($invoice['company_logo'] ?? '')))
                || !empty($logoPlan['delete_after']);
            flash('ok', $logoChanged
                ? 'Company, bill as, and payment details saved (including the logo).'
                : 'Company, bill as, and payment details saved.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
        if ($action === 'save_bill') {
            if ($isManual) {
                throw new InvalidArgumentException('Use Save as draft / Mark as sent on a blank invoice.');
            }
            if ($isPaid) {
                throw new InvalidArgumentException('Paid invoices cannot change line items. Company, bill as, and payment details can still be edited.');
            }
            $descs = (array) ($_POST['line_desc'] ?? []);
            $amounts = (array) ($_POST['line_amount'] ?? []);
            $qtys = (array) ($_POST['line_qty'] ?? []);
            $orderIds = (array) ($_POST['line_order_item_ids'] ?? []);
            $lines = [];
            foreach ($descs as $i => $desc) {
                $lines[] = [
                    'description' => (string) $desc,
                    'amount' => $amounts[$i] ?? 0,
                    'qty' => $qtys[$i] ?? 1,
                    'order_item_ids' => (string) ($orderIds[$i] ?? ''),
                ];
            }
            // Validate lines before touching logo files so a bad save cannot delete the current logo.
            $hasLine = false;
            foreach ($lines as $line) {
                if (trim((string) ($line['description'] ?? '')) !== '') {
                    $hasLine = true;
                    break;
                }
            }
            if (!$hasLine) {
                throw new InvalidArgumentException('Add at least one line item with a description.');
            }
            $logoPlan = invoice_resolve_logo_for_save(
                $invoice,
                isset($_FILES['company_logo']) && is_array($_FILES['company_logo']) ? $_FILES['company_logo'] : null,
                (string) post('company_logo_reset') === '1',
                (string) post('company_logo_data')
            );
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
                'currency' => (string) post('currency'),
                'company_logo' => $logoPlan['value'],
            ];
            try {
                update_generated_invoice($id, $header, $lines);
            } catch (Throwable $genSaveEx) {
                $staged = (string) ($logoPlan['value'] ?? '');
                $prev = basename(trim((string) ($invoice['company_logo'] ?? '')));
                if ($staged !== '' && $staged !== $prev) {
                    invoice_delete_logo_file($staged);
                }
                throw $genSaveEx;
            }
            invoice_finalize_logo_cleanup($logoPlan['delete_after'] ?? null);
            $logoChanged = ($logoPlan['value'] ?? '') !== basename(trim((string) ($invoice['company_logo'] ?? '')))
                || !empty($logoPlan['delete_after']);
            flash('ok', $logoChanged
                ? 'Invoice saved, including the logo. Order-sheet prices were not changed.'
                : 'Invoice saved. Order-sheet prices were not changed.');
            redirect('index.php?page=admin_invoice_view&id=' . $id);
        }
        if ($action === 'save_blank') {
            if (!$isManual) {
                throw new InvalidArgumentException('Only blank invoices can be edited.');
            }
            if ($isPaid) {
                throw new InvalidArgumentException('Paid blank invoices cannot change line items. Company, bill as, and payment details can still be edited.');
            }
            $workStatus = normalize_invoice_work_status((string) post('work_status'));
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
            $logoPlan = invoice_resolve_logo_for_save(
                $invoice,
                isset($_FILES['company_logo']) && is_array($_FILES['company_logo']) ? $_FILES['company_logo'] : null,
                (string) post('company_logo_reset') === '1',
                (string) post('company_logo_data')
            );
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
                'currency' => (string) post('currency'),
                'company_logo' => $logoPlan['value'],
            ];
            try {
                update_blank_invoice($id, $header, $lines, $workStatus);
            } catch (Throwable $blankSaveEx) {
                // Drop a newly staged upload if the blank save rejects (e.g. Done with €0).
                $staged = (string) ($logoPlan['value'] ?? '');
                $prev = basename(trim((string) ($invoice['company_logo'] ?? '')));
                if ($staged !== '' && $staged !== $prev) {
                    invoice_delete_logo_file($staged);
                }
                throw $blankSaveEx;
            }
            invoice_finalize_logo_cleanup($logoPlan['delete_after'] ?? null);
            $logoChanged = ($logoPlan['value'] ?? '') !== basename(trim((string) ($invoice['company_logo'] ?? '')))
                || !empty($logoPlan['delete_after']);
            if ($workStatus === 'done') {
                flash('ok', $logoChanged
                    ? 'Invoice saved as Done (logo updated) — waiting for payment.'
                    : 'Invoice saved as Done — waiting for payment.');
            } else {
                flash('ok', $logoChanged
                    ? 'Draft saved, including the logo. You can finish the invoice later.'
                    : 'Draft saved. You can finish the invoice later.');
            }
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
$isDraft = invoice_is_draft($invoice);
$editable = $isManual && !$isPaid && !$print;
$editableLines = !$isPaid && !$print;
$editableCompany = !$print;
$editableBill = !$print;
$editableGenerated = !$isManual && $editableLines;
$editablePartyOnly = !$editable && !$editableGenerated && ($editableCompany || $editableBill);
$linkedOrders = (!$print && !$isManual) ? list_invoice_linked_order_items($id) : [];
$invoiceEvents = $print ? [] : list_invoice_events($id);
$legacyClientId = (int) ($invoice['client_id'] ?? 0);

if ($print) {
    $editable = false;
    $editableBill = false;
    $editableLines = false;
    $editableCompany = false;
    $editableGenerated = false;
    $editablePartyOnly = false;
    $cssPhp = stylesheet_url();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Invoice <?= h($invoice['invoice_number']) ?></title>
  <link rel="stylesheet" href="<?= h($cssPhp) ?>">
  <style>
    @page { size: A4; margin: 12mm; }
    html, body.invoice-print-body {
      background: #fff !important;
      margin: 0;
      padding: 0.75rem;
    }
    @media print {
      html, body.invoice-print-body { padding: 0 !important; }
    }
  </style>
</head>
<body class="invoice-print-body">
  <p class="invoice-print-toolbar no-print">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <span class="help">Preview first — this page does not print automatically.</span>
  </p>
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
        <span class="invoice-manual-tag is-kind">(blank)</span>
      <?php endif; ?>
    </h1>
    <p class="muted">
      <?= h(format_invoice_date((string) $invoice['invoice_date'])) ?>
      · <?= h(format_euro($invoice['total_amount'])) ?>
      ·
      <?php if ($isPaid): ?>
        <span class="invoice-pay-badge is-paid">Paid</span>
      <?php elseif ($isDraft): ?>
        <span class="invoice-pay-badge is-draft" title="Not sent yet">Draft</span>
      <?php elseif ($isManual): ?>
        <span class="invoice-pay-badge is-done" title="Sent — waiting for payment">Waiting</span>
      <?php else: ?>
        <span class="invoice-pay-badge" title="Sent — waiting for payment">Waiting</span>
      <?php endif; ?>
      <?php if ($editable): ?>
        · <strong>Draft</strong> = still needs data · <strong>Waiting</strong> = sent, still unpaid
      <?php elseif ($editableGenerated): ?>
        · Edit logo, company details, bill as, payment details, and line items, then Save changes
        <?php if ($isDraft && !$isManual): ?>
          · Draft — add more sites from Generate, then Mark as sent
        <?php elseif (!$isPaid && invoice_can_append_orders($invoice)): ?>
          · Waiting for payment — add more unpaid sites, or Mark paid when it arrives
        <?php endif; ?>
        · Removing a line takes those sites off this bill — order-sheet prices stay as they are
      <?php elseif ($editablePartyOnly): ?>
        · Edit logo, company details, bill as, and payment details, then Save changes
        <?php if ($isPaid): ?>
          · Line items are locked on paid invoices
        <?php endif; ?>
      <?php elseif ($isDraft && !$isManual): ?>
        · Draft — add more sites from Generate, then Mark as sent
      <?php elseif (!$isPaid && invoice_can_append_orders($invoice)): ?>
        · Waiting for payment — add more unpaid sites to this invoice, or Mark paid when it arrives
      <?php elseif (invoice_admin_note($invoice) !== ''): ?>
        · <?= h(invoice_admin_note($invoice)) ?>
      <?php endif; ?>
      <?php if ($legacyClientId > 0): ?>
        · Leftover client-folder id <?= (int) $legacyClientId ?> — new bills use Bill as
      <?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_invoices">All invoices</a>
    <?php if ($isManual): ?>
      <a class="btn secondary" href="index.php?page=admin_invoice_manual">Blank invoice</a>
    <?php elseif (!$isPaid && invoice_can_append_orders($invoice)): ?>
      <a class="btn" href="<?= h(invoice_generate_append_href($id)) ?>">Add sites to this invoice</a>
    <?php endif; ?>
    <?php if ($editableGenerated): ?>
      <button class="btn" type="submit" form="generated-invoice-form">Save changes</button>
    <?php elseif ($editablePartyOnly): ?>
      <button class="btn" type="submit" form="party-invoice-form">Save changes</button>
    <?php endif; ?>
    <?php if ($editable): ?>
      <button class="btn secondary" type="submit" form="blank-invoice-form" name="work_status" value="draft"
              id="blank-invoice-save-draft"
              title="Save progress even if incomplete">Save as draft</button>
      <button class="btn" type="submit" form="blank-invoice-form" name="work_status" value="done"
              id="blank-invoice-save-done"
              title="Mark as sent — requires a bill total above €0">Mark as sent</button>
    <?php endif; ?>
    <?php if ($isDraft && !$isManual && !$isPaid): ?>
      <form method="post" class="inline" action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>"
            <?= confirm_data_attr('Mark this invoice as sent for payment? You can still add more unpaid sites until it is paid.') ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_sent">
        <button class="btn" type="submit">Mark as sent</button>
      </form>
    <?php endif; ?>
    <?php if (!$isPaid && !$isDraft): ?>
      <form method="post" class="inline" action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>"
            <?= confirm_data_attr(
                $isManual
                    ? 'Mark this blank invoice as paid?'
                    : 'Mark this invoice as paid? Linked unpaid sheet rows will be marked Paid.'
            ) ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_paid">
        <button class="btn-paid btn-paid-mark" type="submit">Mark paid</button>
      </form>
    <?php elseif ($isDraft): ?>
      <span class="help" style="align-self:center">Mark paid after Mark as sent</span>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>&amp;print=1" target="_blank" rel="noopener"
       title="Open a print preview. It does not print until you click Print.">Print / PDF</a>
  </div>
  <?php if ($editable): ?>
    <p class="help no-print" id="blank-invoice-save-hint" style="margin:0.35rem 0 0;text-align:right" hidden>
      Mark as sent needs a total above €0. Use <strong>Save as draft</strong> while descriptions or amounts are still incomplete.
    </p>
  <?php endif; ?>
</div>

<?php if ($editable): ?>
<form method="post" id="blank-invoice-form" class="invoice-blank-edit-form"
      enctype="multipart/form-data" autocomplete="off"
      action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>" data-no-draft>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_blank">
  <div class="invoice-preview-wrap">
    <?php include __DIR__ . '/_invoice_document.php'; ?>
  </div>
  <div class="no-print invoice-edit-save-bar" style="margin:0.75rem 0 1.25rem;text-align:right;display:flex;gap:0.5rem;justify-content:flex-end;flex-wrap:wrap">
    <button class="btn secondary" type="submit" name="work_status" value="draft">Save as draft</button>
    <button class="btn" type="submit" name="work_status" value="done">Mark as sent</button>
  </div>
</form>
<?php elseif ($editableGenerated): ?>
<form method="post" id="generated-invoice-form" class="invoice-blank-edit-form"
      enctype="multipart/form-data" autocomplete="off"
      action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>" data-no-draft>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_bill">
  <div class="invoice-preview-wrap">
    <?php include __DIR__ . '/_invoice_document.php'; ?>
  </div>
  <div class="no-print invoice-edit-save-bar" style="margin:0.75rem 0 1.25rem;text-align:right">
    <button class="btn" type="submit">Save changes</button>
  </div>
</form>
<?php elseif ($editablePartyOnly): ?>
<form method="post" id="party-invoice-form" class="invoice-blank-edit-form"
      enctype="multipart/form-data" autocomplete="off"
      action="index.php?page=admin_invoice_view&amp;id=<?= (int) $id ?>" data-no-draft>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_party">
  <div class="invoice-preview-wrap">
    <?php include __DIR__ . '/_invoice_document.php'; ?>
  </div>
  <div class="no-print invoice-edit-save-bar" style="margin:0.75rem 0 1.25rem;text-align:right">
    <button class="btn" type="submit">Save changes</button>
  </div>
</form>
<?php else: ?>
<div class="invoice-preview-wrap">
  <?php include __DIR__ . '/_invoice_document.php'; ?>
</div>
<?php endif; ?>
<?php if ($editable || $editableGenerated || $editablePartyOnly): ?>
<script>
(function () {
  var form = document.getElementById('blank-invoice-form')
    || document.getElementById('generated-invoice-form')
    || document.getElementById('party-invoice-form');
  if (!form) return;
  var tbody = document.getElementById('invoice-edit-items');
  var addBtn = document.getElementById('invoice-edit-add');
  var saveDraftBtn = document.getElementById('blank-invoice-save-draft');
  var saveDoneBtn = document.getElementById('blank-invoice-save-done');
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
    if (!tbody) return;
    tbody.querySelectorAll('.invoice-edit-row').forEach(function (row, i) {
      var num = row.querySelector('.invoice-edit-num');
      if (num) num.textContent = String(i + 1);
    });
  }
  function fieldValue(name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? (el.value || '') : '';
  }
  function setMirror(sel, value) {
    form.querySelectorAll(sel).forEach(function (el) {
      if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        if (document.activeElement !== el) el.value = value;
      } else {
        el.textContent = value;
      }
    });
  }
  function syncPaybox() {
    var name = fieldValue('company_name');
    var iban = fieldValue('company_iban');
    var bic = fieldValue('company_bic');
    var vat = fieldValue('vat_note');
    setMirror('.invoice-pay-company', name);
    setMirror('.invoice-pay-iban', iban);
    setMirror('.invoice-pay-bic', bic);
    setMirror('.invoice-pay-vat', vat);
    setMirror('.invoice-footer-company', name || 'Teqno Ltd');
  }
  function currentGrand() {
    var grand = 0;
    if (!tbody) return grand;
    tbody.querySelectorAll('.invoice-edit-row').forEach(function (row) {
      var amount = parseNum((row.querySelector('.invoice-edit-amount') || {}).value);
      var qty = Math.max(1, parseInt((row.querySelector('.invoice-edit-qty') || {}).value, 10) || 1);
      grand += amount * qty;
    });
    return grand;
  }
  function syncSaveState(grand) {
    var canDone = grand > 0;
    if (saveDoneBtn) {
      saveDoneBtn.disabled = !canDone;
      saveDoneBtn.setAttribute('aria-disabled', canDone ? 'false' : 'true');
    }
    if (saveDraftBtn) {
      saveDraftBtn.disabled = false;
      saveDraftBtn.setAttribute('aria-disabled', 'false');
    }
    if (saveHint) saveHint.hidden = canDone;
  }
  function refreshTotals() {
    var grand = 0;
    if (tbody) {
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
    }
    syncPaybox();
    syncSaveState(grand);
  }
  function syncRemove() {
    if (!tbody) return;
    var rows = tbody.querySelectorAll('.invoice-edit-row');
    rows.forEach(function (row) {
      var btn = row.querySelector('.invoice-edit-remove');
      if (btn) btn.disabled = rows.length <= 1;
    });
  }

  if (addBtn && tbody) {
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

  if (tbody) {
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
  }

  form.addEventListener('input', function (e) {
    var mirror = e.target && e.target.getAttribute && e.target.getAttribute('data-pay-mirror');
    if (mirror) {
      var target = form.querySelector('[name="' + mirror + '"]');
      if (target && target !== e.target) target.value = e.target.value || '';
      var foot = form.querySelector('.invoice-footer-company');
      if (mirror === 'company_name' && foot) {
        foot.textContent = (e.target.value || '') || 'Teqno Ltd';
      }
    }
    refreshTotals();
  });
  form.addEventListener('change', refreshTotals);
  form.addEventListener('submit', function (e) {
    var submitter = e.submitter;
    var status = submitter && submitter.name === 'work_status'
      ? String(submitter.value || '')
      : 'draft';
    if (status === 'done' && !(currentGrand() > 0)) {
      e.preventDefault();
      syncSaveState(0);
      if (saveHint) saveHint.hidden = false;
      if (typeof window.txfAlert === 'function') {
        window.txfAlert('Mark as sent needs a total above €0. Use Save as draft while the invoice is incomplete.');
      } else {
        alert('Mark as sent needs a total above €0. Use Save as draft while the invoice is incomplete.');
      }
    }
  });
  syncRemove();
  refreshTotals();

  var logoInput = form.querySelector('[data-invoice-logo-input]');
  var logoImg = form.querySelector('[data-invoice-logo-img]');
  var logoReset = form.querySelector('[data-invoice-logo-reset], [name="company_logo_reset"]');
  var logoData = form.querySelector('[data-invoice-logo-data], [name="company_logo_data"]');
  var logoHint = form.querySelector('[data-invoice-logo-hint]');
  var logoObjectUrl = null;
  var logoPending = false;
  var logoDefaultHint = logoHint ? String(logoHint.innerHTML || '') : '';
  function setLogoPreview(src) {
    if (!logoImg || !src) return;
    if (logoObjectUrl) {
      try { URL.revokeObjectURL(logoObjectUrl); } catch (e) {}
      logoObjectUrl = null;
    }
    logoImg.removeAttribute('onerror');
    logoImg.src = src;
  }
  function setLogoData(value) {
    if (logoData) logoData.value = value || '';
  }
  function markLogoDirty(msg) {
    if (logoHint) {
      logoHint.innerHTML = msg || logoDefaultHint;
    }
    if (logoReset) logoReset.disabled = false;
  }
  function setLogoPending(on) {
    logoPending = !!on;
    form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
      if (on) {
        btn.setAttribute('data-logo-was-disabled', btn.disabled ? '1' : '0');
        btn.disabled = true;
      } else if (btn.getAttribute('data-logo-was-disabled') === '0') {
        btn.disabled = false;
        btn.removeAttribute('data-logo-was-disabled');
      } else if (btn.getAttribute('data-logo-was-disabled') === '1') {
        btn.removeAttribute('data-logo-was-disabled');
      }
    });
    document.querySelectorAll('button[form="' + form.id + '"]').forEach(function (btn) {
      if (on) {
        btn.setAttribute('data-logo-was-disabled', btn.disabled ? '1' : '0');
        btn.disabled = true;
      } else if (btn.getAttribute('data-logo-was-disabled') === '0') {
        btn.disabled = false;
        btn.removeAttribute('data-logo-was-disabled');
      } else if (btn.getAttribute('data-logo-was-disabled') === '1') {
        btn.removeAttribute('data-logo-was-disabled');
      }
    });
    // Re-apply blank Done rule after logo unlock.
    if (!on) refreshTotals();
  }
  if (logoInput && logoImg) {
    logoInput.addEventListener('change', function () {
      var file = logoInput.files && logoInput.files[0];
      if (!file) {
        setLogoData('');
        setLogoPending(false);
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        setLogoData('');
        logoInput.value = '';
        setLogoPending(false);
        if (typeof window.txfAlert === 'function') {
          window.txfAlert('Logo must be under 2 MB.');
        } else {
          alert('Logo must be under 2 MB.');
        }
        return;
      }
      if (logoReset) logoReset.checked = false;
      logoObjectUrl = URL.createObjectURL(file);
      setLogoPreview(logoObjectUrl);
      markLogoDirty('Preparing logo… then save the invoice to keep it.');
      if (typeof FileReader === 'undefined') {
        setLogoData('');
        setLogoPending(false);
        markLogoDirty('New logo selected — save the invoice to keep it.');
        return;
      }
      setLogoPending(true);
      var reader = new FileReader();
      reader.onload = function () {
        var result = String(reader.result || '');
        if (result.indexOf('data:image/') === 0) {
          setLogoData(result);
          // Avoid posting file + base64 together (can blow post_max_size on shared hosts).
          try { logoInput.value = ''; } catch (err) {}
          markLogoDirty('New logo ready — save the invoice to keep it.');
        } else {
          setLogoData('');
          markLogoDirty('Could not read that image. Try a smaller PNG or JPG.');
        }
        setLogoPending(false);
      };
      reader.onerror = function () {
        setLogoData('');
        setLogoPending(false);
        markLogoDirty('Could not read that image. Try a smaller PNG or JPG.');
      };
      reader.readAsDataURL(file);
    });
  }
  if (logoReset && logoImg) {
    logoReset.addEventListener('change', function () {
      if (!logoReset.checked) return;
      if (logoInput) logoInput.value = '';
      setLogoData('');
      setLogoPending(false);
      setLogoPreview(logoImg.getAttribute('data-default-logo') || logoImg.src);
      markLogoDirty('Default logo selected — save the invoice to apply.');
    });
  }
  form.addEventListener('submit', function (e) {
    // Flush Payment-details mirrors into the named From fields before POST.
    form.querySelectorAll('[data-pay-mirror]').forEach(function (el) {
      var name = el.getAttribute('data-pay-mirror');
      if (!name) return;
      var target = form.querySelector('[name="' + name + '"]');
      if (target) target.value = el.value || '';
    });
    if (logoPending) {
      e.preventDefault();
      markLogoDirty('Still preparing the logo… wait a second, then save again.');
      if (typeof window.txfAlert === 'function') {
        window.txfAlert('Still preparing the logo. Wait a moment, then save again.');
      } else {
        alert('Still preparing the logo. Wait a moment, then save again.');
      }
      return;
    }
    // Prefer the data-URI field alone so the POST stays under shared-host limits.
    if (logoData && logoData.value && logoInput) {
      try { logoInput.value = ''; } catch (err2) {}
    }
  });

  var noteTa = form.querySelector('#admin_note, [data-note-input]');
  if (noteTa) {
    function fitNote() {
      noteTa.style.height = 'auto';
      noteTa.style.height = Math.min(Math.max(noteTa.scrollHeight, 56), 192) + 'px';
    }
    noteTa.addEventListener('input', fitNote);
    fitNote();
  }
})();
</script>
<?php endif; ?>
<?php if ($linkedOrders): ?>
<section class="card no-print invoice-om-links">
  <h2><?= label_with_info('Order management rows', 'Sites on this bill. Open Completed to jump to the sheet row.') ?></h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Site</th>
          <th>LIVE URL</th>
          <th>Article doc</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($linkedOrders as $omRow): ?>
          <?php
            $omId = (int) ($omRow['id'] ?? 0);
            $omSite = trim((string) ($omRow['site_name'] ?? ''));
            $omLive = trim((string) ($omRow['live_url'] ?? ''));
            $omDoc = trim((string) ($omRow['article_doc_url'] ?? ''));
            $omHref = 'index.php?page=admin_orders&folder=completed';
            if ($omSite !== '') {
                $omHref .= '&q=' . rawurlencode($omSite);
            }
            $omHref .= '#row-' . $omId;
          ?>
          <tr>
            <td><?= h($omSite !== '' ? $omSite : 'Site') ?></td>
            <td><?php if ($omLive !== ''): ?>
              <a href="<?= h($omLive) ?>" target="_blank" rel="noopener"><?= h($omLive) ?></a>
            <?php else: ?>
              —
            <?php endif; ?></td>
            <td><?php if ($omDoc !== ''): ?>
              <a href="<?= h($omDoc) ?>" target="_blank" rel="noopener">Open</a>
            <?php else: ?>
              —
            <?php endif; ?></td>
            <td><a class="btn secondary small" href="<?= h($omHref) ?>">Completed</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<section class="card no-print invoice-history">
  <h2><?= label_with_info('History', 'Who changed this invoice and which sites were on it then. Article doc and LIVE URL are internal — they do not print on the bill.') ?></h2>
  <?php if (!$invoiceEvents): ?>
    <div class="empty-state"><p>No history yet.</p></div>
  <?php else: ?>
    <ol class="invoice-history-list">
      <?php foreach ($invoiceEvents as $ev): ?>
        <?php
          $evWhen = (string) ($ev['created_at'] ?? '');
          $evWho = invoice_event_actor_label($ev);
          $evSummary = trim((string) ($ev['summary'] ?? ''));
          $evType = invoice_event_type_label((string) ($ev['event_type'] ?? ''));
          $evRows = (array) (($ev['payload_data'] ?? [])['rows'] ?? []);
        ?>
        <li class="invoice-history-item">
          <div class="invoice-history-meta">
            <time datetime="<?= h($evWhen) ?>"><?= h($evWhen !== '' ? $evWhen : '') ?></time>
            <span class="invoice-history-who"><?= h($evWho) ?></span>
            <span class="invoice-history-type"><?= h($evType) ?></span>
          </div>
          <?php if ($evSummary !== ''): ?>
            <p class="invoice-history-summary"><?= h($evSummary) ?></p>
          <?php endif; ?>
          <?php if ($evRows): ?>
            <details class="invoice-history-sites">
              <summary><?= count($evRows) === 1 ? '1 site' : (count($evRows) . ' sites') ?></summary>
              <ul>
                <?php foreach ($evRows as $snap): ?>
                  <?php
                    $snapSite = trim((string) ($snap['site_name'] ?? ''));
                    $snapLive = trim((string) ($snap['live_url'] ?? ''));
                    $snapDoc = trim((string) ($snap['article_doc_url'] ?? ''));
                  ?>
                  <li>
                    <strong><?= h($snapSite !== '' ? $snapSite : 'Site') ?></strong>
                    <?php if ($snapLive !== ''): ?>
                      · LIVE <a href="<?= h($snapLive) ?>" target="_blank" rel="noopener">Open</a>
                    <?php endif; ?>
                    <?php if ($snapDoc !== ''): ?>
                      · Article doc <a href="<?= h($snapDoc) ?>" target="_blank" rel="noopener">Open</a>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
<?php render_footer('admin'); ?>
