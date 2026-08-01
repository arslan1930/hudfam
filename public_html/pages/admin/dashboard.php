<?php
$user = require_admin();
$counts = [];
foreach (db()->query('SELECT status, COUNT(*) c FROM sites GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['c'];
}
$activeProjects = (int) db()->query("SELECT COUNT(*) FROM projects WHERE status='active'")->fetchColumn();
$agreed = (int) db()->query("SELECT COUNT(*) FROM sites WHERE status='agreed'")->fetchColumn();
$processing = (int) db()->query("SELECT COUNT(*) FROM sites WHERE status='processing'")->fetchColumn();
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

render_header('Admin dashboard', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Admin dashboard</h1>
    <p class="muted">Hello <?= h($user['username']) ?> — manage projects, catalog, and client orders.</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_project_form">New project</a>
    <a class="btn secondary" href="index.php?page=admin_client_form">New client folder</a>
    <a class="btn secondary" href="index.php?page=admin_clients">Clients</a>
    <a class="btn secondary" href="index.php?page=admin_orders_export">Orders CSV</a>
  </div>
</div>

<?php
render_workflow([
    ['label' => 'Create project', 'href' => 'index.php?page=admin_project_form', 'hint' => 'Folder + requirements'],
    ['label' => 'Build catalog', 'href' => 'index.php?page=admin_sites', 'hint' => 'Import or add priced sites'],
    ['label' => 'Send pack', 'href' => 'index.php?page=admin_projects', 'hint' => 'Agreed sites to client'],
    ['label' => 'Track orders', 'href' => 'index.php?page=admin_orders_export', 'hint' => 'Publication CSV'],
]);
render_glossary('admin');
?>

<div class="grid">
  <div class="card stat"><span class="muted">Active projects</span><strong><?= $activeProjects ?></strong></div>
  <div class="card stat"><span class="muted">Agreed (catalog)</span><strong><?= $agreed ?></strong></div>
  <div class="card stat"><span class="muted">Processing</span><strong><?= $processing ?></strong></div>
</div>
<div class="card">
  <h2>Catalog sites by status</h2>
  <div class="actions" style="margin-top:0.7rem">
    <?php foreach ($counts as $status => $c): ?>
      <?= badge((string) $status) ?> <span class="muted" style="margin-right:0.6rem">· <?= $c ?></span>
    <?php endforeach; ?>
    <?php if (!$counts): ?><p class="muted">No catalog sites yet.</p><?php endif; ?>
  </div>
</div>
<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <h2>Projects</h2>
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
  </div>
  <div class="card">
    <h2>Recent rejects</h2>
    <?php foreach ($rejects as $item): ?>
      <div class="history-item">
        <strong><?= h($item['domain']) ?></strong> · <?= h($item['project_name']) ?><br>
        <span class="muted"><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?> — <?= h($item['reject_comment']) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$rejects): ?><p class="muted">No rejects yet.</p><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
