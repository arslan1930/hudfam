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
    <?php render_dashboard_help('team'); ?>
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

    <?php render_dashboard_help('team'); ?>

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
                $overdue = department_task_is_overdue($t);
                $rowClass = trim(($mine ? 'dept-task-mine' : '') . ($overdue ? ' dept-task-overdue' : ''));
                ?>
              <tr<?= $rowClass !== '' ? ' class="' . h($rowClass) . '"' : '' ?>>
                <td><?= h((string) $t['department_name']) ?></td>
                <td>
                  <strong><?= h((string) $t['title']) ?></strong>
                  <?php if ($mine): ?><span class="badge">Yours</span><?php endif; ?>
                  <?php if ($overdue): ?><span class="badge" data-overdue-badge>Overdue</span><?php endif; ?>
                  <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
                    <div class="help"><?= nl2br(h((string) $t['notes'])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h(department_task_status_label((string) $t['status'])) ?></td>
                <td class="muted<?= $overdue ? ' dept-due-overdue' : '' ?>"><?= h((string) ($t['due_date'] ?: '—')) ?></td>
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
        $toolCards[] = ['team_semrush_research', 'Semrush Research', 'From Extracting Push · edit, comment'];
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

// Admin viewing Team: every Team user is either waiting or assigned.
render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">
      You are signed in as Admin. Team members see assigned work here after you add them to a department.
      Our database country lists stay private to Admin.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_departments">My departments</a>
    <a class="btn" href="index.php?page=admin_dashboard">Admin dashboard</a>
  </div>
</div>
<?php render_dashboard_help('team'); ?>
<?php
render_footer('team');

