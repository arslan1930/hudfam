<?php
require_admin();
$q = trim((string) get('q'));
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$region = (string) get('region');
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$inv = prospect_inventory_query(compact('q', 'country', 'language', 'region', 'status'), $pageNum, 50);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryOptions = list_countries(null, true);
$langs = distinct_prospect_languages();
$qs = http_build_query(array_filter([
    'page' => 'admin_prospects', 'q' => $q, 'country' => $country,
    'language' => $language, 'region' => $region, 'status' => $status,
], fn($v) => $v !== '' && $v !== null));

render_header('Prospects', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Prospects</h1>
    <p class="muted"><?= $total ?> site<?= $total === 1 ? '' : 's' ?> · Team outreach list · no prices · read-only here</p>
  </div>
</div>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>"></div>
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
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Empty prospect list.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
