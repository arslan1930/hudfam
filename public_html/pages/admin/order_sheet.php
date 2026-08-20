<?php
$user = require_admin();
ensure_order_schema();

$clientId = (int) get('id');
$client = get_order_client($clientId);
if (!$client) {
    flash('error', 'Client sheet not found.');
    redirect('index.php?page=admin_orders');
}

// Download whole sheet (CSV or Excel) before any HTML
$download = strtolower((string) get('download'));
if ($download === 'csv' || $download === 'xls' || $download === 'excel') {
    $exportRows = order_sheet_export_rows($clientId);
    if ($download === 'csv') {
        order_sheet_download_csv($client, $exportRows);
    } else {
        order_sheet_download_xls($client, $exportRows);
    }
    exit;
}

$months = order_month_names();
$yearNow = (int) date('Y');
$yearMax = max(2030, $yearNow + 2);
$yearOptions = range(2018, $yearMax);

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
        $notes = (array) ($_POST['site_note'] ?? []);
        $placements = (array) ($_POST['placement_type'] ?? []);
        $countries = (array) ($_POST['country'] ?? []);
        $orderMonths = (array) ($_POST['order_month'] ?? []);
        $endMonths = (array) ($_POST['period_end_month'] ?? []);
        $orderYears = (array) ($_POST['order_year'] ?? []);
        $owner = (array) ($_POST['owner_price'] ?? []);
        $decided = (array) ($_POST['decided_price'] ?? []);
        $urls = (array) ($_POST['live_url'] ?? []);

        if ($action === 'add_row') {
            save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            add_order_item($clientId);
            flash('ok', 'New site row added.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#sheet-bottom');
        }
        if ($action === 'year_end') {
            save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            $endingYear = (int) post('ending_year');
            if ($endingYear <= 0) {
                $endingYear = (int) date('Y');
            }
            add_order_year_end($clientId, $endingYear);
            flash('ok', 'Year end ' . $endingYear . ' marked — ' . ($endingYear + 1) . ' started.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#sheet-bottom');
        }
        if ($action === 'save_sheet') {
            $n = save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            flash('ok', 'Saved ' . $n . ' row' . ($n === 1 ? '' : 's') . '.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
        if ($action === 'delete_row') {
            save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            delete_order_item((int) post('item_id'), $clientId);
            flash('ok', 'Row removed.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId);
        }
        if ($action === 'mark_paid') {
            save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, $clientId, true);
            flash('ok', 'Row marked as paid.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#row-' . $itemId);
        }
        if ($action === 'unmark_paid') {
            save_order_sheet_rows($clientId, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls);
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, $clientId, false);
            flash('ok', 'Paid mark removed.');
            redirect('index.php?page=admin_order_sheet&id=' . $clientId . '#row-' . $itemId);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=admin_order_sheet&id=' . $clientId);
    }
}

$items = list_order_items($clientId);
$display = order_sheet_display_rows($items);
$unpaidLiveCount = count_order_client_unpaid_live($clientId);
$countryCatalog = [];
try {
    seed_countries_if_empty(db());
    foreach (list_countries(null, true) as $cRow) {
        $nm = trim((string) ($cRow['name'] ?? ''));
        if ($nm !== '') {
            $countryCatalog[] = $nm;
        }
    }
} catch (Throwable $e) {
    $countryCatalog = [];
}

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

$colspan = 11;
$placementOptions = order_placement_options();
$defaultYearEnd = (int) date('Y');
foreach (array_reverse($items) as $row) {
    if (($row['row_type'] ?? '') === 'site' && (int) ($row['order_year'] ?? 0) > 0) {
        $defaultYearEnd = (int) $row['order_year'];
        break;
    }
}
// Keep any stored years in the dropdown so Save cannot clamp/corrupt them.
foreach ($items as $row) {
    $y = (int) ($row['order_year'] ?? 0);
    if ($y >= 2018 && !in_array($y, $yearOptions, true)) {
        $yearOptions[] = $y;
    }
}
sort($yearOptions);

render_header('Order · ' . $client['name'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management', 'href' => 'index.php?page=admin_orders'],
    ['label' => $client['name']],
]); ?>

