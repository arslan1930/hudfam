<?php
$user = require_team();
$id = (int) get('id');
$project = require_project_access($id, $user);

$tab = (string) get('tab', 'inventory');
$superQ = trim((string) get('sq'));
$q = trim((string) get('q'));
$status = (string) get('status');
$region = (string) get('region');
$country = trim((string) get('country'));
$language = trim((string) get('language'));
$mailbox = trim((string) get('mailbox'));
$pageNum = max(1, (int) get('p', 1));

// This project's catalog — metrics + quote/agreed (no admin-only client/comments)
$superResults = $superQ !== '' ? search_project_inventory_for_team($id, $superQ, 40) : [];
$inventory = project_inventory_query($id, compact('q', 'status', 'region', 'country', 'language', 'mailbox'), $pageNum, 50);
$rows = $inventory['rows'];
$total = $inventory['total'];
$pages = $inventory['pages'];

$countryOptions = list_countries(null, true);
$langs = distinct_project_languages($id);
$mailboxes = distinct_project_mailboxes($id);

$itemsStmt = db()->prepare(
    "SELECT pi.*, s.domain, s.our_mailbox, s.our_contact_name FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id JOIN sites s ON s.id=pi.site_id
     WHERE ph.project_id=? AND pi.item_status=? ORDER BY pi.updated_at DESC"
);
function team_items(PDOStatement $stmt, int $id, string $status): array
{
    $stmt->execute([$id, $status]);
    return $stmt->fetchAll();
}
$published = db()->prepare(
    'SELECT pp.*, s.domain FROM published_placements pp JOIN sites s ON s.id=pp.site_id WHERE pp.project_id=? ORDER BY pp.published_at DESC'
);
$published->execute([$id]);
$published = $published->fetchAll();

$qsBase = array_filter([
    'page' => 'team_project', 'id' => $id, 'tab' => 'inventory',
    'q' => $q, 'status' => $status, 'region' => $region,
    'country' => $country, 'language' => $language, 'mailbox' => $mailbox,
], fn($v) => $v !== '' && $v !== null);
$qs = http_build_query($qsBase);

render_header($project['name'], 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Projects', 'href' => 'index.php?page=team_projects'],
    ['label' => $project['name']],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($project['name']) ?></h1>
    <p class="muted">This project has its own catalog. Filter &amp; add checks new sites against Box 1 (this project only).</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=team_project_filter&project_id=<?= $id ?>">Filter &amp; add</a>
    <a class="btn secondary" href="index.php?page=team_site_form&project_id=<?= $id ?>">Add one site</a>
    <a class="btn secondary" href="index.php?page=team_search">Super search</a>
  </div>
</div>

<form class="card super-search" method="get" action="index.php">
  <input type="hidden" name="page" value="team_project">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="tab" value="inventory">
  <label for="sq">Search this project’s catalog</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" autofocus placeholder="example.com">
    <button class="btn" type="submit">Search</button>
    <?php if ($superQ !== ''): ?>
      <a class="btn secondary" href="index.php?page=team_project&id=<?= $id ?>&tab=inventory">Clear</a>
    <?php endif; ?>
  </div>
  <p class="help">
    Results are for <strong><?= h($project['name']) ?></strong> only — DR, DA, traffic, quote &amp; agreed price.
    Cross-project duplicate check: <a href="index.php?page=team_search">Super search</a>.
  </p>
</form>

<?php if ($superQ !== ''): ?>
<div class="card">
  <h2>Catalog search · “<?= h($superQ) ?>” · <?= count($superResults) ?> site(s)</h2>
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Country / lang</th><th>DR / DA / Traffic</th>
        <th>Quote / Agreed</th><th>Status</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($superResults as $s): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&amp;id=<?= (int) $s['id'] ?>"><strong><?= h($s['domain']) ?></strong></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td>
          <?= money_or_dash($s['publisher_quote_price'] ?? null) ?>
          / <?= money_or_dash($s['backlink_price'] ?? null) ?> <?= h($s['currency'] ?? '') ?>
        </td>
        <td><?= badge($s['status']) ?></td>
        <td><span class="badge agreed">In this project</span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$superResults): ?>
      <tr>
        <td colspan="6" class="muted">
          Not in this project’s catalog.
          <a href="index.php?page=team_project_filter&amp;project_id=<?= $id ?>">Filter &amp; add</a>
          or <a href="index.php?page=team_site_form&amp;project_id=<?= $id ?>">Add one site</a>.
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="grid">
    <div><span class="muted">Niche</span><br><strong><?= h($project['niche'] ?: '—') ?></strong></div>
    <div><span class="muted">Countries</span><br><strong><?= h($project['countries'] ?: '—') ?></strong></div>
    <div><span class="muted">Budget</span><br><strong><?= h($project['budget'] ?: '—') ?></strong></div>
    <div><span class="muted">Price range</span><br><strong><?= money_or_dash($project['price_min']) ?> – <?= money_or_dash($project['price_max']) ?> <?= h($project['currency']) ?></strong></div>
    <div><span class="muted">Avoid</span><br><strong><?= h($project['avoid_notes'] ?: '—') ?></strong></div>
  </div>
