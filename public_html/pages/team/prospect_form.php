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
    $domainRaw = trim((string) post('domain'));
    $analyzed = analyze_pasted_domain_line($domainRaw);
    $domain = $analyzed['ok'] ? $analyzed['domain'] : '';
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
    if (!$analyzed['ok']) {
        flash('error', 'Use a root domain only (e.g. example.com or my-site.co.uk) — no https, paths, or subdomains.');
        $site['domain'] = $domainRaw;
        $site['country'] = $country;
        $site['language'] = $language;
        $site['region'] = $region;
        $site['niche'] = $niche;
        $site['notes'] = $notes;
        $site['url'] = $url;
        $site['status'] = $status;
    } elseif ($country === '' && !$id) {
        flash('error', 'Select a country database (type to search, then Enter).');
    } elseif (!$id) {
        $exists = filter_domains_against_prospects([$domain], $country);
        if ($exists['existing']) {
            flash('error', 'Already in this country’s database. Filter first — do not add duplicates.');
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }
        $added = add_prospect_domains([$domain], $user, $country, $language, $region, $niche, $notes);
        if ($added['inserted'] < 1) {
            flash('error', 'Already in this country’s database. Filter first — do not add duplicates.');
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }
        if ($url !== '' || $status !== 'new') {
            db()->prepare(
                'UPDATE prospect_sites SET url=?, status=? WHERE TRIM(country)=? AND domain=?'
            )->execute([$url, $status, $country, $domain]);
        }
        flash('ok', 'Prospect added to ' . $country . ' (also saved in today’s add history).');
        if (!empty($added['batch_id'])) {
            redirect('index.php?page=team_prospect_batch&id=' . (int) $added['batch_id']);
        }
        redirect('index.php?page=team_prospects&country=' . urlencode($country));
    } else {
        try {
            db()->prepare(
                'UPDATE prospect_sites SET domain=?, url=?, country=?, language=?, region=?, niche=?, notes=?, status=? WHERE id=?'
            )->execute([$domain, $url, $country, $language, $region, $niche, $notes, $status, $id]);
            flash('ok', 'Prospect updated.');
            redirect('index.php?page=team_prospects&country=' . urlencode($country !== '' ? $country : '_none'));
        } catch (PDOException $e) {
            flash('error', 'Domain already exists in this country’s database.');
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
    <div>
      <label>Domain <span class="help">(root only)</span></label>
      <input name="domain" value="<?= h($site['domain']) ?>" required placeholder="example.com" spellcheck="false">
      <p class="help">No https, paths, or subdomains. Hyphens and .co.uk are OK.</p>
    </div>
    <div><label>URL</label><input name="url" value="<?= h($site['url']) ?>"></div>
    <?= render_country_typeahead((string) ($site['country'] ?? ''), [
        'required' => !$id,
        'label' => 'Country database',
        'attrs' => 'data-fill-language="[data-name=language]" data-fill-region="select[name=region]"',
    ]) ?>
    <?= render_language_typeahead((string) ($site['language'] ?? '')) ?>
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
<?= sites_form_script_tag() ?>
<?php render_footer('team'); ?>
