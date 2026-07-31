<?php
require_admin();
$q = trim((string) get('q'));
$sql = 'SELECT pp.*, s.domain, p.name project_name FROM published_placements pp
        JOIN sites s ON s.id=pp.site_id JOIN projects p ON p.id=pp.project_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE s.domain LIKE ? OR p.name LIKE ? OR pp.live_link LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY pp.published_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
render_header('Published', 'admin');
?>
<div class="topbar"><div><h1>Published placements</h1><p class="muted">Live links you already placed.</p></div></div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_published">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
  <button class="btn" type="submit">Search</button>
</form>
<div class="card">
  <table>
    <thead><tr><th>Site</th><th>Project</th><th>Live link</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['domain']) ?></td>
        <td><a href="index.php?page=admin_project&id=<?= (int)$row['project_id'] ?>"><?= h($row['project_name']) ?></a></td>
        <td><a href="<?= h($row['live_link']) ?>" target="_blank"><?= h($row['live_link']) ?></a></td>
        <td><?= h(substr($row['published_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">No published placements yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('admin'); ?>
