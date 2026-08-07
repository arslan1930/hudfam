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

render_header('Batch ' . $batch['batch_date'], 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($batch['full_name'] ?: $batch['username']) ?></h1>
    <p class="muted"><?= (int) $batch['site_count'] ?> site(s) · <?= h($batch['country'] ?: '—') ?></p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">My batches</a>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add</a>
  </div>
</div>
<div class="card">
  <?php if ($batch['notes']): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">These are the sites you added on this day.</p>
  <?php if ($domains): ?>
    <textarea class="inventory-box" rows="18" readonly><?= h(implode("\n", $domains)) ?></textarea>
  <?php else: ?>
    <div class="empty-state"><p>No domains in this batch.</p></div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
