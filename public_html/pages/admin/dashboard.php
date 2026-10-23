<?php
$user = require_admin();
require_once __DIR__ . '/../../includes/admin_dashboard.php';

$range = admin_dashboard_normalize_range((string) get('range', '7d'));
$dash = admin_dashboard_build($range, $user);
$window = $dash['window'];
$stats = $dash['stats'];
$money = $dash['money'];
$funnel = $dash['funnel'];
$alerts = $dash['alerts'];
$board = $dash['leaderboard'];

$recent = [];
try {
    $recent = list_prospect_batches(null, 8);
} catch (Throwable $e) {
    $recent = [];
}

$rangeBase = 'index.php?page=admin_dashboard';

render_header('Dashboard', 'admin');
?>
<div class="topbar dashboard-topbar">
  <div>
    <h1><?= label_with_info(
        'Admin dashboard',
        'Overview of Our database, Extracted Sites, Emails data, departments, orders, and invoices. Use the range switch to scope counts. Stat trends compare to the previous equal window (or last 7 days when viewing All).'
    ) ?></h1>
    <p class="dashboard-updated muted">
      Hello <?= h($user['full_name'] ?: $user['username']) ?>
      · Updated <?= h((string) $dash['updated_at']) ?>
    </p>
  </div>
  <div class="actions dashboard-top-actions">
    <form class="dashboard-range" method="get" action="index.php" aria-label="Dashboard date range">
      <input type="hidden" name="page" value="admin_dashboard">
      <?php foreach (admin_dashboard_range_keys() as $key):
          $label = match ($key) {
              'today' => 'Today',
              '7d' => '7 days',
              '30d' => '30 days',
              default => 'All',
          };
          ?>
        <button type="submit" name="range" value="<?= h($key) ?>"
                class="dashboard-range-btn<?= $range === $key ? ' is-active' : '' ?>"
                <?= $range === $key ? ' aria-current="true"' : '' ?>><?= h($label) ?></button>
      <?php endforeach; ?>
    </form>
    <form class="dashboard-global-search" method="get" action="index.php" role="search">
      <input type="hidden" name="page" value="admin_search">
      <label class="visually-hidden" for="admin-global-search">Search domains, emails, orders</label>
      <input id="admin-global-search" type="search" name="q" placeholder="Search domains, emails, orders…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Search Our database, emails, and order sheets">
      <button class="btn secondary" type="submit">Search</button>
    </form>
  </div>
</div>

