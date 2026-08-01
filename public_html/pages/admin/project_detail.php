<?php
$user = require_admin();
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

$members = db()->prepare(
    'SELECT u.username FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=?'
);
$members->execute([$id]);
$members = $members->fetchAll();

$superResults = $superQ !== '' ? search_project_inventory($id, $superQ, 40) : [];
$inventory = project_inventory_query($id, compact('q', 'status', 'region', 'country', 'language', 'mailbox'), $pageNum, 50);
$sites = $inventory['rows'];
$siteTotal = $inventory['total'];
$sitePages = $inventory['pages'];
$countryOptions = list_countries(null, true);
$langs = distinct_project_languages($id);
$mailboxes = distinct_project_mailboxes($id);
$qsBase = array_filter([
    'page' => 'admin_project', 'id' => $id, 'tab' => 'inventory',
    'q' => $q, 'status' => $status, 'region' => $region,
    'country' => $country, 'language' => $language, 'mailbox' => $mailbox,
], fn($v) => $v !== '' && $v !== null);
$qs = http_build_query($qsBase);

$itemsStmt = db()->prepare(
    "SELECT pi.*, s.domain, s.our_mailbox, s.our_contact_name FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id
     JOIN sites s ON s.id=pi.site_id
     WHERE ph.project_id=? AND pi.item_status=?
     ORDER BY pi.updated_at DESC"
);
function project_items(PDOStatement $stmt, int $id, string $status): array
{
    $stmt->execute([$id, $status]);
    return $stmt->fetchAll();
}
$sent = project_items($itemsStmt, $id, 'sent');
$rejected = project_items($itemsStmt, $id, 'rejected');
$processing = project_items($itemsStmt, $id, 'processing');
$completed = project_items($itemsStmt, $id, 'completed');
$published = db()->prepare(
    'SELECT pp.*, s.domain FROM published_placements pp JOIN sites s ON s.id=pp.site_id WHERE pp.project_id=? ORDER BY pp.published_at DESC'
);
$published->execute([$id]);
$published = $published->fetchAll();

$projectClients = [];
try {
    $cStmt = db()->prepare(
        'SELECT c.*,
          (SELECT COUNT(*) FROM publication_orders o WHERE o.client_id=c.id) AS order_count,
          (SELECT COUNT(*) FROM publication_orders o WHERE o.client_id=c.id AND o.status="processing") AS processing_count
         FROM clients c WHERE c.project_id=? ORDER BY c.name'
    );
    $cStmt->execute([$id]);
    $projectClients = $cStmt->fetchAll();
} catch (Throwable $e) {
    $projectClients = [];
}

