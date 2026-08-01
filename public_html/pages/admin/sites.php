<?php
require_admin();
$q = trim((string) get('q'));
$status = (string) get('status');
$region = (string) get('region');
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$niche = trim((string) get('niche'));
$pageNum = max(1, (int) get('p', 1));
$per = 50;
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(s.domain LIKE ? OR s.niche LIKE ? OR s.country LIKE ? OR s.warning_flags LIKE ? OR s.language LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 's.status=?';
    $params[] = $status;
}
if ($niche !== '') {
    $where[] = 's.niche LIKE ?';
    $params[] = '%' . $niche . '%';
}
apply_site_geo_filters($where, $params, compact('region', 'country', 'language'));
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
$countryOptions = list_countries(null, true);
$langs = distinct_site_languages();
$qsBase = [
    'page' => 'admin_sites', 'q' => $q, 'status' => $status, 'region' => $region,
    'country' => $country, 'language' => $language, 'niche' => $niche,
];

render_header('Inventory', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Inventory catalog</h1>
    <p class="muted"><?= $total ?> matching</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_site_form">Add site</a>
    <a class="btn secondary" href="index.php?page=admin_countries">Countries</a>
  </div>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_sites">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Region</label>
    <select name="region">
      <option value="">All</option>
      <?php foreach (regions() as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Country</label>
    <select name="country">
      <option value="">All</option>
      <?php foreach ($countryOptions as $c): ?>
        <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Language</label>
    <select name="language">
      <option value="">All</option>
      <?php foreach ($langs as $lang): ?>
        <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Niche</label><input name="niche" value="<?= h($niche) ?>"></div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Country / lang</th><th>Metrics</th>
        <th>Publisher quote</th><th>Agreed</th><th>Status</th><th>Owner</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['publisher_quote_price'] ?? null) ?><?php if (!empty($s['publisher_quote_date'])): ?><br><span class="muted"><?= h($s['publisher_quote_date']) ?></span><?php endif; ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['owner'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No sites found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php
    $qs = http_build_query(array_filter($qsBase, fn($v) => $v !== '' && $v !== null));
    ?>
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
