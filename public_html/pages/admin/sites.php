<?php
require_admin();
$q = trim((string) get('q'));
$status = (string) get('status');
$orderStatus = (string) get('order_status');
$region = (string) get('region');
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$projectId = (int) get('project_id');
$pageNum = max(1, (int) get('p', 1));

$inventory = admin_inventory_query([
    'q' => $q,
    'status' => $status,
    'order_status' => $orderStatus,
    'region' => $region,
    'country' => $country,
    'language' => $language,
    'project_id' => $projectId ?: '',
], $pageNum, 50);

$rows = $inventory['rows'];
$total = $inventory['total'];
$pages = $inventory['pages'];
$projects = db()->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
$countryOptions = list_countries(null, true);
$langs = distinct_site_languages();
$qs = http_build_query(array_filter([
    'page' => 'admin_sites', 'q' => $q, 'status' => $status, 'order_status' => $orderStatus,
    'region' => $region, 'country' => $country, 'language' => $language,
    'project_id' => $projectId ?: '',
], fn($v) => $v !== '' && $v !== null));

render_header('Inventory', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Admin inventory</h1>
    <p class="muted"><?= $total ?> sites · language, country, DA/DR/traffic, order status, comments, client name</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_bulk_import">Bulk import CSV</a>
    <?php if ($projectId): ?>
      <a class="btn secondary" href="index.php?page=admin_site_form&project_id=<?= $projectId ?>">Add site</a>
    <?php endif; ?>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_sites">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain, client, comments…"></div>
  <div><label>Project</label>
    <select name="project_id">
      <option value="">All</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $projectId === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Site status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Order status</label>
    <select name="order_status">
      <?php foreach (inventory_order_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $orderStatus === $code ? 'selected' : '' ?>><?= h($label) ?></option>
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
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Project</th><th>Client name</th>
        <th>Country / lang</th><th>DR / DA / Traffic</th>
        <th>Order status</th><th>Comments</th><th>Site status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><a href="index.php?page=admin_project&id=<?= (int) $s['primary_project_id'] ?>&tab=inventory"><?= h($s['project_name']) ?></a></td>
        <td><?= h($s['inventory_client_name'] ?: '—') ?></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td><?= h(inventory_order_statuses()[$s['order_status']] ?? ($s['order_status'] ?: '—')) ?></td>
        <td class="help"><?php $c = (string) ($s['admin_comments'] ?? ''); echo h(strlen($c) > 80 ? substr($c, 0, 77) . '…' : $c); ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No sites. Use Bulk import or open a project to add sites.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
