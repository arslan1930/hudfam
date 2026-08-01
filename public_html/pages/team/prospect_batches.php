<?php
$user = require_team();
// Admins see all batches; team sees own (admins collaborating may want all — show all for admin, own for team)
$batches = is_admin($user) ? list_prospect_batches(null, 100) : list_prospect_batches((int) $user['id'], 100);

render_header('Dated batches', 'team');
?>
<div class="topbar">
  <div>
    <h1>Prospect batches by date</h1>
    <p class="muted">Each teammate’s daily adds — also stored in old inventory (Box 1).</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter & add sites</a>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Teammate</th>
        <th>Sites added</th>
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
    <?php if (!$batches): ?>
      <tr><td colspan="5" class="muted">No batches yet. Use Filter & add sites.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('team'); ?>
