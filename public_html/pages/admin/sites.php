<?php
require_admin();
$q = trim((string) get('q'));
$status = (string) get('status');
$country = trim((string) get('country'));
$niche = trim((string) get('niche'));
$pageNum = max(1, (int) get('p', 1));
$per = 50;
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(s.domain LIKE ? OR s.niche LIKE ? OR s.country LIKE ? OR s.warning_flags LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 's.status=?';
    $params[] = $status;
}
if ($country !== '') {
    $where[] = 's.country LIKE ?';
    $params[] = '%' . $country . '%';
}
if ($niche !== '') {
    $where[] = 's.niche LIKE ?';
    $params[] = '%' . $niche . '%';
}
$whereSql = implode(' AND ', $where);
$countStmt = db()->prepare("SELECT COUNT(*) FROM sites s WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$offset = ($pageNum - 1) * $per;
$stmt = db()->prepare(
    "SELECT s.*, u.username owner FROM sites s LEFT JOIN users u ON u.id=s.assigned_to
     WHERE $whereSql ORDER BY s.updated_at DESC LIMIT $per OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pages = max(1, (int) ceil($total / $per));

render_header('Sites', 'admin');
?>
<div class="topbar">
  <div>
    <h1>All sites</h1>
    <p class="muted"><?= $total ?> matching</p>
  </div>
  <a class="btn" href="index.php?page=admin_site_form">Add site</a>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_sites">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code=>$label): ?>
        <option value="<?= h($code) ?>" <?= $status===$code?'selected':'' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Country</label><input name="country" value="<?= h($country) ?>"></div>
  <div><label>Niche</label><input name="niche" value="<?= h($niche) ?>"></div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>Geo / niche</th><th>DR/DA/Traffic</th><th>Price</th><th>Status</th><th>Owner</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int)$s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['niche'] ?: '—') ?></td>
        <td><?= h((string)($s['dr'] ?? '—')) ?> / <?= h((string)($s['da'] ?? '—')) ?> / <?= h((string)($s['traffic'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['owner'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No sites found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="index.php?page=admin_sites&p=<?= $pageNum-1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="index.php?page=admin_sites&p=<?= $pageNum+1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
