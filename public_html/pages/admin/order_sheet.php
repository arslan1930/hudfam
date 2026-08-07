<?php
$user = require_admin();
ensure_order_schema();

$clientId = (int) get('id');
$client = get_order_client($clientId);
if (!$client) {
    flash('error', 'Client sheet not found.');
    redirect('index.php?page=admin_orders');
}

$months = order_month_names();
$yearOptions = range((int) date('Y') - 2, (int) date('Y') + 3);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'rename') {
            update_order_client($clientId, (string) post('name'), (string) post('notes'));
            flash('ok', 'Client details saved.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }

        // Always persist current site-row edits before structural changes
        $sites = (array) ($_POST['site_name'] ?? []);
        $countries = (array) ($_POST['country'] ?? []);
        $orderMonths = (array) ($_POST['order_month'] ?? []);
        $orderYears = (array) ($_POST['order_year'] ?? []);
        $owner = (array) ($_POST['owner_price'] ?? []);
        $decided = (array) ($_POST['decided_price'] ?? []);
        $urls = (array) ($_POST['live_url'] ?? []);

        if ($action === 'add_row') {
            save_order_sheet_rows($clientId, $sites, $countries, $orderMonths, $orderYears, $owner, $decided, $urls);
            add_order_item($clientId);
            flash('ok', 'New site row added.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#sheet-bottom');
        }
        if ($action === 'year_end') {
            save_order_sheet_rows($clientId, $sites, $countries, $orderMonths, $orderYears, $owner, $decided, $urls);
            $endingYear = (int) post('ending_year');
            if ($endingYear <= 0) {
                $endingYear = (int) date('Y');
            }
            add_order_year_end($clientId, $endingYear);
            flash('ok', 'Year end ' . $endingYear . ' marked — ' . ($endingYear + 1) . ' started.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#sheet-bottom');
        }
        if ($action === 'save_sheet') {
            $n = save_order_sheet_rows($clientId, $sites, $countries, $orderMonths, $orderYears, $owner, $decided, $urls);
            flash('ok', 'Saved ' . $n . ' row' . ($n === 1 ? '' : 's') . '.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
        if ($action === 'delete_row') {
            save_order_sheet_rows($clientId, $sites, $countries, $orderMonths, $orderYears, $owner, $decided, $urls);
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
$display = order_sheet_display_rows($items);

$totalOwner = 0.0;
$totalDecided = 0.0;
$totalProfit = 0.0;
$completedCount = 0;
$completedProfit = 0.0;
$siteCount = 0;
foreach ($items as $row) {
    if (($row['row_type'] ?? 'site') !== 'site') {
        continue;
    }
    $siteCount++;
    $profit = order_profit($row['owner_price'], $row['decided_price']);
    $totalOwner += parse_money($row['owner_price']);
    $totalDecided += parse_money($row['decided_price']);
    $totalProfit += $profit;
    if (order_is_completed($row)) {
        $completedCount++;
        $completedProfit += $profit;
    }
}

$colspan = 9;
$defaultYearEnd = (int) date('Y');
foreach (array_reverse($items) as $row) {
    if (($row['row_type'] ?? '') === 'site' && (int) ($row['order_year'] ?? 0) > 0) {
        $defaultYearEnd = (int) $row['order_year'];
        break;
    }
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
    <p class="muted">Client sheet — country, month, prices, profit. Completed = LIVE URL filled.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders">All clients</a>
  </div>
</div>

<div class="orders-summary orders-summary-6">
  <div class="orders-summary-item">
    <strong data-summary-sites><?= (int) $siteCount ?></strong>
    <span>Sites</span>
  </div>
  <div class="orders-summary-item orders-summary-done">
    <strong data-summary-completed><?= (int) $completedCount ?></strong>
    <span>Completed orders</span>
  </div>
  <div class="orders-summary-item orders-summary-done">
    <strong data-summary-completed-profit class="<?= $completedProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($completedProfit)) ?></strong>
    <span>Completed profit</span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-owner><?= h(format_money($totalOwner)) ?></strong>
    <span>Owner total</span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-decided><?= h(format_money($totalDecided)) ?></strong>
    <span>Decided total</span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong>
    <span>All profit</span>
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
      <button class="btn secondary" type="submit"
              onclick="document.getElementById('sheet-action').value='year_end'">
        Mark year end
      </button>
      <label class="year-end-label">
        Ending year
        <select name="ending_year" class="year-end-select">
          <?php foreach ($yearOptions as $y): ?>
            <option value="<?= (int) $y ?>" <?= $y === $defaultYearEnd ? 'selected' : '' ?>><?= (int) $y ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    </div>
  </div>

  <div class="order-sheet-scroll">
    <table class="order-sheet">
      <thead>
        <tr>
          <th class="col-num">#</th>
          <th class="col-month">Month</th>
          <th class="col-country">Country</th>
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
          <td colspan="<?= (int) $colspan ?>" class="muted" style="padding:1rem">No rows yet — click “Add site”.</td>
        </tr>
      <?php endif; ?>
      <?php
      $siteIndex = 0;
      foreach ($display as $block):
          if ($block['kind'] === 'year_end' || $block['kind'] === 'year_end_auto'):
              $from = (int) ($block['from_year'] ?? 0);
              $to = (int) ($block['to_year'] ?? ($from + 1));
              $markerId = isset($block['row']) ? (int) $block['row']['id'] : 0;
      ?>
        <tr class="order-year-end-row">
          <td colspan="<?= (int) $colspan ?>">
            <div class="order-year-end-banner">
              <span>Year <?= (int) $from ?> ended</span>
              <span class="order-year-end-sep" aria-hidden="true">·</span>
              <span><?= (int) $to ?> months started</span>
              <?php if ($markerId > 0): ?>
                <button class="btn-link danger order-year-end-remove" type="submit"
                        onclick="document.getElementById('delete-item-id').value='<?= $markerId ?>'; document.getElementById('sheet-action').value='delete_row'; return confirm('Remove this year-end marker?');">
                  Remove marker
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php
              continue;
          endif;

          $row = $block['row'];
          $profit = order_profit($row['owner_price'], $row['decided_price']);
          $id = (int) $row['id'];
          $siteIndex++;
          $done = order_is_completed($row);
          $monthVal = (int) ($row['order_month'] ?? 0);
          $yearVal = (int) ($row['order_year'] ?: date('Y'));
      ?>
        <tr class="order-row<?= $done ? ' is-completed' : '' ?>" data-row>
          <td class="col-num muted"><?= (int) $siteIndex ?></td>
          <td class="col-month">
            <div class="month-year-cell">
              <select class="cell-input cell-select" name="order_month[<?= $id ?>]" aria-label="Month">
                <option value="">Month</option>
                <?php foreach ($months as $num => $label): ?>
                  <option value="<?= (int) $num ?>" <?= $monthVal === (int) $num ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="cell-input cell-select cell-year" name="order_year[<?= $id ?>]" aria-label="Year">
                <?php foreach ($yearOptions as $y): ?>
                  <option value="<?= (int) $y ?>" <?= $yearVal === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </td>
          <td class="col-country">
            <input class="cell-input cell-hint" type="text" name="country[<?= $id ?>]"
                   value="<?= h((string) ($row['country'] ?? '')) ?>"
                   placeholder=".de .nl .com …" autocomplete="off">
          </td>
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
                   value="<?= h($row['live_url']) ?>" placeholder="(empty until live)"
                   data-live autocomplete="off">
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
      <?php if ($siteCount > 0): ?>
      <tfoot>
        <tr>
          <td></td>
          <td colspan="2"><strong>Totals</strong></td>
          <td></td>
          <td><strong data-total-owner><?= h(format_money($totalOwner)) ?></strong></td>
          <td><strong data-total-decided><?= h(format_money($totalDecided)) ?></strong></td>
          <td><strong data-total-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong></td>
          <td colspan="2">
            <span class="muted">Completed </span>
            <strong data-total-completed><?= (int) $completedCount ?></strong>
            <span class="muted"> · profit </span>
            <strong data-total-completed-profit class="<?= $completedProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($completedProfit)) ?></strong>
          </td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="help" style="margin:0.75rem 0 0" id="sheet-bottom">
    Country boxes stay empty for you to type (placeholder reminder: .de .nl …).
    Month is the month name; use <strong>Mark year end</strong> for a full-width year break and a fresh January row.
    An order counts as <strong>completed</strong> only when LIVE URL is filled.
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
    var completed = 0, completedProfit = 0, sites = 0;
    document.querySelectorAll('[data-row]').forEach(function (row) {
      sites++;
      var o = num((row.querySelector('[data-owner]') || {}).value);
      var d = num((row.querySelector('[data-decided]') || {}).value);
      var live = String((row.querySelector('[data-live]') || {}).value || '').trim();
      var p = d - o;
      ownerTotal += o;
      decidedTotal += d;
      profitTotal += p;
      if (live) {
        completed++;
        completedProfit += p;
        row.classList.add('is-completed');
      } else {
        row.classList.remove('is-completed');
      }
      var cell = row.querySelector('[data-profit]');
      if (cell) {
        cell.textContent = money(p);
        cell.classList.toggle('profit-pos', p >= 0);
        cell.classList.toggle('profit-neg', p < 0);
      }
    });
    function setText(sel, val) {
      var el = document.querySelector(sel);
      if (el) el.textContent = val;
    }
    function setMoney(sel, val) {
      var el = document.querySelector(sel);
      if (!el) return;
      el.textContent = money(val);
      el.classList.toggle('profit-pos', val >= 0);
      el.classList.toggle('profit-neg', val < 0);
    }
    setText('[data-total-owner]', money(ownerTotal));
    setText('[data-total-decided]', money(decidedTotal));
    setMoney('[data-total-profit]', profitTotal);
    setText('[data-total-completed]', String(completed));
    setMoney('[data-total-completed-profit]', completedProfit);
    setText('[data-summary-sites]', String(sites));
    setText('[data-summary-completed]', String(completed));
    setMoney('[data-summary-completed-profit]', completedProfit);
    setText('[data-summary-owner]', money(ownerTotal));
    setText('[data-summary-decided]', money(decidedTotal));
    setMoney('[data-summary-profit]', profitTotal);
  }
  var form = document.getElementById('order-sheet-form');
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
})();
</script>
<?php render_footer('admin'); ?>
