<?php
$user = require_team();
$status = (string) get('status');
if (is_admin($user)) {
    $projectIds = array_column(db()->query('SELECT id FROM projects')->fetchAll(), 'id') ?: [0];
} else {
    $stmt = db()->prepare('SELECT project_id FROM project_members WHERE user_id=?');
    $stmt->execute([$user['id']]);
    $projectIds = array_column($stmt->fetchAll(), 'project_id') ?: [0];
}
$in = implode(',', array_fill(0, count($projectIds), '?'));
$params = $projectIds;
$sql = "SELECT pi.*, s.domain, p.name project_name
        FROM pitch_items pi
        JOIN sites s ON s.id=pi.site_id
        JOIN pitches ph ON ph.id=pi.pitch_id
        JOIN projects p ON p.id=ph.project_id
        WHERE ph.project_id IN ($in)";
if ($status !== '') {
    $sql .= ' AND pi.item_status=?';
    $params[] = $status;
}
$sql .= ' ORDER BY pi.updated_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
render_header('Results', 'team');
?>
<div class="topbar">
  <div>
    <h1>Results</h1>
    <p class="muted">Read-only statuses from Admin. Use them to refill better sites.</p>
  </div>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_results">
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (['rejected','processing','completed','sent'] as $st): ?>
        <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= $st ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <table>
    <thead><tr><th>Site</th><th>Project</th><th>Status</th><th>Comment</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $item): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&id=<?= (int)$item['site_id'] ?>"><?= h($item['domain']) ?></a></td>
        <td><?= h($item['project_name']) ?></td>
        <td>
          <?= badge($item['item_status']) ?>
          <?php if ($item['reject_reason_code']): ?><br><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
        </td>
        <td><?= h($item['reject_comment'] ?: $item['client_notes'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">No results yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('team'); ?>
