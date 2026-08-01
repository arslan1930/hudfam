<?php
$user = require_team();
$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Batch not found.');
    redirect('index.php?page=team_prospect_batches');
}
if (!is_admin($user) && (int) $batch['user_id'] !== (int) $user['id']) {
    http_response_code(403);
    echo 'You can only view your own batches.';
    exit;
}
$domains = get_prospect_batch_domains($id);

render_header('Batch ' . $batch['batch_date'], 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($batch['full_name'] ?: $batch['username']) ?></h1>
    <p class="muted"><?= (int) $batch['site_count'] ?> site(s) · <?= h($batch['country'] ?: '—') ?> · <?= h($batch['language'] ?: '—') ?></p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">All batches</a>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add more</a>
  </div>
</div>
<div class="card">
  <?php if ($batch['notes']): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">These domains are also in Box 1 (old prospect inventory).</p>
  <textarea class="inventory-box" rows="18" readonly><?= h(implode("\n", $domains)) ?></textarea>
</div>
<?php render_footer('team'); ?>
