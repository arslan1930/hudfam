<?php
/**
 * Shared printable / editable invoice layout.
 * Expects $invoice + $items.
 * Set $editable = true for blank-invoice in-document editing (not for print).
 */
if (!isset($invoice) || !is_array($invoice)) {
    return;
}
$items = $items ?? [];
$editable = !empty($editable);
$logo = topurlz_logo_url();
$logoFile = asset_url('assets/img/topurlz-logo.svg');
$lineNo = 0;
$adminNote = function_exists('invoice_admin_note')
    ? invoice_admin_note($invoice)
    : trim((string) ($invoice['admin_note'] ?? ''));

/** Ensure at least one empty editable row when there are no items. */
$editRows = $items;
if ($editable && !$editRows) {
    $editRows = [[
        'description' => '',
        'amount' => '',
        'qty' => 1,
        'line_total' => 0,
    ]];
}
?>
<article class="invoice-doc<?= $editable ? ' invoice-doc-editable' : '' ?>" aria-label="Invoice <?= h($invoice['invoice_number']) ?>">
  <header class="invoice-doc-logohead">
    <img class="invoice-doc-logo" src="<?= h($logo) ?>" alt="topUrlz"
         onerror="this.onerror=null;this.src='<?= h($logoFile) ?>';">
  </header>

  <section class="invoice-doc-ids">
    <div>
      <span class="invoice-k">Invoice No.</span>
      <strong><?= h($invoice['invoice_number']) ?></strong>
      <?php if ($editable): ?>
        <label class="visually-hidden" for="admin_note">Note</label>
        <input id="admin_note" class="invoice-edit-note" name="admin_note" type="text" maxlength="255"
               value="<?= h($adminNote) ?>" placeholder="note..">
      <?php elseif ($adminNote !== ''): ?>
        <div class="invoice-doc-admin-note"><?= h($adminNote) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <span class="invoice-k">Date</span>
      <?php if ($editable): ?>
        <input class="invoice-edit-date" name="invoice_date" type="date"
               value="<?= h((string) $invoice['invoice_date']) ?>" required>
      <?php else: ?>
        <strong><?= h(format_invoice_date((string) $invoice['invoice_date'])) ?></strong>
      <?php endif; ?>
    </div>
  </section>

  <section class="invoice-doc-parties">
    <div class="invoice-party invoice-party-from">
      <div class="invoice-party-label">From / Bank details</div>
      <?php if ($editable): ?>
        <input class="invoice-edit-input invoice-edit-strong" name="company_name"
               value="<?= h($invoice['company_name']) ?>" placeholder="Company name">
        <div class="invoice-party-lines invoice-edit-party-lines">
          <div><span>BIC (SWIFT)</span> <input name="company_bic" value="<?= h($invoice['company_bic']) ?>"></div>
          <div><span>IBAN</span> <input name="company_iban" value="<?= h($invoice['company_iban']) ?>"></div>
          <div><span>Phone</span> <input name="company_phone" value="<?= h($invoice['company_phone']) ?>"></div>
          <div><span>Address</span> <input name="company_address" value="<?= h($invoice['company_address']) ?>"></div>
          <div><span>Registration No.</span> <input name="company_reg_no" value="<?= h($invoice['company_reg_no']) ?>"></div>
        </div>
        <input class="invoice-edit-input invoice-edit-vat" name="vat_note"
               value="<?= h($invoice['vat_note']) ?>" placeholder="VAT note">
      <?php else: ?>
        <div class="invoice-doc-strong"><?= h($invoice['company_name']) ?></div>
        <div class="invoice-party-lines">
          <div><span>BIC (SWIFT)</span> <?= h($invoice['company_bic']) ?></div>
          <div><span>IBAN</span> <?= h($invoice['company_iban']) ?></div>
          <div><span>Phone</span> <?= h($invoice['company_phone']) ?></div>
          <div><span>Address</span> <?= h($invoice['company_address']) ?></div>
          <div><span>Registration No.</span> <?= h($invoice['company_reg_no']) ?></div>
        </div>
        <div class="invoice-doc-vat"><?= h($invoice['vat_note']) ?></div>
      <?php endif; ?>
    </div>
    <div class="invoice-party invoice-party-to">
      <div class="invoice-party-label">Bill to</div>
      <?php if ($editable): ?>
        <input class="invoice-edit-input invoice-edit-strong" name="bill_to_name"
               value="<?= h($invoice['bill_to_name']) ?>" placeholder="Client / company name" required>
        <textarea class="invoice-edit-textarea" name="bill_to_address" rows="2"
                  placeholder="Address"><?= h((string) $invoice['bill_to_address']) ?></textarea>
        <div class="invoice-party-lines invoice-edit-party-lines">
          <div><span>Company reg / HRB</span> <input name="bill_to_hrb" value="<?= h($invoice['bill_to_hrb']) ?>" placeholder="—"></div>
          <div><span>Ust-IdNr</span> <input name="bill_to_vat" value="<?= h($invoice['bill_to_vat']) ?>" placeholder="—"></div>
          <div><span>Supplier number</span> <input name="supplier_number" value="<?= h($invoice['supplier_number'] !== '' ? $invoice['supplier_number'] : 'NEW') ?>"></div>
          <div><span>Cost center</span> <input name="cost_center" value="<?= h($invoice['cost_center']) ?>" placeholder="—"></div>
          <div><span>Orderer</span> <input name="orderer" value="<?= h($invoice['orderer']) ?>" placeholder="—"></div>
        </div>
      <?php else: ?>
        <div class="invoice-doc-strong"><?= h($invoice['bill_to_name']) ?></div>
        <div class="invoice-party-lines">
          <?php if (trim((string) $invoice['bill_to_address']) !== ''): ?>
            <div class="invoice-doc-address"><?= nl2br(h((string) $invoice['bill_to_address'])) ?></div>
          <?php endif; ?>
          <?php if (trim((string) $invoice['bill_to_hrb']) !== ''): ?>
            <div><span>Company reg / HRB</span> <?= h($invoice['bill_to_hrb']) ?></div>
          <?php endif; ?>
          <?php if (trim((string) $invoice['bill_to_vat']) !== ''): ?>
            <div><span>Ust-IdNr</span> <?= h($invoice['bill_to_vat']) ?></div>
          <?php endif; ?>
          <div><span>Supplier number</span> <?= h($invoice['supplier_number'] !== '' ? $invoice['supplier_number'] : 'NEW') ?></div>
          <?php if (trim((string) $invoice['cost_center']) !== ''): ?>
            <div><span>Cost center</span> <?= h($invoice['cost_center']) ?></div>
          <?php endif; ?>
          <?php if (trim((string) $invoice['orderer']) !== ''): ?>
            <div><span>Orderer</span> <?= h($invoice['orderer']) ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <table class="invoice-doc-table<?= $editable ? ' invoice-edit-table' : '' ?>">
    <thead>
      <tr>
        <th class="col-line">#</th>
        <th>Description</th>
        <th class="num">Amount</th>
        <th class="num">Qty</th>
        <th class="num">Total</th>
        <?php if ($editable): ?>
          <th class="col-edit-actions no-print"></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody<?= $editable ? ' id="invoice-edit-items"' : '' ?>>
      <?php if ($editable): ?>
        <?php foreach ($editRows as $item): ?>
          <?php $lineNo++; ?>
          <tr class="invoice-edit-row">
            <td class="col-line muted invoice-edit-num"><?= (int) $lineNo ?></td>
            <td>
              <textarea name="line_desc[]" rows="2" class="invoice-edit-desc"
                        placeholder="Item description"><?= h((string) ($item['description'] ?? '')) ?></textarea>
            </td>
            <td class="num">
              <input name="line_amount[]" type="text" inputmode="decimal" class="invoice-edit-amount"
                     value="<?= h($item['amount'] === '' || $item['amount'] === null ? '' : format_money($item['amount'])) ?>"
                     placeholder="0.00">
            </td>
            <td class="num">
              <input name="line_qty[]" type="number" min="1" step="1" class="invoice-edit-qty"
                     value="<?= (int) max(1, (int) ($item['qty'] ?? 1)) ?>">
            </td>
            <td class="num"><span class="invoice-edit-line-total"><?= h(format_euro(
                isset($item['line_total']) ? $item['line_total'] : (parse_money($item['amount'] ?? 0) * max(1, (int) ($item['qty'] ?? 1)))
            )) ?></span></td>
            <td class="col-edit-actions no-print">
              <button type="button" class="btn secondary small invoice-edit-remove" title="Remove item">×</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php elseif ($items): ?>
        <?php foreach ($items as $item): ?>
          <?php $lineNo++; ?>
          <tr>
            <td class="col-line muted"><?= (int) $lineNo ?></td>
            <td class="invoice-desc"><?= nl2br(h((string) $item['description'])) ?></td>
            <td class="num"><?= h(format_euro($item['amount'])) ?></td>
            <td class="num"><?= (int) $item['qty'] ?></td>
            <td class="num"><?= h(format_euro($item['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="5" class="muted">No line items.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($editable): ?>
    <div class="invoice-edit-add-row no-print">
      <button type="button" class="btn crystal small" id="invoice-edit-add">+ Add item</button>
    </div>
  <?php endif; ?>

  <section class="invoice-doc-summary">
    <div class="invoice-doc-paybox">
      <div class="invoice-party-label">Payment details</div>
      <div><strong class="invoice-pay-company"><?= h($invoice['company_name']) ?></strong></div>
      <div>IBAN <span class="invoice-pay-iban"><?= h($invoice['company_iban']) ?></span></div>
      <div>BIC <span class="invoice-pay-bic"><?= h($invoice['company_bic']) ?></span></div>
      <div class="invoice-doc-vat invoice-pay-vat"><?= h($invoice['vat_note']) ?></div>
    </div>
    <div class="invoice-doc-totals">
      <div class="invoice-total-row">
        <span>Currency</span>
        <strong><?= h((string) ($invoice['currency'] ?? 'EUR')) ?></strong>
      </div>
      <div class="invoice-total-row invoice-total-grand">
        <span>TOTAL</span>
        <strong data-invoice-grand-total><?= h(format_euro($invoice['total_amount'])) ?></strong>
      </div>
    </div>
  </section>

  <footer class="invoice-doc-footer">
    Thank you for your business — <span class="invoice-footer-company"><?= h($invoice['company_name'] !== '' ? $invoice['company_name'] : 'Topurlz Ltd') ?></span>
  </footer>
</article>
