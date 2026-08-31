<?php
$user = require_admin();

if (!function_exists('render_admin_dashboard_stat')) {
    /**
     * @param array{ok?:bool,n?:int} $result
     */
    function render_admin_dashboard_stat(
        string $label,
        array $result,
        string $href,
        string $okTitle,
        string $sub = '',
        string $search = ''
    ): void {
        $ok = !empty($result['ok']);
        $n = (int) ($result['n'] ?? 0);
        $val = $ok ? number_format($n) : '—';
        $title = $ok ? $okTitle : 'Could not load';
        $hay = trim($search !== '' ? $search : (strtolower($label . ' ' . $okTitle . ' ' . $sub)));
        echo '<a class="card stat" href="' . h($href) . '" title="' . h($title) . '"'
            . ' data-dashboard-item data-search="' . h($hay) . '">';
        echo '<span class="muted">' . h($label) . '</span>';
        echo '<strong>' . h($val) . '</strong>';
        if ($sub !== '') {
            echo '<span class="dashboard-stat-sub muted">' . h($sub) . '</span>';
        }
        echo '</a>';
    }
}

$prospect = cached_count_result('dash_prospect_sites', static function () {
    return (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
});
$team = cached_count_result('dash_team_users', static function () {
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role='team' AND is_active=1")->fetchColumn();
});
$extracted = cached_count_result('dash_extracted', static function () {
    return count_extracted_sites();
});
$sweAdmin = cached_count_result('dash_swe_admin', static function () {
    return count_sites_with_emails('admin');
});
$sweFinal = cached_count_result('dash_swe_final', static function () {
    return count_sites_with_emails('admin_all');
});
$campaignSheets = cached_count_result('dash_campaign_sheets', static function () {
    return count_email_campaign_sheets();
});
$campaignProjects = cached_count_result('dash_campaign_projects', static function () {
    return function_exists('count_email_campaign_projects')
        ? count_email_campaign_projects()
        : 0;
});
$mustChangePasswords = cached_count_result('dash_must_change', static function () {
    return (int) db()->query(
        'SELECT COUNT(*) FROM users WHERE must_change_password=1 AND is_active=1'
    )->fetchColumn();
});
$invoiceDrafts = cached_count_result('dash_invoice_drafts', static function () {
    return count_invoices_by_work_status('draft');
});
$invoiceUnpaid = cached_count_result('dash_invoice_unpaid', static function () {
    return count_invoices_unpaid();
});
$invoiceWaitingAged = cached_count_result('dash_invoice_waiting_aged', static function () {
    return function_exists('count_invoices_waiting_older_than')
        ? count_invoices_waiting_older_than(14)
        : 0;
});

$recent = [];
try {
    $recent = list_prospect_batches(null, 8);
} catch (Throwable $e) {
    $recent = [];
}
$orderRowCount = 0;
$orderUnpaidLive = 0;
$omOk = true;
$deptOpenTasks = 0;
$deptMembers = 0;
$deptUnassignedTeam = 0;
$deptOk = true;
$wpProcessing = 0;
$wpNew = 0;
$wpTotal = 0;
$wpOk = true;
try {
    $omStats = order_management_dashboard_stats();
    $orderRowCount = (int) ($omStats['orders'] ?? 0);
    $orderUnpaidLive = (int) ($omStats['unpaid_live'] ?? 0);
} catch (Throwable $e) {
    $omOk = false;
    $orderRowCount = 0;
    $orderUnpaidLive = 0;
}
try {
    if (function_exists('count_site_price_rows_by_lane')) {
        $wpLanes = count_site_price_rows_by_lane();
        $wpProcessing = (int) ($wpLanes['processing'] ?? 0);
        $wpNew = (int) ($wpLanes['new'] ?? 0);
        $wpTotal = (int) ($wpLanes['total'] ?? 0);
    }
} catch (Throwable $e) {
    $wpOk = false;
    $wpProcessing = 0;
    $wpNew = 0;
    $wpTotal = 0;
}
try {
    $deptStats = departments_dashboard_stats();
    $deptOpenTasks = (int) ($deptStats['open_tasks'] ?? 0);
    $deptMembers = (int) ($deptStats['members'] ?? 0);
    $deptUnassignedTeam = (int) ($deptStats['unassigned_team'] ?? 0);
} catch (Throwable $e) {
    $deptOk = false;
    $deptOpenTasks = 0;
    $deptMembers = 0;
    $deptUnassignedTeam = 0;
}

$adminEmailsNew = function_exists('admin_has_new_data') && admin_has_new_data('emails_admin', $user);
$teamCount = !empty($team['ok']) ? (int) $team['n'] : 0;
$invoiceDraftCount = !empty($invoiceDrafts['ok']) ? (int) $invoiceDrafts['n'] : 0;
$invoiceUnpaidCount = !empty($invoiceUnpaid['ok']) ? (int) $invoiceUnpaid['n'] : 0;
$invoiceWaitingAgedCount = !empty($invoiceWaitingAged['ok']) ? (int) $invoiceWaitingAged['n'] : 0;
$waitingByBill = [];
try {
    $waitingByBill = function_exists('list_invoices_waiting_totals_by_bill_as')
        ? list_invoices_waiting_totals_by_bill_as(8)
        : [];
} catch (Throwable $e) {
    $waitingByBill = [];
}
$mustChangeCount = !empty($mustChangePasswords['ok']) ? (int) $mustChangePasswords['n'] : 0;
$campaignProjectCount = !empty($campaignProjects['ok']) ? (int) $campaignProjects['n'] : 0;
$campaignSheetSub = '';
if (!empty($campaignProjects['ok']) && $campaignProjectCount > 0) {
    $campaignSheetSub = number_format($campaignProjectCount) . ' project'
        . ($campaignProjectCount === 1 ? '' : 's');
}

render_header('Dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Admin dashboard', 'Home for Our database, Extracted Sites, Emails data, departments, orders, and invoices.') ?></h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — inventory, emails, departments, and office tools.</p>
  </div>
  <div class="actions" style="align-items:center;flex-wrap:wrap;gap:0.55rem">
    <label class="sheet-search dashboard-search" for="dashboard-search">
      <span class="visually-hidden">Filter this page</span>
      <input id="dashboard-search" type="search" placeholder="Filter this page…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to filter cards, stats, and chips · Enter = next match · Shift+Enter = previous">
      <span class="sheet-search-meta muted" data-dashboard-search-meta hidden></span>
    </label>
  </div>
</div>

<?php
render_workflow([
    ['label' => 'Departments', 'href' => 'index.php?page=admin_departments', 'hint' => 'Assign Team to tools'],
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects', 'hint' => 'Country folders'],
    ['label' => 'Extracted Sites', 'href' => 'index.php?page=admin_extracted', 'hint' => 'From Extracting Push'],
    ['label' => 'Emails data', 'href' => 'index.php?page=admin_emails_data', 'hint' => 'Admin · Final · Campaign'],
    ['label' => 'Orders + Invoices', 'href' => 'index.php?page=admin_orders', 'hint' => 'Sheet then printable bills'],
]);
render_dashboard_help('admin');
?>

<div class="grid dashboard-stats">
  <?php
  render_admin_dashboard_stat(
      'URLs (all countries)',
      $prospect,
      'index.php?page=admin_prospects',
      'Sites in Our database'
  );
  render_admin_dashboard_stat(
      'Extracted',
      $extracted,
      'index.php?page=admin_extracted',
      'Extracted Sites from Team Push'
  );
  render_admin_dashboard_stat(
      'Emails Admin',
      $sweAdmin,
      'index.php?page=admin_emails_data&folder=sites_with_emails',
      'Sites with emails — Admin working list'
  );
  render_admin_dashboard_stat(
      'Campaign sheets',
      $campaignSheets,
      'index.php?page=admin_emails_data&folder=email_campaigns',
      'Email campaign country sheets',
      $campaignSheetSub
  );
  render_admin_dashboard_stat(
      'Unpaid LIVE',
      ['ok' => $omOk, 'n' => $orderUnpaidLive],
      'index.php?page=admin_orders&folder=completed&status=unpaid',
      'Live placements not marked paid'
  );
  render_admin_dashboard_stat(
      'Waiting invoices',
      $invoiceUnpaid,
      'index.php?page=admin_invoices&filter=unpaid',
      'Sent invoices still unpaid'
  );
  render_admin_dashboard_stat(
      'Waiting > 14 days',
      $invoiceWaitingAged,
      'index.php?page=admin_invoices&filter=unpaid',
      'Waiting bills dated 14 days ago or older'
  );
  ?>
</div>

<?php
$attention = [];
if ($deptOk && $deptUnassignedTeam > 0) {
    $attention[] = [
        'href' => 'index.php?page=admin_users&awaiting=1',
        'label' => (int) $deptUnassignedTeam . ' team awaiting assignment',
        'search' => 'awaiting access users assignment team',
    ];
}
if ($mustChangeCount > 0) {
    $attention[] = [
        'href' => 'index.php?page=admin_users&must_change=1',
        'label' => (int) $mustChangeCount . ' must change password',
        'search' => 'must change password users',
    ];
}
if ($deptOk && $deptOpenTasks > 0) {
    $attention[] = [
        'href' => 'index.php?page=admin_departments',
        'label' => (int) $deptOpenTasks . ' open task' . ($deptOpenTasks === 1 ? '' : 's'),
        'search' => 'open tasks departments',
    ];
}
if (!empty($invoiceDrafts['ok']) && $invoiceDraftCount > 0) {
    $attention[] = [
        'href' => 'index.php?page=admin_invoices&filter=draft',
        'label' => number_format($invoiceDraftCount) . ' draft invoice' . ($invoiceDraftCount === 1 ? '' : 's'),
        'search' => 'draft invoices',
    ];
}
if (!empty($invoiceWaitingAged['ok']) && $invoiceWaitingAgedCount > 0) {
    $attention[] = [
        'href' => 'index.php?page=admin_invoices&filter=unpaid',
        'label' => number_format($invoiceWaitingAgedCount) . ' waiting > 14 days',
        'search' => 'waiting invoices overdue unpaid 14 days',
    ];
}
if ($adminEmailsNew) {
    $attention[] = [
        'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails',
        'label' => 'New Admin emails',
        'search' => 'new admin emails',
    ];
}
if ($attention):
?>
<div class="dashboard-attention" data-dashboard-attention>
  <?php foreach ($attention as $chip): ?>
    <a href="<?= h((string) $chip['href']) ?>"
       data-dashboard-item
       data-search="<?= h((string) ($chip['search'] ?? '')) ?>"><?= h((string) $chip['label']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="launch-cards" id="dashboard-launch-cards">
  <a class="launch-card" href="index.php?page=admin_prospects#add-sites" data-dashboard-item
     data-search="our database add sites paste root domains country folders urls">
    <h2>Our database</h2>
    <p>Country folders — browse and add sites.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_departments" data-dashboard-item
     data-search="departments site finding extracting email communication team assign tasks office">
    <h2>Departments</h2>
    <p><?= (int) $deptOpenTasks ?> open task<?= (int) $deptOpenTasks === 1 ? '' : 's' ?> · <?= (int) $deptMembers ?> member<?= (int) $deptMembers === 1 ? '' : 's' ?><?php if ($deptUnassignedTeam > 0): ?> · <?= (int) $deptUnassignedTeam ?> team awaiting assignment<?php endif; ?>.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_extracted" data-dashboard-item
     data-search="extracted urls extracted sites countries copy edit remove push">
    <h2>Extracted Sites</h2>
    <p>From Team Extracting Results Push.</p>
  </a>
  <a class="launch-card<?= $adminEmailsNew ? ' has-admin-new' : '' ?>" href="index.php?page=admin_emails_data" data-dashboard-item
     data-search="emails data sites with emails admin archive final campaign push">
    <h2>Emails data<?= function_exists('admin_new_badge_html') ? admin_new_badge_html('emails_admin', $user) : '' ?></h2>
    <p>Admin <?= !empty($sweAdmin['ok']) ? number_format((int) $sweAdmin['n']) : '—' ?>
      · Final <?= !empty($sweFinal['ok']) ? number_format((int) $sweFinal['n']) : '—' ?>
      · Campaign <?= !empty($campaignSheets['ok']) ? number_format((int) $campaignSheets['n']) : '—' ?>
      <?= !empty($campaignSheets['ok']) && (int) $campaignSheets['n'] === 1 ? 'sheet' : 'sheets' ?>.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_semrush_research" data-dashboard-item
    data-search="semrush research site finding">
    <h2>Semrush Research</h2>
    <p>Site Finding copy from Extracting Push · optional seed.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_orders" data-dashboard-item
     data-search="order management sheet sites prices profit live url unpaid invoice client email admin country date">
    <h2>Order management</h2>
    <p><?= (int) $orderRowCount ?> order<?= (int) $orderRowCount === 1 ? '' : 's' ?><?php if ($orderUnpaidLive > 0): ?> · <?= (int) $orderUnpaidLive ?> unpaid LIVE<?php endif; ?> — Processing · Completed.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_site_prices" data-dashboard-item
     data-search="website prices publisher rates country sheet da dr traffic status office processing new">
    <h2>Website prices</h2>
    <p><?php if ($wpOk): ?>
        <?= number_format($wpTotal) ?> <?= $wpTotal === 1 ? 'row' : 'rows' ?><?php
            $wpBits = [];
            if ($wpProcessing > 0) {
                $wpBits[] = number_format($wpProcessing) . ' Processing';
            }
            if ($wpNew > 0) {
                $wpBits[] = number_format($wpNew) . ' New';
            }
            echo $wpBits !== [] ? ' · ' . implode(' · ', $wpBits) : '';
        ?> — publisher rate book.
      <?php else: ?>
        Publisher rate book — one country sheet of prices and statuses.
      <?php endif; ?></p>
  </a>
  <a class="launch-card" href="index.php?page=admin_invoices&amp;filter=unpaid" data-dashboard-item
     data-search="invoices generate printable blank draft waiting paid payment unpaid">
    <h2>Invoices</h2>
    <p><?php if (!empty($invoiceUnpaid['ok']) && !empty($invoiceDrafts['ok'])): ?>
        <?= number_format($invoiceUnpaidCount) ?> waiting
        · <?= number_format($invoiceDraftCount) ?> draft.
      <?php else: ?>
        Generate printable invoices from unpaid LIVE orders.
      <?php endif; ?></p>
  </a>
  <a class="launch-card" href="index.php?page=admin_users" data-dashboard-item
     data-search="users admin team logins password department assign awaiting must change">
    <h2>Users</h2>
    <p><?php if (!empty($team['ok'])): ?>
        <?= number_format($teamCount) ?> active team user<?= $teamCount === 1 ? '' : 's' ?><?php if ($deptUnassignedTeam > 0): ?> · <?= (int) $deptUnassignedTeam ?> awaiting assignment<?php endif; ?><?php if ($mustChangeCount > 0): ?> · <?= (int) $mustChangeCount ?> must change password<?php endif; ?>.
      <?php else: ?>
        Could not load team users.
      <?php endif; ?></p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches" data-dashboard-item
     data-search="site adding history who added sites by day batches">
    <h2>Site adding history</h2>
    <p>See who added sites, by day.</p>
  </a>
</div>
<p class="help dashboard-search-empty" data-dashboard-search-empty hidden style="margin-top:0.5rem">No dashboard items match your search.</p>

<?php if ($waitingByBill): ?>
<div class="card" id="dashboard-waiting-bills" data-dashboard-item
     data-search="waiting invoices bill as unpaid euro totals">
  <div class="invoice-list-toolbar" style="margin-bottom:0.7rem">
    <h2 style="margin:0"><?= label_with_info('Waiting by bill-as', 'Unpaid sent invoices grouped by client email or name. Open Waiting to mark paid.') ?></h2>
    <a class="btn secondary small" href="index.php?page=admin_invoices&amp;filter=unpaid">Waiting invoices</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bill as</th><th class="num">Bills</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($waitingByBill as $billRow): ?>
        <tr>
          <td><?= h((string) $billRow['bill_as']) ?></td>
          <td class="num"><?= (int) $billRow['n'] ?></td>
          <td class="num"><?= h(format_euro($billRow['total'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" id="dashboard-recent-card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.7rem">
    <h2 style="margin:0">Recent Our database adds</h2>
    <a class="btn secondary small" href="index.php?page=admin_prospect_batches">See all</a>
  </div>
  <?php if ($recent): ?>
    <div class="table-wrap">
    <table id="dashboard-recent-table">
      <thead><tr><th>Date</th><th>Person</th><th>Sites</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr data-dashboard-item
            data-search="<?= h(mb_strtolower(trim(
                (string) $b['batch_date'] . ' '
                . (string) ($b['full_name'] ?: $b['username']) . ' '
                . (string) ($b['username'] ?? '') . ' '
                . (int) $b['site_count'] . ' sites site adding history'
            ))) ?>">
          <td><?= h($b['batch_date']) ?></td>
          <td><?= h($b['full_name'] ?: $b['username']) ?></td>
          <td><?= (int) $b['site_count'] ?></td>
          <td><a href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
        <tr class="sheet-search-empty" data-dashboard-recent-empty hidden>
          <td colspan="4" class="muted">No recent adds match your search.</td>
        </tr>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <p>No sites added yet.</p>
      <a class="btn" href="index.php?page=admin_prospects#add-sites">Add the first sites</a>
    </div>
  <?php endif; ?>
</div>
<script>
(function () {
  var input = document.getElementById('dashboard-search');
  if (!input) return;
  var matchItems = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-dashboard-search-meta]');
  var emptyCards = document.querySelector('[data-dashboard-search-empty]');
  var emptyRecent = document.querySelector('[data-dashboard-recent-empty]');
  var recentCard = document.getElementById('dashboard-recent-card');
  var attention = document.querySelector('[data-dashboard-attention]');

  function clearHits() {
    document.querySelectorAll('.sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function filterDashboard() {
    var q = String(input.value || '').trim().toLowerCase();
    var items = document.querySelectorAll('[data-dashboard-item]');
    var shownMain = 0;
    var shownRecent = 0;
    matchItems = [];
    clearHits();
    items.forEach(function (el) {
      var hay = String(el.getAttribute('data-search') || '').toLowerCase();
      var hit = !q || hay.indexOf(q) !== -1;
      el.hidden = !hit;
      if (hit) {
        if (el.closest('#dashboard-recent-table')) shownRecent++;
        else shownMain++;
        if (q) matchItems.push(el);
      }
    });
    if (emptyCards) emptyCards.hidden = !(q && shownMain === 0);
    if (emptyRecent) emptyRecent.hidden = !(q && shownRecent === 0 && document.querySelectorAll('#dashboard-recent-table [data-dashboard-item]').length > 0);
    if (recentCard) recentCard.hidden = !!(q && shownRecent === 0);
    if (attention) {
      var chips = attention.querySelectorAll('[data-dashboard-item]');
      var shownChips = 0;
      chips.forEach(function (chip) {
        if (!chip.hidden) shownChips++;
      });
      attention.hidden = !!(q && chips.length && shownChips === 0);
    }
    if (matchIndex >= matchItems.length) matchIndex = matchItems.length ? 0 : -1;
    if (meta) {
      if (q) {
        meta.hidden = false;
        if (!matchItems.length) {
          meta.textContent = '0 · Enter = next';
        } else if (matchIndex >= 0) {
          meta.textContent = (matchIndex + 1) + ' of ' + matchItems.length + ' · Enter = next';
        } else {
          meta.textContent = matchItems.length + (matchItems.length === 1 ? ' match' : ' matches')
            + ' · Enter = next';
        }
      } else {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
      }
    }
  }

  function jumpToMatch(dir) {
    var q = String(input.value || '').trim();
    if (!q) return;
    filterDashboard();
    if (!matchItems.length) return;
    if (matchIndex < 0) {
      matchIndex = dir > 0 ? 0 : matchItems.length - 1;
    } else {
      matchIndex = (matchIndex + dir + matchItems.length) % matchItems.length;
    }
    var el = matchItems[matchIndex];
    if (!el) return;
    clearHits();
    el.hidden = false;
    el.classList.add('sheet-search-hit');
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (meta) {
      meta.hidden = false;
      meta.textContent = (matchIndex + 1) + ' of ' + matchItems.length + ' · Enter = next';
    }
    window.setTimeout(function () {
      try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
      try {
        var len = String(input.value || '').length;
        input.setSelectionRange(len, len);
      } catch (err2) {}
    }, 0);
  }

  var filterTimer = null;
  function scheduleFilterDashboard() {
    if (filterTimer) window.clearTimeout(filterTimer);
    var q = String(input.value || '').trim();
    if (!q) {
      filterTimer = null;
      filterDashboard();
      return;
    }
    filterTimer = window.setTimeout(function () {
      filterTimer = null;
      filterDashboard();
    }, 80);
  }

  input.addEventListener('input', function () {
    matchIndex = -1;
    scheduleFilterDashboard();
  });
  input.addEventListener('search', function () {
    matchIndex = -1;
    scheduleFilterDashboard();
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      jumpToMatch(e.shiftKey ? -1 : 1);
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
