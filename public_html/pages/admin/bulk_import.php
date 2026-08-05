<?php
$user = require_admin();
ensure_country_catalog_schema();

$country = trim((string) (post('country') ?: get('country')));
$countryGroups = country_catalog_countries_grouped();
$result = null;

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=country-catalog-template.csv');
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
    $country = trim((string) post('country'));
    if ($country === '') {
        flash('error', 'Select a country catalog first.');
        redirect('index.php?page=admin_bulk_import');
    }
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        flash('error', 'Please upload a CSV file.');
        redirect('index.php?page=admin_bulk_import&country=' . urlencode($country));
    }
    $result = bulk_import_country_catalog_csv($country, $_FILES['csv']['tmp_name'], (int) $user['id']);
    flash(
        'ok',
        "Import into {$country}: {$result['inserted']} inserted, {$result['updated']} updated, {$result['skipped']} skipped."
    );
}

render_header('Bulk import', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
    ['label' => 'Bulk import'],
]); ?>
<div class="topbar">
  <div>
    <h1>Bulk import</h1>
    <p class="muted">CSV into a <strong>country catalog</strong> · duplicates in the same country are updated. Not tied to a client project.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_bulk_import&amp;template=1">CSV template</a>
    <a class="btn secondary" href="index.php?page=admin_sites<?= $country !== '' ? '&sheet=' . urlencode($country) : '' ?>">Catalog</a>
  </div>
</div>

<div class="card">
  <h2>CSV columns</h2>
  <p class="help">
    Required: <code>domain</code>.  
    Recommended: <code>language</code>, <code>da</code>, <code>dr</code>, <code>traffic</code>,
    <code>order_status</code>, <code>admin_comments</code> (or <code>comments</code>), <code>client_name</code>.  
    Optional: region, niche, url, status, prices, our_mailbox, our_contact_name.
  </p>
  <p class="help">
    Rows are saved into the <strong>selected country</strong> (CSV <code>country</code> column is ignored for placement).
    <code>order_status</code>: pending, processing, completed, on_hold, cancelled.
  </p>
</div>

<div class="card">
<form method="post" enctype="multipart/form-data">
  <div class="form-grid">
    <div>
      <label>Target country catalog <span class="help">(required)</span></label>
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
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
  </div>
  <p class="help" style="margin-top:0.7rem">Large files: keep under your host’s upload limit. Split into multiple CSVs if needed.</p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Import CSV into country</button>
  </p>
</form>
</div>

<?php if ($result): ?>
<div class="card">
  <h2>Last import summary · <?= h($country) ?></h2>
  <p>
    Inserted: <strong><?= (int) $result['inserted'] ?></strong> ·
    Updated: <strong><?= (int) $result['updated'] ?></strong> ·
    Skipped: <strong><?= (int) $result['skipped'] ?></strong>
  </p>
  <?php if ($result['errors']): ?>
    <h3>Notes / errors (first <?= count($result['errors']) ?>)</h3>
    <ul class="help">
      <?php foreach ($result['errors'] as $err): ?>
        <li><?= h($err) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($country !== ''): ?>
    <p class="actions"><a class="btn" href="index.php?page=admin_sites&amp;sheet=<?= urlencode($country) ?>">Open <?= h($country) ?> catalog</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
