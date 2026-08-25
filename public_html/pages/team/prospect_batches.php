<?php
$user = require_team();
ensure_prospect_schema();

$ownerId = is_admin($user) ? null : (int) $user['id'];
$perPage = 100;
$pageNum = max(1, (int) get('p', 1));
$totalBatches = 0;
try {
    $totalBatches = count_prospect_batches($ownerId);
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload Dated batches.');
}
$totalPages = max(1, (int) ceil(max(1, $totalBatches) / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}

$listUrl = static function (array $overrides = []): string {
    $params = array_merge([
        'page' => 'team_prospect_batches',
        'p' => '1',
    ], $overrides);
    $bits = [];
    foreach ($params as $k => $v) {
        $v = (string) $v;
        if ($v === '' || ($k === 'p' && $v === '1')) {
            continue;
        }
        $bits[] = rawurlencode((string) $k) . '=' . rawurlencode($v);
    }
    return 'index.php?' . implode('&', $bits);
};

$batches = [];
try {
    $batches = list_prospect_batches($ownerId, $perPage, '', ($pageNum - 1) * $perPage);
} catch (Throwable $e) {
    if ($totalBatches < 1) {
        flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload Dated batches.');
    }
}

render_header('Site adding history', 'team');
?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">
      Sites you added, saved by day.
      <?php if ($totalBatches > 0): ?>
        · <?= (int) $totalBatches ?> day<?= $totalBatches === 1 ? '' : 's' ?>
      <?php endif; ?>
    </p>
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
  <?php if ($totalBatches > 100): ?>
  <p class="muted" style="margin-top:0.85rem">
    Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
    · <?= (int) $totalBatches ?> day<?= $totalBatches === 1 ? '' : 's' ?>
    <?php if ($pageNum > 1): ?>
      · <a href="<?= h($listUrl(['p' => (string) ($pageNum - 1)])) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
      · <a href="<?= h($listUrl(['p' => (string) ($pageNum + 1)])) ?>">Next</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>
  <?php else: ?>
  <div class="empty-state">
    <p>No batches yet.</p>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add sites</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
