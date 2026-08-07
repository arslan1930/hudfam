<?php
$user = require_admin();
ensure_order_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'create') {
            $id = create_order_client((string) post('name'), (string) post('notes'), (int) ($user['id'] ?? 0));
            // Start with one empty row so the sheet is ready to fill
            add_order_item($id);
            flash('ok', 'Client sheet created.');
            redirect('index.php?page=admin_order_sheet&id=' . $id);
        }
        if ($action === 'delete') {
            $id = (int) post('id');
            $client = get_order_client($id);
            if (!$client) {
                flash('error', 'Client not found.');
            } else {
                delete_order_client($id);
                flash('ok', 'Deleted sheet for “' . $client['name'] . '”.');
            }
            redirect('index.php?page=admin_orders');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_orders');
    }
}

$clients = list_order_clients();

render_header('Order management', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management'],
]); ?>

<div class="topbar">
  <div>
    <h1>Order management</h1>
    <p class="muted">One sheet per client — sites, owner price, decided price, profit, and live URL.</p>
  </div>
</div>

<div class="orders-layout">
  <section class="card">
    <h2>Client sheets</h2>
    <?php if (!$clients): ?>
      <div class="empty-state">
        <p>No client sheets yet. Create one on the right to start.</p>
      </div>
    <?php else: ?>
      <ul class="order-client-list">
        <?php foreach ($clients as $c): ?>
          <li class="order-client-row">
            <div class="order-client-main">
              <a class="order-client-name" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">
                <?= h($c['name']) ?>
              </a>
              <div class="order-client-meta muted">
                <span><?= (int) $c['item_count'] ?> site<?= (int) $c['item_count'] === 1 ? '' : 's' ?></span>
                <span>Profit <?= h(format_money($c['total_profit'])) ?></span>
              </div>
            </div>
            <div class="order-client-actions">
              <a class="btn small" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">Open sheet</a>
              <form method="post" onsubmit="return confirm(<?= h(json_encode('Delete sheet for ' . $c['name'] . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" id="new-client">
    <h2>New client sheet</h2>
    <p class="muted" style="margin-top:0">Each client gets their own sheet of sites and prices.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <label for="client_name">Client name</label>
      <input id="client_name" name="name" required placeholder="e.g. Acme SEO" autocomplete="off">
      <label for="client_notes">Notes <span class="help">(optional)</span></label>
      <textarea id="client_notes" name="notes" rows="3" placeholder="Campaign, contact, deadline…"></textarea>
      <p class="actions" style="margin-top:1rem">
        <button class="btn" type="submit">Create sheet</button>
      </p>
    </form>
  </section>
</div>
<?php render_footer('admin'); ?>
