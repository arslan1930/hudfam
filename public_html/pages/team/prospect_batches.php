<?php
$user = require_team();
// Admins see all batches; team sees own (admins collaborating may want all — show all for admin, own for team)
$batches = [];
try {
    $batches = is_admin($user) ? list_prospect_batches(null, 100) : list_prospect_batches((int) $user['id'], 100);
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload Dated batches.');
}

render_header('Site adding history', 'team');
?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">Sites you added, saved by day.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>
<?= guide_add_history() ?>

<div class="card">
  <?php if ($batches): ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Teammate</th>
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
        <td><a class="btn small" href="index.php?page=team_prospect_batch&id=<?= (int) $b['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <p>No batches yet.</p>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add sites</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
