<?php
$user = require_admin();
$raw = '';
$result = null;
$errorDetail = '';

try {
    ensure_prospect_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) post('urls');
        if (trim($raw) === '') {
            flash('error', 'Paste at least one URL or domain.');
        } else {
            $result = admin_add_urls_to_database($raw, $user);
            if ($result['total'] <= 0) {
                flash('error', 'No valid URLs/domains found. Example: https://example.com or example.com');
            } else {
                $msg = 'Saved ' . (int) $result['total'] . ' URL(s) to Our database.';
                $msg .= ' New: ' . (int) $result['inserted'] . '.';
                if ((int) $result['updated'] > 0) {
                    $msg .= ' Already present (kept/updated): ' . (int) $result['updated'] . '.';
                }
                flash('ok', $msg);
                redirect('index.php?page=admin_prospects');
            }
        }
    }
} catch (Throwable $e) {
    $errorDetail = $e->getMessage();
    flash('error', 'Could not save URLs. ' . $errorDetail . ' — if tables are missing, open upgrade.php once.');
}

render_header('Add URLs', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
    ['label' => 'Add URLs'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add URLs</h1>
    <p class="muted">Paste URLs and save them into Our database. No uniqueness check — they are stored for Team to filter against later.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
</div>

<?= render_page_purpose(
    'Add URLs — save into Our database',
    'Admin seeds the shared URL list. Team uses Filter & add against this list.',
    'Paste one URL or domain per line, click Save. Entries appear under Our database and Add history.',
    [
        'Paste URLs or domains (one per line).',
        'Click Save to Our database.',
        'Open Our database to browse what was saved.',
    ]
) ?>

<form class="card" method="post">
  <label for="urls">URLs / domains</label>
  <textarea id="urls" name="urls" rows="16" required autofocus
    placeholder="https://www.site1.com&#10;site2.de&#10;https://site3.com/blog"><?= h($raw) ?></textarea>
  <p class="help" style="margin-top:0.5rem">
    One per line (or comma-separated). Full URLs are kept when provided; domain is stored for matching.
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save to Our database</button>
  </p>
</form>

<?php if ($errorDetail !== ''): ?>
  <div class="card"><p class="help">Technical detail: <?= h($errorDetail) ?></p></div>
<?php endif; ?>
<?php render_footer('admin'); ?>
