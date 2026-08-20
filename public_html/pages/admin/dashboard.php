<?php
$user = require_admin();
$prospectTotal = 0;
$batchCount = 0;
$teamCount = 0;
try {
    $prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
} catch (Throwable $e) {
    $prospectTotal = 0;
}
try {
    $batchCount = (int) db()->query('SELECT COUNT(*) FROM prospect_batches')->fetchColumn();
} catch (Throwable $e) {
    $batchCount = 0;
}
try {
    $teamCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='team' AND is_active=1")->fetchColumn();
} catch (Throwable $e) {
    $teamCount = 0;
}

$recent = [];
try {
    $recent = list_prospect_batches(null, 8);
} catch (Throwable $e) {
    $recent = [];
}
$orderClientCount = 0;
$orderUnpaidLive = 0;
$invoiceCount = 0;
$extractedCount = 0;
$deptOpenTasks = 0;
$deptMembers = 0;
$deptUnassignedTeam = 0;
try {
    $omStats = order_management_dashboard_stats();
    $orderClientCount = (int) ($omStats['clients'] ?? 0);
    $orderUnpaidLive = (int) ($omStats['unpaid_live'] ?? 0);
} catch (Throwable $e) {
    $orderClientCount = 0;
    $orderUnpaidLive = 0;
}
try {
    $deptStats = departments_dashboard_stats();
    $deptOpenTasks = (int) ($deptStats['open_tasks'] ?? 0);
    $deptMembers = (int) ($deptStats['members'] ?? 0);
    $deptUnassignedTeam = (int) ($deptStats['unassigned_team'] ?? 0);
} catch (Throwable $e) {
    $deptOpenTasks = 0;
    $deptMembers = 0;
    $deptUnassignedTeam = 0;
}
try {
    ensure_invoice_schema();
    $invoiceCount = (int) db()->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
} catch (Throwable $e) {
    $invoiceCount = 0;
}
try {
    $extractedCount = count_extracted_sites();
} catch (Throwable $e) {
    $extractedCount = 0;
}

render_header('Dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Admin dashboard', 'Overview of Our database, Extracted Sites, Emails data, departments, orders, and invoices.') ?></h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — each country has its own URL database.</p>
  </div>
  <div class="actions" style="align-items:center;flex-wrap:wrap;gap:0.55rem">
    <label class="sheet-search dashboard-search" for="dashboard-search">
      <span class="visually-hidden">Search dashboard</span>
      <input id="dashboard-search" type="search" placeholder="Search…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to filter · Enter = next match · Shift+Enter = previous">
      <span class="sheet-search-meta muted" data-dashboard-search-meta hidden></span>
    </label>
    <a class="btn" href="index.php?page=admin_prospects#add-sites">Our database</a>
  </div>
</div>

<?php render_dashboard_help('admin'); ?>

<div class="grid">
  <div class="card stat"><span class="muted">URLs (all countries)</span><strong><?= $prospectTotal ?></strong></div>
  <div class="card stat"><span class="muted">Site adding history days</span><strong><?= $batchCount ?></strong></div>
  <div class="card stat"><span class="muted">Active team users</span><strong><?= $teamCount ?></strong></div>
  <div class="card stat"><span class="muted">Extracted sites</span><strong><?= $extractedCount ?></strong></div>
  <div class="card stat"><span class="muted">Client sheets</span><strong><?= $orderClientCount ?></strong></div>
  <div class="card stat"><span class="muted">Invoices</span><strong><?= $invoiceCount ?></strong></div>
</div>

<div class="launch-cards" id="dashboard-launch-cards">
  <a class="launch-card" href="index.php?page=admin_semrush_research" data-dashboard-item
    data-search="semrush research site finding">
    <h2>Semrush Research</h2>
    <p>Site Finding copy from Extracting Push · optional seed.</p>
  </a>
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
  <a class="launch-card" href="index.php?page=admin_emails_data" data-dashboard-item
     data-search="emails data sites with emails admin archive push">
    <h2>Emails data</h2>
    <p>Admin archive, Final mirror, and campaign sheets.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_orders" data-dashboard-item
     data-search="order management client sheets sites prices profit live url unpaid invoice">
    <h2>Order management</h2>
    <p><?= (int) $orderClientCount ?> active client<?= (int) $orderClientCount === 1 ? '' : 's' ?><?php if ($orderUnpaidLive > 0): ?> · <?= (int) $orderUnpaidLive ?> unpaid LIVE<?php endif; ?> — sheets, prices, profit.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_invoices" data-dashboard-item
     data-search="invoices generate printable blank draft done payment">
    <h2>Invoices</h2>
    <p>Generate printable invoices from completed articles.</p>
  </a>
  <a class="launch-card" href="index.php?page=admin_prospect_batches" data-dashboard-item
     data-search="site adding history who added sites by day batches">
    <h2>Site adding history</h2>
    <p>See who added sites, by day.</p>
  </a>
</div>
<p class="help dashboard-search-empty" data-dashboard-search-empty hidden style="margin-top:0.5rem">No dashboard items match your search.</p>

<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.7rem">
    <h2 style="margin:0">Recent adds</h2>
  </div>
  <?php if ($recent): ?>
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

  function clearHits() {
    document.querySelectorAll('.sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function filterDashboard() {
    var q = String(input.value || '').trim().toLowerCase();
    var items = document.querySelectorAll('[data-dashboard-item]');
    var shownCards = 0;
    var shownRecent = 0;
    matchItems = [];
    clearHits();
    items.forEach(function (el) {
      var hay = String(el.getAttribute('data-search') || '').toLowerCase();
      var hit = !q || hay.indexOf(q) !== -1;
      el.hidden = !hit;
      if (hit) {
        if (el.classList.contains('launch-card')) shownCards++;
        else shownRecent++;
        if (q) matchItems.push(el);
      }
    });
    if (emptyCards) emptyCards.hidden = !(q && shownCards === 0);
    if (emptyRecent) emptyRecent.hidden = !(q && shownRecent === 0 && document.querySelectorAll('#dashboard-recent-table [data-dashboard-item]').length > 0);
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

  input.addEventListener('input', function () {
    matchIndex = -1;
    filterDashboard();
  });
  input.addEventListener('search', function () {
    matchIndex = -1;
    filterDashboard();
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
