<?php
$user = require_admin();
ensure_order_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'create') {
            $id = create_order_client((string) post('name'), (string) post('notes'), (int) ($user['id'] ?? 0));
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
                $invCount = count_invoices_for_order_client($id);
                delete_order_client($id);
                $msg = 'Deleted sheet for “' . $client['name'] . '”.';
                if ($invCount > 0) {
                    $msg .= ' ' . $invCount . ' invoice(s) kept; client link cleared.';
                }
                flash('ok', $msg);
            }
            redirect('index.php?page=admin_orders');
        }
        if ($action === 'archive' || $action === 'restore') {
            $id = (int) post('id');
            $client = get_order_client($id);
            if (!$client) {
                flash('error', 'Client not found.');
            } else {
                $archiving = $action === 'archive';
                set_order_client_archived($id, $archiving);
                flash('ok', ($archiving ? 'Archived' : 'Restored') . ' “' . $client['name'] . '”.');
            }
            $redir = 'index.php?page=admin_orders';
            if ($action === 'archive') {
                // stay on active list
            } else {
                $redir .= '&filter=archived';
            }
            redirect($redir);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_orders');
    }
}

$filter = (string) get('filter');
if (!in_array($filter, ['all', 'unpaid', 'completed', 'archived'], true)) {
    $filter = 'all';
}
$sort = (string) get('sort');
if (!in_array($sort, ['name', 'updated', 'unpaid'], true)) {
    $sort = 'name';
}
$q = trim((string) get('q'));

