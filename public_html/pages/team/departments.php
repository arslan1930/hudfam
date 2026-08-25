<?php
/**
 * Team · Departments — members only see folders they belong to + their tasks.
 */
$user = require_team();
ensure_departments_schema();

$uid = (int) $user['id'];
$isAdminViewing = (($user['role'] ?? '') === 'admin');
$base = 'index.php?page=team_departments';

// Admins can browse all; team only their memberships.
$myDepartments = $isAdminViewing ? list_departments(true) : list_departments_for_user($uid);
$allowedSlugs = array_map(static fn ($d) => (string) $d['slug'], $myDepartments);

$folder = (string) get('folder');
if ($folder !== '' && !in_array($folder, $allowedSlugs, true)) {
    flash('error', 'You are not assigned to that department.');
    redirect($base);
}

$dept = $folder !== '' ? get_department_by_slug($folder) : null;
if ($folder !== '' && !$dept) {
    flash('error', 'Department not found.');
    redirect($base);
}
if ($dept && !$isAdminViewing && !user_in_department($uid, (int) $dept['id'])) {
    flash('error', 'You are not assigned to that department.');
    redirect($base);
}

// Team can update status on their department tasks
if ($dept && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $back = $base . '&folder=' . rawurlencode((string) $dept['slug']);

    if ($action === 'set_status') {
        $taskId = (int) post('task_id');
        $status = (string) post('status');
        $wantsJson = (string) post('ajax') === '1'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $task = get_department_task($taskId);
        if (!$task || (int) $task['department_id'] !== (int) $dept['id']) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Task not found.']);
                exit;
            }
            flash('error', 'Task not found.');
            redirect($back);
        }
        if (!team_can_set_department_task_status($user, $task)) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Only the assignee can update this task.']);
                exit;
            }
            flash('error', 'Only the assignee can update this task.');
            redirect($back);
        }
        $updated = update_department_task_status($taskId, $status);
        if (!$updated) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid status.']);
                exit;
            }
            flash('error', 'Invalid status.');
            redirect($back);
        }
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'task_id' => $taskId,
                'status' => $status,
                'message' => 'Status updated.',
            ]);
            exit;
        }
        flash('ok', 'Status updated.');
        redirect($back);
    }
}

$canAdminEmailsSearch = team_page_unlocked($user, 'team_admin_emails_delete');
$canSitesEmails = team_page_unlocked($user, 'team_sites_emails');
$canCampaigns = team_page_unlocked($user, 'team_email_campaigns');
$canCampaignDrafts = team_page_unlocked($user, 'team_email_campaigns_drafts');
$showEmailCommShortcuts = $canAdminEmailsSearch || $canSitesEmails || $canCampaigns || $canCampaignDrafts;

