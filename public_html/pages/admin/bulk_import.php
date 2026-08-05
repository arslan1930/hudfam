<?php
$user = require_admin();
$ctx = catalog_context_from_request('admin');
$projects = db()->query("SELECT id, name, client_name FROM projects WHERE status!='archived' ORDER BY name")->fetchAll();
$projectId = (int) $ctx['project_id'];
$country = $ctx['country'];
$language = $ctx['language'];
$countryGroups = country_catalog_countries_grouped();
$result = null;

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=project-country-catalog-template.csv');
    $out = fopen('php://output', 'wb');
    fputcsv($out, bulk_csv_headers());
    fputcsv($out, [
        'example-site.com', 'German', 'Germany', '35', '40', '12000',
        'pending', 'Demo comment', 'Rexbo',
        'europe', 'Finance', 'https://example-site.com', 'agreed',
        '140', '120', 'EUR',
        'outreach.de@gmail.com', 'Alex DE',
    ]);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ctx = catalog_context_from_request('admin');
    $projectId = (int) $ctx['project_id'];
    $country = $ctx['country'];
    $language = $ctx['language'];
    require_project_access($projectId, $user);
    if ($country === '') {
        flash('error', 'Select a country sheet for this project.');
        redirect('index.php?page=admin_bulk_import&project_id=' . $projectId);
    }
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        flash('error', 'Please upload a CSV file.');
        redirect('index.php?page=admin_bulk_import&project_id=' . $projectId . '&country=' . urlencode($country));
    }
    // Import into project, then force country (+ language if set) on imported domains
    $result = bulk_import_sites_csv($projectId, $_FILES['csv']['tmp_name'], (int) $user['id']);
    // Normalize country/language on rows that came in without matching sheet
    try {
        if ($language !== '') {
            db()->prepare(
                'UPDATE sites SET country=?, language=COALESCE(NULLIF(language,\'\'), ?)
                 WHERE primary_project_id=? AND (TRIM(country)=\'\' OR TRIM(country)=?)'
            )->execute([$country, $language, $projectId, $country]);
            db()->prepare(
                'UPDATE sites SET country=? WHERE primary_project_id=? AND domain IN (
                    SELECT domain FROM (SELECT domain FROM sites WHERE primary_project_id=?) t
                 ) AND TRIM(country)<>?'
            );
            // Simpler: set country for all rows just imported is hard; force country on blank country rows
            db()->prepare(
                'UPDATE sites SET country=? WHERE primary_project_id=? AND TRIM(country)=\'\''
            )->execute([$country, $projectId]);
        } else {
            db()->prepare(
                'UPDATE sites SET country=? WHERE primary_project_id=? AND TRIM(country)=\'\''
            )->execute([$country, $projectId]);
        }
    } catch (Throwable $e) {
        // non-fatal
    }
    flash(
        'ok',
        "Import into project · {$country}"
        . ($language !== '' ? " · {$language}" : '')
        . ": {$result['inserted']} inserted, {$result['updated']} updated, {$result['skipped']} skipped."
    );
}

$langOptions = project_country_language_options($projectId, $country);

render_header('Bulk import', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
    ['label' => 'Bulk import'],
]); ?>
<div class="topbar">
  <div>
    <h1>Bulk import</h1>
    <p class="muted">CSV into a <strong>project’s country catalog</strong>. Choose project + country (+ language). Selection is saved for your session.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;template=1">CSV template</a>
    <a class="btn secondary" href="index.php?page=admin_sites<?= $projectId ? '&project_id=' . $projectId : '' ?>">Catalog</a>
  </div>
</div>
<?= guide_admin_bulk_import() ?>

<div class="card">
  <h2>CSV columns</h2>
  <p class="help">
    Required: <code>domain</code>. Recommended: language, da, dr, traffic, order_status, admin_comments, client_name.
    Rows land in the selected <strong>project</strong>; blank country cells are filled with your selected country.
  </p>
</div>

<div class="card">
<form method="post" enctype="multipart/form-data">
  <div class="form-grid">
    <div>
      <label>Project <span class="help">(required)</span></label>
      <select name="project_id" required>
        <option value="">— choose project —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $projectId === (int) $p['id'] ? 'selected' : '' ?>>
            <?= h($p['name']) ?><?= $p['client_name'] ? ' · ' . h($p['client_name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Country <span class="help">(required)</span></label>
      <select name="country" required>
        <option value="">— Select country —</option>
        <?php foreach ($countryGroups as $regionCode => $block): ?>
          <?php if (empty($block['countries'])) {
              continue;
          } ?>
          <optgroup label="<?= h($block['label']) ?>">
            <?php foreach ($block['countries'] as $c): ?>
              <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Language</label>
      <select name="language">
        <option value="">— optional default —</option>
        <?php foreach ($langOptions as $lang): ?>
          <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Import CSV</button>
  </p>
</form>
</div>

<?php if ($result): ?>
<div class="card">
  <h2>Last import summary</h2>
  <p>
    Inserted: <strong><?= (int) $result['inserted'] ?></strong> ·
    Updated: <strong><?= (int) $result['updated'] ?></strong> ·
    Skipped: <strong><?= (int) $result['skipped'] ?></strong>
  </p>
  <?php if ($result['errors']): ?>
    <ul class="help"><?php foreach ($result['errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($projectId && $country !== ''): ?>
    <p class="actions">
      <a class="btn" href="index.php?page=admin_sites&amp;project_id=<?= $projectId ?>&amp;sheet=<?= urlencode($country) ?>">Open country sheet</a>
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
