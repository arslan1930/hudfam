<?php
$user = require_team();
$uid = (int) $user['id'];
ensure_departments_schema();

$deptScoped = user_is_department_scoped($user);
$awaitsDept = team_user_awaits_department($user);
$myDepartments = $deptScoped ? list_departments_for_user($uid) : [];
$myTasks = $deptScoped ? list_open_tasks_for_user($uid, 40) : [];

if ($awaitsDept) {
    render_header('Dashboard', 'team');
    ?>
    <div class="topbar">
      <div>
        <h1>Waiting for assignment</h1>
        <p class="muted">Your Team login works, but you are not in a department yet.</p>
      </div>
    </div>
    <div class="card">
      <div class="empty-state">
        <p>No department assigned.</p>
        <p class="muted">
          Ask Admin to add you to a department (Site Finding, Site Extracting, Email Extracting, or Communication).
          Your tools will appear here after that.
        </p>
      </div>
      <p class="actions" style="margin-top:1rem;justify-content:center">
        <a class="btn secondary" href="index.php?page=team_departments">My departments</a>
      </p>
    </div>
    <?php
    render_footer('team');
    return;
}

if ($deptScoped) {
    render_header('Dashboard', 'team');
    ?>
    <div class="topbar">
      <div>
        <h1>Your work</h1>
        <p class="muted">
          You are assigned to
          <?= count($myDepartments) ?> department<?= count($myDepartments) === 1 ? '' : 's' ?>.
          Your tasks and department tools are shown below.
        </p>
      </div>
      <a class="btn" href="index.php?page=team_departments">My departments</a>
    </div>

    <div class="card">
      <h2>Open tasks</h2>
      <?php if (!$myTasks): ?>
        <div class="empty-state">
          <p>No open tasks right now.</p>
          <p class="muted">When Admin assigns work to your department, it appears here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="dept-task-table">
            <thead>
              <tr>
                <th>Department</th>
                <th>Task</th>
                <th>Status</th>
                <th>Due</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($myTasks as $t):
                $mine = (int) ($t['assigned_to'] ?? 0) === $uid;
                ?>
              <tr class="<?= $mine ? 'dept-task-mine' : '' ?>">
                <td><?= h((string) $t['department_name']) ?></td>
                <td>
                  <strong><?= h((string) $t['title']) ?></strong>
                  <?php if ($mine): ?><span class="badge">Yours</span><?php endif; ?>
                  <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
                    <div class="help"><?= nl2br(h((string) $t['notes'])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h(department_task_status_label((string) $t['status'])) ?></td>
                <td class="muted"><?= h((string) ($t['due_date'] ?: '—')) ?></td>
                <td>
                  <a href="index.php?page=team_departments&amp;folder=<?= urlencode((string) $t['department_slug']) ?>">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php
    $toolPages = department_tool_pages_for_user($user);
    $toolSet = array_fill_keys($toolPages, true);
    $toolCards = [];
    if (!empty($toolSet['team_prospect_check'])) {
        $toolCards[] = ['team_prospect_check', 'Filter & add', 'Paste → filter → add unique sites'];
        $toolCards[] = ['team_prospect_batches', 'Site adding history', 'Your daily adds'];
    }
    if (!empty($toolSet['team_semrush_research'])) {
        $toolCards[] = ['team_semrush_research', 'Semrush Research', 'Site names per country · edit + comments'];
    }
    if (!empty($toolSet['team_extracting'])) {
        $toolCards[] = ['team_extracting', 'Extracting sites', 'Sites list + Results + Push'];
    }
    if (!empty($toolSet['team_sites_emails'])) {
        $toolCards[] = ['team_sites_emails', 'Sites with emails - Team', 'Add emails · Push to Admin'];
    }
    if (!empty($toolSet['team_admin_emails_delete'])) {
        $toolCards[] = ['team_admin_emails_delete', 'Admin emails search', 'Sites with emails - Admin · all countries'];
    }
    if (!empty($toolSet['team_email_campaigns'])) {
        $toolCards[] = ['team_email_campaigns', 'Campaign search', 'Email campaign sheets · all countries'];
    }
    if (!empty($toolSet['team_email_campaigns_drafts'])) {
        $toolCards[] = ['team_email_campaigns_drafts', 'Campaign drafts', 'Formatted outreach per project · copy for email'];
    }
    ?>
    <?php if ($toolCards): ?>
    <div class="card" style="margin-top:1rem">
      <h2>Your tools</h2>
      <div class="folders">
        <?php foreach ($toolCards as [$pageKey, $title, $hint]): ?>
          <a class="folder" href="index.php?page=<?= h($pageKey) ?>">
            <h3><?= h($title) ?></h3>
            <p class="muted"><?= h($hint) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($myDepartments): ?>
    <div class="card" style="margin-top:1rem">
      <h2>Your departments</h2>
      <div class="folders">
        <?php foreach ($myDepartments as $d):
            $stats = department_stats((int) $d['id']);
            ?>
          <a class="folder" href="index.php?page=team_departments&amp;folder=<?= urlencode((string) $d['slug']) ?>">
            <h3><?= h((string) $d['name']) ?></h3>
            <p class="muted"><?= (int) $stats['open_tasks'] ?> open task<?= (int) $stats['open_tasks'] === 1 ? '' : 's' ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php
    render_footer('team');
    return;
}

$todayBatch = null;
$extractCount = 0;
try {
    $tb = db()->prepare(
        'SELECT * FROM prospect_batches WHERE user_id=? AND batch_date=CURDATE() LIMIT 1'
    );
    $tb->execute([$uid]);
    $todayBatch = $tb->fetch() ?: null;
} catch (Throwable $e) {
    $todayBatch = null;
}
try {
    $extractCount = count_extract_batches();
} catch (Throwable $e) {
    $extractCount = 0;
}

render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Filter new sites against a country database, then add only the unique ones. Existing country lists stay private.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_semrush_research">Semrush Research</a>
    <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
  </div>
</div>

<?php render_dashboard_help('team'); ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_prospect_check">
    <h2>Filter &amp; add</h2>
    <p>Filter against the country database, then add only new unique sites.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_semrush_research">
    <h2>Semrush Research</h2>
    <p>Site names per country from Admin · edit, copy, undo, comments.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_extracting">
    <h2>Extracting sites</h2>
    <p><?= $extractCount > 0 ? $extractCount . ' country batch' . ($extractCount === 1 ? '' : 'es') . ' ready' : 'Waiting for sites from the team mate' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_sites_emails">
    <h2>Sites with emails - Team</h2>
    <p>Add emails after Extracting Results Push, then Push to Admin.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_admin_emails_delete">
    <h2>Admin emails search</h2>
    <p>Super search Sites with emails - Admin · update or remove.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_email_campaigns">
    <h2>Campaign search</h2>
    <p>Super search Email campaign sheets across all countries.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_email_campaigns_drafts">
    <h2>Campaign drafts</h2>
    <p>Formatted outreach / offers per project · copy keeps email formatting.</p>
  </a>
  <a class="launch-card" href="index.php?page=team_departments">
    <h2>My departments</h2>
    <p>If Admin assigns you to a department, your tasks appear here.</p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Today’s history</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' sites added today' : 'No adds yet today' ?></p>
  </a>
  <a class="launch-card" href="index.php?page=team_prospect_batches">
    <h2>Site adding history</h2>
    <p>Sites you added, saved by day.</p>
  </a>
</div>
<?php render_footer('team'); ?>
