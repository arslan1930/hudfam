<?php
$user = require_admin();
ensure_order_schema();

$filter = [
    'q' => trim((string) get('q')),
    'country' => trim((string) get('country')),
    'admin_id' => max(0, (int) get('admin_id')),
    'date_from' => (string) get('date_from'),
    'date_to' => (string) get('date_to'),
    'status' => (string) get('status'),
];
if (!in_array($filter['status'], ['all', 'open', 'completed', 'unpaid', 'paid'], true)) {
    $filter['status'] = 'all';
}
$perPage = (int) get('per', 100);
if (!in_array($perPage, [50, 100, 250], true)) {
    $perPage = 100;
}
$pageNum = max(1, (int) get('p', 1));

$ordersQs = static function (array $overrides = []) use ($filter, $perPage, $pageNum): string {
    $params = array_merge([
        'page' => 'admin_orders',
        'q' => $filter['q'],
        'country' => $filter['country'],
        'admin_id' => $filter['admin_id'],
        'date_from' => $filter['date_from'],
        'date_to' => $filter['date_to'],
        'status' => $filter['status'],
        'per' => $perPage,
        'p' => $pageNum,
    ], $overrides);
    $bits = ['page=' . rawurlencode((string) $params['page'])];
    foreach (['q', 'country', 'date_from', 'date_to'] as $k) {
        $v = trim((string) ($params[$k] ?? ''));
        if ($v !== '') {
            $bits[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
    }
    $adminId = (int) ($params['admin_id'] ?? 0);
    if ($adminId > 0) {
        $bits[] = 'admin_id=' . $adminId;
    }
    $status = (string) ($params['status'] ?? 'all');
    if ($status !== '' && $status !== 'all') {
        $bits[] = 'status=' . rawurlencode($status);
    }
    $per = (int) ($params['per'] ?? 100);
    if ($per !== 100) {
        $bits[] = 'per=' . $per;
    }
    $p = max(1, (int) ($params['p'] ?? 1));
    if ($p > 1) {
        $bits[] = 'p=' . $p;
    }
    $download = strtolower(trim((string) ($params['download'] ?? '')));
    if ($download === 'csv' || $download === 'xls' || $download === 'excel') {
        $bits[] = 'download=' . rawurlencode($download);
    }
    return 'index.php?' . implode('&', $bits);
};

$listOpts = [
    'q' => $filter['q'],
    'country' => $filter['country'],
    'admin_id' => $filter['admin_id'],
    'date_from' => $filter['date_from'],
    'date_to' => $filter['date_to'],
    'status' => $filter['status'],
];

$download = strtolower((string) get('download'));
if ($download === 'csv' || $download === 'xls' || $download === 'excel') {
    $exportRows = order_pipeline_export_rows(list_order_pipeline_rows($listOpts));
    if ($download === 'csv') {
        order_pipeline_download_csv($exportRows);
    } else {
        order_pipeline_download_xls($exportRows);
    }
    exit;
}

$postedFields = static function (): array {
    return [
        (array) ($_POST['site_name'] ?? []),
        (array) ($_POST['site_note'] ?? []),
        (array) ($_POST['placement_type'] ?? []),
        (array) ($_POST['country'] ?? []),
        (array) ($_POST['order_month'] ?? []),
        (array) ($_POST['period_end_month'] ?? []),
        (array) ($_POST['order_year'] ?? []),
        (array) ($_POST['owner_price'] ?? []),
        (array) ($_POST['decided_price'] ?? []),
        (array) ($_POST['live_url'] ?? []),
        (array) ($_POST['client_label'] ?? []),
        (array) ($_POST['admin_user_id'] ?? []),
        (array) ($_POST['order_date'] ?? []),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        [$sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls, $labels, $adminIds, $dates] = $postedFields();
        $saveCurrent = static function () use ($sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls, $labels, $adminIds, $dates): int {
            return save_order_sheet_rows(
                0, $sites, $notes, $placements, $countries, $orderMonths, $endMonths, $orderYears, $owner, $decided, $urls, $labels, $adminIds, $dates
            );
        };

        if ($action === 'add_row') {
            $saveCurrent();
            add_order_pipeline_row((int) ($user['id'] ?? 0));
            flash('ok', 'New order row added.');
            redirect($ordersQs(['p' => 1]) . '#sheet-bottom');
        }
        if ($action === 'save_sheet') {
            $n = $saveCurrent();
            flash('ok', 'Saved ' . $n . ' row' . ($n === 1 ? '' : 's') . '.');
            redirect($ordersQs());
        }
        if ($action === 'delete_row') {
            $saveCurrent();
            delete_order_item((int) post('item_id'));
            flash('ok', 'Row removed.');
            redirect($ordersQs());
        }
        if ($action === 'mark_paid') {
            $saveCurrent();
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, 0, true);
            flash('ok', 'Row marked as paid.');
            redirect($ordersQs() . '#row-' . $itemId);
        }
        if ($action === 'unmark_paid') {
            $saveCurrent();
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, 0, false);
            flash('ok', 'Paid mark removed.');
            redirect($ordersQs() . '#row-' . $itemId);
        }
        if ($action === 'push_invoice') {
            $saveCurrent();
            $selectedIds = array_map('intval', (array) ($_POST['item_ids'] ?? []));
            $selectedIds = array_values(array_filter($selectedIds, static fn ($id) => $id > 0));
            if (!$selectedIds) {
                throw new InvalidArgumentException('Tick at least one unpaid LIVE row to push to an invoice.');
            }
            $picked = list_order_items_by_ids($selectedIds);
            $ready = [];
            foreach ($picked as $row) {
                if (order_is_completed($row) && !order_is_paid($row)) {
                    $ready[] = (int) $row['id'];
                }
            }
            if (!$ready) {
                throw new InvalidArgumentException('None of the selected rows are unpaid with a LIVE URL.');
            }
            redirect('index.php?page=admin_invoice_generate&ids=' . implode(',', $ready));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($ordersQs());
    }
}

$totalRows = count_order_pipeline_rows($listOpts);
$totalPages = max(1, (int) ceil(max($totalRows, 1) / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$items = list_order_pipeline_rows($listOpts + [
    'limit' => $perPage,
    'offset' => ($pageNum - 1) * $perPage,
]);
$unpaidLiveCount = count_order_pipeline_rows(['status' => 'unpaid']);
$filterCountries = list_order_pipeline_countries();
$admins = order_admin_options();
$adminById = [];
foreach ($admins as $aRow) {
    $adminById[(int) $aRow['id']] = $aRow;
}
foreach ($items as $row) {
    $aid = (int) ($row['admin_user_id'] ?? 0);
    if ($aid > 0 && !isset($adminById[$aid])) {
        $adminById[$aid] = [
            'id' => $aid,
            'full_name' => (string) ($row['admin_full_name'] ?? ''),
            'username' => (string) ($row['admin_username'] ?? ''),
        ];
    }
}

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

$months = order_month_names();
$yearNow = (int) date('Y');
$yearMax = max(2030, $yearNow + 2);
$yearOptions = range(2018, $yearMax);
foreach ($items as $row) {
    $y = (int) ($row['order_year'] ?? 0);
    if ($y >= 2018 && !in_array($y, $yearOptions, true)) {
        $yearOptions[] = $y;
    }
}
sort($yearOptions);

$totalOwner = 0.0;
$totalDecided = 0.0;
$totalProfit = 0.0;
$completedCount = 0;
$completedProfit = 0.0;
$siteCount = 0;
foreach ($items as $row) {
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

$colspan = 15;
$placementOptions = order_placement_options();
$filtersOn = $filter['q'] !== '' || $filter['country'] !== '' || $filter['admin_id'] > 0
    || trim($filter['date_from']) !== '' || trim($filter['date_to']) !== '' || $filter['status'] !== 'all';

render_header('Order management', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Order management', 'One sheet of orders. Fill country, date, who owns the row, and client email or name. Push unpaid LIVE rows to Invoices for payment.') ?></h1>
    <p class="muted">One sheet — country, date, admin, client email or name, prices, LIVE URL. Completed = LIVE URL filled. Tick unpaid LIVE rows and push them to an invoice.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_invoices">Invoices</a>
    <?php if ($unpaidLiveCount > 0): ?>
      <a class="btn" href="index.php?page=admin_invoice_generate">Push unpaid (<?= (int) $unpaidLiveCount ?>)</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=admin_invoice_generate">Generate invoice</a>
    <?php endif; ?>
  </div>
</div>

<?= guide_orders() ?>

<form method="get" action="index.php" class="card order-filter-bar" id="order-filter-bar" data-no-draft>
  <input type="hidden" name="page" value="admin_orders">
  <input type="hidden" name="per" value="<?= (int) $perPage ?>">
  <div class="order-filter-grid">
    <label class="sheet-search" for="order-sheet-search" style="margin:0">
      <span class="visually-hidden">Search orders</span>
      <input id="order-sheet-search" type="search" name="q" value="<?= h($filter['q']) ?>"
             placeholder="Search site, client, country, admin…" autocomplete="off" spellcheck="false" data-no-draft>
    </label>
    <label class="order-filter-field">
      <span class="visually-hidden">Country</span>
      <select name="country" aria-label="Filter by country" onchange="this.form.submit()">
        <option value="">All countries</option>
        <?php foreach ($filterCountries as $cname): ?>
          <option value="<?= h($cname) ?>" <?= $filter['country'] === $cname ? 'selected' : '' ?>><?= h($cname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="order-filter-field">
      <span class="visually-hidden">Admin</span>
      <select name="admin_id" aria-label="Filter by admin" onchange="this.form.submit()">
        <option value="">All admins</option>
        <?php foreach ($admins as $aRow):
            $aid = (int) $aRow['id'];
            $alabel = trim((string) ($aRow['full_name'] ?? ''));
            if ($alabel === '') {
                $alabel = (string) ($aRow['username'] ?? '');
            }
            ?>
          <option value="<?= $aid ?>" <?= $filter['admin_id'] === $aid ? 'selected' : '' ?>><?= h($alabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="order-filter-field">
      <span class="visually-hidden">From date</span>
      <input type="date" name="date_from" value="<?= h($filter['date_from']) ?>" aria-label="From date" data-no-draft>
    </label>
    <label class="order-filter-field">
      <span class="visually-hidden">To date</span>
      <input type="date" name="date_to" value="<?= h($filter['date_to']) ?>" aria-label="To date" data-no-draft>
    </label>
    <label class="order-filter-field">
      <span class="visually-hidden">Status</span>
      <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
        <option value="all" <?= $filter['status'] === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="open" <?= $filter['status'] === 'open' ? 'selected' : '' ?>>Open</option>
        <option value="completed" <?= $filter['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="unpaid" <?= $filter['status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid LIVE</option>
        <option value="paid" <?= $filter['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
      </select>
    </label>
    <div class="order-filter-actions">
      <button class="btn secondary small" type="submit">Search</button>
      <?php if ($filtersOn): ?>
        <a class="btn secondary small" href="index.php?page=admin_orders">Clear</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="orders-summary orders-summary-6">
  <div class="orders-summary-item">
    <strong><?= (int) $totalRows ?></strong>
    <span><?= label_with_info('Orders', 'Rows matching this filter (all pages).') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-sites><?= (int) $siteCount ?></strong>
    <span><?= label_with_info('This page', 'Rows on the current page.') ?></span>
  </div>
  <div class="orders-summary-item orders-summary-done">
    <strong data-summary-completed><?= (int) $completedCount ?></strong>
    <span><?= label_with_info('Completed on page', 'Rows on this page with a LIVE URL filled.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong><?= (int) $unpaidLiveCount ?></strong>
    <span><?= label_with_info('Unpaid LIVE', 'Completed rows not yet paid — ready to push to an invoice.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-decided><?= h(format_money($totalDecided)) ?></strong>
    <span><?= label_with_info('Decided on page', 'Sum of decided prices on this page.') ?></span>
  </div>
  <div class="orders-summary-item">
    <strong data-summary-profit class="<?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= h(format_money($totalProfit)) ?></strong>
    <span><?= label_with_info('Profit on page', 'Decided − Owner on this page.') ?></span>
  </div>
</div>

<form method="post" id="order-sheet-form" class="card order-sheet-card"
      action="<?= h($ordersQs()) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_sheet" id="sheet-action">
  <input type="hidden" name="item_id" id="delete-item-id" value="">
  <div class="order-sheet-toolbar">
    <div class="order-sheet-toolbar-left">
      <h2 style="margin:0" class="with-info-heading"><?= label_with_info('Sheet', 'Fill country, date, admin, and client email or name. Tick unpaid LIVE rows, then Push to invoice.') ?></h2>
    </div>
    <div class="actions">
      <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add order</button>
      <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='push_invoice'">Push to invoice</button>
      <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    </div>
  </div>

  <div class="order-sheet-scroll">
    <table class="order-sheet">
      <thead>
        <tr>
          <th class="col-check">
            <label class="visually-hidden" for="order-select-all">Select all on this page</label>
            <input type="checkbox" id="order-select-all" title="Select all unpaid LIVE on this page">
          </th>
          <th class="col-num">#</th>
          <th class="col-country"><?= label_with_info('Country', 'Country name for this order.') ?></th>
          <th class="col-date"><?= label_with_info('Date', 'Order date.') ?></th>
          <th class="col-admin"><?= label_with_info('Admin', 'Which admin this order belongs to.') ?></th>
          <th class="col-client"><?= label_with_info('Client email or name', 'Free text — email or a short name. No client folder or extra details required.') ?></th>
          <th class="col-site"><?= label_with_info('Site name', 'Website or domain for this row (e.g. site.com).') ?></th>
          <th class="col-placement"><?= label_with_info('Banner / Textlink', 'Leave empty for articles. Choose Banner or Textlink only when this placement is not an article.') ?></th>
          <th class="col-price"><?= label_with_info('Owner price', 'What you pay the site owner / publisher.') ?></th>
          <th class="col-price"><?= label_with_info('Decided price', 'What the client pays you. Profit = Decided − Owner.') ?></th>
          <th class="col-live"><?= label_with_info('LIVE URL', 'Fill when the placement is live (marks the order completed). When filled, Owner and Decided cannot be empty, and Decided must be greater than 0.') ?></th>
          <th class="col-paid"><?= label_with_info('Paid', 'Paid after the invoice is marked paid, or click here. Paid rows cannot be pushed to a new invoice.') ?></th>
          <th class="col-profit"><?= label_with_info('Profit', 'Auto-calculated: Decided price − Owner price.') ?></th>
          <th class="col-month"><?= label_with_info('Month', 'Article month, or for Banner/Textlink the start month plus end month.') ?></th>
          <th class="col-del"><?= label_with_info('Remove', 'Deletes this row after confirmation. Cannot be undone.') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr>
          <td colspan="<?= (int) $colspan ?>" class="muted" style="padding:1rem">
            <?= $filtersOn ? 'No orders match this filter.' : 'No orders yet — click “Add order”.' ?>
            <?php if ($filtersOn): ?>
              <a href="index.php?page=admin_orders">Clear filters</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endif; ?>
      <?php
      $siteIndex = ($pageNum - 1) * $perPage;
      foreach ($items as $row):
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
          $orderDate = (string) ($row['order_date'] ?? '');
          if ($orderDate === '') {
              $orderDate = date('Y-m-d');
          }
          $rowAdminId = (int) ($row['admin_user_id'] ?? 0);
          if ($rowAdminId < 1) {
              $rowAdminId = (int) ($user['id'] ?? 0);
          }
          $canPush = $done && !$paid;
      ?>
        <tr class="order-row<?= $done ? ' is-completed' : '' ?><?= $paid ? ' is-paid' : '' ?><?= $isPlacement ? ' is-placement' : '' ?>"
            data-row id="row-<?= $id ?>">
          <td class="col-check">
            <input type="checkbox" name="item_ids[]" value="<?= $id ?>"
                   <?= $canPush ? '' : 'disabled' ?>
                   title="<?= $canPush ? 'Push this unpaid LIVE row to an invoice' : 'Only unpaid LIVE rows can be pushed' ?>"
                   data-push-check>
          </td>
          <td class="col-num muted"><?= (int) $siteIndex ?></td>
          <td class="col-country">
            <input class="cell-input cell-hint" type="text" name="country[<?= $id ?>]"
                   value="<?= h((string) ($row['country'] ?? '')) ?>"
                   list="order-country-list"
                   placeholder="Country…" autocomplete="off">
          </td>
          <td class="col-date">
            <input class="cell-input" type="date" name="order_date[<?= $id ?>]"
                   value="<?= h($orderDate) ?>" aria-label="Order date">
          </td>
          <td class="col-admin">
            <select class="cell-input cell-select" name="admin_user_id[<?= $id ?>]" aria-label="Admin">
              <?php foreach ($adminById as $aid => $aRow):
                  $alabel = trim((string) ($aRow['full_name'] ?? ''));
                  if ($alabel === '') {
                      $alabel = (string) ($aRow['username'] ?? '');
                  }
                  ?>
                <option value="<?= (int) $aid ?>" <?= $rowAdminId === (int) $aid ? 'selected' : '' ?>><?= h($alabel) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="col-client">
            <input class="cell-input" type="text" name="client_label[<?= $id ?>]"
                   value="<?= h((string) ($row['client_label'] ?? '')) ?>"
                   placeholder="email or name" autocomplete="off">
          </td>
          <td class="col-site">
            <div class="site-cell open-site-cell" data-open-site-cell>
              <input class="cell-input" type="text" name="site_name[<?= $id ?>]"
                     value="<?= h($row['site_name']) ?>" placeholder="site.com" autocomplete="off"
                     data-open-site-host>
              <?= render_open_site_anchor((string) $row['site_name'], ['class' => 'order-open-site']) ?>
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
            <div class="open-site-cell order-live-cell" data-open-site-cell>
              <input class="cell-input" type="text" name="live_url[<?= $id ?>]"
                     value="<?= h($row['live_url']) ?>"
                     placeholder="<?= $isPlacement ? 'site.com (required)' : '(empty until live)' ?>"
                     data-live data-open-site-host autocomplete="off">
              <?= render_open_site_anchor((string) $row['live_url'], ['class' => 'order-open-site', 'label' => 'Open']) ?>
            </div>
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
          <td colspan="8"><strong>Page totals</strong></td>
          <td><strong data-total-owner><?= h(format_money($totalOwner)) ?></strong></td>
          <td><strong data-total-decided><?= h(format_money($totalDecided)) ?></strong></td>
          <td>
            <span class="muted">Completed </span>
            <strong data-total-completed><?= (int) $completedCount ?></strong>
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
    Use the filter bar to search by site, client email or name, country, admin, date, or status.
    <strong>Client email or name</strong> is free text — no client folder or extra details.
    Tick unpaid LIVE rows and <strong>Push to invoice</strong> to bill them.
    An order counts as <strong>completed</strong> only when LIVE URL is filled.
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
    <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='push_invoice'">Push to invoice</button>
    <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add order</button>
  </div>
</form>

<?php if ($totalPages > 1 || $totalRows > 50): ?>
<p class="muted" style="margin-top:0.85rem">
  Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
  · <?= (int) $totalRows ?> order<?= $totalRows === 1 ? '' : 's' ?>
  ·
  <?php foreach ([50, 100, 250] as $n): ?>
    <?php if ($perPage === $n): ?>
      <strong><?= (int) $n ?></strong>
    <?php else: ?>
      <a href="<?= h($ordersQs(['per' => $n, 'p' => 1])) ?>"><?= (int) $n ?></a>
    <?php endif; ?>
    <?= $n === 250 ? '' : ' · ' ?>
  <?php endforeach; ?>
  per page
  <?php if ($pageNum > 1): ?>
    · <a href="<?= h($ordersQs(['p' => $pageNum - 1])) ?>">Previous</a>
  <?php endif; ?>
  <?php if ($pageNum < $totalPages): ?>
    · <a href="<?= h($ordersQs(['p' => $pageNum + 1])) ?>">Next</a>
  <?php endif; ?>
</p>
<?php endif; ?>

<div class="card order-download-bar" id="sheet-download">
  <div>
    <strong>Download this sheet</strong>
    <p class="muted" style="margin:0.25rem 0 0">Export matching rows (all pages of this filter) for Excel or spreadsheets.</p>
  </div>
  <div class="actions">
    <a class="btn secondary small" href="<?= h($ordersQs(['download' => 'csv'])) ?>">Download CSV</a>
    <a class="btn secondary small" href="<?= h($ordersQs(['download' => 'xls'])) ?>">Download Excel</a>
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
  function syncPushCheck(row) {
    var live = String((row.querySelector('[data-live]') || {}).value || '').trim();
    var paidBtn = row.querySelector('.btn-paid.is-paid');
    var check = row.querySelector('[data-push-check]');
    if (!check) return;
    var canPush = !!live && !paidBtn;
    check.disabled = !canPush;
    check.title = canPush
      ? 'Push this unpaid LIVE row to an invoice'
      : 'Only unpaid LIVE rows can be pushed';
    if (!canPush) check.checked = false;
  }

  function refresh() {
    var ownerTotal = 0, decidedTotal = 0, profitTotal = 0;
    var completed = 0, completedProfit = 0, sites = 0;
    document.querySelectorAll('[data-row]').forEach(function (row) {
      syncPlacementRow(row);
      syncPushCheck(row);
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
    setText('[data-summary-sites]', String(sites));
    setText('[data-summary-completed]', String(completed));
    setText('[data-summary-decided]', money(decidedTotal));
    setMoney('[data-summary-profit]', profitTotal);
  }

  var form = document.getElementById('order-sheet-form');
  var dirty = false;
  var submitting = false;
  var isDraftIgnored = function (el) {
    return !!(el && el.closest && el.closest('[data-no-draft]'));
  };

  form.addEventListener('submit', function (e) {
    var actionEl = document.getElementById('sheet-action');
    var action = actionEl ? String(actionEl.value || '') : '';
    if (action === 'push_invoice') {
      var any = false;
      document.querySelectorAll('[data-push-check]').forEach(function (cb) {
        if (cb.checked && !cb.disabled) any = true;
      });
      if (!any) {
        e.preventDefault();
        e.stopPropagation();
        submitting = false;
        alert('Tick at least one unpaid LIVE row to push to an invoice.');
        return;
      }
    }
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
      submitting = false;
      return;
    }
    submitting = true;
  });
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);

  var selectAll = document.getElementById('order-select-all');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('[data-push-check]').forEach(function (cb) {
        if (!cb.disabled) cb.checked = selectAll.checked;
      });
    });
  }

  form.addEventListener('input', function (e) {
    if (isDraftIgnored(e.target)) return;
    dirty = true;
  }, true);
  form.addEventListener('change', function (e) {
    if (isDraftIgnored(e.target)) return;
    dirty = true;
  }, true);
  window.addEventListener('beforeunload', function (e) {
    if (!dirty || submitting) return;
    e.preventDefault();
    e.returnValue = '';
  });
  refresh();
})();
</script>
<?= open_site_script_tag() ?>
<?php render_footer('admin'); ?>
