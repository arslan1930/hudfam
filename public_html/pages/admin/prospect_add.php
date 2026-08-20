<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$raw = '';
$errorDetail = '';

// Prefill language from country default
if ($country !== '' && $language === '') {
    foreach (list_countries(null, true) as $c) {
        if (strcasecmp((string) $c['name'], $country) === 0) {
            $language = (string) ($c['default_language'] ?? '');
            break;
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) post('urls');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));
        if ($country === '') {
            flash('error', 'Select a country folder first.');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one URL or domain.');
        } else {
            $result = admin_add_urls_to_database($raw, $user, $country, $language);
            if ($result['total'] <= 0) {
                flash('error', 'No valid URLs/domains found. Example: https://example.com or example.com');
            } else {
                $msg = 'Saved ' . (int) $result['total'] . ' URL(s) to ' . $result['country'] . '.';
                $msg .= ' New: ' . (int) $result['inserted'] . '.';
                if ((int) $result['updated'] > 0) {
                    $msg .= ' Already in this country (kept/updated): ' . (int) $result['updated'] . '.';
                }
                flash('ok', $msg);
                redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
            }
        }
    }
} catch (Throwable $e) {
    $errorDetail = $e->getMessage();
    flash('error', 'Could not save URLs. ' . $errorDetail);
}

render_header('Add sites', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
    ['label' => $country !== '' ? $country : 'Add sites', 'href' => $country !== '' ? 'index.php?page=admin_prospects&country=' . urlencode($country) : null],
    ['label' => 'Add sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add sites<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Paste sites into one country’s database. No uniqueness preview — they are saved for that country folder.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_prospects&amp;country=<?= urlencode($country) ?>">Open <?= h($country) ?></a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?= render_page_purpose(
    'Add sites into a country database',
    'Each country folder has its own list of sites.',
    'Choose the country, paste sites, click Save. They appear only in that country’s folder.',
    [
        'Select country.',
        'Paste sites (one per line).',
        'Save — then open that country folder to review.',
    ]
) ?>

<form class="card" method="post">
  <div class="form-grid">
    <div>
      <label for="country">Country <span class="help">(required — type to search)</span></label>
      <?= render_country_select('country', $country, 'country', true, '— Select country —') ?>
    </div>
    <div>
      <label for="language">Language <span class="help">(optional — type to search or enter your own)</span></label>
      <?= render_language_select('language', $language, 'language') ?>
    </div>
  </div>
  <label for="urls" style="margin-top:0.9rem">URLs / domains</label>
  <textarea id="urls" name="urls" rows="14" required
    placeholder="https://www.site1.com&#10;site2.de&#10;https://site3.com/blog"><?= h($raw) ?></textarea>
  <p class="help" style="margin-top:0.5rem">
    One per line. Saved into the selected country’s database only.
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save to country database</button>
  </p>
</form>

<script>
(function () {
  var country = document.getElementById('country');
  var lang = document.getElementById('language');
  if (!country || !lang) return;
  country.addEventListener('change', function () {
    var opt = country.options[country.selectedIndex];
    if (!opt || !opt.dataset.lang) return;
    if (lang.value) return;
    lang.value = opt.dataset.lang;
    lang.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
</script>

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?php render_footer('admin'); ?>
