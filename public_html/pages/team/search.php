<?php
$user = require_team();
$before = catalog_context_get('team');
$ctx = catalog_context_from_request('team');
// Changing project alone clears country/language (disabled fields are not submitted)
if (
    isset($_GET['project_id'])
    && !isset($_GET['country'])
    && !isset($_GET['language'])
    && (int) $_GET['project_id'] !== (int) $before['project_id']
) {
    catalog_context_save('team', [
        'project_id' => (int) $_GET['project_id'],
        'country' => '',
        'language' => '',
    ]);
    $ctx = catalog_context_get('team');
}
$projectId = (int) $ctx['project_id'];
$country = $ctx['country'];
$language = $ctx['language'];
$superQ = trim((string) (post('sq') ?: get('sq')));

$projects = db()->prepare(
    "SELECT p.id, p.name, p.client_name FROM projects p
     JOIN project_members pm ON pm.project_id=p.id
     WHERE pm.user_id=? AND p.status!='archived'
     ORDER BY p.name"
);
$projects->execute([(int) $user['id']]);
$projects = $projects->fetchAll();
// Fallback: if no memberships, show none

$project = null;
if ($projectId > 0) {
    $stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch() ?: null;
    if ($project) {
        $chk = db()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
        $chk->execute([$projectId, (int) $user['id']]);
        if (!$chk->fetchColumn() && !is_admin($user)) {
            $project = null;
            $projectId = 0;
            catalog_context_save('team', ['project_id' => 0]);
        }
    } else {
        $projectId = 0;
        catalog_context_save('team', ['project_id' => 0]);
    }
}

$countryGroups = country_catalog_countries_grouped();
$langOptions = project_country_language_options($projectId, $country);
$countrySheets = $projectId > 0
    ? project_country_sheets($projectId, (string) ($project['countries'] ?? ''))
    : [];
$lookup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $ctx = catalog_context_from_request('team');
    $projectId = (int) $ctx['project_id'];
    $country = $ctx['country'];
    $language = $ctx['language'];
    if ($action === 'add_to_project') {
        if ($projectId <= 0 || $country === '' || $language === '') {
            flash('error', 'Select project, country, and language first.');
        } else {
            $domain = normalize_domain((string) post('domain'));
            $added = add_domains_to_project(
                $projectId,
                [$domain],
                $user,
                $country,
                $language,
                (string) post('region'),
                trim((string) post('niche')),
                trim((string) post('notes'))
            );
            if ($added['inserted'] > 0) {
                flash('ok', "Added {$domain} to {$project['name']} · {$country} · {$language}.");
            } else {
                flash('error', 'Already known in this project or globally — not added.');
            }
            $superQ = $domain;
        }
    }
}

if ($superQ !== '' && $projectId > 0 && $country !== '' && $language !== '') {
    $lookup = lookup_domain_in_project_country($superQ, $projectId, $country, $language);
}

render_header('Catalog search', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Catalog search'],
]); ?>
<div class="topbar">
  <div>
    <h1>Search a project catalog</h1>
    <p class="muted">
      Select <strong>Project → Country → Language</strong> (saved on the server for your session).
      Then search Admin’s data for that project’s country sheet. Your choices stay after refresh.
    </p>
  </div>
  <div class="actions">
    <?php if ($projectId): ?>
      <a class="btn secondary" href="index.php?page=team_project&amp;id=<?= $projectId ?>&amp;tab=inventory">Open project</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=team_search&amp;clear_ctx=1">Clear selection</a>
  </div>
</div>

