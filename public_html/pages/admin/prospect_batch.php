<?php
$user = require_admin();
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('our_database', $user);
}
$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Add history day not found.');
    redirect('index.php?page=admin_prospect_batches');
}
$items = get_prospect_batch_items($id);
$domains = array_column($items, 'domain');
$person = $batch['full_name'] ?: $batch['username'];

render_header('Add history · ' . $batch['batch_date'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Add history', 'href' => 'index.php?page=admin_prospect_batches'],
    ['label' => $batch['batch_date'] . ' · ' . $person],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($person) ?></h1>
    <p class="muted">
      <?= (int) $batch['site_count'] ?> site(s) added
      · <?= h($batch['country'] ?: '—') ?>
      · role <?= h($batch['role'] ?? '—') ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $batch['user_id'] ?>">This teammate</a>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">All history</a>
    <a class="btn secondary" href="index.php?page=admin_prospects&amp;created_by=<?= (int) $batch['user_id'] ?>">Inventory by person</a>
  </div>
</div>

<div class="card">
  <?php if (!empty($batch['notes'])): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">Saved in Our database and in this teammate’s daily add history.</p>
  <?php if ($domains): ?>
    <textarea class="inventory-box" rows="16" readonly><?= h(implode("\n", $domains)) ?></textarea>
    <details style="margin-top:1rem">
      <summary>Added timestamps</summary>
      <table style="margin-top:0.75rem">
        <thead><tr><th>Domain</th><th>Added at</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><?= h($item['domain']) ?></td>
            <td><?= h((string) $item['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  <?php else: ?>
    <p class="muted">No domains recorded for this day.</p>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
