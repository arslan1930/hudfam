<?php
$user = require_team();
$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Batch not found.');
    redirect('index.php?page=team_prospect_batches');
}
if (!is_admin($user) && (int) $batch['user_id'] !== (int) $user['id']) {
    flash('error', 'You can only view your own site adding history.');
    redirect('index.php?page=team_prospect_batches');
}
$domains = get_prospect_batch_domains($id);
$isAdmin = is_admin($user);

render_header('Site adding history · ' . $batch['batch_date'], 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($batch['full_name'] ?: $batch['username']) ?></h1>
    <p class="muted"><?= (int) $batch['site_count'] ?> site(s) · <?= h($batch['country'] ?: '—') ?> · <?= h($batch['language'] ?: '—') ?></p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">My history</a>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add</a>
    <?php if ($isAdmin): ?>
      <a class="btn" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $id ?>">Edit / delete (Admin)</a>
    <?php endif; ?>
  </div>
</div>
<div class="card">
  <?php if ($batch['notes']): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">
    Read-only list of sites added on this day.
    <?php if ($isAdmin): ?>
      Use <strong>Edit / delete (Admin)</strong> to change the list or remove the day.
    <?php endif; ?>
  </p>
  <?php if ($domains): ?>
    <textarea class="inventory-box" rows="18" readonly><?= h(implode("\n", $domains)) ?></textarea>
  <?php else: ?>
    <div class="empty-state"><p>No domains in this batch.</p></div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
