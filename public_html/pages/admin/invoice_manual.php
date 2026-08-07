<?php
/**
 * Blank invoice — create an empty bill in the normal invoice format, then edit on the invoice.
 */
$user = require_admin();
ensure_invoice_schema();

try {
    $id = create_blank_invoice((int) ($user['id'] ?? 0));
    flash('ok', 'Blank invoice created — fill in bill-to, items, and prices on the invoice.');
    redirect('index.php?page=admin_invoice_view&id=' . $id);
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect('index.php?page=admin_invoices');
}
