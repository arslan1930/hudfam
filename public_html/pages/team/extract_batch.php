<?php
$user = require_team();
ensure_extract_schema();

$id = (int) (get('id') ?: 0);
$batch = $id > 0 ? get_extract_batch($id) : null;
if (!$batch || (int) ($batch['site_count'] ?? 0) < 1) {
    flash('error', 'That country batch is not available yet. Waiting for sites from the team mate.');
    redirect('index.php?page=team_extracting');
}

$domains = get_extract_batch_domains($id);
$resultsText = (string) ($batch['results_text'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'save_results') {
        $resultsText = (string) post('results_text');
        save_extract_batch_results($id, $resultsText);
        flash('ok', 'Extracting Results saved for ' . (string) $batch['country'] . '.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }
}

$country = (string) $batch['country'];

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
    <p class="help">New unique sites teammates added for <?= h($country) ?> (also merged into the admin country database).</p>
    <?php if ($domains): ?>
      <textarea class="inventory-box" rows="16" readonly><?= h(implode("\n", $domains)) ?></textarea>
      <p class="muted" style="margin:0.5rem 0 0"><?= count($domains) ?> domain<?= count($domains) === 1 ? '' : 's' ?></p>
    <?php else: ?>
      <div class="empty-state">
        <p>Waiting for sites from the team mate</p>
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
<?php render_footer('team'); ?>
