<?php
$user = require_admin();
ensure_order_schema();

$clientId = (int) get('id');
$client = get_order_client($clientId);
if (!$client) {
    flash('error', 'Client sheet not found.');
    redirect('index.php?page=admin_orders');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'rename') {
            update_order_client($clientId, (string) post('name'), (string) post('notes'));
            flash('ok', 'Client details saved.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
        if ($action === 'add_row') {
            // Save current edits first so nothing is lost
            save_order_sheet_rows(
                $clientId,
                (array) ($_POST['site_name'] ?? []),
                (array) ($_POST['owner_price'] ?? []),
                (array) ($_POST['decided_price'] ?? []),
                (array) ($_POST['live_url'] ?? [])
            );
            add_order_item($clientId);
            flash('ok', 'New site row added.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#sheet-bottom');
        }
        if ($action === 'save_sheet') {
            $n = save_order_sheet_rows(
                $clientId,
                (array) ($_POST['site_name'] ?? []),
                (array) ($_POST['owner_price'] ?? []),
                (array) ($_POST['decided_price'] ?? []),
                (array) ($_POST['live_url'] ?? [])
            );
            flash('ok', 'Saved ' . $n . ' row' . ($n === 1 ? '' : 's') . '.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
        if ($action === 'delete_row') {
            save_order_sheet_rows(
                $clientId,
                (array) ($_POST['site_name'] ?? []),
                (array) ($_POST['owner_price'] ?? []),
                (array) ($_POST['decided_price'] ?? []),
                (array) ($_POST['live_url'] ?? [])
            );
            delete_order_item((int) post('item_id'), $clientId);
            flash('ok', 'Row removed.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_order_sheet&id=' . $clientId);
    }
}

$items = list_order_items($clientId);
$totalOwner = 0.0;
$totalDecided = 0.0;
$totalProfit = 0.0;
foreach ($items as $row) {
    $totalOwner += parse_money($row['owner_price']);
    $totalDecided += parse_money($row['decided_price']);
    $totalProfit += order_profit($row['owner_price'], $row['decided_price']);
}

render_header('Order · ' . $client['name'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management', 'href' => 'index.php?page=admin_orders'],
    ['label' => $client['name']],
]); ?>

<div class="topbar">
  <div>
    <h1><?= h($client['name']) ?></h1>
    <p class="muted">Client sheet — fill each site in its own box. Profit = decided − owner.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders">All clients</a>
  </div>
</div>

<div class="orders-summary">
  <div class="orders-summary-item">
    <strong><?= count($items) ?></strong>
    <span>Sites</span>
  </div>
  <div class="orders-summary-item">
    <strong><?= h(format_money($totalOwner)) ?></strong>
    <span>Owner total</span>
  </div>
  <div class="orders-summary-item">
    <strong><?= h(format_money($totalDecided)) ?></strong>
    <span>Decided total</span>
  </div>
  <div class="orders-summary-item">
    <strong class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong>
    <span>Profit</span>
  </div>
</div>

<details class="card order-client-edit">
  <summary>Edit client name / notes</summary>
  <form method="post" style="margin-top:0.85rem">
    <input type="hidden" name="action" value="rename">
    <div class="form-grid">
      <div>
        <label for="rename_name">Client name</label>
        <input id="rename_name" name="name" value="<?= h($client['name']) ?>" required>
      </div>
      <div class="full">
        <label for="rename_notes">Notes</label>
        <textarea id="rename_notes" name="notes" rows="2"><?= h((string) ($client['notes'] ?? '')) ?></textarea>
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Save client</button>
    </p>
  </form>
</details>

<form method="post" id="order-sheet-form" class="card order-sheet-card">
  <input type="hidden" name="action" value="save_sheet" id="sheet-action">
  <input type="hidden" name="item_id" id="delete-item-id" value="">
  <div class="order-sheet-toolbar">
    <h2 style="margin:0">Sheet</h2>
    <div class="actions">
      <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add site</button>
      <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    </div>
  </div>

  <div class="order-sheet-scroll">
    <table class="order-sheet">
      <thead>
        <tr>
          <th class="col-num">#</th>
          <th class="col-site">Site name</th>
          <th class="col-price">Owner price</th>
          <th class="col-price">Decided price</th>
          <th class="col-profit">Profit</th>
          <th class="col-live">LIVE URL</th>
          <th class="col-del"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr>
          <td colspan="7" class="muted" style="padding:1rem">No rows yet — click “Add site”.</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($items as $i => $row):
          $profit = order_profit($row['owner_price'], $row['decided_price']);
          $id = (int) $row['id'];
      ?>
        <tr class="order-row" data-row>
          <td class="col-num muted"><?= $i + 1 ?></td>
          <td class="col-site">
            <input class="cell-input" type="text" name="site_name[<?= $id ?>]"
                   value="<?= h($row['site_name']) ?>" placeholder="site.com" autocomplete="off">
          </td>
          <td class="col-price">
            <input class="cell-input cell-money" type="text" inputmode="decimal"
                   name="owner_price[<?= $id ?>]" value="<?= h(format_money($row['owner_price'])) ?>"
                   data-owner placeholder="0.00" autocomplete="off">
          </td>
          <td class="col-price">
            <input class="cell-input cell-money" type="text" inputmode="decimal"
                   name="decided_price[<?= $id ?>]" value="<?= h(format_money($row['decided_price'])) ?>"
                   data-decided placeholder="0.00" autocomplete="off">
          </td>
          <td class="col-profit">
            <span class="profit-cell <?= $profit >= 0 ? 'profit-pos' : 'profit-neg' ?>" data-profit>
              <?= h(format_money($profit)) ?>
            </span>
          </td>
          <td class="col-live">
            <input class="cell-input" type="text" name="live_url[<?= $id ?>]"
                   value="<?= h($row['live_url']) ?>" placeholder="(empty until live)" autocomplete="off">
          </td>
          <td class="col-del">
            <button class="btn-link danger" type="submit"
                    onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='delete_row'; return confirm('Remove this row?');">
              Remove
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($items): ?>
      <tfoot>
        <tr>
          <td></td>
          <td><strong>Totals</strong></td>
          <td><strong data-total-owner><?= h(format_money($totalOwner)) ?></strong></td>
          <td><strong data-total-decided><?= h(format_money($totalDecided)) ?></strong></td>
          <td><strong data-total-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="help" style="margin:0.75rem 0 0" id="sheet-bottom">
    LIVE URL stays empty until you paste the published link. Profit updates as you type; click Save sheet to store changes.
  </p>
  <div class="actions-sticky">
    <button class="btn large" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add site</button>
  </div>
</form>

<script>
(function () {
  function num(v) {
    v = String(v || '').replace(/,/g, '').trim();
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function money(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }
  function refresh() {
    var ownerTotal = 0, decidedTotal = 0, profitTotal = 0;
    document.querySelectorAll('[data-row]').forEach(function (row) {
      var o = num((row.querySelector('[data-owner]') || {}).value);
      var d = num((row.querySelector('[data-decided]') || {}).value);
      var p = d - o;
      ownerTotal += o;
      decidedTotal += d;
      profitTotal += p;
      var cell = row.querySelector('[data-profit]');
      if (cell) {
        cell.textContent = money(p);
        cell.classList.toggle('profit-pos', p >= 0);
        cell.classList.toggle('profit-neg', p < 0);
      }
    });
    var to = document.querySelector('[data-total-owner]');
    var td = document.querySelector('[data-total-decided]');
    var tp = document.querySelector('[data-total-profit]');
    if (to) to.textContent = money(ownerTotal);
    if (td) td.textContent = money(decidedTotal);
    if (tp) {
      tp.textContent = money(profitTotal);
      tp.classList.toggle('profit-pos', profitTotal >= 0);
      tp.classList.toggle('profit-neg', profitTotal < 0);
    }
  }
  document.getElementById('order-sheet-form').addEventListener('input', function (e) {
    if (e.target && (e.target.hasAttribute('data-owner') || e.target.hasAttribute('data-decided'))) {
      refresh();
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
