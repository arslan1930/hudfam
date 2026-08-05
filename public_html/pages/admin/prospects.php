<?php
require_admin();
$q = trim((string) get('q'));
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$region = (string) get('region');
$status = (string) get('status');
$createdBy = (int) get('created_by');
$pageNum = max(1, (int) get('p', 1));
$rows = [];
$total = 0;
$pages = 1;
$countryOptions = list_countries(null, true);
$langs = [];
$adders = [];

try {
    $inv = prospect_inventory_query(
        compact('q', 'country', 'language', 'region', 'status') + ['created_by' => $createdBy ?: null],
        $pageNum,
        50
    );
    $rows = $inv['rows'];
    $total = $inv['total'];
    $pages = $inv['pages'];
    $langs = distinct_prospect_languages();
    $adders = db()->query(
        "SELECT DISTINCT u.id, u.username, u.full_name
         FROM prospect_sites p
         JOIN users u ON u.id = p.created_by
         ORDER BY u.full_name, u.username"
    )->fetchAll();
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload.');
}

$qs = http_build_query(array_filter([
    'page' => 'admin_prospects', 'q' => $q, 'country' => $country,
    'language' => $language, 'region' => $region, 'status' => $status,
    'created_by' => $createdBy ?: '',
], fn($v) => $v !== '' && $v !== null));

render_header('Our database', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Our database'],
]); ?>
<div class="topbar">
  <div>
    <h1>Our database</h1>
    <p class="muted"><?= $total ?> unique domains · shared with Team</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_prospect_add">Add URLs</a>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
  </div>
</div>
<?= guide_inventory() ?>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
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
  <div><label>Region</label>
    <select name="region">
      <option value="">All</option>
      <?php foreach (regions() as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Added by</label>
    <select name="created_by">
      <option value="">Anyone</option>
      <?php foreach ($adders as $adder): ?>
        <option value="<?= (int) $adder['id'] ?>" <?= $createdBy === (int) $adder['id'] ? 'selected' : '' ?>>
          <?= h($adder['full_name'] ?: $adder['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>Country / lang</th><th>Status</th><th>Added by</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><?= h($s['domain']) ?></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
  <div class="empty-state"><p>No prospect sites yet — Team adds them via Filter & add.</p></div>
  <?php else: ?>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
