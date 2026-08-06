<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$raw = '';
$errorDetail = '';
$invalidSamples = [];
$countryGroups = countries_grouped();

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
        $raw = (string) post('sites');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));
        if ($country === '') {
            flash('error', 'Select a country folder first.');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one site (example.com).');
        } else {
            $result = admin_add_sites_to_database($raw, $user, $country, $language);
            if ($result['invalid'] !== []) {
                $invalidSamples = array_slice($result['invalid'], 0, 8);
                $msg = 'Root domains only (example.com or example.co.uk). Not allowed: https://, www., subdomains, paths.';
                $msg .= ' Bad lines: ' . implode(', ', $invalidSamples);
                if (count($result['invalid']) > 8) {
                    $msg .= ' (+' . (count($result['invalid']) - 8) . ' more)';
                }
                flash('error', $msg);
            } elseif ($result['total'] <= 0) {
                flash('error', 'No valid sites found. Use root domains only: example.com or example.co.uk');
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
    <p class="muted">Paste root domains only: <strong>example.com</strong> or <strong>example.co.uk</strong> (hyphens OK).</p>
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
    'Choose the country, paste root domains, click Save.',
    [
        'Select country.',
        'Paste root domains only (example.com / example.co.uk).',
        'Save — then open that country folder to review.',
    ]
) ?>

<form class="card" method="post">
  <div class="form-grid">
    <div>
      <label for="country">Country <span class="help">(required)</span></label>
      <select id="country" name="country" required>
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
      <label for="language">Language <span class="help">(optional)</span></label>
      <?= render_language_select('language', $language, 'language') ?>
      <p class="help" style="margin-top:0.35rem">Prefills from the country; leave blank if you don’t need it.</p>
    </div>
  </div>
  <label for="sites" style="margin-top:0.9rem">Sites <span class="help">(root domain only)</span></label>
  <textarea id="sites" name="sites" rows="14" required
    placeholder="site1.com&#10;my-site.de&#10;shop.co.uk"><?= h($raw) ?></textarea>
  <p class="help" style="margin-top:0.5rem">
    One per line. Allowed: <code>example.com</code>, <code>my-site.com</code>, <code>example.co.uk</code>.
    Not allowed: <code>https://…</code>, <code>www.…</code>, <code>blog.example.com</code>, <code>example.com/main</code>.
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save sites</button>
  </p>
</form>

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?php render_footer('admin'); ?>
