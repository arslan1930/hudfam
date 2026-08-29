<?php
$user = require_admin();
ensure_order_schema();

$folder = strtolower(trim((string) get('folder')));
if ($folder === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $folder = strtolower(trim((string) ($_POST['folder'] ?? '')));
}
if (!in_array($folder, ['processing', 'completed'], true)) {
    $folder = '';
}
$isProcessing = $folder === 'processing';
$isCompleted = $folder === 'completed';
$isHub = $folder === '';

$filter = [
    'q' => trim((string) get('q')),
    'country' => trim((string) get('country')),
    'admin_id' => max(0, (int) get('admin_id')),
    'date_from' => (string) get('date_from'),
    'date_to' => (string) get('date_to'),
    'status' => (string) get('status'),
];
$allowedStatus = $isCompleted ? ['all', 'unpaid', 'paid'] : ['all', 'open', 'completed', 'unpaid', 'paid'];
if (!in_array($filter['status'], $allowedStatus, true)) {
    $filter['status'] = 'all';
}
if ($isProcessing) {
    $filter['status'] = 'all';
}
$perPage = (int) get('per', 100);
if (!in_array($perPage, [50, 100, 250], true)) {
    $perPage = 100;
}
$pageNum = max(1, (int) get('p', 1));

$ordersQs = static function (array $overrides = []) use ($filter, $perPage, $pageNum, $folder): string {
    $params = array_merge([
        'page' => 'admin_orders',
        'folder' => $folder,
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
    $folderName = (string) ($params['folder'] ?? '');
    if ($folderName === 'processing' || $folderName === 'completed') {
        $bits[] = 'folder=' . rawurlencode($folderName);
    }
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
    if ($download === 'csv' || $download === 'xls' || $download === 'excel' || $download === 'txt') {
        $bits[] = 'download=' . rawurlencode($download);
    }
    $copy = strtolower(trim((string) ($params['copy'] ?? '')));
    if ($copy === 'live_urls') {
        $bits[] = 'copy=' . rawurlencode($copy);
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
    'folder' => $folder,
];

$download = strtolower((string) get('download'));
if ($folder !== '' && ($download === 'csv' || $download === 'xls' || $download === 'excel' || $download === 'txt')) {
    $exportItems = list_order_pipeline_rows($listOpts);
    if ($download === 'txt') {
        order_pipeline_download_txt(order_live_urls_from_rows($exportItems));
    } elseif ($download === 'csv') {
        order_pipeline_download_csv(order_pipeline_export_rows($exportItems));
    } else {
        order_pipeline_download_xls(order_pipeline_export_rows($exportItems));
    }
    exit;
}

$copyMode = strtolower(trim((string) get('copy')));
if ($folder !== '' && $copyMode === 'live_urls') {
    header('Content-Type: application/json; charset=utf-8');
    $urls = order_live_urls_from_rows(list_order_pipeline_rows($listOpts));
    echo json_encode(['ok' => true, 'urls' => $urls, 'n' => count($urls)]);
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
            if (!$isProcessing) {
                throw new InvalidArgumentException('New orders are added in the Processing folder.');
            }
            $saveCurrent();
            add_order_pipeline_row((int) ($user['id'] ?? 0), '', [
                'country' => $filter['country'],
                'admin_user_id' => $filter['admin_id'] > 0 ? $filter['admin_id'] : (int) ($user['id'] ?? 0),
            ]);
            flash('ok', 'New order row added.');
            // Drop search/status/date filters so the blank row is not hidden.
            redirect($ordersQs([
                'p' => 1,
                'q' => '',
                'status' => 'all',
                'date_from' => '',
                'date_to' => '',
            ]) . '#sheet-bottom');
        }
        if ($action === 'save_sheet') {
            $n = $saveCurrent();
            flash('ok', 'Saved ' . $n . ' row' . ($n === 1 ? '' : 's') . '.');
            redirect($ordersQs());
        }
        if ($action === 'delete_row') {
            $saveCurrent();
            $itemId = (int) post('item_id');
            $restoreWp = (string) post('restore_wp') === '1';
            $item = get_order_item($itemId);
            $wpId = (int) ($item['site_price_row_id'] ?? 0);
            $wpStatus = '';
            if ($wpId > 0 && function_exists('get_site_price_row')) {
                $wp = get_site_price_row($wpId);
                $wpStatus = strtolower(trim((string) ($wp['status_slug'] ?? '')));
            }
            delete_order_item($itemId);
            $didRestore = false;
            if ($restoreWp && $wpId > 0 && function_exists('site_price_save_row')) {
                try {
                    site_price_save_row($wpId, ['status_slug' => 'processing'], [
                        'id' => (int) ($user['id'] ?? 0),
                        'role' => 'admin',
                    ]);
                    $didRestore = true;
                } catch (Throwable $e) {
                    // OM row is already gone; Website prices restore is best-effort
                }
            }
            if ($didRestore) {
                flash('ok', 'Row removed. Website prices set back to Processing — it will show in Processing.');
            } elseif ($wpStatus === 'processing') {
                flash('ok', 'Row removed. Website prices is still Processing, so this order will reappear when Processing loads.');
            } else {
                flash('ok', 'Row removed.');
            }
            redirect($ordersQs());
        }
        if ($action === 'mark_completed') {
            if (!$isProcessing) {
                throw new InvalidArgumentException('Mark completed is only on the Processing folder.');
            }
            try {
                $saveCurrent();
            } catch (Throwable $e) {
                // Still complete ticked rows from posted live URLs if other rows fail validation.
            }
            $selectedIds = array_map('intval', (array) ($_POST['item_ids'] ?? []));
            $oneId = (int) post('item_id');
            if ($oneId > 0) {
                $selectedIds[] = $oneId;
            }
            $selectedIds = array_values(array_unique(array_filter($selectedIds, static fn ($id) => $id > 0)));
            if (!$selectedIds) {
                throw new InvalidArgumentException('Tick at least one row (with a live URL) to mark completed.');
            }
            $stayProcessing = $oneId > 0 && count($selectedIds) === 1;
            $result = order_mark_items_completed($selectedIds, $urls, (int) ($user['id'] ?? 0));
            if ($result['ok'] < 1) {
                throw new InvalidArgumentException(
                    $result['errors'] ? implode(' ', $result['errors']) : 'Could not mark orders completed.'
                );
            }
            $msg = 'Marked ' . (int) $result['ok'] . ' order' . ($result['ok'] === 1 ? '' : 's') . ' completed.';
            if ($result['errors']) {
                $msg .= ' ' . implode(' ', $result['errors']);
            }
            flash('ok', $msg);
            if ($stayProcessing) {
                redirect($ordersQs());
            }
            redirect($ordersQs(['folder' => 'completed']));
        }
        if ($action === 'mark_paid') {
            if (!$isCompleted) {
                throw new InvalidArgumentException('Paid is only on Completed orders.');
            }
            $saveCurrent();
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, 0, true);
            flash('ok', 'Row marked as paid.');
            redirect($ordersQs() . '#row-' . $itemId);
        }
        if ($action === 'unmark_paid') {
            if (!$isCompleted) {
                throw new InvalidArgumentException('Paid is only on Completed orders.');
            }
            $saveCurrent();
            $itemId = (int) post('item_id');
            set_order_item_paid($itemId, 0, false);
            flash('ok', 'Paid mark removed.');
            redirect($ordersQs() . '#row-' . $itemId);
        }
        if ($action === 'push_invoice') {
            if (!$isCompleted) {
                throw new InvalidArgumentException('Push to invoice is only on Completed orders.');
            }
            $saveCurrent();
            $selectedIds = array_map('intval', (array) ($_POST['item_ids'] ?? []));
            $selectedIds = array_values(array_filter($selectedIds, static fn ($id) => $id > 0));
            if (!$selectedIds) {
                throw new InvalidArgumentException('Tick at least one unpaid completed row to push to an invoice.');
            }
            $picked = list_order_items_by_ids($selectedIds);
            $onOpen = function_exists('order_items_on_open_invoices')
                ? order_items_on_open_invoices($selectedIds)
                : [];
            $ready = [];
            $blockedNums = [];
            foreach ($picked as $row) {
                $oid = (int) $row['id'];
                if (isset($onOpen[$oid])) {
                    $num = trim((string) ($onOpen[$oid]['invoice_number'] ?? ''));
                    if ($num !== '') {
                        $blockedNums[$num] = true;
                    }
                    continue;
                }
                if (order_is_completed($row) && !order_is_paid($row)) {
                    $ready[] = $oid;
                }
            }
            if (!$ready) {
                throw new InvalidArgumentException(
                    $blockedNums
                        ? ('Those rows are already on invoice ' . implode(', ', array_keys($blockedNums)) . '.')
                        : 'None of the selected rows are unpaid completed orders with a LIVE URL.'
                );
            }
            redirect('index.php?page=admin_invoice_generate&ids=' . implode(',', $ready));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($ordersQs());
    }
}

if ($isHub) {
    $processingCount = count_order_pipeline_rows(['folder' => 'processing']);
    $completedCount = count_order_pipeline_rows(['folder' => 'completed']);
    $unpaidCompleted = count_order_pipeline_rows(['folder' => 'completed', 'status' => 'unpaid']);
    render_header('Order management', 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Order management'],
    ]);
    ?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Order management', 'Two folders. Processing is filled from Website prices Processing. Completed is after you add a live URL and mark the order done. Only Completed unpaid rows can be pushed to an invoice.') ?></h1>
    <p class="muted">Processing · Completed. Website prices never shows LIVE URL, profit, client, or invoice fields. Team sees the Website prices status change only.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_invoices">Invoices</a>
    <a class="btn secondary" href="index.php?page=admin_site_prices">Website prices</a>
  </div>
</div>
<?= guide_orders() ?>
<div class="launch-cards om-folder-cards" id="om-folders">
  <a class="launch-card" href="index.php?page=admin_orders&amp;folder=processing" data-om-folder="processing">
    <h2>Processing</h2>
    <p><strong class="om-folder-count"><?= (int) $processingCount ?></strong> order<?= $processingCount === 1 ? '' : 's' ?> from Website prices Processing. Fill OM fields, then mark completed with a live URL.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_orders&amp;folder=completed" data-om-folder="completed">
    <h2>Completed orders</h2>
    <p><strong class="om-folder-count"><?= (int) $completedCount ?></strong> completed<?= $unpaidCompleted > 0 ? ' · ' . (int) $unpaidCompleted . ' unpaid' : '' ?>. Push unpaid rows to an invoice. Paid stays in this folder.</p>
  </a>
</div>
    <?php
    render_footer('admin');
    return;
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
$unpaidFilterOpts = [
    'q' => $filter['q'],
    'country' => $filter['country'],
    'admin_id' => $filter['admin_id'],
    'date_from' => $filter['date_from'],
    'date_to' => $filter['date_to'],
    'status' => 'unpaid',
    'folder' => 'completed',
];
$unpaidLiveCount = count_order_pipeline_rows($unpaidFilterOpts);
$unpaidPushIds = [];
$unbilledCount = $unpaidLiveCount;
$openInvoicesByOrder = [];
if ($isCompleted && $unpaidLiveCount > 0 && function_exists('order_items_on_open_invoices')) {
    $allUnpaidIds = list_order_pipeline_ids($unpaidFilterOpts);
    $openOnUnpaid = order_items_on_open_invoices($allUnpaidIds);
    $unpaidPushIds = [];
    foreach ($allUnpaidIds as $oid) {
        $oid = (int) $oid;
        if ($oid > 0 && !isset($openOnUnpaid[$oid])) {
            $unpaidPushIds[] = $oid;
        }
    }
    $unbilledCount = count($unpaidPushIds);
    if ($unbilledCount > order_invoice_push_id_cap()) {
        $unpaidPushIds = [];
    }
} elseif ($isCompleted && $unpaidLiveCount > 0 && $unpaidLiveCount <= order_invoice_push_id_cap()) {
    $unpaidPushIds = list_order_pipeline_ids($unpaidFilterOpts);
}
$unpaidPush = order_invoice_generate_push_cta($unbilledCount, $unpaidPushIds);
if ($isCompleted && $items && function_exists('order_items_on_open_invoices')) {
    $pageIds = [];
    foreach ($items as $row) {
        $pageIds[] = (int) ($row['id'] ?? 0);
    }
    $openInvoicesByOrder = order_items_on_open_invoices($pageIds);
}
$filterCountries = list_order_pipeline_countries();
$clientCatalog = list_order_pipeline_client_labels();
$admins = order_admin_options();
$adminById = [];
foreach ($admins as $aRow) {
    $adminById[(int) $aRow['id']] = $aRow;
}
$viewerId = (int) ($user['id'] ?? 0);
if ($viewerId > 0 && !isset($adminById[$viewerId])) {
    $adminById[$viewerId] = $user;
    $admins[] = $user;
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
$liveFilledCount = 0;
$siteCount = 0;
foreach ($items as $row) {
    $siteCount++;
    $profit = order_profit($row['owner_price'], $row['decided_price']);
    $totalOwner += parse_money($row['owner_price']);
    $totalDecided += parse_money($row['decided_price']);
    $totalProfit += $profit;
    if (trim((string) ($row['live_url'] ?? '')) !== '') {
        $liveFilledCount++;
    }
    if (order_is_completed($row)) {
        $completedCount++;
        $completedProfit += $profit;
    }
}

$colspan = 15;
$placementOptions = order_placement_options();
$filtersOn = $filter['q'] !== '' || $filter['country'] !== '' || $filter['admin_id'] > 0
    || trim($filter['date_from']) !== '' || trim($filter['date_to']) !== '' || $filter['status'] !== 'all';

render_header($isProcessing ? 'Processing' : 'Completed orders', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Order management', 'href' => 'index.php?page=admin_orders'],
    ['label' => $isProcessing ? 'Processing' : 'Completed orders'],
]); ?>

<div class="topbar">
  <div>
    <?php if ($isProcessing): ?>
      <h1><?= label_with_info('Processing', 'Rows from Website prices Processing, plus any you add here. Fill LIVE URL, then Mark completed. That moves the order here to Completed and sets Website prices to Completed. Saving a live URL does not complete the row by itself.') ?></h1>
      <p class="muted">Website prices Processing feeds this folder. Mark completed requires a live URL. Push to invoice is only on Completed orders.</p>
    <?php else: ?>
      <h1><?= label_with_info('Completed orders', 'After a live URL and Mark completed. Unpaid until Paid. Tick unpaid rows and Push to invoice. Paid stays in this folder. Website prices status is not changed when you mark paid.') ?></h1>
      <p class="muted">Unpaid until paid. Push to invoice from here. Team Website prices only shows the Completed status — never LIVE URL, profit, or client.</p>
    <?php endif; ?>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_orders">Folders</a>
    <?php if ($isProcessing): ?>
      <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=completed">Completed orders</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=admin_orders&amp;folder=processing">Processing</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_invoices">Invoices</a>
    <?php if ($isCompleted): ?>
      <a class="<?= h($unpaidPush['class']) ?>" href="<?= h($unpaidPush['href']) ?>"><?= h($unpaidPush['label']) ?></a>
    <?php endif; ?>
  </div>
</div>

<?= guide_orders() ?>

<form method="get" action="index.php" class="card order-filter-bar" id="order-filter-bar" data-no-draft>
  <input type="hidden" name="page" value="admin_orders">
  <input type="hidden" name="folder" value="<?= h($folder) ?>">
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
    <?php if ($isCompleted): ?>
    <label class="order-filter-field">
      <span class="visually-hidden">Status</span>
      <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
        <option value="all" <?= $filter['status'] === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="unpaid" <?= $filter['status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid LIVE</option>
        <option value="paid" <?= $filter['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
      </select>
    </label>
    <?php endif; ?>
    <div class="order-filter-actions">
      <button class="btn secondary small" type="submit">Search</button>
      <?php if ($filtersOn): ?>
        <a class="btn secondary small" href="index.php?page=admin_orders&amp;folder=<?= h($folder) ?>">Clear</a>
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
    <strong data-summary-completed><?= (int) ($isProcessing ? $liveFilledCount : $completedCount) ?></strong>
    <span><?= label_with_info($isCompleted ? 'Completed on page' : 'With live URL on page', $isCompleted ? 'Rows on this page already marked completed.' : 'Rows on this page that already have a live URL filled — still Processing until you mark completed.') ?></span>
  </div>
  <?php if ($isCompleted): ?>
  <div class="orders-summary-item">
    <strong><?= (int) $unpaidLiveCount ?></strong>
    <span><?= label_with_info('Unpaid LIVE', 'Completed unpaid rows matching this search, country, admin, and dates — ready to push to an invoice.') ?></span>
  </div>
  <?php endif; ?>
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
      action="<?= h($ordersQs()) ?>" data-folder="<?= h($folder) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_sheet" id="sheet-action">
  <input type="hidden" name="folder" value="<?= h($folder) ?>">
  <input type="hidden" name="item_id" id="delete-item-id" value="">
  <input type="hidden" name="restore_wp" id="restore-wp" value="">
  <div class="order-sheet-toolbar">
    <div class="order-sheet-toolbar-left">
      <h2 style="margin:0" class="with-info-heading"><?= label_with_info(
          $isProcessing ? 'Processing sheet' : 'Completed sheet',
          $isProcessing
              ? 'Fill country, date, admin, client, prices, and LIVE URL. Tick rows with a live URL, then Mark completed. Saving a live URL does not complete the row.'
              : 'Tick unpaid completed rows, then Push to invoice. Mark paid after payment. Website prices is not updated from this folder.'
      ) ?></h2>
    </div>
    <div class="actions">
      <?php if ($isProcessing): ?>
        <button class="btn secondary" type="button" data-copy-selected-sites>Copy selected sites (this page)</button>
        <button class="btn secondary" type="button" data-copy-selected-live>Copy selected live URLs (this page)</button>
        <button class="btn secondary" type="button" data-copy-all-live
                data-copy-url="<?= h($ordersQs(['copy' => 'live_urls'])) ?>">Copy all live URLs</button>
        <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add order</button>
        <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='mark_completed'">Mark completed</button>
      <?php else: ?>
        <button class="btn secondary" type="button" data-copy-selected-live>Copy selected live URLs (this page)</button>
        <button class="btn secondary" type="button" data-copy-all-live
                data-copy-url="<?= h($ordersQs(['copy' => 'live_urls'])) ?>">Copy all live URLs</button>
        <button class="btn secondary" type="button" data-copy-selected-sites>Copy selected sites (this page)</button>
        <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='push_invoice'">Push to invoice</button>
      <?php endif; ?>
      <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    </div>
  </div>
  <p class="muted order-check-hint" style="margin:0.35rem 0 0">
    Left tick = <strong>Copy</strong> (this page). Right tick = <strong><?= $isProcessing ? 'Mark completed' : 'Push to invoice' ?></strong>.
  </p>
  <p class="muted" id="order-copy-status" style="margin:0.35rem 0 0" hidden></p>

  <div class="order-sheet-scroll">
    <table class="order-sheet">
      <thead>
        <tr>
          <th class="col-check">
            <div class="order-check-heads">
              <label class="order-check-head" for="order-copy-all">
                <span>Copy</span>
                <input type="checkbox" id="order-copy-all" title="Select all on this page for copy" data-no-draft>
              </label>
              <label class="order-check-head" for="order-select-all">
                <span><?= $isProcessing ? 'Complete' : 'Bill' ?></span>
                <input type="checkbox" id="order-select-all" title="<?= $isProcessing ? 'Select all rows with a live URL to mark completed' : 'Select all unpaid LIVE to push to invoice' ?>" data-no-draft>
              </label>
            </div>
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
          <th class="col-live"><?= label_with_info('LIVE URL', $isProcessing ? 'Required to mark completed. Filling this and saving does not complete the order — use Mark completed.' : 'Live placement URL. Required for completed orders.') ?></th>
          <th class="col-paid"><?= $isProcessing
              ? label_with_info('Complete', 'Mark this row completed after the live URL is filled. Moves it to Completed orders and sets Website prices to Completed.')
              : label_with_info('Paid', 'Click Mark paid after payment, or mark Paid on the invoice. Green Paid means it is already paid. Paid rows cannot be pushed to a new invoice.') ?></th>
          <th class="col-profit"><?= label_with_info('Profit', 'Auto-calculated: Decided price − Owner price.') ?></th>
          <th class="col-month"><?= label_with_info('Month', 'Article month, or for Banner/Textlink the start month plus end month.') ?></th>
          <th class="col-del"><?= label_with_info('Remove', 'Deletes this row after confirmation. Cannot be undone.') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr>
          <td colspan="<?= (int) $colspan ?>" class="muted" style="padding:1rem">
            <?= $filtersOn ? 'No orders match this filter.' : ($isProcessing ? 'No processing orders — Website prices Processing rows appear here, or click “Add order”.' : 'No completed orders yet — mark processing rows completed with a live URL.') ?>
            <?php if ($filtersOn): ?>
              <a href="index.php?page=admin_orders&amp;folder=<?= h($folder) ?>">Clear filters</a>
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
          $canPush = $isCompleted && $done && !$paid && empty($openInvoicesByOrder[$id]);
          $openInv = $openInvoicesByOrder[$id] ?? null;
          $canComplete = $isProcessing && trim((string) ($row['live_url'] ?? '')) !== '';
      ?>
        <tr class="order-row<?= $done ? ' is-completed' : '' ?><?= $paid ? ' is-paid' : '' ?><?= $isPlacement ? ' is-placement' : '' ?>"
            data-row id="row-<?= $id ?>"<?= $openInv ? ' data-on-invoice="1"' : '' ?>>
          <td class="col-check">
            <div class="order-check-pair">
              <input type="checkbox" value="<?= $id ?>" data-copy-check data-no-draft
                     title="Select for copy" aria-label="Select <?= h($row['site_name'] !== '' ? $row['site_name'] : 'row') ?> for copy">
              <input type="checkbox" name="item_ids[]" value="<?= $id ?>"
                     <?= ($isProcessing ? $canComplete : $canPush) ? '' : 'disabled' ?>
                     title="<?= $isProcessing
                         ? ($canComplete ? 'Mark this row completed' : 'Fill LIVE URL before marking completed')
                         : ($canPush
                            ? 'Push this unpaid LIVE row to an invoice'
                            : ($openInv
                                ? ('Already on invoice ' . (string) ($openInv['invoice_number'] ?? ''))
                                : 'Only unpaid completed rows can be pushed')) ?>"
                     data-push-check>
            </div>
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
                   list="order-client-list"
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
              <?php
                $wpHref = order_wp_sheet_url($row);
                $wpStatusSlug = strtolower(trim((string) ($row['wp_status_slug'] ?? '')));
                $omStage = $isProcessing ? 'processing' : 'completed';
                $wpMismatch = $wpHref !== '' && $wpStatusSlug !== '' && $wpStatusSlug !== $omStage;
                $wpStatusLabel = ($wpMismatch && function_exists('site_price_status_label'))
                    ? site_price_status_label($wpStatusSlug)
                    : '';
              ?>
              <?php if ($wpHref !== ''): ?>
                <a class="order-wp-open" href="<?= h($wpHref) ?>">Open in Website prices</a>
                <?php if ($wpMismatch && $wpStatusLabel !== ''): ?>
                  <span class="order-wp-mismatch">Website prices: <?= h($wpStatusLabel) ?></span>
                <?php endif; ?>
              <?php endif; ?>
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
            <?php if ($isProcessing): ?>
              <button class="btn secondary small" type="submit"
                      title="<?= $canComplete ? 'Mark this order completed' : 'Fill LIVE URL before marking completed' ?>"
                      <?= $canComplete ? '' : 'disabled' ?>
                      data-complete-btn
                      onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='mark_completed';">
                Mark completed
              </button>
            <?php elseif ($paid): ?>
              <button class="btn-paid is-paid" type="submit"
                      title="Click to remove paid mark"
                      data-paid="paid"
                      onclick="if (!confirm('Remove paid mark?')) return false; document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='unmark_paid';">
                Paid
              </button>
            <?php else: ?>
              <button class="btn-paid btn-paid-mark" type="submit"
                      data-paid=""
                      title="Mark this completed row as paid"
                      onclick="document.getElementById('delete-item-id').value='<?= $id ?>'; document.getElementById('sheet-action').value='mark_paid';">
                Mark paid
              </button>
              <?php if ($openInv): ?>
                <a class="order-on-invoice" href="index.php?page=admin_invoice_view&amp;id=<?= (int) $openInv['id'] ?>">Invoice <?= h((string) $openInv['invoice_number']) ?></a>
              <?php endif; ?>
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
              $wpStatusForDel = strtolower(trim((string) ($row['wp_status_slug'] ?? '')));
            ?>
            <button class="btn-link danger" type="submit"
                    data-item-id="<?= $id ?>"
                    data-site="<?= h($siteLabel) ?>"
                    data-wp-status="<?= h($wpStatusForDel) ?>"
                    onclick="return omConfirmRemove(this);">
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
            <span class="muted"><?= $isProcessing ? 'With live URL ' : 'Completed ' ?></span>
            <strong data-total-completed><?= (int) ($isProcessing ? $liveFilledCount : $completedCount) ?></strong>
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
    <?php if ($isProcessing): ?>
      Rows come from <strong>Website prices Processing</strong>, or click <strong>+ Add order</strong> for a manual row.
      Fill <strong>LIVE URL</strong>, then <strong>Mark completed</strong> — saving a live URL does not complete the order.
      Completing moves the row to Completed orders and sets Website prices to Completed. Publisher reply email is not copied into client email/name.
      Open in Website prices jumps to the linked site. If that site’s status no longer matches this folder, the mismatch is shown on the row.
    <?php else: ?>
      Use the filter bar to search by site, client email or name, country, admin, date, or unpaid/paid.
      Tick unpaid completed rows and <strong>Push to invoice</strong> to bill them.
      Paid stays in this folder. Website prices is not changed when you mark paid.
    <?php endif; ?>
  </p>
  <?php if ($countryCatalog): ?>
  <datalist id="order-country-list">
    <?php foreach ($countryCatalog as $cname): ?>
      <option value="<?= h($cname) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <?php endif; ?>
  <datalist id="order-client-list">
    <?php foreach ($clientCatalog as $clabel): ?>
      <option value="<?= h($clabel) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <div class="actions-sticky">
    <button class="btn large" type="submit" onclick="document.getElementById('sheet-action').value='save_sheet'">Save sheet</button>
    <?php if ($isProcessing): ?>
      <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='mark_completed'">Mark completed</button>
      <button class="btn secondary" type="submit" onclick="document.getElementById('sheet-action').value='add_row'">+ Add order</button>
    <?php else: ?>
      <button class="btn" type="submit" onclick="document.getElementById('sheet-action').value='push_invoice'">Push to invoice</button>
    <?php endif; ?>
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
    <p class="muted" style="margin:0.25rem 0 0">CSV and Excel are the full sheet (this folder and filter, all pages). .txt is live URLs only, one per line.</p>
  </div>
  <div class="actions">
    <a class="btn secondary small" href="<?= h($ordersQs(['download' => 'csv'])) ?>">Download CSV</a>
    <a class="btn secondary small" href="<?= h($ordersQs(['download' => 'xls'])) ?>">Download Excel</a>
    <a class="btn secondary small" href="<?= h($ordersQs(['download' => 'txt'])) ?>">Download .txt</a>
  </div>
</div>

<script>
(function () {
  var sheetFolder = <?= json_encode($folder) ?>;
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
    var completeBtn = row.querySelector('[data-complete-btn]');
    if (!check) return;
    var folder = sheetFolder;
    var can;
    if (folder === 'processing') {
      can = !!live;
      check.disabled = !can;
      check.title = can ? 'Mark this row completed' : 'Fill LIVE URL before marking completed';
      if (completeBtn) {
        completeBtn.disabled = !can;
        completeBtn.title = can ? 'Mark this order completed' : 'Fill LIVE URL before marking completed';
      }
    } else {
      can = !!live && !paidBtn && !row.getAttribute('data-on-invoice');
      check.disabled = !can;
      check.title = can
        ? 'Push this unpaid LIVE row to an invoice'
        : (row.getAttribute('data-on-invoice')
          ? 'Already on an invoice'
          : 'Only unpaid completed rows can be pushed');
    }
    if (!can) check.checked = false;
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
        if (sheetFolder === 'completed') {
          row.classList.add('is-completed');
        } else {
          row.classList.remove('is-completed');
        }
      } else {
        row.classList.remove('is-completed');
      }
      var paidBtn = row.querySelector('.btn-paid:not(.is-paid)');
      if (paidBtn) {
        paidBtn.disabled = false;
        paidBtn.title = 'Mark this completed row as paid';
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

  function setCopyStatus(msg, isError) {
    var el = document.getElementById('order-copy-status');
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || '';
    el.style.color = isError ? '#a32020' : '';
  }
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return false; });
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(!!ok);
    } catch (err) {
      return Promise.resolve(false);
    }
  }
  function uniqueList(items) {
    var seen = {};
    var out = [];
    items.forEach(function (v) {
      v = String(v || '').trim();
      if (!v || seen[v]) return;
      seen[v] = true;
      out.push(v);
    });
    return out;
  }
  function copySelected(kind) {
    var values = [];
    document.querySelectorAll('[data-row]').forEach(function (row) {
      var box = row.querySelector('[data-copy-check]');
      if (!box || !box.checked) return;
      if (kind === 'sites') {
        var site = row.querySelector('[name^="site_name"]');
        values.push(site ? site.value : '');
      } else {
        var live = row.querySelector('[data-live]');
        values.push(live ? live.value : '');
      }
    });
    values = uniqueList(values);
    if (!values.length) {
      setCopyStatus(
        kind === 'sites'
          ? 'Tick at least one row with a site name (this page).'
          : 'Tick at least one row that has a live URL (this page).',
        true
      );
      return;
    }
    copyText(values.join('\n')).then(function (ok) {
      if (!ok) {
        setCopyStatus('Could not copy.', true);
        return;
      }
      var noun = kind === 'sites'
        ? (values.length === 1 ? 'site' : 'sites')
        : (values.length === 1 ? 'live URL' : 'live URLs');
      setCopyStatus('Copied ' + values.length + ' ' + noun + ' (this page).', false);
    });
  }
  function copyAllLive(btn) {
    var url = btn.getAttribute('data-copy-url') || '';
    if (!url) {
      setCopyStatus('Could not copy.', true);
      return;
    }
    setCopyStatus('Copying…', false);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        var urls = uniqueList((data && data.urls) || []);
        if (!urls.length) {
          setCopyStatus('No live URLs in this folder/filter.', true);
          return;
        }
        return copyText(urls.join('\n')).then(function (ok) {
          if (!ok) setCopyStatus('Could not copy.', true);
          else setCopyStatus('Copied ' + urls.length + ' live URL' + (urls.length === 1 ? '' : 's') + '.', false);
        });
      })
      .catch(function () {
        setCopyStatus('Could not copy. Use Download .txt if the list is large.', true);
      });
  }

  form.querySelectorAll('[data-copy-selected-sites]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      copySelected('sites');
    });
  });
  form.querySelectorAll('[data-copy-selected-live]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      copySelected('live');
    });
  });
  form.querySelectorAll('[data-copy-all-live]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      copyAllLive(btn);
    });
  });

  var copyAll = document.getElementById('order-copy-all');
  if (copyAll) {
    copyAll.addEventListener('change', function () {
      document.querySelectorAll('[data-copy-check]').forEach(function (cb) {
        cb.checked = copyAll.checked;
      });
    });
  }

  form.addEventListener('submit', function (e) {
    var actionEl = document.getElementById('sheet-action');
    var action = actionEl ? String(actionEl.value || '') : '';
    if (action !== 'delete_row') {
      var restoreEl = document.getElementById('restore-wp');
      if (restoreEl) restoreEl.value = '';
    }
    if (action === 'push_invoice') {
      var any = false;
      document.querySelectorAll('[data-push-check]').forEach(function (cb) {
        if (cb.checked && !cb.disabled) any = true;
      });
      if (!any) {
        e.preventDefault();
        e.stopPropagation();
        submitting = false;
        alert('Tick at least one unpaid completed row to push to an invoice.');
        return;
      }
    }
    if (action === 'mark_completed') {
      var itemId = String((document.getElementById('delete-item-id') || {}).value || '').trim();
      var anyComplete = !!itemId;
      if (!anyComplete) {
        document.querySelectorAll('[data-push-check]').forEach(function (cb) {
          if (cb.checked && !cb.disabled) anyComplete = true;
        });
      }
      if (!anyComplete) {
        e.preventDefault();
        e.stopPropagation();
        submitting = false;
        alert('Tick at least one row with a live URL, or fill LIVE URL and click Mark completed on that row.');
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
    if (action === 'mark_completed') {
      var oneRow = String((document.getElementById('delete-item-id') || {}).value || '').trim();
      var confirmMsg = oneRow
        ? 'Mark this order completed? It moves to Completed orders and sets Website prices to Completed.'
        : 'Mark selected orders completed? They move to Completed orders and Website prices is set to Completed.';
      if (!confirm(confirmMsg)) {
        e.preventDefault();
        e.stopPropagation();
        submitting = false;
        return;
      }
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
function omConfirmRemove(btn) {
  if (!btn) return false;
  var id = String(btn.getAttribute('data-item-id') || '').trim();
  var site = String(btn.getAttribute('data-site') || '').trim();
  var wp = String(btn.getAttribute('data-wp-status') || '').toLowerCase();
  var msg = site
    ? 'Delete this row for “' + site + '”?'
    : 'Delete this empty row?';
  if (wp === 'processing') {
    msg += ' Website prices is still Processing, so this order will reappear the next time Processing loads.';
  }
  msg += ' This cannot be undone.';
  if (!confirm(msg)) return false;
  var restore = document.getElementById('restore-wp');
  if (restore) restore.value = '';
  if (wp === 'completed') {
    if (confirm('Also set Website prices back to Processing for this site? That creates a new Processing order.')) {
      if (restore) restore.value = '1';
    }
  }
  var itemEl = document.getElementById('delete-item-id');
  var actionEl = document.getElementById('sheet-action');
  if (itemEl) itemEl.value = id;
  if (actionEl) actionEl.value = 'delete_row';
  return true;
}
</script>
<?= open_site_script_tag() ?>
<?php render_footer('admin'); ?>
