<?php
require_admin();
require_once __DIR__ . '/../../includes/orders.php';

$filters = [
    'q' => trim((string) get('q')),
    'project_id' => (int) get('project_id'),
    'client_id' => (int) get('client_id'),
    'status' => (string) get('status'),
];

if (get('download') === '1') {
    $rows = fetch_orders_query($filters);
    $name = 'hudfam_orders_' . date('Y-m-d') . '.csv';
    if ($filters['client_id']) {
        $name = 'hudfam_client_' . $filters['client_id'] . '_orders.csv';
    } elseif ($filters['project_id']) {
        $name = 'hudfam_project_' . $filters['project_id'] . '_orders.csv';
    }
    stream_orders_csv($rows, $name);
}

$rows = fetch_orders_query($filters);
$projects = db()->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
$clients = db()->query('SELECT id, name, email FROM clients ORDER BY name')->fetchAll();

render_header('Orders export', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Publication orders spreadsheet</h1>
    <p class="muted">Filter records, then download CSV for Excel / Google Sheets.</p>
  </div>
  <a class="btn" href="index.php?page=admin_orders_export&download=1&q=<?= urlencode($filters['q']) ?>&project_id=<?= (int)$filters['project_id'] ?>&client_id=<?= (int)$filters['client_id'] ?>&status=<?= urlencode($filters['status']) ?>">Download CSV</a>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_orders_export">
  <div><label>Search</label><input name="q" value="<?= h($filters['q']) ?>"></div>
  <div>
    <label>Project</label>
    <select name="project_id">
      <option value="">All</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $filters['project_id']===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Client</label>
    <select name="client_id">
      <option value="">All</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filters['client_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?> &lt;<?= h($c['email']) ?>&gt;</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      <option value="processing" <?= $filters['status']==='processing'?'selected':'' ?>>processing</option>
      <option value="completed" <?= $filters['status']==='completed'?'selected':'' ?>>completed</option>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <p class="muted"><?= count($rows) ?> record(s)</p>
  <table>
    <thead>
      <tr>
        <th>Project</th><th>Client</th><th>Site</th><th>Date sent</th><th>Price</th><th>Live URL</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $o): ?>
      <tr>
        <td><?= h($o['project_name']) ?></td>
        <td><?= h($o['client_name']) ?><br><span class="muted"><?= h($o['client_email']) ?></span></td>
        <td><?= h($o['site_domain']) ?></td>
        <td><?= h($o['sent_for_publication_at'] ?: '—') ?></td>
        <td><?= money_or_dash($o['client_price']) ?> <?= h($o['currency']) ?></td>
        <td><?= $o['live_url'] ? '<a href="'.h($o['live_url']).'" target="_blank">Live</a>' : '<span class="muted">—</span>' ?></td>
        <td><?= badge($o['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No orders match.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('admin'); ?>
