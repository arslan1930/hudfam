<?php
$user = require_admin();
ensure_extract_schema();

$country = trim((string) get('country'));
if ($country !== '' && $country !== 'all') {
    $country = canonicalize_country_name($country);
}
$inCountry = ($country !== '' && $country !== 'all');

if (!$inCountry) {
    $folders = extract_country_folders();
    $totals = extract_totals();
    $byRegion = [];
    foreach ($folders as $f) {
        $byRegion[$f['region_label']][] = $f;
    }
    render_header('Extracted sites', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracting Sites with Emails'],
        ['label' => 'Extracted sites'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted sites</h1>
        <p class="muted">
          Block 1 queue: <?= (int) $totals['queue'] ?> ·
          Block 2 extracted: <?= (int) $totals['extracted'] ?> ·
          With emails: <?= (int) $totals['with_emails'] ?>
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="index.php?page=admin_extract_emails">Extracted sites with Emails</a>
      </div>
    </div>
    <?php if (!$folders): ?>
      <div class="card empty-state">
        <p>No extraction activity yet. Assign Team 1 a task to submit sites into Block 1.</p>
        <a class="btn" href="index.php?page=admin_tasks">Assign tasks</a>
      </div>
    <?php else: ?>
      <?php foreach ($byRegion as $regionLabel => $list): ?>
        <div class="card">
          <h2><?= h($regionLabel) ?></h2>
          <div class="folders" style="margin-top:0.7rem">
            <?php foreach ($list as $f): ?>
              <a class="folder" href="index.php?page=admin_extract_sites&amp;country=<?= urlencode($f['country']) ?>">
                <h3><?= h($f['country']) ?></h3>
                <p class="muted">
                  Block 1: <?= (int) $f['queue'] ?> ·
                  Block 2: <?= (int) $f['extracted'] ?> ·
                  Emails: <?= (int) $f['with_emails'] ?>
                </p>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    render_footer('admin');
    return;
}

$queue = extract_queue_list($country);
$extracted = extract_sites_list($country);
render_header('Extracted sites · ' . $country, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted sites', 'href' => 'index.php?page=admin_extract_sites'],
    ['label' => $country],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($country) ?> · extraction</h1>
    <p class="muted">Block 1 = need extraction · Block 2 = final extracted list</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_extract_emails&amp;country=<?= urlencode($country) ?>">With Emails</a>
    <a class="btn secondary" href="index.php?page=admin_extract_sites">All countries</a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Block 1 · Need to be extracted (<?= count($queue) ?>)</h2>
  <p class="muted">Sites submitted by Team 1. Team 2 claims/opens a batch — those leave Block 1. New submissions keep arriving.</p>
  <?php if ($queue): ?>
    <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_column($queue, 'domain'))) ?></textarea>
    <table style="margin-top:0.85rem">
      <thead><tr><th>Site</th><th>Submitted by</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($queue, 0, 200) as $row): ?>
        <tr>
          <td><strong><?= h($row['domain']) ?></strong></td>
          <td><?= h($row['full_name'] ?: $row['username'] ?: '—') ?></td>
          <td><?= h(substr((string) $row['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($queue) > 200): ?>
      <p class="muted">Showing 200 of <?= count($queue) ?>.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted" style="margin:0">Queue empty for this country.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Block 2 · Extracted final (<?= count($extracted) ?>)</h2>
  <p class="muted">Final list pasted by Team 2 after extraction. Feeds Extracted sites with Emails.</p>
  <?php if ($extracted): ?>
    <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_column($extracted, 'domain'))) ?></textarea>
    <table style="margin-top:0.85rem">
      <thead><tr><th>Site</th><th>Emails</th><th>Added by</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($extracted, 0, 200) as $row): ?>
        <tr>
          <td><strong><?= h($row['domain']) ?></strong></td>
          <td><span class="badge"><?= (int) $row['email_count'] ?></span></td>
          <td><?= h($row['full_name'] ?: $row['username'] ?: '—') ?></td>
          <td><?= h(substr((string) $row['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted" style="margin:0">No extracted sites yet for this country.</p>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
