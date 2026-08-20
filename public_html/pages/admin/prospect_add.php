<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$frequent = user_frequent_countries((int) $user['id'], 8);
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
        $action = (string) (post('action') ?: 'save');
        $raw = (string) post('sites');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));

        if ($country === '') {
            flash('error', 'Select a country folder first (type to search, then Enter).');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one root domain.');
        } else {
            $parsed = parse_domain_list_strict($raw);
            if ($parsed['invalid_count'] > 0) {
                flash('error', 'Remove invalid lines first (Clean errors). Root domains only — e.g. example.com or my-site.co.uk.');
                $raw = $parsed['valid_text'] !== '' ? $parsed['valid_text'] . "\n" . implode("\n", array_column($parsed['invalid'], 'raw')) : $raw;
            } else {
                $result = admin_add_urls_to_database($raw, $user, $country, $language);
                if ($result['total'] <= 0) {
                    flash('error', 'No valid root domains found. Example: example.com or my-site.co.uk');
                } else {
                    $msg = 'Saved ' . (int) $result['total'] . ' site(s) to ' . $result['country'] . '.';
                    $msg .= ' New: ' . (int) $result['inserted'] . '.';
                    if ((int) $result['updated'] > 0) {
                        $msg .= ' Already in this country (kept/updated): ' . (int) $result['updated'] . '.';
                    }
                    flash('ok', $msg);
                    redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
                }
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
    <p class="muted">Paste root domains into one country’s database. No uniqueness preview — they are saved for that country folder.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_prospects&amp;country=<?= urlencode($country) ?>">Open <?= h($country) ?></a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?= guide_admin_add() ?>

<form class="card" method="post" id="add_sites_form">
  <input type="hidden" name="action" id="form_action" value="save">
  <div class="form-grid">
    <?= render_country_typeahead($country) ?>
    <?= render_language_typeahead($language) ?>
  </div>
  <div style="margin-top:0.9rem">
    <?= render_domains_paste_field('urls', $raw, [
        'id' => 'urls',
        'label' => 'Sites (root domains)',
        'required' => true,
        'rows' => 14,
    ]) ?>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn secondary" type="submit" onclick="document.getElementById('form_action').value='clean'">Clean list</button>
    <button class="btn" type="submit" onclick="document.getElementById('form_action').value='save'">Save sites</button>
  </p>
  <?php if ($needsClean): ?>
    <p class="help" style="margin-top:0.6rem"><strong>Tip:</strong> Click <em>Clean list</em> first, then Save sites.</p>
  <?php endif; ?>
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
<?= sites_form_script_tag() ?>
<?php render_footer('admin'); ?>
