<?php
/**
 * Team: view assigned tasks and update status.
 */
$user = require_team();
ensure_tasks_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post('id');
    $status = (string) post('status');
    $task = get_team_task($id);
    if (!$task || (int) $task['assigned_to'] !== (int) $user['id']) {
        flash('error', 'Task not found.');
        redirect('index.php?page=team_tasks');
    }
    if (!in_array($status, ['open', 'in_progress', 'done'], true)) {
        flash('error', 'Invalid status.');
        redirect('index.php?page=team_tasks');
    }
    try {
        update_team_task($id, [
            'title' => $task['title'],
            'notes' => $task['notes'],
            'country' => $task['country'],
            'language' => $task['language'],
            'niche' => $task['niche'],
            'target_count' => $task['target_count'],
            'assigned_to' => $task['assigned_to'],
            'due_date' => $task['due_date'],
            'status' => $status,
        ]);
        flash('ok', 'Task updated.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=team_tasks');
}

$showDone = get('done') === '1';
$open = list_team_tasks((int) $user['id'], '');
$active = array_values(array_filter($open, static fn($t) => in_array($t['status'], ['open', 'in_progress'], true)));
$done = array_values(array_filter($open, static fn($t) => in_array($t['status'], ['done', 'cancelled'], true)));
$list = $showDone ? $done : $active;

render_header('My tasks', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Tasks'],
]); ?>
<div class="topbar">
  <div>
    <h1>My tasks</h1>
    <p class="muted">Work assigned by Admin. Open Filter &amp; add for the country, then mark the task done.</p>
  </div>
  <div class="actions">
    <?php if ($showDone): ?>
      <a class="btn secondary" href="index.php?page=team_tasks">Open tasks (<?= count($active) ?>)</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=team_tasks&amp;done=1">Completed (<?= count($done) ?>)</a>
    <?php endif; ?>
  </div>
</div>

<?= render_page_purpose(
    'Tasks — what Admin asked you to do',
    'Each task is a job (often a country + target).',
    'Open the country in Filter & add, add unique sites, then mark In progress / Done.',
    []
) ?>

<?php if (!$list): ?>
  <div class="card empty-state">
    <p><?= $showDone ? 'No completed tasks yet.' : 'No open tasks — check back when Admin assigns work.' ?></p>
    <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
  </div>
<?php else: ?>
  <?php foreach ($list as $t): ?>
    <div class="card" style="margin-bottom:1rem">
      <div class="topbar" style="margin:0;padding:0;border:0">
        <div>
          <h2 style="margin:0"><?= h($t['title']) ?></h2>
          <p class="muted" style="margin:0.35rem 0 0">
            <span class="badge"><?= h(task_status_label((string) $t['status'])) ?></span>
            <?php if ($t['country'] !== ''): ?> · <?= h($t['country']) ?><?php endif; ?>
            <?php if (!empty($t['target_count'])): ?> · target <?= (int) $t['target_count'] ?> sites<?php endif; ?>
            <?php if (!empty($t['due_date'])): ?> · due <?= h($t['due_date']) ?><?php endif; ?>
          </p>
        </div>
        <div class="actions">
          <?php if ($t['country'] !== ''): ?>
            <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode((string) $t['country']) ?>">Filter &amp; add</a>
          <?php else: ?>
            <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
        <p style="margin:0.85rem 0 0"><?= nl2br(h((string) $t['notes'])) ?></p>
      <?php endif; ?>
      <?php if (trim((string) ($t['niche'] ?? '')) !== '' || trim((string) ($t['language'] ?? '')) !== ''): ?>
        <p class="help" style="margin:0.5rem 0 0">
          <?= $t['language'] !== '' ? 'Language: ' . h($t['language']) : '' ?>
          <?= $t['language'] !== '' && $t['niche'] !== '' ? ' · ' : '' ?>
          <?= $t['niche'] !== '' ? 'Niche: ' . h($t['niche']) : '' ?>
        </p>
      <?php endif; ?>
      <?php if (!$showDone): ?>
        <form method="post" class="actions" style="margin-top:1rem">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <label class="help" style="margin:0">Update status</label>
          <select name="status" data-searchable="1" style="max-width:200px">
            <option value="open" <?= $t['status'] === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="in_progress" <?= $t['status'] === 'in_progress' ? 'selected' : '' ?>>In progress</option>
            <option value="done" <?= $t['status'] === 'done' ? 'selected' : '' ?>>Done</option>
          </select>
          <button class="btn secondary" type="submit">Save</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer('team'); ?>
