<?php
$user = require_team();
ensure_extract_schema();
$country = canonicalize_country_name(trim((string) (post('country') ?: get('country'))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $country !== '') {
    $action = (string) post('action');
    $siteId = (int) post('site_id');
    try {
        if ($action === 'save_emails') {
            $raw = (string) post('emails');
            $parts = preg_split('/[\n,;]+/', $raw) ?: [];
            $res = extract_site_set_emails($siteId, $parts, $user, true);
            flash('ok', 'Saved ' . (int) $res['saved'] . ' email(s) for this site.');
        }
        if ($action === 'delete_email') {
            extract_site_delete_email((int) post('email_id'), $siteId);
            flash('ok', 'Email removed.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=team_extract_emails&country=' . urlencode($country) . '#site-' . $siteId);
}

$rows = $country !== '' ? extract_sites_with_emails($country) : [];
render_header('Add emails', 'team');
?>
<div class="topbar">
  <div>
    <h1>Add emails</h1>
    <p class="muted">Team 3 · each Block 2 site is a branch. Add 2–4 emails under each site name.</p>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_extract_emails">
  <div>
    <label>Country</label>
    <?= render_country_select('country', $country, 'country', true) ?>
  </div>
  <button class="btn" type="submit">Show sites</button>
</form>

<?php if ($country === ''): ?>
  <div class="card empty-state"><p>Select a country to edit emails.</p></div>
<?php elseif (!$rows): ?>
  <div class="card empty-state"><p>No Block 2 sites for <?= h($country) ?> yet.</p></div>
<?php else: ?>
  <?php foreach ($rows as $block):
      $s = $block['site'];
      $emails = $block['emails'];
      $emailText = implode("\n", array_column($emails, 'email'));
  ?>
    <div class="card" id="site-<?= (int) $s['id'] ?>">
      <h2 style="margin-top:0"><?= h($s['domain']) ?></h2>
      <?php if ($emails): ?>
        <ul style="margin:0.35rem 0 0.75rem;padding-left:1.2rem">
          <?php foreach ($emails as $em): ?>
            <li style="margin:0.25rem 0;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
              <code><?= h($em['email']) ?></code>
              <form method="post" style="display:inline;margin:0">
                <input type="hidden" name="action" value="delete_email">
                <input type="hidden" name="country" value="<?= h($country) ?>">
                <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="email_id" value="<?= (int) $em['id'] ?>">
                <button class="btn-link danger" type="submit">Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">No emails yet — add below.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="save_emails">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
        <label>Emails <span class="help">(one per line · saves as this site’s branch)</span></label>
        <textarea name="emails" rows="4" placeholder="info@<?= h($s['domain']) ?>&#10;contact@<?= h($s['domain']) ?>"><?= h($emailText) ?></textarea>
        <p class="actions" style="margin-top:0.65rem">
          <button class="btn" type="submit">Save emails</button>
        </p>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer('team'); ?>
