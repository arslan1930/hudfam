<?php
/**
 * Admin: assign tasks to teammates.
 */
$user = require_admin();
ensure_tasks_schema();

$statusFilter = trim((string) get('status'));
$assigneeFilter = (int) get('user');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'create') {
        try {
            $created = create_team_task([
                'title' => post('title'),
                'notes' => post('notes'),
                'country' => post('country'),
                'language' => post('language'),
                'niche' => post('niche'),
                'target_count' => post('target_count'),
                'assigned_to' => post('assigned_to'),
                'due_date' => post('due_date'),
                'status' => 'open',
            ], (int) $user['id']);
            flash('ok', 'Task assigned.');
            redirect('index.php?page=admin_tasks&highlight=' . (int) $created['id']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('index.php?page=admin_tasks');
        }
    }
    if ($action === 'update') {
        $id = (int) post('id');
        try {
            update_team_task($id, [
                'title' => post('title'),
                'notes' => post('notes'),
                'country' => post('country'),
                'language' => post('language'),
                'niche' => post('niche'),
                'target_count' => post('target_count'),
                'assigned_to' => post('assigned_to'),
                'due_date' => post('due_date'),
                'status' => post('status'),
            ]);
            flash('ok', 'Task updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('index.php?page=admin_tasks' . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''));
    }
    if ($action === 'delete') {
        $id = (int) post('id');
        db()->prepare('DELETE FROM team_tasks WHERE id=?')->execute([$id]);
        flash('ok', 'Task removed.');
        redirect('index.php?page=admin_tasks');
    }
}

$teamUsers = list_team_users(true);
$frequent = user_frequent_countries((int) $user['id'], 8);
$tasks = list_team_tasks($assigneeFilter > 0 ? $assigneeFilter : null, $statusFilter);
$editId = (int) get('edit');
$edit = $editId ? get_team_task($editId) : null;
$highlight = (int) get('highlight');
$defaultAssignee = (int) ($edit['assigned_to'] ?? ($assigneeFilter ?: 0));

render_header('Tasks', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Users', 'href' => 'index.php?page=admin_users'],
    ['label' => 'Tasks'],
]); ?>
<div class="topbar">
  <div>
    <h1>Assign tasks</h1>
    <p class="muted">Assign work to teammates.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_users">Back to Users</a>
</div>

<div class="grid" style="grid-template-columns:1fr 1.1fr">
  <div class="card">
    <h2><?= $edit ? 'Edit task' : 'New task' ?></h2>
    <form method="post">
      <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <label>Title</label>
      <input name="title" required value="<?= h((string) ($edit['title'] ?? '')) ?>" placeholder="e.g. Add 200 Germany sites">
      <label>Assign to</label>
      <select name="assigned_to" required data-searchable="1">
        <option value="">— Teammate —</option>
        <?php foreach ($teamUsers as $t): ?>
          <option value="<?= (int) $t['id'] ?>" <?= $defaultAssignee === (int) $t['id'] ? 'selected' : '' ?>>
            <?= h($t['full_name'] ?: $t['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label>Country <span class="help">(type to search)</span></label>
      <?= render_country_select('country', (string) ($edit['country'] ?? ''), 'task_country', false, $frequent, '— Optional —') ?>
      <label>Language</label>
      <?= render_language_select('language', (string) ($edit['language'] ?? ''), 'task_language') ?>
      <label>Niche</label>
      <input name="niche" value="<?= h((string) ($edit['niche'] ?? '')) ?>" placeholder="optional">
      <div class="form-grid">
        <div>
          <label>Target sites</label>
          <input type="number" name="target_count" min="1" value="<?= h((string) ($edit['target_count'] ?? '')) ?>" placeholder="e.g. 200">
        </div>
        <div>
          <label>Due date</label>
          <input type="date" name="due_date" value="<?= h((string) ($edit['due_date'] ?? '')) ?>">
        </div>
      </div>
      <?php if ($edit): ?>
        <label>Status</label>
        <select name="status" data-searchable="1">
          <?php foreach (['open','in_progress','done','cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= ($edit['status'] ?? '') === $st ? 'selected' : '' ?>><?= h(task_status_label($st)) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label>Notes</label>
      <textarea name="notes" rows="3" placeholder="Instructions for the teammate…"><?= h((string) ($edit['notes'] ?? '')) ?></textarea>
      <p class="actions" style="margin-top:1rem">
        <button class="btn" type="submit"><?= $edit ? 'Save task' : 'Assign task' ?></button>
        <?php if ($edit): ?><a class="btn secondary" href="index.php?page=admin_tasks">Cancel</a><?php endif; ?>
      </p>
    </form>
  </div>

  <div class="card">
    <h2>All tasks</h2>
    <form class="filters" method="get" style="margin-bottom:1rem">
      <input type="hidden" name="page" value="admin_tasks">
      <div>
        <label>Status</label>
        <select name="status" data-searchable="1">
          <option value="">All</option>
          <?php foreach (['open','in_progress','done','cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h(task_status_label($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Teammate</label>
        <select name="user" data-searchable="1">
          <option value="">All</option>
          <?php foreach ($teamUsers as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $assigneeFilter === (int) $t['id'] ? 'selected' : '' ?>>
              <?= h($t['full_name'] ?: $t['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn secondary" type="submit">Filter</button>
    </form>

    <?php if (!$tasks): ?>
      <div class="empty-state"><p>No tasks yet — assign one on the left.</p></div>
    <?php else: ?>
      <table>
        <thead><tr><th>Task</th><th>Who</th><th>Country</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr<?= $highlight === (int) $t['id'] ? ' style="background:var(--brand-soft)"' : '' ?>>
            <td>
              <strong><?= h($t['title']) ?></strong>
              <?php if (!empty($t['target_count'])): ?>
                <div class="help">Target: <?= (int) $t['target_count'] ?> sites</div>
              <?php endif; ?>
              <?php if (!empty($t['due_date'])): ?>
                <div class="help">Due <?= h($t['due_date']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= h($t['assignee_name'] ?: $t['assignee_username']) ?></td>
            <td><?= h($t['country'] ?: '—') ?></td>
            <td><span class="badge"><?= h(task_status_label((string) $t['status'])) ?></span></td>
            <td class="actions">
              <a href="index.php?page=admin_tasks&amp;edit=<?= (int) $t['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this task?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn-link danger" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