if (!$dept) {
    render_header('My departments', 'team');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
        ['label' => 'My departments'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1>My departments</h1>
        <p class="muted">
          <?= $myDepartments
              ? 'Open a department to see work assigned to your team.'
              : 'You are not assigned to a department yet. Ask Admin to add you — tools stay locked until then.' ?>
        </p>
      </div>
      <?php if ($showEmailCommShortcuts && $myDepartments): ?>
        <div class="actions">
          <?php if ($canSitesEmails): ?>
            <a class="btn" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
          <?php endif; ?>
          <?php if ($canAdminEmailsSearch): ?>
            <a class="btn <?= $canSitesEmails ? 'secondary' : '' ?>" href="index.php?page=team_admin_emails_delete">Admin emails search</a>
          <?php endif; ?>
          <?php if ($canCampaigns): ?>
            <a class="btn secondary" href="index.php?page=team_email_campaigns">Campaign search</a>
          <?php endif; ?>
          <?php if ($canCampaignDrafts): ?>
            <a class="btn secondary" href="index.php?page=team_email_campaigns_drafts">Campaign drafts</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$myDepartments && !$isAdminViewing): ?>
    <div class="card">
      <div class="empty-state">
        <p>No departments yet.</p>
        <p class="muted">
          Admin assigns departments under Admin → Departments.
          After you are added, your tasks and tools unlock automatically.
        </p>
      </div>
    </div>
    <?php
    render_footer('team');
    return;
    endif; ?>

    <?php if ($showEmailCommShortcuts && $myDepartments): ?>
    <div class="card" style="margin-bottom:1rem">
      <h2 style="margin-top:0">Email &amp; Communication tools</h2>
      <p class="help muted" style="margin-bottom:0.85rem">
        Shortcuts for unlocked tools — keeps this departments view focused on tasks.
      </p>
      <div class="folders">
        <?php if ($canSitesEmails): ?>
        <a class="folder" href="index.php?page=team_sites_emails">
          <h3>Sites with emails - Team</h3>
          <p class="muted">Add emails · Push final list to Admin</p>
        </a>
        <?php endif; ?>
        <?php if ($canAdminEmailsSearch): ?>
        <a class="folder" href="index.php?page=team_admin_emails_delete">
          <h3>Admin emails search</h3>
          <p class="muted">Sites with emails - Admin · all countries</p>
        </a>
        <?php endif; ?>
        <?php if ($canCampaigns): ?>
        <a class="folder" href="index.php?page=team_email_campaigns">
          <h3>Campaign search</h3>
          <p class="muted">Email campaign sheets · all countries</p>
        </a>
        <?php endif; ?>
        <?php if ($canCampaignDrafts): ?>
        <a class="folder" href="index.php?page=team_email_campaigns_drafts">
          <h3>Campaign drafts</h3>
          <p class="muted">Formatted outreach per project · copy for email</p>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <?php if ($myDepartments): ?>
      <div class="folders">
        <?php foreach ($myDepartments as $d):
            $stats = department_stats((int) $d['id']);
            ?>
          <a class="folder" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $d['slug']) ?>">
            <h3><?= h((string) $d['name']) ?></h3>
            <p class="muted">
              <?= (int) $stats['open_tasks'] ?> open task<?= (int) $stats['open_tasks'] === 1 ? '' : 's' ?>
              · <?= (int) $stats['total_tasks'] ?> total
            </p>
          </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <p>No department assigned.</p>
        <p class="muted">Admin → Departments → add your login to a folder.</p>
      </div>
      <?php endif; ?>
    </div>
    <?php
    render_footer('team');
    return;
}

$deptId = (int) $dept['id'];
$statusFilter = (string) get('status');
$assigneeFilter = (string) get('assignee');
if (!in_array($assigneeFilter, ['', 'all', 'mine', 'unassigned'], true)) {
    $assigneeFilter = '';
}
if ($assigneeFilter === 'all') {
    $assigneeFilter = '';
}
$taskQ = trim((string) get('q'));
$tasksAll = list_department_tasks($deptId, $statusFilter, $uid, $assigneeFilter, $taskQ);
$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$totalTasks = count($tasksAll);
$totalPages = max(1, (int) ceil($totalTasks / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$tasks = array_slice($tasksAll, ($pageNum - 1) * $perPage, $perPage);
$stats = department_stats($deptId);
$isCommunicationDept = (string) $dept['slug'] === 'communication';
$isEmailExtractingDept = (string) $dept['slug'] === 'email_extracting';

$deptFolderUrl = static function (array $overrides = []) use ($base, $dept, $statusFilter, $assigneeFilter, $taskQ, $pageNum): string {
    $params = array_merge([
        'folder' => (string) $dept['slug'],
        'status' => $statusFilter,
        'assignee' => $assigneeFilter,
        'q' => $taskQ,
        'p' => $pageNum,
    ], $overrides);
    $href = $base . '&folder=' . rawurlencode((string) $params['folder']);
    if (($params['status'] ?? '') !== '') {
        $href .= '&status=' . rawurlencode((string) $params['status']);
    }
    if (($params['assignee'] ?? '') !== '') {
        $href .= '&assignee=' . rawurlencode((string) $params['assignee']);
    }
    if (trim((string) ($params['q'] ?? '')) !== '') {
        $href .= '&q=' . rawurlencode((string) $params['q']);
    }
    if ((int) ($params['p'] ?? 1) > 1) {
        $href .= '&p=' . (int) $params['p'];
    }
    return $href;
};

render_header((string) $dept['name'], 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'My departments', 'href' => $base],
    ['label' => (string) $dept['name']],
]);
?>
<div class="topbar">
  <div>
    <h1><?= h((string) $dept['name']) ?></h1>
    <p class="muted">
      <?= (int) $stats['open_tasks'] ?> open · <?= (int) $stats['total_tasks'] ?> task<?= (int) $stats['total_tasks'] === 1 ? '' : 's' ?>
      · update status as you work
    </p>
  </div>
  <div class="actions">
    <?php if ($isEmailExtractingDept): ?>
      <?php if ($canSitesEmails): ?>
        <a class="btn" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
      <?php endif; ?>
      <?php if ($canAdminEmailsSearch): ?>
        <a class="btn <?= $canSitesEmails ? 'secondary' : '' ?>" href="index.php?page=team_admin_emails_delete">Admin emails search</a>
      <?php endif; ?>
    <?php elseif ($isCommunicationDept): ?>
      <?php if ($canAdminEmailsSearch): ?>
        <a class="btn" href="index.php?page=team_admin_emails_delete">Admin emails search</a>
      <?php endif; ?>
      <?php if ($canCampaigns): ?>
        <a class="btn secondary" href="index.php?page=team_email_campaigns">Campaign search</a>
      <?php endif; ?>
      <?php if ($canCampaignDrafts): ?>
        <a class="btn secondary" href="index.php?page=team_email_campaigns_drafts">Campaign drafts</a>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn secondary" href="<?= h($base) ?>">All my departments</a>
  </div>
