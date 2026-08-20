<?php
$user = require_team();
// Team sees own batches; admins browsing Team panel see all.
$batches = [];
$schemaOk = true;
$schemaError = '';
try {
    $batches = is_admin($user) ? list_prospect_batches(null, 100) : list_prospect_batches((int) $user['id'], 100);
} catch (Throwable $e) {
    $schemaOk = false;
    $schemaError = $e->getMessage();
    flash('error', 'Prospects database tables are missing or broken. Ask Admin to open upgrade.php once, then reload.');
}

render_header('Site adding history', 'team');
?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">Sites you added, saved by day. (Our database is Admin-only.)</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>
<?= guide_add_history() ?>

<?php if (!$schemaOk): ?>
<ul class="messages"><li class="error">
  Could not load history<?= $schemaError !== '' ? ': ' . h($schemaError) : '.' ?>
</li></ul>
<?php endif; ?>

<div class="card">
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Teammate</th>
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
        <td><a class="btn small" href="index.php?page=team_prospect_batch&id=<?= (int) $b['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-state">
    <p>No batches yet.</p>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add sites</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
