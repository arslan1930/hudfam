<?php
require_admin();
$id = (int) get('id');
$stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('index.php?page=admin_projects');
}
$tab = (string) get('tab', 'brief');
$members = db()->prepare(
    'SELECT u.username FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=?'
);
$members->execute([$id]);
$members = $members->fetchAll();

$sites = db()->prepare(
    'SELECT s.*, u.username owner FROM sites s LEFT JOIN users u ON u.id=s.assigned_to WHERE s.primary_project_id=? ORDER BY s.updated_at DESC LIMIT 100'
);
$sites->execute([$id]);
$sites = $sites->fetchAll();

$itemsStmt = db()->prepare(
    "SELECT pi.*, s.domain FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id
     JOIN sites s ON s.id=pi.site_id
     WHERE ph.project_id=? AND pi.item_status=?
     ORDER BY pi.updated_at DESC"
);
function project_items(PDOStatement $stmt, int $id, string $status): array
{
    $stmt->execute([$id, $status]);
    return $stmt->fetchAll();
}
$sent = project_items($itemsStmt, $id, 'sent');
$rejected = project_items($itemsStmt, $id, 'rejected');
$processing = project_items($itemsStmt, $id, 'processing');
$completed = project_items($itemsStmt, $id, 'completed');
$published = db()->prepare(
    'SELECT pp.*, s.domain FROM published_placements pp JOIN sites s ON s.id=pp.site_id WHERE pp.project_id=? ORDER BY pp.published_at DESC'
);
$published->execute([$id]);
$published = $published->fetchAll();

render_header($project['name'], 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= h($project['name']) ?></h1>
    <p class="muted"><?= h($project['client_name'] ?: 'Client campaign') ?> · <?= h($project['niche'] ?: '—') ?> · <?= h($project['countries'] ?: '—') ?></p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_pitch_create&project_id=<?= $id ?>">Send pack</a>
    <a class="btn secondary" href="index.php?page=admin_project_form&id=<?= $id ?>">Edit requirements</a>
  </div>
</div>
<div class="card">
  <div class="grid">
    <div><span class="muted">Budget</span><br><strong><?= h($project['budget'] ?: '—') ?></strong></div>
    <div><span class="muted">Price range</span><br><strong><?= money_or_dash($project['price_min']) ?> – <?= money_or_dash($project['price_max']) ?> <?= h($project['currency']) ?></strong></div>
    <div><span class="muted">Min DR/DA/Traffic</span><br><strong><?= h((string)($project['min_dr'] ?? '—')) ?> / <?= h((string)($project['min_da'] ?? '—')) ?> / <?= h((string)($project['min_traffic'] ?? '—')) ?></strong></div>
    <div><span class="muted">Avoid</span><br><strong><?= h($project['avoid_notes'] ?: '—') ?></strong></div>
  </div>
</div>
<div class="tabs">
  <?php foreach (['brief','sites','sent','rejected','processing','completed','published'] as $t): ?>
    <a class="<?= $tab===$t?'active':'' ?>" href="index.php?page=admin_project&id=<?= $id ?>&tab=<?= $t ?>"><?= ucfirst($t) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($tab === 'brief'): ?>
<div class="card">
  <h2>Requirements brief</h2>
  <p><?= nl2br(h($project['requirements_brief'] ?: 'No brief yet.')) ?></p>
  <h3>Workflow</h3>
  <p><?= nl2br(h($project['workflow_notes'] ?: '—')) ?></p>
  <h3>Assigned team</h3>
  <p><?php foreach ($members as $m): ?><span class="badge"><?= h($m['username']) ?></span> <?php endforeach; ?><?php if (!$members): ?><span class="muted">None</span><?php endif; ?></p>
</div>
<?php elseif ($tab === 'sites'): ?>
<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>Metrics</th><th>Price</th><th>Status</th><th>Owner</th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int)$s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td>DR <?= h((string)($s['dr'] ?? '—')) ?> / DA <?= h((string)($s['da'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['owner'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr><td colspan="5" class="muted">No sites tagged yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php else:
  $map = ['sent'=>$sent,'rejected'=>$rejected,'processing'=>$processing,'completed'=>$completed];
  $rows = $tab === 'published' ? $published : ($map[$tab] ?? []);
?>
<div class="card">
  <table>
    <thead><tr><th>Site</th><th>Status / reason</th><th>Comment / link</th><th></th></tr></thead>
    <tbody>
    <?php if ($tab === 'published'): foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['domain']) ?></td>
        <td><?= badge('completed') ?></td>
        <td><a href="<?= h($row['live_link']) ?>" target="_blank"><?= h($row['live_link']) ?></a></td>
        <td><?= h(substr($row['published_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; else: foreach ($rows as $item): ?>
      <tr>
        <td><?= h($item['domain']) ?></td>
        <td>
          <?= badge($item['item_status']) ?>
          <?php if ($item['reject_reason_code']): ?><br><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
        </td>
        <td>
          <?php if ($item['live_link']): ?><a href="<?= h($item['live_link']) ?>" target="_blank"><?= h($item['live_link']) ?></a>
          <?php else: ?><?= h($item['reject_comment'] ?: $item['client_notes'] ?: '—') ?><?php endif; ?>
        </td>
        <td><a class="btn small" href="index.php?page=admin_pitch_item&id=<?= (int)$item['id'] ?>">Update</a></td>
      </tr>
    <?php endforeach; endif; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing here yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