<form class="card super-search" method="get" action="index.php" id="ctx_form">
  <input type="hidden" name="page" value="team_search">
  <div class="form-grid">
    <div>
      <label for="project_id">Project <span class="help">(required)</span></label>
      <select id="project_id" name="project_id" required onchange="document.getElementById('country').disabled=true;document.getElementById('language').disabled=true;this.form.submit()">
        <option value="">— Select project —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $projectId === (int) $p['id'] ? 'selected' : '' ?>>
            <?= h($p['name']) ?><?= $p['client_name'] ? ' · ' . h($p['client_name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="country">Country <span class="help">(required)</span></label>
      <select id="country" name="country" required <?= $projectId ? '' : 'disabled' ?> onchange="this.form.submit()">
        <option value="">— Select country —</option>
        <?php if ($countrySheets): ?>
          <?php foreach ($countrySheets as $sh): ?>
            <?php
              $name = (string) ($sh['country'] ?? '');
              if ($name === '') {
                  continue;
              }
            ?>
            <option value="<?= h($name) ?>" <?= $country === $name ? 'selected' : '' ?>>
              <?= h($name) ?> (<?= (int) ($sh['total'] ?? 0) ?>)
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
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
      <label for="language">Language <span class="help">(required)</span></label>
      <select id="language" name="language" required <?= ($projectId && $country !== '') ? '' : 'disabled' ?> onchange="this.form.submit()">
        <option value="">— Select language —</option>
        <?php foreach ($langOptions as $lang): ?>
          <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <p class="help" style="margin-top:0.5rem">
    Saved selection:
    <strong><?= $project ? h($project['name']) : '—' ?></strong>
    · <strong><?= h($country ?: '—') ?></strong>
    · <strong><?= h($language ?: '—') ?></strong>
  </p>

  <label for="sq" style="margin-top:0.9rem">Website / domain</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" placeholder="example.com"
           <?= ($projectId && $country !== '' && $language !== '') ? 'autofocus' : 'disabled' ?>>
    <button class="btn" type="submit" <?= ($projectId && $country !== '' && $language !== '') ? '' : 'disabled' ?>>Search</button>
  </div>
</form>

<?php if ($superQ !== '' && ($projectId <= 0 || $country === '' || $language === '')): ?>
<div class="card"><p class="muted">Choose project, country, and language first, then search.</p></div>
<?php endif; ?>

<?php if ($lookup !== null): ?>
  <?php
    $domain = $lookup['domain'] !== '' ? $lookup['domain'] : $superQ;
    $row = $lookup['site'];
  ?>
<div class="card">
  <h2>Result · <?= h($project['name'] ?? '') ?> · <?= h($country) ?> · <?= h($language) ?> · <?= h($domain) ?></h2>
  <?php if ($lookup['in_project'] && $row): ?>
    <p class="help" style="margin-bottom:0.75rem">Already in this project country catalog — do not add again.</p>
    <table>
      <tbody>
        <tr><th>Domain</th><td><strong><?= h($row['domain']) ?></strong></td></tr>
        <tr><th>Project</th><td><?= h($row['project_name'] ?? '') ?></td></tr>
        <tr><th>Country / language</th><td><?= h($row['country'] ?: '—') ?> · <?= h($row['language'] ?: '—') ?></td></tr>
        <tr><th>DR / DA / Traffic</th>
          <td><?= h((string) ($row['dr'] ?? '—')) ?> / <?= h((string) ($row['da'] ?? '—')) ?> / <?= h((string) ($row['traffic'] ?? '—')) ?></td></tr>
        <tr><th>Quote / Agreed</th>
          <td><?= money_or_dash($row['publisher_quote_price'] ?? null) ?>
            / <?= money_or_dash($row['backlink_price'] ?? null) ?> <?= h($row['currency'] ?? '') ?></td></tr>
        <tr><th>Status</th><td><?= badge($row['status']) ?></td></tr>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">Not in this project’s <?= h($country) ?> / <?= h($language) ?> catalog.</p>
    <form method="post" class="form-grid" style="margin-top:1rem">
      <input type="hidden" name="action" value="add_to_project">
      <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">
      <input type="hidden" name="country" value="<?= h($country) ?>">
      <input type="hidden" name="language" value="<?= h($language) ?>">
      <input type="hidden" name="domain" value="<?= h($domain) ?>">
      <div><label>Region</label>
        <select name="region">
          <option value="">—</option>
          <?php foreach (regions() as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Niche</label><input name="niche" placeholder="optional"></div>
      <div class="full"><label>Note</label><textarea name="notes" rows="2"></textarea></div>
      <div class="full actions">
        <button class="btn" type="submit">Add <?= h($domain) ?> to <?= h($project['name'] ?? '') ?> · <?= h($country) ?></button>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php if ($lookup['in_inventory'] && $lookup['inventory']): ?>
<div class="card">
  <h2>Also in Our inventory</h2>
  <p class="help"><?= h($lookup['inventory']['domain']) ?> · <?= h($lookup['inventory']['country'] ?: '—') ?></p>
</div>
<?php endif; ?>
<?php endif; ?>
<?php render_footer('team'); ?>
