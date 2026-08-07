<?php
/**
 * Admin Add sites — paste text list or 1-column CSV into Extracted URLs → Extracted Sites.
 */
$user = require_admin();
ensure_extracted_schema();
seed_countries_if_empty(db());

$country = trim((string) (post('country') ?: get('country')));
$raw = (string) post('sites_text');
$errorDetail = '';

if ($country !== '') {
    $canonCountry = resolve_canonical_country($country);
    if ($canonCountry !== null) {
        $country = $canonCountry['name'];
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $country = trim((string) post('country'));
        $raw = (string) post('sites_text');
        if ($country === '' || resolve_canonical_country($country) === null) {
            flash('error', 'Select a country first (type to search, then Enter).');
        } else {
            $result = admin_add_extracted_sites(
                $country,
                $user,
                $raw,
                $_FILES['sites_csv'] ?? null
            );
            if ($result['inserted'] < 1 && $result['skipped'] < 1) {
                flash(
                    'error',
                    $result['invalid'] > 0
                        ? 'No valid sites to add. Use root domains, or a 1-column CSV of site names.'
                        : 'Paste a site list or upload a CSV first.'
                );
            } else {
                $msg = 'Added ' . (int) $result['inserted'] . ' site(s) to Extracted Sites · ' . $result['country'];
                if ((int) $result['skipped'] > 0) {
                    $msg .= ' · ' . (int) $result['skipped'] . ' already there';
                }
                if ((int) $result['invalid'] > 0) {
                    $msg .= ' · ' . (int) $result['invalid'] . ' invalid skipped';
                }
                flash('ok', $msg . '.');
                redirect(
                    'index.php?page=admin_extracted&folder=extracted_sites&country='
                    . urlencode($result['country'])
                );
            }
        }
    }
} catch (Throwable $e) {
    $errorDetail = $e->getMessage();
    flash('error', 'Could not add sites. ' . $errorDetail);
}

render_header('Add sites', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
    ['label' => 'Extracted Sites', 'href' => 'index.php?page=admin_extracted&folder=extracted_sites'],
    ['label' => 'Add sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add sites<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Paste a text list or upload a 1-column CSV into <strong>Extracted URLs → Extracted Sites</strong> for one country.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_extracted&amp;folder=extracted_sites&amp;country=<?= urlencode($country) ?>">Open <?= h($country) ?></a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_extracted&amp;folder=extracted_sites">Extracted Sites</a>
  </div>
</div>

<?= guide_admin_add() ?>

<form class="card" method="post" action="index.php?page=admin_prospect_add" enctype="multipart/form-data">
  <div class="form-grid">
    <?= render_country_typeahead($country, [
        'id' => 'country',
        'label' => 'Country',
        'required' => true,
        'attrs' => '',
    ]) ?>
    <div class="full">
      <?= render_domains_paste_field('sites_text', $raw, [
          'id' => 'sites_text',
          'label' => 'Site list (text)',
          'required' => false,
          'rows' => 14,
      ]) ?>
    </div>
    <div class="full">
      <label for="sites_csv">Or upload CSV (1 column)</label>
      <input id="sites_csv" type="file" name="sites_csv" accept=".csv,text/csv,text/plain,.txt">
      <p class="help">One site name per row in the first column. Optional header: site / domain.</p>
    </div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Add sites</button>
  </p>
</form>

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?= sites_form_script_tag() ?>
<?php render_footer('admin'); ?>
