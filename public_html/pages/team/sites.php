<?php
$user = require_team();
$q = trim((string) get('q'));
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$per = 50;
$where = ['(s.assigned_to = ? OR s.status = ?)'];
$params = [$user['id'], 'agreed'];
if (is_admin($user)) {
    $where = ['1=1'];
    $params = [];
}
if ($q !== '') {
    $where[] = '(s.domain LIKE ? OR s.niche LIKE ? OR s.country LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 's.status=?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$count = db()->prepare("SELECT COUNT(*) FROM sites s WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$offset = ($pageNum - 1) * $per;
$stmt = db()->prepare("SELECT s.* FROM sites s WHERE $whereSql ORDER BY s.updated_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pages = max(1, (int) ceil($total / $per));
render_header('Sites', 'team');
?>
<div class="topbar">
  <div><h1>Sites</h1><p class="muted">Your sites + agreed source pool · <?= $total ?></p></div>
  <a class="btn" href="index.php?page=team_site_form">Add site</a>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_sites">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code=>$label): ?>
        <option value="<?= h($code) ?>" <?= $status===$code?'selected':'' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>Geo / niche</th><th>Metrics</th><th>Price</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&id=<?= (int)$s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['niche'] ?: '—') ?></td>
        <td><?= h((string)($s['dr'] ?? '—')) ?> / <?= h((string)($s['da'] ?? '—')) ?> / <?= h((string)($s['traffic'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No sites found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="index.php?page=team_sites&p=<?= $pageNum-1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="index.php?page=team_sites&p=<?= $pageNum+1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('team'); ?>
