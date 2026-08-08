<?php
/**
 * Admin · Departments — office folders + assign members/tasks.
 */
$user = require_admin();
ensure_departments_schema();

$base = 'index.php?page=admin_departments';
$folder = (string) get('folder');
$allowed = array_column(department_seed_definitions(), 'slug');
if ($folder !== '' && !in_array($folder, $allowed, true)) {
    flash('error', 'Unknown department.');
    redirect($base);
}

$dept = $folder !== '' ? get_department_by_slug($folder) : null;
if ($folder !== '' && !$dept) {
    flash('error', 'Department not found. Run upgrade.php once.');
    redirect($base);
}

// --- Mutations inside a department ---
if ($dept && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $back = $base . '&folder=' . rawurlencode((string) $dept['slug']);

    if ($action === 'add_member') {
        $uid = (int) post('user_id');
        if (!add_department_member((int) $dept['id'], $uid, $user)) {
            flash('error', 'Could not add member (team user required).');
        } else {
            flash('ok', 'Member added to ' . $dept['name'] . '.');
        }
        redirect($back . '#members');
    }

    if ($action === 'remove_member') {
        $uid = (int) post('user_id');
        remove_department_member((int) $dept['id'], $uid);
        flash('ok', 'Member removed.');
        redirect($back . '#members');
    }

    if ($action === 'save_task') {
        $taskId = (int) post('task_id');
        $assigned = (int) post('assigned_to');
        $result = save_department_task(
            (int) $dept['id'],
            (string) post('title'),
            (string) post('notes'),
            (string) post('status'),
            $assigned > 0 ? $assigned : null,
            (string) post('due_date'),
            $user,
            $taskId > 0 ? $taskId : null
        );
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not save task.'));
        } else {
            flash('ok', $taskId > 0 ? 'Task updated.' : 'Task assigned to ' . $dept['name'] . '.');
        }
        redirect($back);
    }

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

    if ($action === 'delete_task') {
        $taskId = (int) post('task_id');
        $task = get_department_task($taskId);
        if ($task && (int) $task['department_id'] === (int) $dept['id']) {
            delete_department_task($taskId);
            flash('ok', 'Task deleted.');
        }
        redirect($back);
    }
}

// --- Hub ---
if (!$dept) {
    $departments = list_departments(true);
    render_header('Departments', 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Departments'],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1>Departments</h1>
        <p class="muted">
          Office folders. Assign team members and work to each department —
          their login shows only their department tasks.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="folders">
        <?php foreach ($departments as $d):
            $stats = department_stats((int) $d['id']);
            ?>
          <a class="folder" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $d['slug']) ?>">
            <h3><?= h((string) $d['name']) ?></h3>
            <p class="muted">
              <?= (int) $stats['member_count'] ?> member<?= (int) $stats['member_count'] === 1 ? '' : 's' ?>
              · <?= (int) $stats['open_tasks'] ?> open task<?= (int) $stats['open_tasks'] === 1 ? '' : 's' ?>
              · <?= (int) $stats['total_tasks'] ?> total
            </p>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (!$departments): ?>
        <div class="empty-state">
          <p>No departments yet.</p>
          <p class="muted">Run upgrade.php once to create the office folders.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- Department detail ---
$deptId = (int) $dept['id'];
$members = list_department_members($deptId);
$memberIds = array_map(static fn ($m) => (int) $m['id'], $members);
$allTeam = list_team_users_for_departments();
$available = array_values(array_filter(
    $allTeam,
    static fn ($u) => !in_array((int) $u['id'], $memberIds, true)
));
$statusFilter = (string) get('status');
$tasks = list_department_tasks($deptId, $statusFilter);
$editTaskId = (int) get('edit_task');
$editTask = $editTaskId ? get_department_task($editTaskId) : null;
if ($editTask && (int) $editTask['department_id'] !== $deptId) {
    $editTask = null;
}
$stats = department_stats($deptId);

render_header((string) $dept['name'], 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Departments', 'href' => $base],
    ['label' => (string) $dept['name']],
]);
?>
<div class="topbar">
  <div>
    <h1><?= h((string) $dept['name']) ?></h1>
    <p class="muted">
      <?= (int) $stats['member_count'] ?> member<?= (int) $stats['member_count'] === 1 ? '' : 's' ?>
      · <?= (int) $stats['open_tasks'] ?> open
      · <?= (int) $stats['total_tasks'] ?> task<?= (int) $stats['total_tasks'] === 1 ? '' : 's' ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="<?= h($base) ?>">All departments</a>
  </div>
</div>

