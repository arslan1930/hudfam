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
        $task = get_department_task($taskId);
        if (!$task || (int) $task['department_id'] !== (int) $dept['id']) {
            flash('error', 'Task not found.');
            redirect($back);
        }
        update_department_task_status($taskId, (string) post('status'));
        flash('ok', 'Status updated.');
        redirect($back);
    }
}

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
              : 'You are not assigned to a department yet. Ask Admin to add you.' ?>
        </p>
      </div>
    </div>

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
$tasks = list_department_tasks($deptId, $statusFilter);
$stats = department_stats($deptId);

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
    <a class="btn secondary" href="<?= h($base) ?>">All my departments</a>
  </div>
</div>

<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0">Tasks</h2>
    <div class="actions">
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>">All</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=open">Open</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=in_progress">In progress</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=done">Done</a>
    </div>
  </div>

  <?php if (!$tasks): ?>
  <div class="empty-state">
    <p>No tasks<?= $statusFilter !== '' ? ' with this status' : ' assigned yet' ?>.</p>
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
          ?>
        <tr class="<?= $mine ? 'dept-task-mine' : '' ?>">
          <td>
            <strong><?= h((string) $t['title']) ?></strong>
            <?php if ($mine): ?><span class="badge">Yours</span><?php endif; ?>
            <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
              <div class="help"><?= nl2br(h((string) $t['notes'])) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($assignee !== '' ? $assignee : 'Whole department') ?></td>
          <td>
            <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>" class="inline-form">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
              <select name="status" onchange="this.form.submit()">
                <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'] as $val => $lab): ?>
                  <option value="<?= h($val) ?>" <?= (string) $t['status'] === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="muted"><?= h((string) ($t['due_date'] ?: '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