render_header($project['name'], 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= h($project['name']) ?></h1>
    <p class="muted"><?= h($project['client_name'] ?: 'Client campaign') ?> · <?= h($project['niche'] ?: '—') ?> · <?= h($project['countries'] ?: '—') ?> · <?= $siteTotal ?> inventory sites</p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_site_form&project_id=<?= $id ?>">Add site</a>
    <a class="btn secondary" href="index.php?page=admin_client_form&project_id=<?= $id ?>">New client folder</a>
    <a class="btn secondary" href="index.php?page=admin_pitch_create&project_id=<?= $id ?>">Send pack</a>
    <a class="btn secondary" href="index.php?page=admin_orders_export&project_id=<?= $id ?>&download=1">Download orders CSV</a>
    <a class="btn secondary" href="index.php?page=admin_project_form&id=<?= $id ?>">Edit requirements</a>
  </div>
</div>

<form class="card super-search" method="get" action="index.php">
  <input type="hidden" name="page" value="admin_project">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="tab" value="inventory">
  <label for="sq">Super search — project inventory only</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" placeholder="Domain, blogger email, our Gmail, contact name…">
    <button class="btn" type="submit">Search</button>
    <?php if ($superQ !== ''): ?>
      <a class="btn secondary" href="index.php?page=admin_project&id=<?= $id ?>&tab=inventory">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($superQ !== ''): ?>
<div class="card">
  <h2>Super search · “<?= h($superQ) ?>” · <?= count($superResults) ?> hit(s)</h2>
  <table>
    <thead>
      <tr><th>Domain</th><th>Status</th><th>Agreed</th><th>Blogger</th><th>Our mailbox</th><th>Contact</th></tr>
    </thead>
    <tbody>
    <?php foreach ($superResults as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int) $s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= h($s['publisher_email'] ?: '—') ?></td>
        <td><strong><?= h($s['our_mailbox'] ?: '—') ?></strong></td>
        <td><?= h($s['our_contact_name'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$superResults): ?>
      <tr><td colspan="6" class="muted">Not in this project. <a href="index.php?page=admin_site_form&project_id=<?= $id ?>">Add site</a></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="grid">
    <div><span class="muted">Budget</span><br><strong><?= h($project['budget'] ?: '—') ?></strong></div>
    <div><span class="muted">Price range</span><br><strong><?= money_or_dash($project['price_min']) ?> – <?= money_or_dash($project['price_max']) ?> <?= h($project['currency']) ?></strong></div>
    <div><span class="muted">Min DR/DA/Traffic</span><br><strong><?= h((string)($project['min_dr'] ?? '—')) ?> / <?= h((string)($project['min_da'] ?? '—')) ?> / <?= h((string)($project['min_traffic'] ?? '—')) ?></strong></div>
    <div><span class="muted">Avoid</span><br><strong><?= h($project['avoid_notes'] ?: '—') ?></strong></div>
  </div>
</div>
<div class="tabs">
  <?php foreach (['inventory','brief','clients','sent','rejected','processing','completed','published'] as $t): ?>
    <a class="<?= $tab===$t?'active':'' ?>" href="index.php?page=admin_project&id=<?= $id ?>&tab=<?= $t ?>"><?= ucfirst($t) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($tab === 'brief'): ?>
<div class="card">
  <h2>Requirements brief</h2>
  <p><?= nl2br(h($project['requirements_brief'] ?: 'No brief yet.')) ?></p>
  <h3>Workflow</h3>
  <p><?= nl2br(h($project['workflow_notes'] ?: '—')) ?></p>
  <h3>Assigned team</h3>
  <p><?php foreach ($members as $m): ?><span class="badge"><?= h($m['username']) ?></span> <?php endforeach; ?><?php if (!$members): ?><span class="muted">None</span><?php endif; ?></p>
</div>
<?php elseif ($tab === 'clients'): ?>
<div class="topbar" style="margin-bottom:0.5rem">
  <p class="muted">Client email folders for deals under this project.</p>
  <a class="btn" href="index.php?page=admin_client_form&project_id=<?= $id ?>">Add client folder</a>
</div>
<div class="folders">
<?php foreach ($projectClients as $c): ?>
  <a class="folder" href="index.php?page=admin_client&id=<?= (int)$c['id'] ?>">
    <h3><?= h($c['name']) ?></h3>
    <p class="muted"><?= h($c['email']) ?></p>
    <p>
      <span class="badge"><?= (int)$c['order_count'] ?> orders</span>
      <?php if ((int)$c['processing_count'] > 0): ?>
        <span class="badge processing"><?= (int)$c['processing_count'] ?> processing</span>
      <?php endif; ?>
    </p>
  </a>
<?php endforeach; ?>
<?php if (!$projectClients): ?><div class="card">No client folders yet. Run upgrade.php if tables are missing, then add a client.</div><?php endif; ?>
</div>
<?php elseif ($tab === 'inventory'): ?>
<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_project">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="tab" value="inventory">
  <div><label>Filter</label><input name="q" value="<?= h($q) ?>"></div>
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
    <p class="muted"><?= $siteTotal ?> site(s) — build this project’s inventory here</p>
    <a class="btn" href="index.php?page=admin_site_form&project_id=<?= $id ?>">Add site</a>
  </div>
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Metrics</th><th>Quote / Agreed</th><th>Status</th>
        <th>Our mailbox</th><th>Contact</th><th>Owner</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="index.php?page=admin_site_form&id=<?= (int)$s['id'] ?>"><?= h($s['domain']) ?></a></td>
        <td>DR <?= h((string)($s['dr'] ?? '—')) ?> / DA <?= h((string)($s['da'] ?? '—')) ?></td>
        <td><?= money_or_dash($s['publisher_quote_price'] ?? null) ?> / <?= money_or_dash($s['backlink_price']) ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><strong><?= h($s['our_mailbox'] ?: '—') ?></strong></td>
        <td><?= h($s['our_contact_name'] ?: '—') ?></td>
        <td><?= h($s['owner'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr><td colspan="7" class="muted">No sites in this project yet. Add inventory for this client.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $sitePages ?></span>
    <?php if ($pageNum < $sitePages): ?><a href="?<?= h($qs) ?>&p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php else:
  $map = ['sent'=>$sent,'rejected'=>$rejected,'processing'=>$processing,'completed'=>$completed];
  $rows = $tab === 'published' ? $published : ($map[$tab] ?? []);
?>
<div class="card">
  <table>
    <thead><tr><th>Site</th><th>Status / reason</th><th>Comment / link</th><th></th></tr></thead>
    <tbody>
    <?php if ($tab === 'published'): foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['domain']) ?></td>
        <td><?= badge('completed') ?></td>
        <td><a href="<?= h($row['live_link']) ?>" target="_blank"><?= h($row['live_link']) ?></a></td>
        <td><?= h(substr($row['published_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; else: foreach ($rows as $item): ?>
      <tr>
        <td><?= h($item['domain']) ?></td>
        <td>
          <?= badge($item['item_status']) ?>
          <?php if ($item['reject_reason_code']): ?><br><?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
        </td>
        <td>
          <?php if ($item['live_link']): ?><a href="<?= h($item['live_link']) ?>" target="_blank"><?= h($item['live_link']) ?></a>
          <?php else: ?><?= h($item['reject_comment'] ?: $item['client_notes'] ?: '—') ?><?php endif; ?>
        </td>
        <td><a class="btn small" href="index.php?page=admin_pitch_item&id=<?= (int)$item['id'] ?>">Update</a></td>
      </tr>
    <?php endforeach; endif; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing here yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
