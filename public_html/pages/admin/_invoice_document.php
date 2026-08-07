<?php
/** Shared printable invoice layout (bill-style). Expects $invoice + $items. */
if (!isset($invoice) || !is_array($invoice)) {
    return;
}
$items = $items ?? [];
$logo = topurlz_logo_url();
$logoFile = asset_url('assets/img/topurlz-logo.svg');
$lineNo = 0;
$adminNote = function_exists('invoice_admin_note')
    ? invoice_admin_note($invoice)
    : trim((string) ($invoice['admin_note'] ?? ''));
?>
<article class="invoice-doc" aria-label="Invoice <?= h($invoice['invoice_number']) ?>">
  <header class="invoice-doc-logohead">
    <img class="invoice-doc-logo" src="<?= h($logo) ?>" alt="topUrlz"
         onerror="this.onerror=null;this.src='<?= h($logoFile) ?>';">
  </header>

  <section class="invoice-doc-ids">
    <div>
      <span class="invoice-k">Invoice No.</span>
      <strong><?= h($invoice['invoice_number']) ?></strong>
      <?php if ($adminNote !== ''): ?>
        <div class="invoice-doc-admin-note"><?= h($adminNote) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <span class="invoice-k">Date</span>
      <strong><?= h(format_invoice_date((string) $invoice['invoice_date'])) ?></strong>
    </div>
  </section>

  <section class="invoice-doc-parties">
    <div class="invoice-party invoice-party-from">
      <div class="invoice-party-label">From / Bank details</div>
      <div class="invoice-doc-strong"><?= h($invoice['company_name']) ?></div>
      <div class="invoice-party-lines">
        <div><span>BIC (SWIFT)</span> <?= h($invoice['company_bic']) ?></div>
        <div><span>IBAN</span> <?= h($invoice['company_iban']) ?></div>
        <div><span>Phone</span> <?= h($invoice['company_phone']) ?></div>
        <div><span>Address</span> <?= h($invoice['company_address']) ?></div>
        <div><span>Registration No.</span> <?= h($invoice['company_reg_no']) ?></div>
      </div>
      <div class="invoice-doc-vat"><?= h($invoice['vat_note']) ?></div>
    </div>
    <div class="invoice-party invoice-party-to">
      <div class="invoice-party-label">Bill to</div>
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
    </div>
  </section>

  <table class="invoice-doc-table">
    <thead>
      <tr>
        <th class="col-line">#</th>
        <th>Description</th>
        <th class="num">Amount</th>
        <th class="num">Qty</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($items): ?>
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

  <section class="invoice-doc-summary">
    <div class="invoice-doc-paybox">
      <div class="invoice-party-label">Payment details</div>
      <div><strong><?= h($invoice['company_name']) ?></strong></div>
      <div>IBAN <?= h($invoice['company_iban']) ?></div>
      <div>BIC <?= h($invoice['company_bic']) ?></div>
      <div class="invoice-doc-vat"><?= h($invoice['vat_note']) ?></div>
    </div>
    <div class="invoice-doc-totals">
      <div class="invoice-total-row">
        <span>Currency</span>
        <strong><?= h((string) ($invoice['currency'] ?? 'EUR')) ?></strong>
      </div>
      <div class="invoice-total-row invoice-total-grand">
        <span>TOTAL</span>
        <strong><?= h(format_euro($invoice['total_amount'])) ?></strong>
      </div>
    </div>
  </section>

  <footer class="invoice-doc-footer">
    Thank you for your business — <?= h($invoice['company_name'] !== '' ? $invoice['company_name'] : 'Topurlz Ltd') ?>
  </footer>
</article>
