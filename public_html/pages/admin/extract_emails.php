<?php
$user = require_admin();
ensure_extract_schema();

$country = trim((string) get('country'));
if ($country !== '' && $country !== 'all') {
    $country = canonicalize_country_name($country);
}
$inCountry = ($country !== '' && $country !== 'all');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inCountry) {
    $action = (string) post('action');
    $siteId = (int) post('site_id');
    try {
        if ($action === 'save_emails') {
            $raw = (string) post('emails');
            $parts = preg_split('/[\n,;]+/', $raw) ?: [];
            $res = extract_site_set_emails($siteId, $parts, $user, true);
            flash('ok', 'Saved ' . (int) $res['saved'] . ' email(s).');
        }
        if ($action === 'delete_email') {
            extract_site_delete_email((int) post('email_id'), $siteId);
            flash('ok', 'Email removed.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=admin_extract_emails&country=' . urlencode($country) . '#site-' . $siteId);
}

if (!$inCountry) {
    $folders = extract_country_folders();
    $byRegion = [];
    foreach ($folders as $f) {
        if ((int) $f['extracted'] <= 0) {
            continue;
        }
        $byRegion[$f['region_label']][] = $f;
    }
    render_header('Extracted sites with Emails', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Extracting Sites with Emails'],
        ['label' => 'Extracted sites with Emails'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1>Extracted sites with Emails</h1>
        <p class="muted">Each site is a branch — add multiple emails under it (Team 3 / Admin).</p>
      </div>
      <a class="btn secondary" href="index.php?page=admin_extract_sites">Extracted sites</a>
    </div>
    <?php if (!$byRegion): ?>
      <div class="card empty-state">
        <p>No Block 2 sites yet. Team 2 must paste extracted lists first.</p>
      </div>
    <?php else: ?>
      <?php foreach ($byRegion as $regionLabel => $list): ?>
        <div class="card">
          <h2><?= h($regionLabel) ?></h2>
          <div class="folders" style="margin-top:0.7rem">
            <?php foreach ($list as $f): ?>
              <a class="folder" href="index.php?page=admin_extract_emails&amp;country=<?= urlencode($f['country']) ?>">
                <h3><?= h($f['country']) ?></h3>
                <p class="muted"><?= (int) $f['extracted'] ?> sites · <?= (int) $f['with_emails'] ?> with emails</p>
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

$rows = extract_sites_with_emails($country);
render_header('Emails · ' . $country, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Extracted sites with Emails', 'href' => 'index.php?page=admin_extract_emails'],
    ['label' => $country],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($country) ?> · emails</h1>
    <p class="muted"><?= count($rows) ?> site<?= count($rows) === 1 ? '' : 's' ?> — each site has its own email branch</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_extract_sites&amp;country=<?= urlencode($country) ?>">Block 1 / 2</a>
    <a class="btn secondary" href="index.php?page=admin_extract_emails">All countries</a>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="card empty-state"><p>No Block 2 sites for this country yet.</p></div>
<?php else: ?>
  <?php foreach ($rows as $block):
      $s = $block['site'];
      $emails = $block['emails'];
      $emailText = implode("\n", array_column($emails, 'email'));
  ?>
    <div class="card" id="site-<?= (int) $s['id'] ?>">
      <h2 style="margin-top:0"><?= h($s['domain']) ?></h2>
      <?php if ($emails): ?>
        <ul style="margin:0.4rem 0 0.85rem;padding-left:1.2rem">
          <?php foreach ($emails as $em): ?>
            <li style="margin:0.25rem 0;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
              <code><?= h($em['email']) ?></code>
              <form method="post" style="display:inline;margin:0">
                <input type="hidden" name="action" value="delete_email">
                <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="email_id" value="<?= (int) $em['id'] ?>">
                <button class="btn-link danger" type="submit">Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">No emails yet.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="save_emails">
        <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
        <label>Emails for this site <span class="help">(one per line · replaces current list)</span></label>
        <textarea name="emails" rows="4" placeholder="info@<?= h($s['domain']) ?>&#10;contact@<?= h($s['domain']) ?>"><?= h($emailText) ?></textarea>
        <p class="actions" style="margin-top:0.65rem">
          <button class="btn" type="submit">Save emails</button>
        </p>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer('admin'); ?>
