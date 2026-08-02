<?php
$user = require_admin();
$raw = '';
$preview = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $raw = (string) post('urls');
        $domains = parse_domain_list($raw);

        if (count($domains) > 100000) {
            flash('error', 'Please paste at most 100,000 URLs/domains per run.');
        } elseif (!$domains) {
            flash('error', 'Paste at least one URL or domain.');
        } elseif ($action === 'save') {
            $added = add_prospect_domains($domains, $user);
            $msg = "Added {$added['inserted']} unique site(s) to Our inventory.";
            $msg .= " Skipped {$added['skipped']} already in the list.";
            flash('ok', $msg);
            redirect('index.php?page=admin_prospects');
        } else {
            // Preview uniqueness before save
            $preview = filter_domains_against_prospects($domains);
        }
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then try again.');
}

render_header('Add sites · Our inventory', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Our inventory', 'href' => 'index.php?page=admin_prospects'],
    ['label' => 'Add sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Add sites to Our inventory</h1>
    <p class="muted">Paste URLs or domains only (no prices). Team Filter checks new sites against this list so only uniques can be added.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Back to inventory</a>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="preview">
  <label for="urls">URLs / domains (one per line, or comma/space separated)</label>
  <textarea id="urls" name="urls" rows="14" required
    placeholder="https://www.site1.com&#10;site2.de&#10;https://site3.com/page"><?= h($raw) ?></textarea>
  <p class="help" style="margin-top:0.5rem">
    <code>https://</code> and paths are stripped automatically → stored as domain names only.
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">1. Check unique vs inventory</button>
  </p>
</form>

<?php if ($preview): ?>
<div class="card">
  <h2>Preview</h2>
  <p>
    Parsed: <strong><?= (int) $preview['total_input'] ?></strong> ·
    Already in Our inventory: <strong><?= count($preview['existing']) ?></strong> ·
    New (unique): <strong><?= count($preview['new']) ?></strong>
  </p>
</div>
<div class="grid two-box">
  <div class="card">
    <h2>Already in inventory (will skip)</h2>
    <?php if ($preview['existing']): ?>
      <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($preview['existing'], 0, 5000))) ?><?= count($preview['existing']) > 5000 ? "\n… +" . (count($preview['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <p class="muted">None — all pasted domains are new.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Unique — ready to add</h2>
    <?php if ($preview['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="urls" value="<?= h($raw) ?>">
        <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($preview['new'], 0, 5000))) ?><?= count($preview['new']) > 5000 ? "\n… +" . (count($preview['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">These domains join Our inventory. Teammates will see them in Filter Box 1 and cannot re-add them.</p>
        <p class="actions" style="margin-top:0.8rem">
          <button class="btn" type="submit">2. Add <?= count($preview['new']) ?> site(s) to inventory</button>
        </p>
      </form>
    <?php else: ?>
      <p class="muted">No unique domains left — everything is already in Our inventory.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
