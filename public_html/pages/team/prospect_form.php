<?php
$user = require_team();
$id = (int) get('id');
$countryOptions = list_countries(null, true);
$site = [
    'domain' => '', 'url' => '', 'country' => trim((string) get('country')),
    'language' => trim((string) get('language')), 'region' => '', 'niche' => '',
    'notes' => '', 'status' => 'new',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM prospect_sites WHERE id=?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Prospect not found.');
        redirect('index.php?page=team_prospects');
    }
    $site = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = normalize_domain((string) post('domain'));
    $country = trim((string) post('country'));
    $language = trim((string) post('language'));
    $region = (string) post('region');
    $niche = trim((string) post('niche'));
    $notes = trim((string) post('notes'));
    $url = trim((string) post('url'));
    $status = (string) post('status');
    if (!in_array($status, ['new', 'contacting', 'replied', 'skipped'], true)) {
        $status = 'new';
    }
    if ($country !== '') {
        foreach ($countryOptions as $c) {
            if (strcasecmp($c['name'], $country) === 0) {
                if ($region === '') {
                    $region = $c['region'];
                }
                if ($language === '' && $c['default_language'] !== '') {
                    $language = $c['default_language'];
                }
                break;
            }
        }
    }
    if ($domain === '') {
        flash('error', 'Domain is required.');
    } elseif (!$id) {
        $exists = filter_domains_against_prospects([$domain]);
        if ($exists['existing']) {
            flash('error', 'Already in prospect inventory. Filter first — do not add duplicates.');
            redirect('index.php?page=team_prospect_check');
        }
        // Writes inventory + today's add history batch for this teammate
        $added = add_prospect_domains([$domain], $user, $country, $language, $region, $niche, $notes);
        if ($added['inserted'] < 1) {
            flash('error', 'Already in prospect inventory. Filter first — do not add duplicates.');
            redirect('index.php?page=team_prospect_check');
        }
        if ($url !== '' || $status !== 'new') {
            db()->prepare(
                'UPDATE prospect_sites SET url=?, status=? WHERE domain=?'
            )->execute([$url, $status, $domain]);
        }
        flash('ok', 'Prospect added (also saved in today’s add history).');
        if (!empty($added['batch_id'])) {
            redirect('index.php?page=team_prospect_batch&id=' . (int) $added['batch_id']);
        }
        redirect('index.php?page=team_prospects');
    } else {
        try {
            db()->prepare(
                'UPDATE prospect_sites SET domain=?, url=?, country=?, language=?, region=?, niche=?, notes=?, status=? WHERE id=?'
            )->execute([$domain, $url, $country, $language, $region, $niche, $notes, $status, $id]);
            flash('ok', 'Prospect updated.');
            redirect('index.php?page=team_prospects');
        } catch (PDOException $e) {
            flash('error', 'Domain already exists in prospect inventory.');
        }
    }
}

render_header($id ? $site['domain'] : 'Add prospect', 'team');
?>
<div class="topbar">
  <div>
    <h1><?= $id ? h($site['domain']) : 'Add one prospect' ?></h1>
    <p class="muted">Prospects only (no prices). Prefer <a href="index.php?page=team_prospect_check">Filter &amp; add</a> for lists.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_prospects">Back</a>
</div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div><label>Domain</label><input name="domain" value="<?= h($site['domain']) ?>" required></div>
    <div><label>URL</label><input name="url" value="<?= h($site['url']) ?>"></div>
    <div><label>Country</label>
      <select name="country">
        <option value="">—</option>
        <?php foreach ($countryOptions as $c): ?>
          <option value="<?= h($c['name']) ?>" <?= ($site['country'] ?? '') === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Language</label><input name="language" value="<?= h($site['language']) ?>"></div>
    <div><label>Region</label>
      <select name="region">
        <option value="">—</option>
        <?php foreach (regions() as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($site['region'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Niche</label><input name="niche" value="<?= h($site['niche']) ?>"></div>
    <div><label>Status</label>
      <select name="status">
        <?php foreach (['new','contacting','replied','skipped'] as $st): ?>
          <option value="<?= $st ?>" <?= ($site['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= h($site['notes'] ?? '') ?></textarea></div>
  </div>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
</form>
</div>
<?php render_footer('team'); ?>
