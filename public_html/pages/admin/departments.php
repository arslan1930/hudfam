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
    $keepStatus = (string) get('status');
    if (in_array($keepStatus, ['open', 'in_progress', 'done', 'overdue'], true)) {
        $back .= '&status=' . rawurlencode($keepStatus);
    }
    $keepAssignee = (string) get('assignee');
    if (in_array($keepAssignee, ['mine', 'unassigned', 'assigned'], true)) {
        $back .= '&assignee=' . rawurlencode($keepAssignee);
    }
    $keepQ = trim((string) get('q'));
    if ($keepQ !== '') {
        $back .= '&q=' . rawurlencode($keepQ);
    }
    $keepP = max(1, (int) get('p', 1));
    if ($keepP > 1) {
        $back .= '&p=' . $keepP;
    }

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
        $openAssigned = count_open_department_tasks_for_assignee((int) $dept['id'], $uid);
        $cleared = clear_open_department_task_assignees((int) $dept['id'], $uid);
        remove_department_member((int) $dept['id'], $uid);
        $msg = 'Member removed.';
        if ($cleared > 0) {
            $msg .= ' Cleared assignee on ' . $cleared . ' open task' . ($cleared === 1 ? '' : 's') . '.';
        } elseif ($openAssigned > 0) {
            $msg .= ' Open tasks were already unassigned.';
        }
        flash('ok', $msg);
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
            $who = 'whole department';
            if ($assigned > 0) {
                $uSt = db()->prepare('SELECT full_name, username FROM users WHERE id=? LIMIT 1');
                $uSt->execute([$assigned]);
                $uRow = $uSt->fetch(PDO::FETCH_ASSOC) ?: [];
                $label = trim((string) ($uRow['full_name'] ?? ''));
                if ($label === '') {
                    $label = trim((string) ($uRow['username'] ?? 'teammate'));
                }
                $who = $label;
            }
            $msg = $taskId > 0
                ? ('Task updated · assigned to ' . $who . '.')
                : ('Task assigned to ' . $who . ' in ' . $dept['name'] . '.');
            if (!empty($result['added_member'])) {
                $msg .= ' Added ' . $who . ' to this department (tools unlocked).';
            }
            flash('ok', $msg);
        }
        redirect($back);
    }

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
    department_stats_map(array_map(static fn ($d) => (int) $d['id'], $departments));
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
          membership unlocks that department's tools, and their login shows only their department tasks.
        </p>
      </div>
    </div>

    <?php $hubDash = departments_dashboard_stats(); ?>
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
              <?php if ((int) ($stats['overdue_count'] ?? 0) > 0): ?>
                · <?= (int) $stats['overdue_count'] ?> overdue
              <?php endif; ?>
            </p>
            <?php folder_open_cue(); ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (!$departments): ?>
        <div class="empty-state">
          <p>No departments yet.</p>
          <p class="muted">Run upgrade.php once to create the office folders.</p>
        </div>
      <?php endif; ?>
      <?php $unassignedTeam = (int) ($hubDash['unassigned_team'] ?? 0); ?>
      <p class="help" style="margin-top:0.85rem">
        <?php if ($unassignedTeam > 0): ?>
          <?= $unassignedTeam ?> active team user<?= $unassignedTeam === 1 ? '' : 's' ?>
          not in any department.
          <a href="index.php?page=admin_users">Open Users</a> to assign them.
        <?php else: ?>
          Every active team user is in a department.
        <?php endif; ?>
      </p>
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
// Assignee list = current members first, then other team users (auto-added on assign).
$assigneeChoices = $members;
foreach ($allTeam as $u) {
    if (!in_array((int) $u['id'], $memberIds, true)) {
        $assigneeChoices[] = $u;
    }
}
$statusFilter = (string) get('status');
if (!in_array($statusFilter, ['', 'open', 'in_progress', 'done', 'overdue'], true)) {
    $statusFilter = '';
}
$assigneeFilter = (string) get('assignee');
if (!in_array($assigneeFilter, ['', 'all', 'mine', 'unassigned', 'assigned'], true)) {
    $assigneeFilter = '';
}
if ($assigneeFilter === 'all') {
    $assigneeFilter = '';
}
$taskQ = trim((string) get('q'));
$tasksAll = list_department_tasks($deptId, $statusFilter, (int) ($user['id'] ?? 0), $assigneeFilter, $taskQ);
$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$totalTasks = count($tasksAll);
$totalPages = max(1, (int) ceil($totalTasks / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$tasks = array_slice($tasksAll, ($pageNum - 1) * $perPage, $perPage);
$editTaskId = (int) get('edit_task');
$editTask = $editTaskId ? get_department_task($editTaskId) : null;
if ($editTask && (int) $editTask['department_id'] !== $deptId) {
    $editTask = null;
}
// Keep historical assignee visible in the edit dropdown (removed / inactive).
if ($editTask && (int) ($editTask['assigned_to'] ?? 0) > 0) {
    $editAssigneeId = (int) $editTask['assigned_to'];
    $choiceIds = array_map(static fn ($u) => (int) $u['id'], $assigneeChoices);
    if (!in_array($editAssigneeId, $choiceIds, true)) {
        $hist = db()->prepare(
            'SELECT id, username, full_name, email, is_active FROM users WHERE id=? LIMIT 1'
        );
        $hist->execute([$editAssigneeId]);
        $histRow = $hist->fetch(PDO::FETCH_ASSOC);
        if ($histRow) {
            $assigneeChoices[] = $histRow;
        }
    }
}
$stats = department_stats($deptId);

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
      <?php if ((int) ($stats['overdue_count'] ?? 0) > 0): ?>
        · <?= (int) $stats['overdue_count'] ?> overdue
      <?php endif; ?>
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
  <div class="table-wrap">
  <table>
    <thead>
      <tr><th>Name</th><th>Username</th><th>Email</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($members as $m):
        $openForMember = count_open_department_tasks_for_assignee($deptId, (int) $m['id']);
        $inactive = (int) ($m['is_active'] ?? 1) !== 1;
        $removeMsg = 'Remove ' . (string) $m['username'] . ' from ' . (string) $dept['name'] . '?';
        if ($openForMember > 0) {
            $removeMsg .= "\n\n" . $openForMember . ' open task(s) assigned to them will become unassigned (done tasks keep history).';
        }
        ?>
      <tr<?= $inactive ? ' class="muted"' : '' ?>>
        <td>
          <?= h((string) ($m['full_name'] ?: '—')) ?>
          <?php if ($inactive): ?><span class="badge">Inactive</span><?php endif; ?>
        </td>
        <td><?= h((string) $m['username']) ?></td>
        <td class="muted"><?= h((string) ($m['email'] ?: '—')) ?></td>
        <td>
          <form method="post" action="<?= h($deptFolderUrl()) ?>#members"
                onsubmit="return confirm(<?= h(json_encode($removeMsg, JSON_UNESCAPED_UNICODE)) ?>);"
                class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_member">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <button class="btn secondary small" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted">No members yet. Add a Team user below.</p>
  <?php endif; ?>

  <?php if ($available): ?>
  <form method="post" action="<?= h($deptFolderUrl()) ?>#members"
        class="dept-add-member" style="margin-top:0.85rem">
    <?= csrf_field() ?>
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
      <?php
      $statusLinks = [
          '' => 'All',
          'open' => 'Open',
          'in_progress' => 'In progress',
          'done' => 'Done',
          'overdue' => 'Overdue',
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
        'unassigned' => 'Unassigned',
        'assigned' => 'Assigned',
    ];
    foreach ($assigneeLinks as $val => $lab):
        $href = $deptFolderUrl(['assignee' => $val, 'p' => 1]);
        $active = $assigneeFilter === $val ? ' active-soft' : '';
        ?>
      <a class="btn secondary small<?= $active ?>" href="<?= h($href) ?>"><?= h($lab) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="index.php" class="actions" style="margin-bottom:0.85rem;flex-wrap:wrap;gap:0.45rem;align-items:center">
    <input type="hidden" name="page" value="admin_departments">
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

  <form method="post" action="<?= h($deptFolderUrl()) ?>" class="dept-task-form">
    <?= csrf_field() ?>
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
        <label for="dept_task_assignee">Assign to user</label>
        <select id="dept_task_assignee" name="assigned_to">
          <option value="0">Whole department</option>
          <?php foreach ($assigneeChoices as $m):
              $isMember = in_array((int) $m['id'], $memberIds, true);
              $inactive = (int) ($m['is_active'] ?? 1) !== 1;
              $name = trim(((string) ($m['full_name'] ?? '')) !== ''
                  ? (string) $m['full_name']
                  : (string) $m['username']);
              $suffix = '';
              if ($inactive) {
                  $suffix = ' (inactive)';
              } elseif (!$isMember) {
                  $suffix = ' (add to department)';
              }
              ?>
            <option value="<?= (int) $m['id'] ?>"
              <?= $editTask && (int) ($editTask['assigned_to'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
              <?= h($name) ?><?= h($suffix) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$allTeam): ?>
          <p class="help">Create Team users under Users first, then assign tasks here.</p>
        <?php else: ?>
          <p class="help">Pick a teammate — they are added to this department automatically if needed.</p>
        <?php endif; ?>
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
        <a class="btn secondary" href="<?= h($deptFolderUrl()) ?>">Cancel edit</a>
      <?php endif; ?>
    </p>
  </form>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Tasks</h2>
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
          echo 'No tasks yet.';
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
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tasks as $t):
          $assignee = trim((string) ($t['assigned_name'] ?? '')) !== ''
              ? (string) $t['assigned_name']
              : (string) ($t['assigned_username'] ?? '');
          $overdue = department_task_is_overdue($t);
          ?>
        <tr<?= $overdue ? ' class="dept-task-overdue"' : '' ?> data-due="<?= h((string) ($t['due_date'] ?? '')) ?>">
          <td>
            <strong><?= h((string) $t['title']) ?></strong>
            <?php if ($overdue): ?><span class="badge" data-overdue-badge>Overdue</span><?php endif; ?>
            <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
              <div class="help"><?= nl2br(h((string) $t['notes'])) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($assignee !== '' ? $assignee : 'Whole department') ?></td>
          <td>
            <form method="post" action="<?= h($deptFolderUrl()) ?>"
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
          </td>
          <td class="muted<?= $overdue ? ' dept-due-overdue' : '' ?>" data-due-cell><?= h((string) ($t['due_date'] ?: '—')) ?></td>
          <td>
            <form method="post" action="<?= h($deptFolderUrl()) ?>"
                  class="inline-form"
                  onsubmit="return confirm(<?= h(json_encode('Delete this task?', JSON_UNESCAPED_UNICODE)) ?>);">
              <?= csrf_field() ?>
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
    var tr = sel.closest('tr');
    var filter = '';
    try { filter = String(new URLSearchParams(window.location.search).get('status') || ''); } catch (err) {}
    if (!tr || !filter) return;
    var stillMatches = filter === 'overdue'
      ? tr.classList.contains('dept-task-overdue')
      : String(sel.value || '') === filter;
    if (stillMatches) return;
    var tbody = tr.parentElement;
    tr.remove();
    if (!tbody || tbody.querySelector('tr')) return;
    var wrap = tbody.closest('.table-wrap');
    var card = tbody.closest('.card');
    if (wrap) wrap.remove();
    if (card && !card.querySelector('.empty-state')) {
      var empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.innerHTML = '<p>No tasks with this status.</p>';
      card.appendChild(empty);
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
