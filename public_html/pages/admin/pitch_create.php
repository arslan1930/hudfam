<?php
$user = require_admin();
$projectId = (int) get('project_id');
$stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('index.php?page=admin_projects');
}

$region = (string) get('region');
$country = trim((string) get('country'));
$language = trim((string) get('language'));

$where = ["s.status='agreed'"];
$params = [];
apply_site_geo_filters($where, $params, compact('region', 'country', 'language'));
$whereSql = implode(' AND ', $where);
$agreedStmt = db()->prepare("SELECT s.* FROM sites s WHERE $whereSql ORDER BY s.domain");
$agreedStmt->execute($params);
$agreed = $agreedStmt->fetchAll();

$histStmt = db()->prepare(
    "SELECT pi.* FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id
     WHERE ph.project_id=? AND pi.site_id=?
     ORDER BY pi.updated_at DESC LIMIT 3"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) post('title'));
    $notes = trim((string) post('notes'));
    $siteIds = array_map('intval', (array) ($_POST['sites'] ?? []));
    if ($title === '' || !$siteIds) {
        flash('error', 'Title and at least one agreed site are required.');
    } else {
        db()->prepare(
            "INSERT INTO pitches (project_id, title, status, notes, sent_at, created_by) VALUES (?,?, 'sent', ?, NOW(), ?)"
        )->execute([$projectId, $title, $notes, $user['id']]);
        $pitchId = (int) db()->lastInsertId();
        $ins = db()->prepare(
            "INSERT INTO pitch_items (pitch_id, site_id, offered_price, item_status, updated_by)
             VALUES (?,?,?, 'sent', ?)"
        );
        $upd = db()->prepare("UPDATE sites SET status='sent' WHERE id=?");
        foreach ($siteIds as $sid) {
            $site = db()->prepare('SELECT backlink_price FROM sites WHERE id=?');
            $site->execute([$sid]);
            $price = $site->fetchColumn();
            $ins->execute([$pitchId, $sid, $price, $user['id']]);
            $upd->execute([$sid]);
        }
        flash('ok', 'Pack sent with ' . count($siteIds) . ' site(s).');
        redirect('index.php?page=admin_project&id=' . $projectId . '&tab=sent');
    }
}

$countryOptions = list_countries(null, true);
$langs = distinct_site_languages();

render_header('Send pack', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Send pack · <?= h($project['name']) ?></h1>
    <p class="muted">Filter inventory by country/language, then select agreed sites.</p>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_pitch_create">
  <input type="hidden" name="project_id" value="<?= $projectId ?>">
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
  <button class="btn" type="submit">Filter sites</button>
</form>

<div class="card">
<form method="post">
  <label>Pitch title</label>
  <input name="title" required placeholder="Pack #1">
  <label>Notes</label>
  <textarea name="notes" rows="2"></textarea>
  <label style="margin-top:1rem">Agreed sites (<?= count($agreed) ?>)</label>
  <div class="checkbox-list">
    <?php foreach ($agreed as $site):
        $histStmt->execute([$projectId, $site['id']]);
        $history = $histStmt->fetchAll();
        ?>
      <label>
        <input type="checkbox" name="sites[]" value="<?= (int) $site['id'] ?>">
        <span>
          <strong><?= h($site['domain']) ?></strong>
          · <?= h($site['country'] ?: '—') ?> · <?= h($site['language'] ?: '—') ?>
          · DR <?= h((string) ($site['dr'] ?? '—')) ?>
          · quote <?= money_or_dash($site['publisher_quote_price'] ?? null) ?>
          · agreed <?= money_or_dash($site['backlink_price']) ?> <?= h($site['currency']) ?>
          <?php if ($site['warning_flags']): ?><br><span class="badge rejected"><?= h($site['warning_flags']) ?></span><?php endif; ?>
          <?php foreach ($history as $hrow): ?>
            <br><span class="muted"><?= h($hrow['item_status']) ?><?php if ($hrow['reject_reason_code']): ?> · <?= h(reject_reasons()[$hrow['reject_reason_code']] ?? $hrow['reject_reason_code']) ?><?php endif; ?><?php if ($hrow['reject_comment']): ?> — <?= h($hrow['reject_comment']) ?><?php endif; ?></span>
          <?php endforeach; ?>
        </span>
      </label>
    <?php endforeach; ?>
    <?php if (!$agreed): ?><p class="muted">No agreed sites match these filters.</p><?php endif; ?>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Send pack</button>
    <a class="btn secondary" href="index.php?page=admin_project&id=<?= $projectId ?>">Cancel</a>
  </p>
</form>
</div>
<?php render_footer('admin'); ?>
