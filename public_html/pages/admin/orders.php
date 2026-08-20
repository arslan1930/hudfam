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
                flash('ok', 'Deleted sheet for “' . $client['name'] . '”. Linked invoices (if any) stay; their client link is cleared.');
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
    <h1><?= label_with_info('Order management', 'One editable sheet per client: sites, prices, LIVE URLs, Banner/Textlink placements, and profit.') ?></h1>
    <p class="muted">One sheet per client — country, month, sites, prices, profit, live URL. Completed = LIVE URL filled.</p>
  </div>
</div>

<div class="orders-layout">
  <section class="card">
    <div class="invoice-list-toolbar" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.65rem">
      <h2 style="margin:0"><?= label_with_info('Client sheets', 'Open a sheet to edit rows. Completed count = rows with LIVE URL filled.') ?></h2>
      <?php if ($clients): ?>
      <label class="sheet-search" for="order-client-search" style="margin-left:auto">
        <span class="visually-hidden">Search clients</span>
        <input id="order-client-search" type="search" placeholder="Search client…"
               autocomplete="off" spellcheck="false" data-no-draft>
      </label>
      <?php endif; ?>
    </div>
    <?php if (!$clients): ?>
      <div class="empty-state">
        <p>No client sheets yet. Create one on the right to start.</p>
      </div>
    <?php else: ?>
      <ul class="order-client-list" id="order-client-list">
        <?php foreach ($clients as $c):
            $searchHay = mb_strtolower(trim((string) ($c['name'] ?? '') . ' ' . (string) ($c['notes'] ?? '')));
            $deleteMsg = 'Delete sheet for ' . $c['name'] . '?\n\nInvoices for this client (if any) are kept; their client link is cleared.';
            ?>
          <li class="order-client-row" data-order-client-row data-search="<?= h($searchHay) ?>">
            <div class="order-client-main">
              <a class="order-client-name" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">
                <?= h($c['name']) ?>
              </a>
              <div class="order-client-meta muted">
                <span><?= (int) $c['item_count'] ?> site<?= (int) $c['item_count'] === 1 ? '' : 's' ?></span>
                <span class="order-meta-done"><?= (int) $c['completed_count'] ?> completed</span>
                <span class="order-meta-done">Completed profit <?= h(format_money($c['completed_profit'])) ?></span>
              </div>
            </div>
            <div class="order-client-actions">
              <a class="btn small" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">Open sheet</a>
              <form method="post" onsubmit="return confirm(<?= h(json_encode($deleteMsg, JSON_UNESCAPED_UNICODE)) ?>);"
                    action="index.php?page=admin_orders">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
        <li class="muted" data-order-client-empty hidden style="list-style:none;padding:0.75rem 0">No clients match your search.</li>
      </ul>
      <script>
      (function () {
        var input = document.getElementById('order-client-search');
        var rows = document.querySelectorAll('[data-order-client-row]');
        var empty = document.querySelector('[data-order-client-empty]');
        if (!input || !rows.length) return;
        function run() {
          var q = String(input.value || '').trim().toLowerCase();
          var shown = 0;
          rows.forEach(function (row) {
            var hay = String(row.getAttribute('data-search') || '');
            var hit = !q || hay.indexOf(q) !== -1;
            row.hidden = !hit;
            if (hit) shown++;
          });
          if (empty) empty.hidden = !(q && shown === 0);
        }
        input.addEventListener('input', run);
        input.addEventListener('search', run);
      })();
      </script>
    <?php endif; ?>
  </section>

  <section class="card" id="new-client">
    <h2><?= label_with_info('New client sheet', 'Creates a new client with a blank sheet ready to fill.') ?></h2>
    <p class="muted" style="margin-top:0">Each client gets their own sheet of sites and prices.</p>
    <form method="post" autocomplete="off" action="index.php?page=admin_orders">
      <?= csrf_field() ?>
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
