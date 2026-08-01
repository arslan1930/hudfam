<?php
$user = require_team();
$id = (int) get('id');
$stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('index.php?page=team_projects');
}
if (!is_admin($user)) {
    $chk = db()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
    $chk->execute([$id, $user['id']]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        echo 'You are not assigned to this project.';
        exit;
    }
}
$tab = (string) get('tab', 'brief');
$sites = db()->prepare('SELECT * FROM sites WHERE primary_project_id=? ORDER BY updated_at DESC');
$sites->execute([$id]);
$sites = $sites->fetchAll();
$itemsStmt = db()->prepare(
    "SELECT pi.*, s.domain FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id JOIN sites s ON s.id=pi.site_id
     WHERE ph.project_id=? AND pi.item_status=? ORDER BY pi.updated_at DESC"
);
function team_items(PDOStatement $stmt, int $id, string $status): array
{
    $stmt->execute([$id, $status]);
    return $stmt->fetchAll();
}
$published = db()->prepare(
    'SELECT pp.*, s.domain FROM published_placements pp JOIN sites s ON s.id=pp.site_id WHERE pp.project_id=? ORDER BY pp.published_at DESC'
);
$published->execute([$id]);
$published = $published->fetchAll();

render_header($project['name'], 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($project['name']) ?></h1>
    <p class="muted">Brief + results only. Add sites in <a href="index.php?page=team_sites">Inventory</a> / <a href="index.php?page=team_countries">Countries</a> — not inside projects.</p>
  </div>
  <a class="btn" href="index.php?page=team_site_form">Add inventory site</a>
</div>
<div class="card">
  <div class="grid">
    <div><span class="muted">Niche</span><br><strong><?= h($project['niche'] ?: '—') ?></strong></div>
    <div><span class="muted">Countries</span><br><strong><?= h($project['countries'] ?: '—') ?></strong></div>
    <div><span class="muted">Budget</span><br><strong><?= h($project['budget'] ?: '—') ?></strong></div>
    <div><span class="muted">Price range</span><br><strong><?= money_or_dash($project['price_min']) ?> – <?= money_or_dash($project['price_max']) ?> <?= h($project['currency']) ?></strong></div>
    <div><span class="muted">Avoid</span><br><strong><?= h($project['avoid_notes'] ?: '—') ?></strong></div>
  </div>
</div>
<div class="tabs">
  <?php foreach (['brief','sites','sent','rejected','processing','completed','published'] as $t): ?>
    <a class="<?= $tab===$t?'active':'' ?>" href="index.php?page=team_project&id=<?= $id ?>&tab=<?= $t ?>"><?= ucfirst($t) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($tab === 'brief'): ?>
<div class="card">
  <h2>What Admin set</h2>
  <p><?= nl2br(h($project['requirements_brief'] ?: 'No brief.')) ?></p>
  <h3>Workflow</h3>
  <p><?= nl2br(h($project['workflow_notes'] ?: '—')) ?></p>
</div>
<?php elseif ($tab === 'sites'): ?>
<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>Metrics</th><th>Price</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&id=<?= (int)$s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td>DR <?= h((string)($s['dr'] ?? '—')) ?> / DA <?= h((string)($s['da'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr><td colspan="4" class="muted">No sites yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php elseif ($tab === 'published'): ?>
<div class="card">
  <?php foreach ($published as $row): ?>
    <div class="history-item"><strong><?= h($row['domain']) ?></strong><div><a href="<?= h($row['live_link']) ?>" target="_blank"><?= h($row['live_link']) ?></a></div></div>
  <?php endforeach; ?>
  <?php if (!$published): ?><p class="muted">No published records.</p><?php endif; ?>
</div>
<?php else:
  $rows = team_items($itemsStmt, $id, $tab);
?>
<div class="card">
  <?php foreach ($rows as $item): ?>
    <div class="history-item">
      <strong><?= h($item['domain']) ?></strong> <?= badge($item['item_status']) ?>
      <?php if ($item['reject_reason_code']): ?> · <?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
      <div class="muted"><?= h($item['reject_comment'] ?: $item['client_notes'] ?: $item['live_link']) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">Nothing here yet.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
