<?php
/**
 * Blank invoice — confirm then create (POST only; never create on GET).
 */
$user = require_admin();
ensure_invoice_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_blank') {
    try {
        $id = create_blank_invoice((int) ($user['id'] ?? 0));
        flash('ok', 'Blank invoice created — fill in bill-to, items, and prices on the invoice.');
        redirect('index.php?page=admin_invoice_view&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_invoice_manual');
    }
}

render_header('Blank invoice', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices'],
    ['label' => 'Blank invoice'],
]); ?>

<div class="topbar">
  <div>
    <h1>Blank invoice</h1>
    <p class="muted">Creates a new empty bill you can fill in. Confirm below — opening or refreshing this page does not create anything.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_invoices">Back to invoices</a>
</div>

<div class="card" style="max-width:36rem">
  <p>Create a blank invoice now? You will be taken to the invoice to enter bill-to details, line items, and prices.</p>
  <form method="post" action="index.php?page=admin_invoice_manual" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_blank">
    <p class="actions">
      <button class="btn" type="submit">Create blank invoice</button>
      <a class="btn secondary" href="index.php?page=admin_invoices">Cancel</a>
    </p>
  </form>
</div>
<?php render_footer('admin'); ?>
