<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$frequent = user_frequent_countries((int) $user['id'], 8);
$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$raw = '';
$errorDetail = '';
$needsClean = false;

// Prefill from often-used country when opening blank Add sites
if ($country === '' && $frequent !== [] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $country = (string) $frequent[0]['name'];
}

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
        $action = (string) (post('action') ?: 'save');
        $raw = (string) post('sites');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));

        if ($country === '') {
            flash('error', 'Select a country folder first.');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one site (example.com).');
        } elseif ($action === 'clean') {
            $clean = clean_site_list($raw, $country, true);
            $raw = $clean['text'];
            if ($clean['kept'] <= 0) {
                flash('error', clean_site_list_summary($clean) . ' Nothing left to save.');
            } else {
                flash('ok', clean_site_list_summary($clean) . ' Review the list, then Save sites.');
            }
        } else {
            // Save: auto-clean + add only unique sites (duplicates removed for you)
            $result = admin_add_sites_to_database($raw, $user, $country, $language);
            $raw = (string) ($result['text'] ?? $raw);
            $c = $result['clean'] ?? [];
            if ($result['inserted'] <= 0) {
                $needsClean = ((int) ($c['dropped'] ?? 0) > 0);
                flash('error', clean_site_list_summary($c) . ' No new sites to add (all duplicates or unusable).');
            } else {
                $msg = 'Added ' . (int) $result['inserted'] . ' new site(s) to ' . $result['country'] . '.';
                if ((int) $result['skipped_existing'] > 0) {
                    $msg .= ' Removed ' . (int) $result['skipped_existing'] . ' already in database.';
                }
                if ((int) ($c['fixed'] ?? 0) > 0 || (int) ($c['dropped'] ?? 0) > 0 || (int) ($c['dup_paste'] ?? 0) > 0) {
                    $msg .= ' ' . clean_site_list_summary($c);
                }
                flash('ok', $msg);
                redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
            }
        }
    }
} catch (Throwable $e) {
    $errorDetail = $e->getMessage();
    flash('error', 'Could not save sites. ' . $errorDetail);
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
    <p class="muted">Paste root domains: <strong>example.com</strong> / <strong>example.co.uk</strong>. Type to search country/language. Use <strong>Clean list</strong> to fix mistakes and remove duplicates.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_prospects&amp;country=<?= urlencode($country) ?>">Open <?= h($country) ?></a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?= render_frequent_country_chips($frequent, 'index.php?page=admin_prospect_add&country=') ?>

<form class="card" method="post" id="add_sites_form">
  <input type="hidden" name="action" id="form_action" value="save">
  <div class="form-grid">
    <div>
      <label for="country">Country <span class="help">(required · type to search)</span></label>
      <?= render_country_select('country', $country, 'country', true, $frequent) ?>
    </div>
    <div>
      <label for="language">Language <span class="help">(optional · type to search)</span></label>
      <?= render_language_select('language', $language, 'language') ?>
      <p class="help" style="margin-top:0.35rem">Prefills from the country; leave blank if you don’t need it.</p>
    </div>
  </div>
  <label for="sites" style="margin-top:0.9rem">Sites <span class="help">(root domain only)</span></label>
  <textarea id="sites" name="sites" rows="14" required
    placeholder="site1.com&#10;my-site.de&#10;shop.co.uk"><?= h($raw) ?></textarea>
  <p class="help" style="margin-top:0.5rem">
    Allowed: <code>example.com</code>, <code>my-site.com</code>, <code>example.co.uk</code>.
    Clean list will strip <code>https://</code>/<code>www.</code>/paths when possible, drop unusable lines, and remove duplicates already in Our database (any country).
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn secondary" type="submit" onclick="document.getElementById('form_action').value='clean'">Clean list</button>
    <button class="btn" type="submit" onclick="document.getElementById('form_action').value='save'">Save sites</button>
  </p>
  <?php if ($needsClean): ?>
    <p class="help" style="margin-top:0.6rem"><strong>Tip:</strong> Click <em>Clean list</em> first, then Save sites.</p>
  <?php endif; ?>
</form>

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?php render_footer('admin'); ?>