</div>

<div class="tabs">
  <?php
  $tabLabels = [
      'inventory' => 'Catalog',
      'brief' => 'Brief',
      'sent' => 'Sent',
      'rejected' => 'Rejected',
      'processing' => 'Processing',
      'completed' => 'Completed',
      'published' => 'Published',
  ];
  foreach ($tabLabels as $t => $label): ?>
    <a class="<?= $tab===$t?'active':'' ?>" href="index.php?page=team_project&id=<?= $id ?>&tab=<?= $t ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'brief'): ?>
<div class="card">
  <h2>What Admin set</h2>
  <p><?= nl2br(h($project['requirements_brief'] ?: 'No brief.')) ?></p>
  <h3>Workflow</h3>
  <p><?= nl2br(h($project['workflow_notes'] ?: '—')) ?></p>
</div>

<?php elseif ($tab === 'inventory'): ?>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_project">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="tab" value="inventory">
  <div><label>Filter</label><input name="q" value="<?= h($q) ?>" placeholder="domain, email, mailbox…"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (site_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Region</label>
    <select name="region">
      <option value="">All</option>
      <?php foreach (regions() as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Country</label>
    <select name="country">
      <option value="">All</option>
      <?php foreach ($countryOptions as $c): ?>
        <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Language</label>
    <select name="language">
      <option value="">All</option>
      <?php foreach ($langs as $lang): ?>
        <option value="<?= h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>><?= h($lang) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Our mailbox</label>
    <select name="mailbox">
      <option value="">All</option>
      <?php foreach ($mailboxes as $mb): ?>
        <option value="<?= h($mb) ?>" <?= $mailbox === $mb ? 'selected' : '' ?>><?= h($mb) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <div class="topbar" style="margin-bottom:0.5rem">
    <p class="muted"><?= $total ?> site(s) in this project</p>
    <a class="btn" href="index.php?page=team_project_filter&project_id=<?= $id ?>">Filter &amp; add</a>
  </div>
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Country / lang</th><th>DR / DA / Traffic</th>
        <th>Quote / Agreed</th><th>Status</th>
        <th>Our mailbox</th><th>Contact</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><a href="index.php?page=team_site_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?> · <?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td>
          <?= money_or_dash($s['publisher_quote_price'] ?? null) ?>
          / <?= money_or_dash($s['backlink_price']) ?> <?= h($s['currency']) ?>
        </td>
        <td><?= badge($s['status']) ?></td>
        <td><strong><?= h($s['our_mailbox'] ?: '—') ?></strong></td>
        <td><?= h($s['our_contact_name'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No sites yet. Add the first site for this project.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'published'): ?>
<div class="card">
  <?php foreach ($published as $row): ?>
    <div class="history-item"><strong><?= h($row['domain']) ?></strong><div><a href="<?= h($row['live_link']) ?>" target="_blank"><?= h($row['live_link']) ?></a></div></div>
  <?php endforeach; ?>
  <?php if (!$published): ?><p class="muted">Nothing published yet.</p><?php endif; ?>
</div>
<?php else:
  $rowsTab = team_items($itemsStmt, $id, $tab);
?>
<div class="card">
  <table>
    <thead><tr><th>Site</th><th>Status</th><th>Our mailbox</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($rowsTab as $item): ?>
      <tr>
        <td><?= h($item['domain']) ?></td>
        <td><?= badge($item['item_status']) ?></td>
        <td><?= h($item['our_mailbox'] ?: '—') ?> · <?= h($item['our_contact_name'] ?: '—') ?></td>
        <td><?= h($item['reject_comment'] ?: $item['client_notes'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rowsTab): ?><tr><td colspan="4" class="muted">Nothing here yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
