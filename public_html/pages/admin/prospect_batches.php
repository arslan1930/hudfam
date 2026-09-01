<?php
$user = require_admin();
ensure_prospect_schema();

$filterUserId = (int) get('user');
if ($filterUserId < 1) {
    $filterUserId = 0;
}
$filterUser = null;
if ($filterUserId > 0) {
    $stmt = db()->prepare('SELECT id, username, full_name, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$filterUserId]);
    $filterUser = $stmt->fetch() ?: null;
    if (!$filterUser) {
        flash('error', 'User not found.');
        redirect('index.php?page=admin_prospect_batches');
    }
}

$listUrl = static function (array $overrides = []) use ($filterUserId): string {
    $params = array_merge([
        'page' => 'admin_prospect_batches',
        'user' => $filterUserId > 0 ? (string) $filterUserId : '',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'repair_missing') {
    $n = sync_missing_prospect_batch_history(5000);
    flash(
        'ok',
        $n > 0
            ? ('Attached ' . $n . ' older add' . ($n === 1 ? '' : 's') . ' to history.')
            : 'No missing history days to repair.'
    );
    redirect($listUrl(['p' => (string) max(1, (int) get('p', 1))]));
}

$perPage = 50;
$pageNum = max(1, (int) get('p', 1));
$totalBatches = count_prospect_batches($filterUserId > 0 ? $filterUserId : null);
$totalPages = max(1, (int) ceil($totalBatches / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$batches = list_prospect_batches(
    $filterUserId > 0 ? $filterUserId : null,
    $perPage,
    '',
    ($pageNum - 1) * $perPage
);
$filterLabel = '';
if ($filterUser) {
    $filterLabel = trim((string) ($filterUser['full_name'] ?: $filterUser['username']));
}

$personTotals = [];
if ($filterUserId < 1) {
    foreach (prospect_add_history_by_user(null, '') as $row) {
        if ((int) ($row['batch_days'] ?? 0) > 0) {
            $personTotals[] = $row;
        }
    }
}

render_header('Site adding history', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Site adding history'],
]); ?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">
      Who added how many sites each day.
      <?php if ($filterUser): ?>
        · Showing <strong><?= h($filterLabel) ?></strong>
      <?php endif; ?>
      · <?= (int) $totalBatches ?> day<?= (int) $totalBatches === 1 ? '' : 's' ?>
    </p>
  </div>
  <div class="actions">
    <?php if ($filterUser): ?>
      <a class="btn secondary" href="index.php?page=admin_prospect_batches">All history</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
    <form method="post" action="<?= h($listUrl(['p' => (string) $pageNum])) ?>"
          <?= confirm_attrs(
              'Attach inventory rows that never got a history day? This does not change Our database.',
              ['title' => 'Repair missing days?', 'confirm_label' => 'Repair']
          ) ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="repair_missing">
      <button class="btn secondary" type="submit">Repair missing days</button>
    </form>
  </div>
</div>
<?= guide_add_history() ?>

<?php if ($personTotals): ?>
<div class="card">
  <h2 style="margin:0 0 0.65rem">By person</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Person</th>
          <th>Role</th>
          <th class="num">Days</th>
          <th class="num">Sites</th>
          <th>Last add</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($personTotals as $pt):
          $label = trim((string) ($pt['full_name'] ?: $pt['username']));
          ?>
        <tr>
          <td><?= h($label) ?></td>
          <td><?= h((string) ($pt['role'] ?? '—')) ?></td>
          <td class="num"><?= (int) $pt['batch_days'] ?></td>
          <td class="num"><?= (int) $pt['site_count'] ?></td>
          <td><?= h((string) ($pt['last_batch_date'] ?: '—')) ?></td>
          <td><a class="btn secondary small" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $pt['user_id'] ?>">Days</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card"<?= $personTotals ? ' style="margin-top:1rem"' : '' ?>>
  <?php if ($batches): ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Sites</th>
        <th>Country</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?></td>
        <td><a class="btn secondary small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <p class="muted" style="margin-top:0.85rem">
    Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?>
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
    <p><?= $filterUser ? 'No adds for this person yet.' : 'No adds yet.' ?></p>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
