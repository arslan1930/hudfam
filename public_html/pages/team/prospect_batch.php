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
    <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
      <a class="btn" href="index.php?page=team_prospect_check">Filter & add</a>
    <?php endif; ?>
    <?php if ($domains): ?>
      <button class="btn secondary" type="button" id="batch-copy-all"
              data-copy-text="<?= h(implode("\n", $domains)) ?>">Copy all</button>
    <?php endif; ?>
  </div>
</div>
<div class="card">
  <?php if ($batch['notes']): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">These are the sites you added on this day<?= $batch['country'] ? (' for <strong>' . h((string) $batch['country']) . '</strong>') : '' ?>.</p>
  <?php if ($domains): ?>
    <textarea class="inventory-box" id="batch-domains-box" rows="18" readonly><?= h(implode("\n", $domains)) ?></textarea>
    <p class="help" id="batch-copy-status" hidden style="margin-top:0.45rem"></p>
  <?php else: ?>
    <div class="empty-state"><p>No domains in this batch.</p></div>
  <?php endif; ?>
</div>
<?php if ($domains): ?>
<script>
(function () {
  var btn = document.getElementById('batch-copy-all');
  var status = document.getElementById('batch-copy-status');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var text = btn.getAttribute('data-copy-text') || '';
    var done = function (ok) {
      if (!status) return;
      status.hidden = false;
      status.textContent = ok ? 'Copied ' + text.split(/\n/).filter(Boolean).length + ' site(s).' : 'Copy failed — select the box and copy manually.';
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () { done(false); });
      return;
    }
    var ta = document.getElementById('batch-domains-box');
    if (ta) {
      ta.focus();
      ta.select();
      try { done(document.execCommand('copy')); } catch (e) { done(false); }
    } else {
      done(false);
    }
  });
})();
</script>
<?php endif; ?>
<?php render_footer('team'); ?>
