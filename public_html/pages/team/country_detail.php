<?php
$user = require_team();
$name = trim((string) get('name'));
if ($name === '') {
    redirect('index.php?page=team_countries');
}

$stmt = db()->prepare('SELECT * FROM countries WHERE name = ? LIMIT 1');
$stmt->execute([$name]);
$country = $stmt->fetch();
$language = trim((string) get('language'));
$status = (string) get('status');

$where = ['s.country = ?'];
$params = [$name];
if (!is_admin($user)) {
    $where[] = '(s.assigned_to = ? OR s.status = ?)';
    array_push($params, $user['id'], 'agreed');
}
if ($language !== '') {
    $where[] = 's.language = ?';
    $params[] = $language;
}
if ($status !== '') {
    $where[] = 's.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$rows = db()->prepare("SELECT s.* FROM sites s WHERE $whereSql ORDER BY s.updated_at DESC LIMIT 200");
$rows->execute($params);
$rows = $rows->fetchAll();
$langs = distinct_site_languages();

render_header($name, 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($name) ?></h1>
    <p class="muted">
      <?= h($country ? (regions()[$country['region']] ?? $country['region']) : '') ?>
      · Lang <?= h($country['default_language'] ?? '—') ?>
      · <a href="index.php?page=team_countries">All countries</a>
    </p>
  </div>
  <a class="btn" href="index.php?page=team_site_form&country=<?= urlencode($name) ?>&region=<?= urlencode($country['region'] ?? '') ?>&language=<?= urlencode($country['default_language'] ?? '') ?>">Add site in <?= h($name) ?></a>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_country">
  <input type="hidden" name="name" value="<?= h($name) ?>">
  <div><label>Language</label>
    <select name="language">
      <option value="">All</option>
      <?php foreach ($langs as $lang): ?>
        <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead>
      <tr><th>Domain</th><th>Language</th><th>Publisher quote</th><th>Agreed</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= money_or_dash($s['publisher_quote_price'] ?? null) ?><?php if (!empty($s['publisher_quote_date'])): ?> <span class="muted">(<?= h($s['publisher_quote_date']) ?>)</span><?php endif; ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No sites in this country yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('team'); ?>