</div>

<?php if ($isEmailExtractingDept && ($canSitesEmails || $canAdminEmailsSearch)): ?>
<div class="card" style="margin-bottom:1rem">
  <h2 style="margin-top:0">Email Extracting tools</h2>
  <p class="help muted" style="margin-bottom:0.85rem">
    Add emails on the Team sheet, then Push to Admin. Search Admin to clean emails after push.
  </p>
  <div class="folders">
    <?php if ($canSitesEmails): ?>
    <a class="folder" href="index.php?page=team_sites_emails">
      <h3>Sites with emails - Team</h3>
      <p class="muted">Add emails · Push final list to Admin</p>
    </a>
    <?php endif; ?>
    <?php if ($canAdminEmailsSearch): ?>
    <a class="folder" href="index.php?page=team_admin_emails_delete">
      <h3>Admin emails search</h3>
      <p class="muted">Sites with emails - Admin · all countries</p>
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($isCommunicationDept && ($canAdminEmailsSearch || $canCampaigns || $canCampaignDrafts)): ?>
<div class="card" style="margin-bottom:1rem">
  <h2 style="margin-top:0">Communication tools</h2>
  <p class="help muted" style="margin-bottom:0.85rem">
    Search sheets to clean emails, or open drafts to copy outreach text per project.
  </p>
  <div class="folders">
    <?php if ($canAdminEmailsSearch): ?>
    <a class="folder" href="index.php?page=team_admin_emails_delete">
      <h3>Admin emails search</h3>
      <p class="muted">Sites with emails - Admin · all countries</p>
    </a>
    <?php endif; ?>
    <?php if ($canCampaigns): ?>
    <a class="folder" href="index.php?page=team_email_campaigns">
      <h3>Campaign search</h3>
      <p class="muted">Email campaign sheets · all countries</p>
    </a>
    <?php endif; ?>
    <?php if ($canCampaignDrafts): ?>
    <a class="folder" href="index.php?page=team_email_campaigns_drafts">
      <h3>Campaign drafts</h3>
      <p class="muted">Formatted outreach per project · copy for email</p>
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0">Tasks</h2>
    <div class="actions">
      <?php
      $statusLinks = [
          '' => 'All',
          'open' => 'Open',
          'in_progress' => 'In progress',
          'done' => 'Done',
      ];
      foreach ($statusLinks as $val => $lab):
          $href = $deptFolderUrl(['status' => $val, 'p' => 1]);
          $active = $statusFilter === $val ? ' active-soft' : '';
          ?>
        <a class="btn secondary small<?= $active ?>" href="<?= h($href) ?>"><?= h($lab) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="actions" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.35rem">
    <?php
    $assigneeLinks = [
        '' => 'Everyone',
        'mine' => 'Mine',
        'unassigned' => 'Unassigned',
    ];
    foreach ($assigneeLinks as $val => $lab):
        $href = $deptFolderUrl(['assignee' => $val, 'p' => 1]);
        $active = $assigneeFilter === $val ? ' active-soft' : '';
        ?>
      <a class="btn secondary small<?= $active ?>" href="<?= h($href) ?>"><?= h($lab) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="index.php" class="actions" style="margin-bottom:0.85rem;flex-wrap:wrap;gap:0.45rem;align-items:center">
    <input type="hidden" name="page" value="team_departments">
    <input type="hidden" name="folder" value="<?= h((string) $dept['slug']) ?>">
    <?php if ($statusFilter !== ''): ?>
      <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
    <?php endif; ?>
    <?php if ($assigneeFilter !== ''): ?>
      <input type="hidden" name="assignee" value="<?= h($assigneeFilter) ?>">
    <?php endif; ?>
    <label class="sheet-search" for="dept-task-search" style="margin:0">
      <span class="visually-hidden">Search tasks</span>
      <input id="dept-task-search" type="search" name="q" value="<?= h($taskQ) ?>"
             placeholder="Search tasks…" autocomplete="off" spellcheck="false" data-no-draft>
    </label>
    <button class="btn secondary small" type="submit">Search</button>
  </form>

  <?php if (!$tasks): ?>
  <div class="empty-state">
    <p><?php
      if ($taskQ !== '') {
          echo 'No tasks match this search.';
      } elseif ($assigneeFilter !== '') {
          echo 'No tasks with this assignee filter.';
      } elseif ($statusFilter !== '') {
          echo 'No tasks with this status.';
      } else {
          echo 'No tasks assigned yet.';
      }
    ?></p>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="dept-task-table">
      <thead>
        <tr>
          <th>Task</th>
          <th>Assigned</th>
          <th>Status</th>
          <th>Due</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tasks as $t):
          $assignee = trim((string) ($t['assigned_name'] ?? '')) !== ''
              ? (string) $t['assigned_name']
              : (string) ($t['assigned_username'] ?? '');
          $mine = (int) ($t['assigned_to'] ?? 0) === $uid;
          $overdue = department_task_is_overdue($t);
          $rowClass = trim(($mine ? 'dept-task-mine' : '') . ($overdue ? ' dept-task-overdue' : ''));
          $canSetStatus = team_can_set_department_task_status($user, $t);
          ?>
        <tr<?= $rowClass !== '' ? ' class="' . h($rowClass) . '"' : '' ?> data-due="<?= h((string) ($t['due_date'] ?? '')) ?>">
          <td>
            <strong><?= h((string) $t['title']) ?></strong>
            <?php if ($mine): ?><span class="badge">Yours</span><?php endif; ?>
            <?php if ($overdue): ?><span class="badge" data-overdue-badge>Overdue</span><?php endif; ?>
            <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
              <div class="help"><?= nl2br(h((string) $t['notes'])) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($assignee !== '' ? $assignee : 'Whole department') ?></td>
          <td>
            <?php if ($canSetStatus): ?>
            <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>"
                  class="inline-form" data-stay-ajax>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
              <select name="status" data-stay-ajax-change aria-label="Task status">
                <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'] as $val => $lab): ?>
                  <option value="<?= h($val) ?>" <?= (string) $t['status'] === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
              <?= h(department_task_status_label((string) $t['status'])) ?>
            <?php endif; ?>
          </td>
          <td class="muted<?= $overdue ? ' dept-due-overdue' : '' ?>" data-due-cell><?= h((string) ($t['due_date'] ?: '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <p class="muted" style="margin-top:0.85rem">
    Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
    · <?= (int) $totalTasks ?> task<?= $totalTasks === 1 ? '' : 's' ?>
    <?php if ($pageNum > 1): ?>
      · <a href="<?= h($deptFolderUrl(['p' => $pageNum - 1])) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
      · <a href="<?= h($deptFolderUrl(['p' => $pageNum + 1])) ?>">Next</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>
  <?php endif; ?>
</div>
<script>
(function () {
  function syncOverdue(sel) {
    var tr = sel.closest('tr');
    if (!tr) return;
    var status = String(sel.value || '');
    var due = String(tr.getAttribute('data-due') || '');
    var today = new Date();
    var ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    var overdue = (status === 'open' || status === 'in_progress') && due !== '' && due < ymd;
    tr.classList.toggle('dept-task-overdue', overdue);
    var dueCell = tr.querySelector('[data-due-cell]');
    if (dueCell) dueCell.classList.toggle('dept-due-overdue', overdue);
    var badge = tr.querySelector('[data-overdue-badge]');
    if (overdue && !badge) {
      var strong = tr.querySelector('td strong');
      if (strong) {
        var span = document.createElement('span');
        span.className = 'badge';
        span.setAttribute('data-overdue-badge', '');
        span.textContent = 'Overdue';
        strong.insertAdjacentElement('afterend', span);
        strong.insertAdjacentText('afterend', ' ');
      }
    } else if (!overdue && badge) {
      badge.remove();
    }
  }
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || sel.name !== 'status' || !sel.matches || !sel.matches('[data-stay-ajax-change]')) return;
    syncOverdue(sel);
  });
})();
</script>
<?php render_footer('team'); ?>
