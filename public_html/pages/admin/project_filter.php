<?php
$user = require_admin();
$projectId = (int) get('project_id', post('project_id'));
$project = require_project_access($projectId, $user);
$countryOptions = list_countries(null, true);
$raw = '';
$country = '';
$language = '';
$region = '';
$niche = '';
$notes = '';
$result = null;
$old = list_project_domain_names($projectId, 25000);
$oldText = implode("\n", $old['domains']);
if ($old['truncated']) {
    $oldText .= "\n… +" . ($old['total'] - count($old['domains'])) . ' more (all used when filtering)';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $raw = (string) post('domains');
    $country = trim((string) post('country'));
    $language = trim((string) post('language'));
    $region = (string) post('region');
    $niche = trim((string) post('niche'));
    $notes = trim((string) post('notes'));
    $domains = parse_domain_list($raw);

    if ($action === 'add_new') {
        $filter = filter_domains_against_project($projectId, $domains);
        $added = add_domains_to_project(
            $projectId,
            $filter['new'],
            $user,
            $country,
            $language,
            $region,
            $niche,
            $notes
        );
        flash(
            'ok',
            "Seeded {$added['inserted']} unique site(s) into {$project['name']} catalog. Skipped {$added['skipped']} already in this project."
        );
        redirect('index.php?page=admin_project&id=' . $projectId . '&tab=inventory');
    }

    if (count($domains) > 100000) {
        flash('error', 'Please paste at most 100,000 domains per run (split into batches).');
    } elseif (!$domains) {
        flash('error', 'Paste at least one domain in Box 2.');
    } else {
        $result = filter_domains_against_project($projectId, $domains);
    }
}

render_header('Filter & add · ' . $project['name'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Projects', 'href' => 'index.php?page=admin_projects'],
    ['label' => $project['name'], 'href' => 'index.php?page=admin_project&id=' . $projectId . '&tab=inventory'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Seed / filter catalog · <?= h($project['name']) ?></h1>
    <p class="muted">
      Seed this project’s inventory first. Teammates then use the same Filter &amp; add against Box 1
      so only domains <strong>new to this project</strong> are added.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_project&id=<?= $projectId ?>&tab=inventory">Project catalog</a>
    <a class="btn secondary" href="index.php?page=admin_bulk_import&project_id=<?= $projectId ?>">CSV bulk import</a>
  </div>
</div>

<form method="post" id="filter_form">
  <input type="hidden" name="action" value="filter">
  <input type="hidden" name="project_id" value="<?= $projectId ?>">

  <div class="grid two-box">
    <div class="card box-panel">
      <h2>Box 1 — Project catalog</h2>
      <p class="help"><?= (int) $old['total'] ?> site name(s) · this project only · used for unique filter</p>
      <textarea class="inventory-box" id="old_inventory" rows="16" readonly><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>Box 2 — New sites to filter</h2>
      <p class="help">Paste domains (one per line or comma/space). https:// is stripped automatically.</p>
      <textarea class="inventory-box" id="domains" name="domains" rows="16" required
        placeholder="site1.com&#10;site2.de&#10;https://www.site3.com"><?= h($raw) ?></textarea>
    </div>
  </div>

  <div class="card">
    <div class="form-grid">
      <div><label>Country (for new sites)</label>
        <select name="country" id="country_select">
          <option value="">—</option>
          <?php foreach ($countryOptions as $c): ?>
            <option value="<?= h($c['name']) ?>"
              data-region="<?= h($c['region']) ?>"
              data-lang="<?= h($c['default_language']) ?>"
              <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Language</label><input name="language" id="language_input" value="<?= h($language) ?>"></div>
      <div><label>Region</label>
        <select name="region">
          <option value="">—</option>
          <?php foreach (regions() as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Niche</label><input name="niche" value="<?= h($niche) ?>"></div>
      <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= h($notes) ?></textarea></div>
    </div>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Filter sites</button>
    </p>
  </div>
</form>

<script>
(function(){
  var sel = document.getElementById('country_select');
  var lang = document.getElementById('language_input');
  var region = document.querySelector('select[name=region]');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    if (region && opt.dataset.region) region.value = opt.dataset.region;
    if (lang && opt.dataset.lang && !lang.value) lang.value = opt.dataset.lang;
  });
})();
</script>

<?php if ($result): ?>
<div class="card">
  <h2>Filter results</h2>
  <p>
    From Box 2: <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in this project (excluded): <strong><?= count($result['existing']) ?></strong> ·
    Unique (new): <strong><?= count($result['new']) ?></strong>
  </p>
</div>

<div class="grid two-box">
  <div class="card">
    <h2>Excluded — already in project catalog</h2>
    <?php if ($result['existing']): ?>
      <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($result['existing'], 0, 5000))) ?><?= count($result['existing']) > 5000 ? "\n… +" . (count($result['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <p class="muted">None excluded — all pasted domains are new for this project.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Unique results — ready to add</h2>
    <?php if ($result['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <input type="hidden" name="domains" value="<?= h($raw) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">Add sites → seeds <strong><?= h($project['name']) ?></strong> catalog. Teammates will filter future lists against these names.</p>
        <p class="actions" style="margin-top:0.8rem">
          <button class="btn" type="submit">Add sites (<?= count($result['new']) ?>)</button>
        </p>
      </form>
    <?php else: ?>
      <p class="muted">No unique domains left — everything was already in this project’s catalog.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
