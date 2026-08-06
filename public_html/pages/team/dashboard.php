<?php
$user = require_team();
$uid = (int) $user['id'];

$todayBatch = null;
try {
    $todayBatch = get_today_prospect_batch($uid);
} catch (Throwable $e) {
    $todayBatch = null;
}

$openTasks = [];
try {
    ensure_tasks_schema();
    $all = list_team_tasks($uid, '');
    $openTasks = array_values(array_filter(
        $all,
        static fn($t) => in_array($t['status'], ['open', 'in_progress'], true)
    ));
} catch (Throwable $e) {
    $openTasks = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'task_status') {
    $id = (int) post('id');
    $status = (string) post('status');
    $task = get_team_task($id);
    if (!$task || (int) $task['assigned_to'] !== $uid) {
        flash('error', 'Task not found.');
        redirect('index.php?page=team_dashboard');
    }
    if (!in_array($status, ['open', 'in_progress', 'done'], true)) {
        flash('error', 'Invalid status.');
        redirect('index.php?page=team_dashboard');
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
    redirect('index.php?page=team_dashboard');
}

render_header('Dashboard', 'team');
$frequent = user_frequent_countries($uid, 6);
$topCountry = $frequent[0]['name'] ?? '';
if ($topCountry === '' && $openTasks !== [] && trim((string) ($openTasks[0]['country'] ?? '')) !== '') {
    $topCountry = (string) $openTasks[0]['country'];
}
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?>.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">Filter &amp; add<?= $topCountry !== '' ? ' · ' . h($topCountry) : '' ?></a>
</div>

<section class="card" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">Your tasks</h2>
  <?php if (!$openTasks): ?>
    <p class="muted" style="margin:0">No open tasks from Admin.</p>
  <?php else: ?>
    <?php foreach ($openTasks as $t): ?>
      <div style="border-top:1px solid var(--line);padding:0.85rem 0;margin-top:0.5rem">
        <div class="topbar" style="margin:0;padding:0;border:0">
          <div>
            <strong><?= h($t['title']) ?></strong>
            <p class="muted" style="margin:0.3rem 0 0">
              <span class="badge"><?= h(task_status_label((string) $t['status'])) ?></span>
              <?php if ($t['country'] !== ''): ?> · <?= h($t['country']) ?><?php endif; ?>
              <?php if (!empty($t['target_count'])): ?> · target <?= (int) $t['target_count'] ?><?php endif; ?>
              <?php if (!empty($t['due_date'])): ?> · due <?= h($t['due_date']) ?><?php endif; ?>
            </p>
            <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
              <p style="margin:0.45rem 0 0"><?= nl2br(h((string) $t['notes'])) ?></p>
            <?php endif; ?>
          </div>
          <div class="actions">
            <?php if ($t['country'] !== ''): ?>
              <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode((string) $t['country']) ?>">Filter &amp; add</a>
            <?php else: ?>
              <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" class="actions" style="margin-top:0.65rem">
          <input type="hidden" name="action" value="task_status">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <select name="status" data-searchable="1" style="max-width:200px">
            <option value="open" <?= $t['status'] === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="in_progress" <?= $t['status'] === 'in_progress' ? 'selected' : '' ?>>In progress</option>
            <option value="done" <?= $t['status'] === 'done' ? 'selected' : '' ?>>Done</option>
          </select>
          <button class="btn secondary" type="submit">Save</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?= render_frequent_country_chips($frequent, 'index.php?page=team_prospect_check&country=') ?>

<div class="launch-cards">
  <a class="launch-card" href="index.php?page=team_prospect_check<?= $topCountry !== '' ? '&country=' . urlencode($topCountry) : '' ?>">
    <h2>Filter &amp; add</h2>
    <p><?= $topCountry !== '' ? 'Continue with ' . h($topCountry) . '.' : 'Paste → filter → add unique.' ?></p>
  </a>
  <a class="launch-card" href="<?= $todayBatch ? 'index.php?page=team_prospect_batch&id=' . (int) $todayBatch['id'] : 'index.php?page=team_prospect_batches' ?>">
    <h2>Added sites</h2>
    <p><?= $todayBatch ? (int) $todayBatch['site_count'] . ' today' : 'None today' ?></p>
  </a>
</div>
<?php render_footer('team'); ?>
