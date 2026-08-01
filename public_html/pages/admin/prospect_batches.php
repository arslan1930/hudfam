<?php
require_admin();
$batches = list_prospect_batches(null, 150);
render_header('Prospect batches', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Team prospect batches by date</h1>
    <p class="muted">Who added how many sites each day (also in old prospect inventory).</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Prospect list</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>Date</th><th>Teammate</th><th>Count</th><th>Country / lang</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?> · <?= h($b['language'] ?: '—') ?></td>
        <td><a href="index.php?page=team_prospect_batch&id=<?= (int) $b['id'] ?>">View domains</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="5" class="muted">No batches yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('admin'); ?>