<div class="topbar">
  <div>
    <h1><?= h($client['name']) ?><?php if (order_client_is_archived($client)): ?> <span class="badge">Archived</span><?php endif; ?></h1>
    <p class="muted">Client sheet — country, month, prices, profit. Completed = LIVE URL filled.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders">All clients</a>
    <?php if (order_client_is_archived($client)): ?>
      <form method="post" action="index.php?page=admin_orders" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="id" value="<?= (int) $clientId ?>">
        <button class="btn" type="submit">Restore client</button>
      </form>
    <?php endif; ?>
    <?php if ($unpaidLiveCount > 0): ?>
      <a class="btn" href="index.php?page=admin_invoice_generate&amp;client_id=<?= (int) $clientId ?>">
        Generate invoice (<?= (int) $unpaidLiveCount ?> unpaid)
      </a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=admin_invoice_generate&amp;client_id=<?= (int) $clientId ?>">Generate invoice</a>
    <?php endif; ?>
  </div>
</div>
<?php if (order_client_is_archived($client)): ?>
<div class="card" style="margin-bottom:1rem">
  <p style="margin:0">This client is <strong>archived</strong> and hidden from the default Order management list. You can still view and edit the sheet, or restore it.</p>
</div>
<?php endif; ?>

<div class="orders-summary orders-summary-6">
  <div class="orders-summary-item">
    <strong data-summary-sites><?= (int) $siteCount ?></strong>
    <span><?= label_with_info('Sites', 'Number of site rows on this sheet (not year-end markers).') ?></span>
  </div>
  <div class="orders-summary-item orders-summary-done">
    <strong data-summary-completed><?= (int) $completedCount ?></strong>
    <span><?= label_with_info('Completed orders', 'Rows with a LIVE URL filled.') ?></span>
  </div>
  <div class="orders-summary-item orders-summary-done">
    <strong data-summary-completed-profit class="<?= $completedProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($completedProfit)) ?></strong>
    <span><?= label_with_info('Completed profit', 'Profit only from rows that have a LIVE URL.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-owner><?= h(format_money($totalOwner)) ?></strong>
    <span><?= label_with_info('Owner total', 'Sum of all owner prices on the sheet.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-decided><?= h(format_money($totalDecided)) ?></strong>
    <span><?= label_with_info('Decided total', 'Sum of all decided (client) prices on the sheet.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong>
    <span><?= label_with_info('All profit', 'Decided total − Owner total for every site row.') ?></span>
  </div>
</div>

<details class="card order-client-edit">
  <summary>Edit client name / notes</summary>
  <form method="post" style="margin-top:0.85rem"
        action="index.php?page=admin_order_sheet&amp;id=<?= (int) $clientId ?>">
    <?= csrf_field() ?>
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

<form method="post" id="order-sheet-form" class="card order-sheet-card"
      action="index.php?page=admin_order_sheet&amp;id=<?= (int) $clientId ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_sheet" id="sheet-action">
  <input type="hidden" name="item_id" id="delete-item-id" value="">
  <div class="order-sheet-toolbar">
    <div class="order-sheet-toolbar-left">
      <h2 style="margin:0" class="with-info-heading"><?= label_with_info('Sheet', 'Fill sites, prices, LIVE URL, and months. Use search to find rows. Save sheet to store changes on the server.') ?></h2>
      <label class="sheet-search" for="sheet-search">
        <span class="visually-hidden">Search sheet</span>
        <input id="sheet-search" type="search" placeholder="Search sheet…"
               autocomplete="off" spellcheck="false" data-no-draft>
        <span class="sheet-search-meta muted" data-sheet-search-meta hidden></span>
      </label>
    </div>
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
          <th class="col-site"><?= label_with_info('Site name', 'Website or domain for this row (e.g. site.com).') ?></th>
          <th class="col-placement"><?= label_with_info('Banner / Textlink', 'Leave empty for normal article rows. Choose Banner or Textlink only when this placement is not an article — then LIVE URL is required and invoices use start/end months.') ?></th>
          <th class="col-country"><?= label_with_info('Country', 'Optional country or TLD reminder (e.g. .de .nl .com). Stays empty unless you type something.') ?></th>
          <th class="col-price"><?= label_with_info('Owner price', 'What you pay the site owner / publisher.') ?></th>
          <th class="col-price"><?= label_with_info('Decided price', 'What the client pays you. Profit = Decided − Owner.') ?></th>
          <th class="col-live"><?= label_with_info('LIVE URL', 'For articles: fill when the piece is live (marks the order completed). For Banner/Textlink: required like the site name. When filled, Owner and Decided prices cannot be empty, and Decided must be greater than 0.') ?></th>
          <th class="col-paid"><?= label_with_info('Paid', 'Click Paid when this row is paid. Paid rows cannot be added to a new invoice.') ?></th>
          <th class="col-profit"><?= label_with_info('Profit', 'Auto-calculated: Decided price − Owner price.') ?></th>
          <th class="col-month"><?= label_with_info('Month', 'Article month, or for Banner/Textlink the start month plus end month for the yearly period.') ?></th>
          <th class="col-del"><?= label_with_info('Remove', 'Deletes this row after confirmation. Cannot be undone.') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr>
          <td colspan="<?= (int) $colspan ?>" class="muted" style="padding:1rem">No rows yet — click “Add site”.</td>
        </tr>
      <?php endif; ?>
        <tr class="sheet-search-empty" data-sheet-search-empty hidden>
          <td colspan="<?= (int) $colspan ?>" class="muted" style="padding:1rem">No rows match your search.</td>
        </tr>
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
                <button class="btn-link order-year-end-remove" type="submit"
                        onclick="document.getElementById('delete-item-id').value='<?= $markerId ?>'; document.getElementById('sheet-action').value='delete_row'; return confirm(<?= h(json_encode('Delete this year-end marker for ' . $from . '? This cannot be undone.', JSON_UNESCAPED_UNICODE)) ?>);">
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
          $paid = order_is_paid($row);
          $placement = normalize_placement_type($row['placement_type'] ?? '');
          $isPlacement = $placement !== '';
          $monthVal = (int) ($row['order_month'] ?? 0);
          $endMonthVal = (int) ($row['period_end_month'] ?? 0);
          $yearVal = (int) ($row['order_year'] ?: date('Y'));
          if ($yearVal < 2018) {
              $yearVal = 2018;
          }
          // Do not clamp high years — options already include stored values.
      ?>
        <tr class="order-row<?= $done ? ' is-completed' : '' ?><?= $paid ? ' is-paid' : '' ?><?= $isPlacement ? ' is-placement' : '' ?>"
            data-row id="row-<?= $id ?>">
          <td class="col-num muted"><?= (int) $siteIndex ?></td>
          <td class="col-site">
            <div class="site-cell">
              <input class="cell-input" type="text" name="site_name[<?= $id ?>]"
                     value="<?= h($row['site_name']) ?>" placeholder="site.com" autocomplete="off">
              <input class="cell-input cell-note" type="text" name="site_note[<?= $id ?>]"
                     value="<?= h((string) ($row['site_note'] ?? '')) ?>"
                     placeholder="note…" maxlength="255" autocomplete="off"
                     aria-label="Note for <?= h($row['site_name'] !== '' ? $row['site_name'] : 'site') ?>">
            </div>
          </td>
          <td class="col-placement">
            <select class="cell-input cell-select cell-placement" name="placement_type[<?= $id ?>]"
                    data-placement aria-label="Banner or Textlink">
              <?php foreach ($placementOptions as $pval => $plabel): ?>
                <option value="<?= h($pval) ?>" <?= $placement === $pval ? 'selected' : '' ?>><?= h($plabel) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="col-country">
            <input class="cell-input cell-hint" type="text" name="country[<?= $id ?>]"
                   value="<?= h((string) ($row['country'] ?? '')) ?>"
                   list="order-country-list"
                   placeholder="Country…" autocomplete="off">
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
          <td class="col-live">
            <input class="cell-input" type="text" name="live_url[<?= $id ?>]"
                   value="<?= h($row['live_url']) ?>"
                   placeholder="<?= $isPlacement ? 'site.com (required)' : '(empty until live)' ?>"
                   data-live autocomplete="off">
          </td>
          <td class="col-paid">
            <?php if ($paid): ?>
              <button class="btn-paid is-paid" type="submit"
                      title="Click to remove paid mark"
                      data-paid="paid"
                      onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='unmark_paid';">
                Paid
              </button>
            <?php else: ?>
              <button class="btn-paid" type="submit"
                      data-paid=""
                      title="<?= $done ? 'Mark this completed row as paid' : 'Fill LIVE URL before marking paid' ?>"
                      <?= $done ? '' : 'disabled' ?>
                      onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='mark_paid';">
                Paid
              </button>
            <?php endif; ?>
          </td>
          <td class="col-profit">
            <span class="profit-cell <?= $profit >= 0 ? 'profit-pos' : 'profit-neg' ?>" data-profit>
              <?= h(format_money($profit)) ?>
            </span>
          </td>
          <td class="col-month">
            <div class="month-year-cell">
              <select class="cell-input cell-select" name="order_month[<?= $id ?>]" aria-label="<?= $isPlacement ? 'Start month' : 'Month' ?>">
                <option value=""><?= $isPlacement ? 'Start' : 'Month' ?></option>
                <?php foreach ($months as $num => $label): ?>
                  <option value="<?= (int) $num ?>" <?= $monthVal === (int) $num ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="cell-input cell-select cell-year" name="order_year[<?= $id ?>]" aria-label="Year">
                <?php foreach ($yearOptions as $y): ?>
                  <option value="<?= (int) $y ?>" <?= $yearVal === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
              </select>
              <select class="cell-input cell-select cell-end-month" name="period_end_month[<?= $id ?>]"
                      aria-label="End month" <?= $isPlacement ? '' : 'hidden' ?>>
                <option value="">End</option>
                <?php foreach ($months as $num => $label): ?>
                  <option value="<?= (int) $num ?>" <?= $endMonthVal === (int) $num ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </td>
          <td class="col-del">
            <?php
              $siteLabel = trim((string) $row['site_name']);
              $confirmMsg = $siteLabel !== ''
                  ? 'Delete this row for “' . $siteLabel . '”? This cannot be undone.'
                  : 'Delete this empty row? This cannot be undone.';
            ?>
            <button class="btn-link danger" type="submit"
                    onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='delete_row'; return confirm(<?= h(json_encode($confirmMsg, JSON_UNESCAPED_UNICODE)) ?>);">
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
          <td colspan="3"><strong>Totals</strong></td>
          <td><strong data-total-owner><?= h(format_money($totalOwner)) ?></strong></td>
          <td><strong data-total-decided><?= h(format_money($totalDecided)) ?></strong></td>
          <td>
            <span class="muted">Completed </span>
            <strong data-total-completed><?= (int) $completedCount ?></strong>
            <span class="muted"> · profit </span>
            <strong data-total-completed-profit class="<?= $completedProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($completedProfit)) ?></strong>
          </td>
          <td></td>
          <td><strong data-total-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="help" style="margin:0.75rem 0 0" id="sheet-bottom">
    Country fields suggest catalog countries (you can still type freely).
    Under each site name you can leave a short <strong>note…</strong> reminder, or leave it empty.
    <strong>Banner / Textlink</strong> stays empty by default; choose only when needed. For those rows, LIVE URL must be filled like the site name, and set start + end months (invoice text uses that period).
    Month is the month name; use <strong>Mark year end</strong> for a full-width year break and a fresh January row.
    An order counts as <strong>completed</strong> only when LIVE URL is filled — then Owner and Decided prices cannot be empty (Decided must be &gt; 0).
    Click <strong>Paid</strong> next to LIVE URL to mark that row as paid (only after LIVE URL is filled; click again to undo).
    Remove asks for confirmation before deleting a row.
    <?php if ($siteCount >= 200): ?>
      <br>This sheet has <?= (int) $siteCount ?> sites — use sheet search to jump to rows before editing.
    <?php endif; ?>
  </p>
  <?php if ($countryCatalog): ?>
  <datalist id="order-country-list">
    <?php foreach ($countryCatalog as $cname): ?>
      <option value="<?= h($cname) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <?php endif; ?>
  <div class="actions-sticky">
    <button class="btn large" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add site</button>
  </div>
</form>

<div class="card order-download-bar" id="sheet-download">
  <div>
    <strong>Download this sheet</strong>
    <p class="muted" style="margin:0.25rem 0 0">Export every row (including year-end markers) for Excel or spreadsheets.</p>
  </div>
  <div class="actions">
    <a class="btn secondary small" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $clientId ?>&amp;download=csv">Download CSV</a>
    <a class="btn secondary small" href="index.php?page=admin_order_sheet&amp;id=<?= (int) $clientId ?>&amp;download=xls">Download Excel</a>
  </div>
</div>

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
  function syncPlacementRow(row) {
    if (!row) return;
    var sel = row.querySelector('[data-placement]');
    var live = row.querySelector('[data-live]');
    var endMonth = row.querySelector('.cell-end-month');
    var startMonth = row.querySelector('select[name^="order_month"]');
    var isPlacement = !!(sel && sel.value);
    row.classList.toggle('is-placement', isPlacement);
    if (live) {
      live.placeholder = isPlacement ? 'site.com (required)' : '(empty until live)';
    }
    if (endMonth) {
      endMonth.hidden = !isPlacement;
      if (!isPlacement) endMonth.value = '';
    }
    if (startMonth) {
      var emptyOpt = startMonth.querySelector('option[value=""]');
      if (emptyOpt) emptyOpt.textContent = isPlacement ? 'Start' : 'Month';
    }
  }

  function refresh() {
    var ownerTotal = 0, decidedTotal = 0, profitTotal = 0;
    var completed = 0, completedProfit = 0, sites = 0;
    document.querySelectorAll('[data-row]').forEach(function (row) {
      syncPlacementRow(row);
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
      var paidBtn = row.querySelector('.btn-paid:not(.is-paid)');
      if (paidBtn) {
        paidBtn.disabled = !live;
        paidBtn.title = live
          ? 'Mark this completed row as paid'
          : 'Fill LIVE URL before marking paid';
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

  function rowSearchParts(row) {
    var parts = [];
    row.querySelectorAll('input, select, textarea, [data-profit], .btn-paid').forEach(function (el) {
      if (el.tagName === 'SELECT') {
        var opt = el.options[el.selectedIndex];
        parts.push(opt ? String(opt.text || '') : '');
      } else if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        parts.push(el.value || '');
      } else if (el.classList && el.classList.contains('btn-paid')) {
        parts.push(el.getAttribute('data-paid') || '');
        parts.push(el.textContent || '');
      } else {
        parts.push(el.textContent || '');
      }
    });
    return parts;
  }

  function countInText(haystack, needle) {
    if (!needle) return 0;
    var count = 0;
    var pos = 0;
    var h = String(haystack).toLowerCase();
    var n = String(needle).toLowerCase();
    while ((pos = h.indexOf(n, pos)) !== -1) {
      count++;
      pos += n.length;
    }
    return count;
  }

  function filterSheet() {
    var input = document.getElementById('sheet-search');
    if (!input) return;
    var q = String(input.value || '').trim().toLowerCase();
    var rows = document.querySelectorAll('[data-row]');
    var yearRows = document.querySelectorAll('.order-year-end-row');
    var empty = document.querySelector('[data-sheet-search-empty]');
    var meta = document.querySelector('[data-sheet-search-meta]');
    var matchingRows = 0;
    var times = 0;
    var total = rows.length;
    matchFields = [];

    document.querySelectorAll('.sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });

    rows.forEach(function (row) {
      var parts = rowSearchParts(row);
      var hitCount = 0;
      parts.forEach(function (part) {
        hitCount += countInText(part, q);
      });
      var show = !q || hitCount > 0;
      row.hidden = !show;
      if (show && q) {
        matchingRows++;
        times += hitCount;
        row.querySelectorAll('input, select, textarea').forEach(function (el) {
          var text = '';
          if (el.tagName === 'SELECT') {
            var opt = el.options[el.selectedIndex];
            text = opt ? String(opt.text || '') : '';
          } else {
            text = String(el.value || '');
          }
          if (q && countInText(text, q) > 0) {
            matchFields.push(el);
          }
        });
      }
    });
    yearRows.forEach(function (row) {
      row.hidden = !!q;
    });
    if (empty) empty.hidden = !(q && matchingRows === 0 && total > 0);
    if (matchIndex >= matchFields.length) matchIndex = matchFields.length ? 0 : -1;
    if (meta) {
      if (q) {
        meta.hidden = false;
        if (times === 0) {
          meta.textContent = '0 times · Enter to jump';
        } else if (matchIndex >= 0 && matchFields.length) {
          meta.textContent = (matchIndex + 1) + ' of ' + matchFields.length
            + ' · ' + times + (times === 1 ? ' time' : ' times')
            + ' · Enter = next';
        } else {
          meta.textContent = times + (times === 1 ? ' time' : ' times')
            + (matchingRows !== times ? ' · ' + matchingRows + (matchingRows === 1 ? ' row' : ' rows') : '')
            + ' · Enter = next';
        }
      } else {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
      }
    }
  }

  var matchFields = [];
  var matchIndex = -1;

  function jumpToMatch(dir) {
    var input = document.getElementById('sheet-search');
    if (!input) return;
    var q = String(input.value || '').trim().toLowerCase();
    if (!q) return;
    filterSheet();
    if (!matchFields.length) return;
    if (matchIndex < 0) {
      matchIndex = dir > 0 ? 0 : matchFields.length - 1;
    } else {
      matchIndex = (matchIndex + dir + matchFields.length) % matchFields.length;
    }
    var el = matchFields[matchIndex];
    if (!el) return;
    document.querySelectorAll('.sheet-search-hit').forEach(function (n) {
      n.classList.remove('sheet-search-hit');
    });
    var row = el.closest('[data-row]');
    if (row) row.hidden = false;
    el.classList.add('sheet-search-hit');
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    var meta = document.querySelector('[data-sheet-search-meta]');
    if (meta) {
      meta.hidden = false;
      meta.textContent = (matchIndex + 1) + ' of ' + matchFields.length
        + ' · Enter = next · Shift+Enter = prev';
    }
    // Keep focus in the search box so Enter can jump 2nd, 3rd, 4th… times.
    window.setTimeout(function () {
      try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
      try {
        var len = String(input.value || '').length;
        input.setSelectionRange(len, len);
      } catch (err2) {}
    }, 0);
  }

  var form = document.getElementById('order-sheet-form');
  form.addEventListener('submit', function (e) {
    var bad = null;
    document.querySelectorAll('[data-row]').forEach(function (row) {
      if (bad) return;
      var live = String((row.querySelector('[data-live]') || {}).value || '').trim();
      if (!live) return;
      var ownerEl = row.querySelector('[data-owner]');
      var decidedEl = row.querySelector('[data-decided]');
      var oRaw = String((ownerEl && ownerEl.value) || '').trim();
      var dRaw = String((decidedEl && decidedEl.value) || '').trim();
      var d = num(dRaw);
      if (oRaw === '' || dRaw === '' || d <= 0) {
        bad = row.querySelector('[name^="site_name"]');
        var site = bad ? String(bad.value || '').trim() : '';
        alert('When LIVE URL is filled, Owner and Decided prices cannot be empty, and Decided must be greater than 0'
          + (site ? ' (' + site + ').' : '.'));
        if (ownerEl && oRaw === '') ownerEl.focus();
        else if (decidedEl) decidedEl.focus();
      }
    });
    if (bad) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
  form.addEventListener('input', function (e) {
    refresh();
    if (e.target && e.target.id === 'sheet-search') {
      matchIndex = -1;
    }
    filterSheet();
  });
  form.addEventListener('change', function () {
    refresh();
    filterSheet();
  });
  var search = document.getElementById('sheet-search');
  if (search) {
    search.addEventListener('search', function () {
      matchIndex = -1;
      filterSheet();
    });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        jumpToMatch(e.shiftKey ? -1 : 1);
      }
    });
  }
  // If a highlighted match somehow has focus, Enter still advances Find.
  form.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    if (!search || !String(search.value || '').trim()) return;
    var t = e.target;
    if (!t) return;
    if (t === search) return; // handled above
    if (t.classList && t.classList.contains('sheet-search-hit')) {
      e.preventDefault();
      e.stopPropagation();
      jumpToMatch(e.shiftKey ? -1 : 1);
    }
  });

  // Unsaved-changes warning (skip after intentional submit).
  var dirty = false;
  var submitting = false;
  form.addEventListener('input', function () { dirty = true; }, true);
  form.addEventListener('change', function () { dirty = true; }, true);
  form.addEventListener('submit', function () { submitting = true; });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty || submitting) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();
</script>
<?php render_footer('admin'); ?>
