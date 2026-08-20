<?php
/**
 * Team single-site form removed from Team nav — Admin owns Our database.
 */
$user = require_team();
if (($user['role'] ?? '') === 'team') {
    flash('error', 'Our database is only available to Admin. Use Filter & add.');
    redirect('index.php?page=team_prospect_check');
}
$id = (int) get('id');
$frequent = user_frequent_countries((int) $user['id'], 8);
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

if (!$id && trim((string) $site['country']) === '' && $frequent !== []) {
    $site['country'] = (string) $frequent[0]['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domainRaw = trim((string) post('domain'));
    $domain = strtolower($domainRaw);
    $country = trim((string) post('country'));
    $language = trim((string) post('language'));
    $region = (string) post('region');
    $niche = trim((string) post('niche'));
    $notes = trim((string) post('notes'));
    $status = (string) post('status');
    if (!in_array($status, ['new', 'contacting', 'replied', 'skipped'], true)) {
        $status = 'new';
    }
    if ($country !== '') {
        foreach (list_countries(null, true) as $c) {
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
    if ($domainRaw === '' || !is_plain_site_domain($domain)) {
        flash('error', 'Root domain only (e.g. example.com or example.co.uk). No https://, www., subdomains, or paths.');
    } elseif ($country === '' && !$id) {
        flash('error', 'Select a country database.');
    } elseif (!$id) {
        $exists = filter_domains_against_prospects([$domain], '');
        if ($exists['existing']) {
            flash('error', 'Already in Our database (any country). Each domain exists only once — filter first.');
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }
        // Writes inventory + today's add history batch for this teammate
        $added = add_prospect_domains([$domain], $user, $country, $language, $region, $niche, $notes);
        if ($added['inserted'] < 1) {
            flash('error', 'Already in Our database (any country). Each domain exists only once — filter first.');
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }
        if ($status !== 'new') {
            db()->prepare(
                'UPDATE prospect_sites SET status=? WHERE TRIM(country)=? AND domain=?'
            )->execute([$status, $country, $domain]);
        }
        flash('ok', 'Site added to ' . $country . ' (also saved in today’s add history).');
        if (!empty($added['batch_id'])) {
            redirect('index.php?page=team_prospect_batch&id=' . (int) $added['batch_id']);
        }
        redirect('index.php?page=team_prospects&country=' . urlencode($country));
    } else {
        try {
            db()->prepare(
                'UPDATE prospect_sites SET domain=?, url=\'\', country=?, language=?, region=?, niche=?, notes=?, status=? WHERE id=?'
            )->execute([$domain, $country, $language, $region, $niche, $notes, $status, $id]);
            flash('ok', 'Site updated.');
            redirect('index.php?page=team_prospects&country=' . urlencode($country !== '' ? $country : '_none'));
        } catch (PDOException $e) {
            flash('error', 'That domain already exists in Our database (any country).');
        }
    }
}

render_header($id ? $site['domain'] : 'Add site', 'team');
?>
<div class="topbar">
  <div>
    <h1><?= $id ? h($site['domain']) : 'Add one site' ?></h1>
    <p class="muted">Root domain only: <strong>example.com</strong> or <strong>example.co.uk</strong>. Prefer <a href="index.php?page=team_prospect_check">Filter &amp; add</a> for lists.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_prospects">Back</a>
</div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div>
      <label>Site <span class="help">(root domain only)</span></label>
      <input name="domain" value="<?= h($site['domain']) ?>" required placeholder="example.com" title="Root domain only, e.g. example.com or example.co.uk">
    </div>
    <div>
      <label for="country_form">Country <?= !$id ? '<span class="help">(required · type to search)</span>' : '<span class="help">(type to search)</span>' ?></label>
      <?= render_country_select('country', (string) ($site['country'] ?? ''), 'country_form', !$id, $frequent) ?>
    </div>
    <div>
      <label>Language <span class="help">(optional · type to search)</span></label>
      <?= render_language_select('language', (string) ($site['language'] ?? '')) ?>
    </div>
    <div>
      <label>Region</label>
      <?= render_region_select('region', (string) ($site['region'] ?? '')) ?>
    </div>
    <div><label>Niche</label><input name="niche" value="<?= h($site['niche']) ?>"></div>
    <div><label>Status</label>
      <select name="status" data-searchable="1">
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