$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$listOpts = [
    'filter' => $filter,
    'sort' => $sort,
    'q' => $q,
];
$totalClients = count_order_clients($listOpts);
$totalPages = max(1, (int) ceil($totalClients / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$clients = list_order_clients($listOpts + [
    'limit' => $perPage,
    'offset' => ($pageNum - 1) * $perPage,
]);

$listBase = 'index.php?page=admin_orders';
$qs = static function (array $overrides) use ($filter, $sort, $q, $listBase): string {
    $params = array_merge([
        'filter' => $filter,
        'sort' => $sort,
        'q' => $q,
        'p' => 1,
    ], $overrides);
    $bits = [];
    foreach ($params as $k => $v) {
        $v = (string) $v;
        if ($v === '' || ($k === 'filter' && $v === 'all') || ($k === 'sort' && $v === 'name') || ($k === 'p' && $v === '1')) {
            continue;
        }
        $bits[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    return $bits ? ($listBase . '&' . implode('&', $bits)) : $listBase;
};

render_header('Order management', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Order management', 'One editable sheet per client: sites, prices, LIVE URLs, Banner/Textlink placements, and profit.') ?></h1>
    <p class="muted">One sheet per client — country, month, sites, prices, profit, live URL. Completed = LIVE URL filled. Unpaid LIVE rows are ready to invoice.</p>
  </div>
</div>

<?= guide_orders() ?>

<div class="orders-layout">
  <section class="card">
    <div class="invoice-list-toolbar" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.65rem">
      <h2 style="margin:0"><?= label_with_info('Client sheets', 'Open a sheet to edit rows. Completed count = rows with LIVE URL filled.') ?></h2>
      <form method="get" action="index.php" class="actions" style="margin-left:auto;flex-wrap:wrap;gap:0.45rem;align-items:center">
        <input type="hidden" name="page" value="admin_orders">
        <label class="sheet-search" for="order-client-search" style="margin:0">
          <span class="visually-hidden">Search clients</span>
          <input id="order-client-search" type="search" name="q" value="<?= h($q) ?>"
                 placeholder="Search client…" autocomplete="off" spellcheck="false" data-no-draft>
        </label>
        <select name="filter" aria-label="Filter clients" onchange="this.form.submit()">
          <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All active</option>
          <option value="unpaid" <?= $filter === 'unpaid' ? 'selected' : '' ?>>Has unpaid LIVE</option>
          <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Has completed</option>
          <option value="archived" <?= $filter === 'archived' ? 'selected' : '' ?>>Archived</option>
        </select>
        <select name="sort" aria-label="Sort clients" onchange="this.form.submit()">
          <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Sort: name</option>
          <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Sort: updated</option>
          <option value="unpaid" <?= $sort === 'unpaid' ? 'selected' : '' ?>>Sort: unpaid amount</option>
        </select>
        <button class="btn secondary small" type="submit">Apply</button>
      </form>
    </div>
    <?php if (!$clients): ?>
      <div class="empty-state">
        <p><?= ($q !== '' || $filter !== 'all') ? 'No clients match this filter.' : 'No client sheets yet. Create one on the right to start.' ?></p>
        <?php if ($q !== '' || $filter !== 'all'): ?>
          <p><a class="btn secondary" href="<?= h($listBase) ?>">Clear filters</a></p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="order-client-list" id="order-client-list">
        <?php foreach ($clients as $c):
            $unpaidLive = (int) ($c['unpaid_live_count'] ?? 0);
            $unpaidDecided = (float) ($c['unpaid_decided'] ?? 0);
            $invCount = count_invoices_for_order_client((int) $c['id']);
            $deleteMsg = 'Delete sheet for ' . $c['name'] . '?';
            if ($invCount > 0) {
                $deleteMsg .= "\n\n" . $invCount . ' invoice(s) will be kept, but their client link will be cleared.';
            } else {
                $deleteMsg .= "\n\nInvoices for this client (if any) are kept; their client link is cleared.";
            }
            ?>
          <li class="order-client-row">
            <div class="order-client-main">
              <a class="order-client-name" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">
                <?= h($c['name']) ?>
              </a>
              <div class="order-client-meta muted">
                <span><?= (int) $c['item_count'] ?> site<?= (int) $c['item_count'] === 1 ? '' : 's' ?></span>
                <span class="order-meta-done"><?= (int) $c['completed_count'] ?> completed</span>
                <span class="order-meta-done">Completed profit <?= h(format_money($c['completed_profit'])) ?></span>
                <?php if ($unpaidLive > 0): ?>
                  <span><strong><?= $unpaidLive ?></strong> unpaid LIVE · <?= h(format_money($unpaidDecided)) ?></span>
                <?php endif; ?>
                <?php if ($invCount > 0): ?>
                  <span><?= (int) $invCount ?> invoice<?= (int) $invCount === 1 ? '' : 's' ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="order-client-actions">
              <a class="btn secondary small" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $c['id'] ?>">Open sheet</a>
              <?php if ($unpaidLive > 0 && !order_client_is_archived($c)): ?>
                <a class="btn small" href="index.php?page=admin_invoice_generate&amp;client_id=<?= (int) $c['id'] ?>">Invoice</a>
              <?php endif; ?>
              <?php if ($invCount > 0): ?>
                <a class="btn secondary small" href="index.php?page=admin_invoices&amp;client_id=<?= (int) $c['id'] ?>">Invoices</a>
              <?php endif; ?>
              <?php if (order_client_is_archived($c)): ?>
                <form method="post" action="index.php?page=admin_orders" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn secondary small" type="submit">Restore</button>
                </form>
              <?php else: ?>
                <form method="post" action="index.php?page=admin_orders" style="display:inline"
                      onsubmit="return confirm(<?= h(json_encode('Archive ' . $c['name'] . '? It will hide from the default list.', JSON_UNESCAPED_UNICODE)) ?>);">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="archive">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn secondary small" type="submit">Archive</button>
                </form>
              <?php endif; ?>
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
      </ul>
      <?php if ($totalPages > 1): ?>
      <p class="muted" style="margin-top:0.85rem">
        Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
        · <?= (int) $totalClients ?> client<?= $totalClients === 1 ? '' : 's' ?>
        <?php if ($pageNum > 1): ?>
          · <a href="<?= h($qs(['p' => $pageNum - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($pageNum < $totalPages): ?>
          · <a href="<?= h($qs(['p' => $pageNum + 1])) ?>">Next</a>
        <?php endif; ?>
      </p>
      <?php endif; ?>
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
