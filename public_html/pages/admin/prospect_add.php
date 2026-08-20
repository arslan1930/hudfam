<?php
$user = require_admin();
ensure_prospect_schema();
seed_countries_if_empty(db());

$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$raw = '';
$errorDetail = '';
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

render_header('Add URLs', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
    ['label' => $country !== '' ? $country : 'Add URLs', 'href' => $country !== '' ? 'index.php?page=admin_prospects&country=' . urlencode($country) : null],
    ['label' => 'Add URLs'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add URLs<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Paste URLs into one country’s database. No uniqueness preview — they are saved for that country folder.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_prospects&amp;country=<?= urlencode($country) ?>">Open <?= h($country) ?></a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?= render_page_purpose(
    'Add URLs into a country database',
    'Each country folder has its own list of URLs.',
    'Choose the country, paste URLs, click Save. They appear only in that country’s folder.',
    [
        'Select country.',
        'Paste URLs (one per line).',
        'Save — then open that country folder to review.',
    ]
) ?>

<form class="card" method="post" action="index.php?page=admin_prospect_add">
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
      <label for="language">Language</label>
      <input id="language" name="language" value="<?= h($language) ?>" placeholder="optional default">
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

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?php render_footer('admin'); ?>
