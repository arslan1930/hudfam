<?php
/** Shared printable invoice layout (sample-aligned). Expects $invoice + $items. */
if (!isset($invoice) || !is_array($invoice)) {
    return;
}
$items = $items ?? [];
?>
<article class="invoice-doc" aria-label="Invoice <?= h($invoice['invoice_number']) ?>">
  <header class="invoice-doc-top">
    <div class="invoice-doc-bank">
      <div class="invoice-doc-label">Bank details:</div>
      <div class="invoice-doc-strong"><?= h($invoice['company_name']) ?></div>
      <div>BIC(SWIFT) <?= h($invoice['company_bic']) ?></div>
      <div>IBAN <?= h($invoice['company_iban']) ?></div>
      <div>Phone no: <?= h($invoice['company_phone']) ?></div>
      <div>Address: <?= h($invoice['company_address']) ?></div>
      <div>Registration No: <?= h($invoice['company_reg_no']) ?></div>
      <div class="invoice-doc-vat"><?= h($invoice['vat_note']) ?></div>
    </div>
    <div class="invoice-doc-billto">
      <div class="invoice-doc-strong"><?= h($invoice['bill_to_name']) ?></div>
      <?php if (trim((string) $invoice['bill_to_address']) !== ''): ?>
        <div class="invoice-doc-address"><?= nl2br(h((string) $invoice['bill_to_address'])) ?></div>
      <?php endif; ?>
      <?php if (trim((string) $invoice['bill_to_hrb']) !== ''): ?>
        <div><?= h($invoice['bill_to_hrb']) ?></div>
      <?php endif; ?>
      <?php if (trim((string) $invoice['bill_to_vat']) !== ''): ?>
        <div>Ust-IdNr: <?= h($invoice['bill_to_vat']) ?></div>
      <?php endif; ?>
      <div>Supplier number: <?= h($invoice['supplier_number'] !== '' ? $invoice['supplier_number'] : 'NEW') ?></div>
      <?php if (trim((string) $invoice['cost_center']) !== ''): ?>
        <div>Cost center number:<br><?= h($invoice['cost_center']) ?></div>
      <?php endif; ?>
      <?php if (trim((string) $invoice['orderer']) !== ''): ?>
        <div>Orderer - <?= h($invoice['orderer']) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <h1 class="invoice-doc-title">INVOICE</h1>

  <table class="invoice-doc-table">
    <thead>
      <tr>
        <th class="invoice-meta-col"></th>
        <th>Description</th>
        <th class="num">Amount</th>
        <th class="num">Qty</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $rowspan = max(1, count($items));
      $first = true;
      foreach ($items as $item):
      ?>
      <tr>
        <?php if ($first): ?>
          <td class="invoice-meta-col" rowspan="<?= (int) $rowspan ?>">
            <div class="invoice-meta-block">
              <div>Date: <?= h(format_invoice_date((string) $invoice['invoice_date'])) ?></div>
              <div class="invoice-meta-number">INVOICE No. <?= h($invoice['invoice_number']) ?></div>
            </div>
          </td>
        <?php
          $first = false;
        endif;
        ?>
        <td class="invoice-desc"><?= nl2br(h((string) $item['description'])) ?></td>
        <td class="num"><?= h(format_euro($item['amount'])) ?></td>
        <td class="num"><?= (int) $item['qty'] ?></td>
        <td class="num"><?= h(format_euro($item['line_total'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?>
      <tr>
        <td class="invoice-meta-col">
          <div class="invoice-meta-block">
            <div>Date: <?= h(format_invoice_date((string) $invoice['invoice_date'])) ?></div>
            <div class="invoice-meta-number">INVOICE No. <?= h($invoice['invoice_number']) ?></div>
          </div>
        </td>
        <td colspan="4" class="muted">No line items.</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <footer class="invoice-doc-total">
    TOTAL = <?= h(format_euro($invoice['total_amount'])) ?>
  </footer>
</article>
