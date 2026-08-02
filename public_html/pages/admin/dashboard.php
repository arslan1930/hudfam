<?php
$user = require_admin();
$counts = [];
foreach (db()->query('SELECT status, COUNT(*) c FROM sites GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['c'];
}
$activeProjects = (int) db()->query("SELECT COUNT(*) FROM projects WHERE status='active'")->fetchColumn();
$agreed = (int) db()->query("SELECT COUNT(*) FROM sites WHERE status='agreed'")->fetchColumn();
$processing = (int) db()->query("SELECT COUNT(*) FROM sites WHERE status='processing'")->fetchColumn();
$prospectTotal = 0;
try {
    $prospectTotal = (int) db()->query('SELECT COUNT(*) FROM prospect_sites')->fetchColumn();
} catch (Throwable $e) {
    $prospectTotal = 0;
}
$projects = db()->query('SELECT * FROM projects ORDER BY name LIMIT 8')->fetchAll();
$rejects = db()->query(
    "SELECT pi.*, s.domain, p.name project_name
     FROM pitch_items pi
     JOIN sites s ON s.id = pi.site_id
     JOIN pitches ph ON ph.id = pi.pitch_id
     JOIN projects p ON p.id = ph.project_id
     WHERE pi.item_status='rejected'
     ORDER BY pi.updated_at DESC LIMIT 8"
)->fetchAll();

render_header('Dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Admin dashboard</h1>
    <p class="muted">Hello <?= h($user['full_name'] ?: $user['username']) ?> — run campaigns from project → catalog → pack.</p>
  </div>
</div>

<div class="card">
  <h2>Workflow</h2>
  <div class="workflow-strip">
    <a class="btn" href="index.php?page=admin_project_form">New project</a>
    <span class="arrow">→</span>
    <a class="btn secondary" href="index.php?page=admin_sites">Catalog</a>
    <a class="btn secondary" href="index.php?page=admin_bulk_import">Bulk import</a>
    <span class="arrow">→</span>
    <a class="btn secondary" href="index.php?page=admin_projects">Send pack</a>
    <span class="arrow">→</span>
    <a class="btn secondary" href="index.php?page=admin_clients">Clients</a>
    <a class="btn secondary" href="index.php?page=admin_orders_export">Orders</a>
  </div>
  <p class="help" style="margin-top:0.8rem">
    Team prospects (no prices):
    <a href="index.php?page=admin_prospects"><?= $prospectTotal ?> sites</a> ·
    <a href="index.php?page=admin_prospect_batches">Batches</a>
  </p>
</div>

<div class="grid">
  <div class="card stat"><span class="muted">Active projects</span><strong><?= $activeProjects ?></strong></div>
  <div class="card stat"><span class="muted">Agreed (catalog)</span><strong><?= $agreed ?></strong></div>
  <div class="card stat"><span class="muted">Processing</span><strong><?= $processing ?></strong></div>
</div>

<div class="card">
  <h2>Catalog by status</h2>
  <div class="actions" style="margin-top:0.7rem">
    <?php foreach ($counts as $status => $c): ?>
      <span class="badge <?= h($status) ?>"><?= h($status) ?> · <?= $c ?></span>
    <?php endforeach; ?>
    <?php if (!$counts): ?><p class="muted">No catalog sites yet.</p><?php endif; ?>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <h2>Projects</h2>
    <?php if ($projects): ?>
      <table>
        <thead><tr><th>Folder</th><th>Niche</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($projects as $p): ?>
          <tr>
            <td><a href="index.php?page=admin_project&id=<?= (int) $p['id'] ?>"><?= h($p['name']) ?></a></td>
            <td><?= h($p['niche'] ?: '—') ?></td>
            <td><?= badge($p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty-state">
        <p>No projects yet.</p>
        <a class="btn" href="index.php?page=admin_project_form">Create project</a>
      </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Recent rejects</h2>
    <?php if ($rejects): ?>
      <?php foreach ($rejects as $item): ?>
        <div class="history-item">
          <strong><?= h($item['domain']) ?></strong> · <?= h($item['project_name']) ?><br>
          <span class="muted"><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?> — <?= h($item['reject_comment']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state"><p>No rejects yet.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
