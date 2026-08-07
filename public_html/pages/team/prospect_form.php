<?php
$user = require_team();
$id = (int) get('id');

// Team may only create sites via Filter & add (unique after filtering).
// This page is for editing an existing prospect only.
if (!$id) {
    $countryPrefill = trim((string) get('country'));
    $redir = 'index.php?page=team_prospect_check';
    if ($countryPrefill !== '') {
        $redir .= '&country=' . urlencode($countryPrefill);
    }
    flash('ok', 'Use Filter & add to paste sites, remove ones already in the country database, then add only the new unique sites.');
    redirect($redir);
}

$countryOptions = list_countries(null, true);
$site = [
    'domain' => '', 'url' => '', 'country' => trim((string) get('country')),
    'language' => trim((string) get('language')), 'region' => '', 'niche' => '',
    'notes' => '', 'status' => 'new',
];

$stmt = db()->prepare('SELECT * FROM prospect_sites WHERE id=?');
$stmt->execute([$id]);
$found = $stmt->fetch();
if (!$found) {
    flash('error', 'Prospect not found.');
    redirect('index.php?page=team_prospects');
}
$site = $found;

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
    $canonCountry = $country !== '' ? resolve_canonical_country($country) : null;
    if ($canonCountry !== null) {
        $country = $canonCountry['name'];
        if ($region === '') {
            $region = $canonCountry['region'];
        }
        if ($language === '') {
            $language = $canonCountry['language'];
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
    } elseif ($canonCountry === null && $country !== '') {
        flash('error', 'Select an existing country database. New country folders are not created.');
        $site['domain'] = $domain;
        $site['country'] = $country;
        $site['language'] = $language;
        $site['region'] = $region;
        $site['niche'] = $niche;
        $site['notes'] = $notes;
        $site['url'] = $url;
        $site['status'] = $status;
    } else {
        // If domain/country changed, block when that domain already exists in the target country.
        $currentDomain = normalize_domain((string) ($site['domain'] ?? ''));
        $currentCountry = trim((string) ($site['country'] ?? ''));
        if ($domain !== '' && $country !== '' && ($domain !== $currentDomain || strcasecmp($country, $currentCountry) !== 0)) {
            $exists = filter_domains_against_prospects([$domain], $country);
            if ($exists['existing']) {
                flash('error', 'That domain is already in the ' . $country . ' database. Keep unique sites only.');
                redirect('index.php?page=team_prospect_form&id=' . $id);
            }
        }
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

render_header($site['domain'], 'team');
?>
<div class="topbar">
  <div>
    <h1><?= h($site['domain']) ?></h1>
    <p class="muted">Edit an existing site. To add new sites, use <a href="index.php?page=team_prospect_check<?= $site['country'] !== '' ? '&amp;country=' . urlencode((string) $site['country']) : '' ?>">Filter &amp; add</a> (unique only).</p>
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
        'required' => true,
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