<?php if ($alerts): ?>
  <div class="dashboard-attention" aria-label="Alerts">
    <?php foreach ($alerts as $chip):
        $tone = (string) ($chip['tone'] ?? 'neutral');
        ?>
      <a class="dashboard-chip tone-<?= h($tone) ?>" href="<?= h((string) $chip['href']) ?>">
        <?= h((string) $chip['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="dashboard-stats grid" aria-label="Key stats for <?= h((string) $window['label']) ?>">
  <?php foreach ($stats as $key => $stat):
      $delta = (int) ($stat['delta'] ?? 0);
      $deltaClass = $delta > 0 ? 'is-up' : ($delta < 0 ? 'is-down' : 'is-flat');
      $trendNote = (string) ($stat['trend_label'] ?? '');
      ?>
    <a class="card stat" href="<?= h((string) $stat['href']) ?>"
       title="<?= h((string) $stat['label']) ?>">
      <span class="muted"><?= h((string) $stat['label']) ?></span>
      <strong><?= number_format((int) $stat['value']) ?></strong>
      <?php if ($key !== 'team'): ?>
        <span class="dashboard-stat-trend <?= $deltaClass ?>">
          <?= h(admin_dashboard_format_delta($delta)) ?>
          <?php if ($trendNote !== ''): ?>
            <span class="dashboard-stat-trend-note"><?= h($trendNote) ?></span>
          <?php endif; ?>
        </span>
      <?php else: ?>
        <span class="dashboard-stat-trend is-flat">active logins</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</section>

<section class="dashboard-money grid" aria-label="Money">
  <a class="card dashboard-money-card" href="index.php?page=admin_orders&amp;folder=completed&amp;status=unpaid">
    <span class="muted">Unpaid LIVE</span>
    <strong><?= h(format_euro($money['unpaid_live_euro'])) ?></strong>
    <span class="dashboard-money-sub muted"><?= number_format((int) $money['unpaid_live_count']) ?> placement<?= (int) $money['unpaid_live_count'] === 1 ? '' : 's' ?></span>
  </a>
  <a class="card dashboard-money-card<?= (int) $money['overdue_count'] > 0 ? ' tone-danger' : ((int) $money['waiting_count'] > 0 ? ' tone-warn' : '') ?>"
     href="index.php?page=admin_invoices&amp;filter=unpaid">
    <span class="muted">Waiting invoices</span>
    <strong><?= h(format_euro($money['waiting_euro'])) ?></strong>
    <span class="dashboard-money-sub muted">
      <?= number_format((int) $money['waiting_count']) ?> waiting
      <?php if ((int) $money['overdue_count'] > 0): ?>
        · <?= number_format((int) $money['overdue_count']) ?> overdue
      <?php endif; ?>
    </span>
  </a>
  <a class="card dashboard-money-card" href="index.php?page=admin_invoices&amp;filter=paid">
    <span class="muted">Revenue this month</span>
    <strong><?= h(format_euro($money['revenue_month'])) ?></strong>
    <span class="dashboard-money-sub muted">Paid invoices in <?= h(date('M Y')) ?></span>
  </a>
  <div class="card dashboard-money-card">
    <span class="muted">Paid vs unpaid</span>
    <strong><?= h(format_euro($money['paid_euro'])) ?> <span class="dashboard-vs">/</span> <?= h(format_euro($money['unpaid_euro'])) ?></strong>
    <span class="dashboard-money-sub muted">All-time invoice totals</span>
  </div>
</section>

<section class="card dashboard-funnel" aria-label="Outreach funnel">
  <h2 class="dashboard-section-title">Outreach funnel <span class="muted">(<?= h((string) $window['label']) ?>)</span></h2>
  <?php
    $steps = [
        ['label' => 'Extracted', 'n' => (int) $funnel['extracted'], 'href' => 'index.php?page=admin_extracted', 'pct' => null],
        ['label' => 'Emails', 'n' => (int) $funnel['emails'], 'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails', 'pct' => $funnel['pct_emails']],
        ['label' => 'Emailed', 'n' => (int) $funnel['emailed'], 'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails', 'pct' => $funnel['pct_emailed']],
        ['label' => 'Orders', 'n' => (int) $funnel['orders'], 'href' => 'index.php?page=admin_orders', 'pct' => $funnel['pct_orders']],
    ];
    $anyFunnel = false;
    foreach ($steps as $s) {
        if ($s['n'] > 0) {
            $anyFunnel = true;
            break;
        }
    }
  ?>
  <?php if (!$anyFunnel): ?>
    <div class="empty-state">
      <p>No funnel activity in this range yet.</p>
      <p class="muted">Push Extracting Results or add order rows to see conversion.</p>
    </div>
  <?php else: ?>
    <ol class="dashboard-funnel-steps">
      <?php foreach ($steps as $i => $s): ?>
        <li>
          <?php if ($i > 0): ?><span class="dashboard-funnel-arrow" aria-hidden="true">→</span><?php endif; ?>
          <a class="dashboard-funnel-step" href="<?= h((string) $s['href']) ?>">
            <span class="dashboard-funnel-n"><?= number_format((int) $s['n']) ?></span>
            <span class="dashboard-funnel-label"><?= h((string) $s['label']) ?></span>
            <?php if ($s['pct'] !== null): ?>
              <span class="dashboard-funnel-pct muted"><?= h((string) $s['pct']) ?>%</span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<div class="dashboard-two-col">
  <section class="card" aria-label="Team leaderboard">
    <h2 class="dashboard-section-title">Team leaderboard</h2>
    <?php if (!$board): ?>
      <div class="empty-state">
        <p>No team activity today or this week.</p>
        <p class="muted">Adds, Extracting Push, and email pushes show up here.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="dashboard-leaderboard">
          <thead>
            <tr>
              <th>Person</th>
              <th class="num">Added</th>
              <th class="num">Extracted</th>
              <th class="num">Emailed</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($board as $row): ?>
            <tr>
              <td>
                <a href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $row['id'] ?>">
                  <?= h((string) $row['name']) ?>
                </a>
              </td>
              <td class="num" title="Today / this week">
                <?= (int) $row['added_today'] ?> <span class="muted">/ <?= (int) $row['added_week'] ?></span>
              </td>
              <td class="num" title="Today / this week">
                <?= (int) $row['extracted_today'] ?> <span class="muted">/ <?= (int) $row['extracted_week'] ?></span>
              </td>
              <td class="num" title="Today / this week">
                <?= (int) $row['emailed_today'] ?> <span class="muted">/ <?= (int) $row['emailed_week'] ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="help muted" style="margin-top:0.65rem">Columns show today / last 7 days. Open a name for Site adding history.</p>
    <?php endif; ?>
  </section>

  <section class="card" aria-label="Recent adds">
    <div class="invoice-list-toolbar" style="margin-bottom:0.7rem">
      <h2 class="dashboard-section-title" style="margin:0">Recent adds</h2>
      <a class="btn secondary small" href="index.php?page=admin_prospect_batches">All history</a>
    </div>
    <?php if ($recent): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Person</th><th class="num">Sites</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($recent as $b): ?>
            <tr>
              <td><?= h((string) $b['batch_date']) ?></td>
              <td><?= h((string) ($b['full_name'] ?: $b['username'])) ?></td>
              <td class="num"><?= (int) $b['site_count'] ?></td>
              <td><a href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <p>No sites added yet.</p>
        <a class="btn" href="index.php?page=admin_prospects#add-sites">Add the first sites</a>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php
// Compact workflow strip — tools appear here once (no launch-card grid).
render_workflow([
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects', 'hint' => 'Country folders'],
    ['label' => 'Extracted', 'href' => 'index.php?page=admin_extracted', 'hint' => 'From Push'],
    ['label' => 'Emails data', 'href' => 'index.php?page=admin_emails_data', 'hint' => 'Archive · campaigns'],
    ['label' => 'Orders', 'href' => 'index.php?page=admin_orders', 'hint' => 'Client sheets'],
    ['label' => 'Invoices', 'href' => 'index.php?page=admin_invoices', 'hint' => 'Printable bills'],
    ['label' => 'Website prices', 'href' => 'index.php?page=admin_site_prices', 'hint' => 'Publisher rates'],
]);
?>

<?php render_footer('admin'); ?>
