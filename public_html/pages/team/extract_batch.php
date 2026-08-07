<?php
$user = require_team();
ensure_extract_schema();

$id = (int) (get('id') ?: 0);
$batch = $id > 0 ? get_extract_batch($id) : null;
if (!$batch) {
    flash('error', 'That country batch is not available yet. Waiting for sites from the team mate.');
    redirect('index.php?page=team_extracting');
}

$resultsText = (string) ($batch['results_text'] ?? '');
$country = (string) $batch['country'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');

    if ($action === 'save_results') {
        $resultsText = (string) post('results_text');
        save_extract_batch_results($id, $resultsText);
        flash('ok', 'Extracting Results saved for ' . $country . '.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }

    if ($action === 'remove_sites') {
        $selected = post('domains');
        if (!is_array($selected)) {
            $selected = [];
        }
        $removed = remove_extract_batch_domains($id, $selected);
        if ($removed === []) {
            flash('error', 'Select at least one site to remove from the Sites list.');
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        set_extract_sites_undo($id, $removed);
        $n = count($removed);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from the Sites list. You can Undo.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }

    if ($action === 'undo_remove') {
        $undo = get_extract_sites_undo($id);
        if ($undo === null) {
            flash('error', 'Nothing to undo.');
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        $restored = restore_extract_batch_domains($id, $undo['rows']);
        clear_extract_sites_undo();
        flash('ok', 'Undo complete — restored ' . $restored . ' site' . ($restored === 1 ? '' : 's') . ' to the Sites list.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }
}

$siteRows = get_extract_batch_site_rows($id);
$domains = array_column($siteRows, 'domain');
$undo = get_extract_sites_undo($id);

// Empty list after removals — still show page with waiting + undo if available
if ($domains === [] && $undo === null) {
    flash('error', 'That country batch is not available yet. Waiting for sites from the team mate.');
    redirect('index.php?page=team_extracting');
}

render_header('Extracting · ' . $country, 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Extracting sites', 'href' => 'index.php?page=team_extracting'],
    ['label' => $country],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($country) ?> · Extracting</h1>
    <p class="muted">
      <?= count($domains) ?> site<?= count($domains) === 1 ? '' : 's' ?> in Sites list
      · <?= h((string) ($batch['language'] ?: '—')) ?>
      · <?= h((string) ($batch['region'] ?: '—')) ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_extracting">All countries</a>
    <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Add more sites</a>
  </div>
</div>

<div class="grid two-box">
  <div class="card box-panel">
    <h2>① Sites list</h2>
    <p class="help">Select sites to <strong>Open</strong> in Chrome (new tabs) or <strong>Remove</strong> from this list after checking. Admin country database is not changed.</p>

    <?php if ($undo): ?>
      <form method="post" class="sites-list-undo">
        <input type="hidden" name="action" value="undo_remove">
        <p class="help" style="margin:0">
          Last remove: <?= count($undo['rows']) ?> site<?= count($undo['rows']) === 1 ? '' : 's' ?>.
          <button class="btn secondary small" type="submit">Undo remove</button>
        </p>
      </form>
    <?php endif; ?>

    <?php if ($domains): ?>
      <form method="post" id="sites_list_form" class="sites-list-form">
        <input type="hidden" name="action" value="remove_sites">

        <div class="sites-list-toolbar">
          <label class="sites-list-select-all">
            <input type="checkbox" id="sites_select_all">
            <span>Select all</span>
          </label>
          <span class="muted" id="sites_selected_count">0 selected</span>
          <div class="sites-list-actions">
            <button type="button" class="btn secondary small" id="sites_open_btn" disabled>Open</button>
            <button type="submit" class="btn small danger" id="sites_remove_btn" disabled>Remove</button>
          </div>
        </div>

        <div class="sites-list-box" data-sites-list>
          <?php foreach ($siteRows as $row): ?>
            <label class="sites-list-item">
              <input type="checkbox" name="domains[]" value="<?= h($row['domain']) ?>" data-site-domain="<?= h($row['domain']) ?>">
              <span class="sites-list-domain"><?= h($row['domain']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <p class="muted" style="margin:0.5rem 0 0">
          <?= count($domains) ?> domain<?= count($domains) === 1 ? '' : 's' ?>
          · Open uses your browser (Chrome if it is your default)
        </p>
      </form>
    <?php else: ?>
      <div class="empty-state">
        <p>Waiting for sites from the team mate</p>
        <?php if ($undo): ?>
          <p class="muted">Or use Undo remove to bring the last sites back.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card box-panel">
    <h2>② Extracting Results</h2>
    <p class="help">Store extraction output for this country batch here.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_results">
      <textarea class="inventory-box" name="results_text" rows="16" placeholder="Paste or type extracting results for <?= h($country) ?>…"><?= h($resultsText) ?></textarea>
      <div class="actions-sticky" style="margin-top:0.75rem">
        <button class="btn" type="submit">Save results</button>
      </div>
    </form>
  </div>
</div>

<?php if ($domains): ?>
<script src="<?= h(script_asset_url('js/extract-sites-list.js')) ?>" defer></script>
<?php endif; ?>
<?php render_footer('team'); ?>
