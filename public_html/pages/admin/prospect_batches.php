<?php
require_admin();
$batches = list_prospect_batches(null, 150);
render_header('Added sites', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Added sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Added sites</h1>
    <p class="muted">Who added how many sites each day.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
</div>

<div class="card">
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Sites</th>
        <th>Country / lang</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?> · <?= h($b['language'] ?: '—') ?></td>
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
