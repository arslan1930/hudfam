<?php
require_admin();
ensure_extracted_schema();
seed_countries_if_empty(db());

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
if ($sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet === null) {
        flash('error', 'That country is not in the country list.');
        redirect('index.php?page=admin_extracted');
    }
    if ($canonSheet['name'] !== $sheet) {
        redirect('index.php?page=admin_extracted&country=' . urlencode($canonSheet['name']));
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

// --- Mutations on country detail ---
if ($inCountry && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $countryName = $sheet;

    if ($action === 'edit_site') {
        $siteId = (int) post('site_id');
        $newDomain = (string) post('domain');
        $site = get_extracted_site($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            flash('error', 'Site not found in this country.');
            redirect('index.php?page=admin_extracted&country=' . urlencode($countryName));
        }
        $result = update_extracted_site_domain($siteId, $newDomain);
        if (!$result['ok']) {
            flash('error', (string) ($result['error'] ?? 'Could not update site.'));
        } else {
            flash('ok', 'Updated site to ' . (string) $result['domain'] . '.');
        }
        redirect('index.php?page=admin_extracted&country=' . urlencode($countryName));
    }

    if ($action === 'remove_site') {
        $siteId = (int) post('site_id');
        $site = get_extracted_site($siteId);
        if (!$site || (string) $site['country'] !== $countryName) {
            flash('error', 'Site not found in this country.');
            redirect('index.php?page=admin_extracted&country=' . urlencode($countryName));
        }
        $domain = (string) $site['domain'];
        delete_extracted_site($siteId);
        flash('ok', 'Removed ' . $domain . ' from ' . $countryName . '.');
        // If country is now empty, go back to list
        $left = get_extracted_domains_for_country($countryName, 1);
        if ($left === []) {
            redirect('index.php?page=admin_extracted');
        }
        redirect('index.php?page=admin_extracted&country=' . urlencode($countryName));
    }

    if ($action === 'remove_all') {
        $n = delete_extracted_sites_for_country($countryName);
        flash('ok', 'Removed ' . $n . ' site' . ($n === 1 ? '' : 's') . ' from ' . $countryName . '.');
        redirect('index.php?page=admin_extracted');
    }
}

// --- Country rows (simple list) ---
if (!$inCountry) {
    $countryRows = list_extracted_country_rows();
    $grandTotal = 0;
    foreach ($countryRows as $r) {
        $grandTotal += (int) $r['total'];
    }

    render_header('Extracted URLs', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracted URLs'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted sites</h1>
        <p class="muted">Countries with sites Team pushed from Extracting Results. <?= (int) $grandTotal ?> site<?= (int) $grandTotal === 1 ? '' : 's' ?> total.</p>
      </div>
    </div>

    <div class="card">
      <?php if ($countryRows): ?>
      <table class="extracted-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th>Sites</th>
            <th>Language</th>
            <th>Last pushed</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($countryRows as $r): ?>
          <tr>
            <td><strong><?= h($r['country']) ?></strong></td>
            <td><span class="badge agreed"><?= (int) $r['total'] ?></span></td>
            <td><?= h($r['language'] !== '' ? $r['language'] : '—') ?></td>
            <td class="muted"><?= h($r['last_pushed_at'] ? substr($r['last_pushed_at'], 0, 16) : '—') ?></td>
            <td><a class="btn small" href="index.php?page=admin_extracted&amp;country=<?= urlencode($r['country']) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <p>No extracted sites yet.</p>
        <p class="muted">They appear when Team pastes into Extracting Results and clicks Push.</p>
      </div>
      <?php endif; ?>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- One country detail ---
$countryName = $sheet;
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$inv = extracted_inventory_query([
    'q' => $q,
    'country' => $countryName,
], $pageNum, 100);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$allDomains = get_extracted_domains_for_country($countryName);
$copyText = implode("\n", array_map(static fn ($d) => 'https://' . $d, $allDomains));

$qs = http_build_query(array_filter([
    'page' => 'admin_extracted',
    'country' => $countryName,
    'q' => $q,
], static fn ($v) => $v !== '' && $v !== null));

render_header('Extracted URLs · ' . $countryName, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted URLs', 'href' => 'index.php?page=admin_extracted'],
    ['label' => $countryName],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($countryName) ?></h1>
    <p class="muted"><?= (int) $total ?> extracted site<?= (int) $total === 1 ? '' : 's' ?></p>
  </div>
  <div class="actions">
    <button type="button" class="btn" id="extracted_copy_all" <?= $allDomains ? '' : 'disabled' ?>>Copy all URLs</button>
    <a class="btn secondary" href="index.php?page=admin_extracted">All countries</a>
  </div>
</div>

<?php if ($allDomains): ?>
<textarea id="extracted_copy_source" class="visually-hidden" readonly aria-hidden="true"><?= h($copyText) ?></textarea>
<p class="help" id="extracted_copy_status" hidden></p>
<?php endif; ?>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_extracted">
  <input type="hidden" name="country" value="<?= h($countryName) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain…"></div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <?php if ($rows): ?>
  <table class="extracted-sites-table">
    <thead>
      <tr>
        <th>Site</th>
        <th>Pushed by</th>
        <th>When</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td>
          <form method="post" class="extracted-edit-form">
            <input type="hidden" name="action" value="edit_site">
            <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
            <div class="extracted-edit-row">
              <input name="domain" value="<?= h((string) $s['domain']) ?>" required>
              <button class="btn secondary small" type="submit">Save</button>
            </div>
          </form>
        </td>
        <td><?= h((string) ($s['pushed_by_full'] ?: $s['pushed_by_name'] ?: '—')) ?></td>
        <td class="muted"><?= h(substr((string) $s['created_at'], 0, 16)) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove <?= h((string) $s['domain']) ?> from <?= h($countryName) ?>?');">
            <input type="hidden" name="action" value="remove_site">
            <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
            <button class="btn small danger" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem;justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
    <div>
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span class="muted">Page <?= $pageNum ?> / <?= $pages ?></span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    </div>
    <form method="post" onsubmit="return confirm('Remove ALL <?= (int) count($allDomains) ?> sites from <?= h($countryName) ?>?');">
      <input type="hidden" name="action" value="remove_all">
      <button class="btn secondary small danger" type="submit">Remove all</button>
    </form>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <p>No extracted sites<?= $q !== '' ? ' match this search' : ' in this country yet' ?>.</p>
    <a class="btn secondary" href="index.php?page=admin_extracted">Back to countries</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($allDomains): ?>
<script>
(function () {
  var btn = document.getElementById('extracted_copy_all');
  var src = document.getElementById('extracted_copy_source');
  var status = document.getElementById('extracted_copy_status');
  if (!btn || !src) return;
  function setStatus(msg) {
    if (!status) return;
    status.hidden = !msg;
    status.textContent = msg || '';
  }
  btn.addEventListener('click', function () {
    var text = src.value || '';
    if (!text) return;
    var done = function () {
      setStatus('Copied ' + text.split(/\n/).filter(Boolean).length + ' URL(s).');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        src.hidden = false;
        src.classList.remove('visually-hidden');
        src.focus();
        src.select();
        try { document.execCommand('copy'); } catch (e) {}
        src.classList.add('visually-hidden');
        done();
      });
    } else {
      src.classList.remove('visually-hidden');
      src.focus();
      src.select();
      try { document.execCommand('copy'); } catch (e) {}
      src.classList.add('visually-hidden');
      done();
    }
  });
})();
</script>
<?php endif; ?>
<?php render_footer('admin'); ?>
