<?php
$user = require_admin();
ensure_prospect_schema();

$filterUserId = (int) get('user');
if ($filterUserId < 1) {
    $filterUserId = 0;
}
$filterUser = null;
if ($filterUserId > 0) {
    $stmt = db()->prepare('SELECT id, username, full_name, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$filterUserId]);
    $filterUser = $stmt->fetch() ?: null;
    if (!$filterUser) {
        flash('error', 'User not found.');
        redirect('index.php?page=admin_prospect_batches');
    }
}

$batches = list_prospect_batches($filterUserId > 0 ? $filterUserId : null, 150);
$filterLabel = '';
if ($filterUser) {
    $filterLabel = trim((string) ($filterUser['full_name'] ?: $filterUser['username']));
}

render_header('Site adding history', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Site adding history'],
]); ?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">
      Who added how many sites each day.
      <?php if ($filterUser): ?>
        · Showing <strong><?= h($filterLabel) ?></strong>
      <?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <?php if ($filterUser): ?>
      <a class="btn secondary" href="index.php?page=admin_prospect_batches">All history</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
  </div>
</div>
<?= guide_add_history() ?>

<div class="card">
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Sites</th>
        <th>Country</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?></td>
        <td><a class="btn small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-state">
    <p><?= $filterUser ? 'No adds for this person yet.' : 'No adds yet.' ?></p>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
