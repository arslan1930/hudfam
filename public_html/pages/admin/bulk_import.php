<?php
$user = require_admin();
$projects = db()->query("SELECT id, name, client_name FROM projects WHERE status!='archived' ORDER BY name")->fetchAll();
$projectId = (int) get('project_id');
$result = null;

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=hudfam-inventory-template.csv');
    $out = fopen('php://output', 'wb');
    fputcsv($out, bulk_csv_headers());
    fputcsv($out, [
        'example-site.com', 'German', 'Germany', '35', '40', '12000',
        'pending', 'Demo comment', 'Rexbo',
        'europe', 'Finance', 'https://example-site.com', 'agreed',
        '140', '120', 'EUR',
        'outreach.de@gmail.com', 'Alex DE',
    ]);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = (int) post('project_id');
    require_project_access($projectId, $user);
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        flash('error', 'Please upload a CSV file.');
        redirect('index.php?page=admin_bulk_import&project_id=' . $projectId);
    }
    $name = (string) ($_FILES['csv']['name'] ?? '');
    if (!preg_match('/\.csv$/i', $name) && ($_FILES['csv']['type'] ?? '') !== 'text/csv') {
        // still allow if tmp exists — some browsers send application/octet-stream
    }
    $result = bulk_import_sites_csv($projectId, $_FILES['csv']['tmp_name'], (int) $user['id']);
    flash(
        'ok',
        "Import done: {$result['inserted']} inserted, {$result['updated']} updated, {$result['skipped']} skipped."
    );
}

render_header('Bulk import', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Bulk import</h1>
    <p class="muted">CSV into a project catalog · duplicates in the same project are updated.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_bulk_import&template=1">CSV template</a>
    <a class="btn secondary" href="index.php?page=admin_sites">Catalog</a>
  </div>
</div>

<div class="card">
  <h2>CSV columns</h2>
  <p class="help">
    Required: <code>domain</code>.  
    Recommended: <code>language</code>, <code>country</code>, <code>da</code>, <code>dr</code>, <code>traffic</code>,
    <code>order_status</code>, <code>admin_comments</code> (or <code>comments</code>), <code>client_name</code>.  
    Optional: region, niche, url, status, prices, our_mailbox, our_contact_name.
  </p>
  <p class="help">
    <code>order_status</code>: pending, processing, completed, on_hold, cancelled.  
    Team Super search will see only site metrics — not client name, comments, or project details.
  </p>
</div>

<div class="card">
<form method="post" enctype="multipart/form-data">
  <div class="form-grid">
    <div>
      <label>Target project</label>
      <select name="project_id" required>
        <option value="">— choose project —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $projectId === (int) $p['id'] ? 'selected' : '' ?>>
            <?= h($p['name']) ?><?= $p['client_name'] ? ' · ' . h($p['client_name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
  </div>
  <p class="help" style="margin-top:0.7rem">Large files: keep under your host’s upload limit (Hostinger often 64–128MB). Split into multiple CSVs if needed.</p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Import CSV</button>
  </p>
</form>
</div>

<?php if ($result): ?>
<div class="card">
  <h2>Last import summary</h2>
  <p>
    Inserted: <strong><?= (int) $result['inserted'] ?></strong> ·
    Updated: <strong><?= (int) $result['updated'] ?></strong> ·
    Skipped: <strong><?= (int) $result['skipped'] ?></strong>
  </p>
  <?php if ($result['errors']): ?>
    <h3>Notes / errors (first <?= count($result['errors']) ?>)</h3>
    <ul class="help">
      <?php foreach ($result['errors'] as $err): ?>
        <li><?= h($err) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($projectId): ?>
    <p class="actions"><a class="btn" href="index.php?page=admin_project&id=<?= $projectId ?>&tab=inventory">Open project inventory</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
