<?php
$user = require_admin();
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('our_database', $user);
}
$batches = list_prospect_batches(null, 150);
render_header('Add history', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Add history'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add history</h1>
    <p class="muted">Who added how many sites each day.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
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
  <div class="empty-state"><p>No adds yet.</p></div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
