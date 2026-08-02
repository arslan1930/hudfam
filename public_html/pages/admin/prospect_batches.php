<?php
require_admin();

$teammateId = (int) get('user');
$showAllRoles = get('who') === 'all';
$roleFilter = $showAllRoles ? '' : 'team';

try {
    sync_missing_prospect_batch_history();
} catch (Throwable $e) {
    // Schema may still be upgrading; list helpers call ensure_prospect_schema.
}

$teamUsers = list_team_users(false);
$summaries = [];
$batches = [];
try {
    // Specific person: any role. Otherwise team-only, or team+admin when who=all.
    $summaryRole = $teammateId ? '' : ($showAllRoles ? '' : 'team');
    $summaries = prospect_add_history_by_user($teammateId ?: null, $summaryRole);
    $batches = list_prospect_batches($teammateId ?: null, 200, $roleFilter);
} catch (Throwable $e) {
    flash('error', 'Prospect history tables are missing. Open upgrade.php once, then reload.');
}

$selectedName = '';
if ($teammateId) {
    foreach ($teamUsers as $tu) {
        if ((int) $tu['id'] === $teammateId) {
            $selectedName = $tu['full_name'] ?: $tu['username'];
            break;
        }
    }
    if ($selectedName === '') {
        foreach ($summaries as $s) {
            if ((int) $s['user_id'] === $teammateId) {
                $selectedName = $s['full_name'] ?: $s['username'];
                break;
            }
        }
    }
}

render_header('Add history', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Add history'],
]); ?>
<div class="topbar">
  <div>
    <h1>Teammate add history</h1>
    <p class="muted">
      Every website each teammate added, saved by person and day.
      <?= $selectedName !== '' ? 'Showing: <strong>' . h($selectedName) . '</strong>.' : '' ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_prospects">Our inventory</a>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospect_batches">
  <div>
    <label>Teammate</label>
    <select name="user">
      <option value="">All teammates</option>
      <?php foreach ($teamUsers as $tu): ?>
        <option value="<?= (int) $tu['id'] ?>" <?= $teammateId === (int) $tu['id'] ? 'selected' : '' ?>>
          <?= h($tu['full_name'] ?: $tu['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Who</label>
    <select name="who">
      <option value="" <?= !$showAllRoles ? 'selected' : '' ?>>Team only</option>
      <option value="all" <?= $showAllRoles ? 'selected' : '' ?>>Team + admin adds</option>
    </select>
  </div>
  <button class="btn" type="submit">Show history</button>
  <?php if ($teammateId || $showAllRoles): ?>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">Clear</a>
  <?php endif; ?>
</form>

<div class="card">
  <h2>By teammate</h2>
  <?php if ($summaries): ?>
  <table>
    <thead>
      <tr>
        <th>Person</th>
        <th>Role</th>
        <th>Sites added</th>
        <th>Days with adds</th>
        <th>Last add</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($summaries as $s): ?>
      <tr>
        <td><strong><?= h($s['full_name'] ?: $s['username']) ?></strong></td>
        <td><span class="badge"><?= h($s['role']) ?></span></td>
        <td><?= (int) $s['site_count'] ?></td>
        <td><?= (int) $s['batch_days'] ?></td>
        <td><?= h($s['last_batch_date'] ?: '—') ?></td>
        <td>
          <a class="btn small" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $s['user_id'] ?>">Day list</a>
          <a class="btn small secondary" href="index.php?page=admin_prospects&amp;created_by=<?= (int) $s['user_id'] ?>">Inventory</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">No add history yet. When teammates use Filter &amp; add, each day’s sites appear here.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Daily history<?= $selectedName !== '' ? ' · ' . h($selectedName) : '' ?></h2>
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Teammate</th>
        <th>Sites</th>
        <th>Country / lang</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?> · <?= h($b['language'] ?: '—') ?></td>
        <td>
          <a class="btn small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View sites</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted">No daily batches for this filter.</p>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
