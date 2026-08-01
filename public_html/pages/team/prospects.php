<?php
$user = require_team();
$q = trim((string) get('q'));
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$region = (string) get('region');
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update_status') {
    $id = (int) post('id');
    $st = (string) post('status');
    if (in_array($st, ['new', 'contacting', 'replied', 'skipped'], true)) {
        db()->prepare('UPDATE prospect_sites SET status=? WHERE id=?')->execute([$st, $id]);
        flash('ok', 'Prospect status updated.');
    }
    redirect('index.php?page=team_prospects&' . http_build_query(array_filter([
        'q' => $q, 'country' => $country, 'language' => $language, 'region' => $region, 'status' => $status, 'p' => $pageNum,
    ])));
}

$inv = prospect_inventory_query(compact('q', 'country', 'language', 'region', 'status'), $pageNum, 50);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryOptions = list_countries(null, true);
$langs = distinct_prospect_languages();
$countriesUsed = distinct_prospect_countries();
$qs = http_build_query(array_filter([
    'page' => 'team_prospects', 'q' => $q, 'country' => $country,
    'language' => $language, 'region' => $region, 'status' => $status,
], fn($v) => $v !== '' && $v !== null));

render_header('Prospects', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Prospects'],
]); ?>
<div class="topbar">
  <div>
    <h1>Prospects</h1>
    <p class="muted"><?= $total ?> sites to contact · no prices · filter uniques before adding</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
    <a class="btn secondary" href="index.php?page=team_prospect_form">Add one site</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_prospects">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <div><label>Country</label>
    <select name="country">
      <option value="">All</option>
      <?php foreach ($countryOptions as $c): ?>
        <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
      <?php foreach ($countriesUsed as $cu): if (!in_array($cu, array_column($countryOptions, 'name'), true)): ?>
        <option value="<?= h($cu) ?>" <?= $country === $cu ? 'selected' : '' ?>><?= h($cu) ?></option>
      <?php endif; endforeach; ?>
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
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Country</th><th>Language</th><th>Status</th>
        <th>Added by</th><th>When</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=team_prospect_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td class="help"><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
        <td>
          <form method="post" class="actions">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <select name="status" onchange="this.form.submit()">
              <?php foreach (prospect_statuses() as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $s['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No prospects yet. <a href="index.php?page=team_prospect_check">Filter &amp; add</a></td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('team'); ?>