<div class="card" id="members">
  <h2>Members</h2>
  <p class="help"><?= h(department_tools_help((string) $dept['slug'])) ?></p>
  <?php if ($members): ?>
  <table>
    <thead>
      <tr><th>Name</th><th>Username</th><th>Email</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><?= h((string) ($m['full_name'] ?: '—')) ?></td>
        <td><?= h((string) $m['username']) ?></td>
        <td class="muted"><?= h((string) ($m['email'] ?: '—')) ?></td>
        <td>
          <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>#members"
                onsubmit="return confirm('Remove <?= h((string) $m['username']) ?> from <?= h((string) $dept['name']) ?>?');"
                class="inline-form">
            <input type="hidden" name="action" value="remove_member">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <button class="btn secondary small" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="muted">No members yet. Add a Team user below.</p>
  <?php endif; ?>

  <?php if ($available): ?>
  <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>#members"
        class="dept-add-member" style="margin-top:0.85rem">
    <input type="hidden" name="action" value="add_member">
    <label for="dept_add_user">Add team member</label>
    <div class="actions" style="align-items:flex-end;gap:0.5rem;flex-wrap:wrap">
      <select id="dept_add_user" name="user_id" required>
        <option value="">Select team user…</option>
        <?php foreach ($available as $u): ?>
          <option value="<?= (int) $u['id'] ?>">
            <?= h(trim(((string) $u['full_name']) !== '' ? (string) $u['full_name'] : (string) $u['username'])) ?>
            (<?= h((string) $u['username']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Add to department</button>
    </div>
  </form>
  <?php elseif (!$allTeam): ?>
  <p class="help" style="margin-top:0.75rem">
    Create Team users under <a href="index.php?page=admin_users">Users</a> first.
  </p>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:1rem">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0"><?= $editTask ? 'Edit task' : 'Assign work' ?></h2>
    <div class="actions">
      <a class="btn secondary small<?= $statusFilter === '' ? ' active-soft' : '' ?>" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>">All</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=open">Open</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=in_progress">In progress</a>
      <a class="btn secondary small" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;status=done">Done</a>
    </div>
  </div>

  <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>" class="dept-task-form">
    <input type="hidden" name="action" value="save_task">
    <input type="hidden" name="task_id" value="<?= $editTask ? (int) $editTask['id'] : 0 ?>">
    <div class="form-grid" style="grid-template-columns:1.4fr 1fr 1fr;gap:0.65rem">
      <div class="full" style="grid-column:1/-1">
        <label for="dept_task_title">Task title</label>
        <input id="dept_task_title" name="title" required maxlength="255"
               value="<?= h((string) ($editTask['title'] ?? '')) ?>"
               placeholder="What should this department do?">
      </div>
      <div class="full" style="grid-column:1/-1">
        <label for="dept_task_notes">Notes</label>
        <textarea id="dept_task_notes" name="notes" rows="3" placeholder="Details, links, country, counts…"><?= h((string) ($editTask['notes'] ?? '')) ?></textarea>
      </div>
      <div>
        <label for="dept_task_assignee">Assign to member</label>
        <select id="dept_task_assignee" name="assigned_to">
          <option value="0">Whole department</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= (int) $m['id'] ?>"
              <?= $editTask && (int) ($editTask['assigned_to'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
              <?= h(trim(((string) $m['full_name']) !== '' ? (string) $m['full_name'] : (string) $m['username'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="dept_task_status">Status</label>
        <select id="dept_task_status" name="status">
          <?php
          $curStatus = (string) ($editTask['status'] ?? 'open');
          foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'] as $val => $lab):
              ?>
            <option value="<?= h($val) ?>" <?= $curStatus === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="dept_task_due">Due date</label>
        <input id="dept_task_due" type="date" name="due_date"
               value="<?= h((string) ($editTask['due_date'] ?? '')) ?>">
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit"><?= $editTask ? 'Update task' : 'Assign task' ?></button>
      <?php if ($editTask): ?>
        <a class="btn secondary" href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>">Cancel edit</a>
      <?php endif; ?>
    </p>
  </form>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Tasks</h2>
  <?php if (!$tasks): ?>
  <div class="empty-state">
    <p>No tasks<?= $statusFilter !== '' ? ' with this status' : ' yet' ?>.</p>
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
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tasks as $t):
          $assignee = trim((string) ($t['assigned_name'] ?? '')) !== ''
              ? (string) $t['assigned_name']
              : (string) ($t['assigned_username'] ?? '');
          ?>
        <tr>
          <td>
            <strong><?= h((string) $t['title']) ?></strong>
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
          <td class="actions">
            <a href="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>&amp;edit_task=<?= (int) $t['id'] ?>">Edit</a>
            <form method="post" action="<?= h($base) ?>&amp;folder=<?= urlencode((string) $dept['slug']) ?>"
                  class="inline-form"
                  onsubmit="return confirm('Delete this task?');">
              <input type="hidden" name="action" value="delete_task">
              <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
              <button class="btn secondary small danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
