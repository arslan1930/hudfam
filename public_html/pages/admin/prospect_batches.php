<?php
require_admin();
$userFilter = (int) get('user');
if ($userFilter < 0) {
    $userFilter = 0;
}
$userFilterRow = null;
if ($userFilter > 0) {
    $stmt = db()->prepare('SELECT id, username, full_name, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userFilter]);
    $userFilterRow = $stmt->fetch() ?: null;
    if (!$userFilterRow) {
        $userFilter = 0;
    }
}
$batches = list_prospect_batches($userFilter > 0 ? $userFilter : null, 150);
$missed = [];
try {
    $missed = list_team_missed_work_days(21);
} catch (Throwable $e) {
    $missed = [];
}
$personLabel = $userFilterRow
    ? trim((string) (($userFilterRow['full_name'] ?: '') !== '' ? $userFilterRow['full_name'] : $userFilterRow['username']))
    : '';
$people = array_merge(list_team_users(false), list_admin_users(false));
render_header('Added sites', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Sites Data', 'href' => 'index.php?page=admin_prospects'],
    ['label' => 'Added sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Added sites</h1>
    <p class="muted">Who added how many sites each day<?= $personLabel !== '' ? ' · ' . h($personLabel) : '' ?>.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Countries</a>
</div>

<div class="date-legend" aria-label="Date highlights">
  <span class="date-legend-item"><span class="date-legend-swatch holiday" aria-hidden="true"></span> Sunday · holiday</span>
  <span class="date-legend-item"><span class="date-legend-swatch no-work" aria-hidden="true"></span> No sites submitted (workday)</span>
</div>

<form class="card filters" method="get" style="margin-bottom:1rem">
  <input type="hidden" name="page" value="admin_prospect_batches">
  <div>
    <label>Person</label>
    <select name="user" data-searchable="1">
      <option value="">Everyone</option>
      <?php foreach ($people as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $userFilter === (int) $p['id'] ? 'selected' : '' ?>>
          <?= h(($p['full_name'] ?: $p['username']) . ' · ' . $p['role']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
  <?php if ($userFilter > 0): ?>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">Clear</a>
    <a class="btn secondary" href="index.php?page=admin_prospects&amp;created_by=<?= (int) $userFilter ?>">Countries · <?= h($personLabel) ?></a>
  <?php endif; ?>
</form>

<div class="card">
  <h2 style="margin-top:0">Daily adds</h2>
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Sites</th>
        <th>Country / lang</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b):
        $ymd = (string) $b['batch_date'];
        $isSunday = is_sunday_holiday_date($ymd);
        $weekday = batch_weekday_label($ymd);
        $rowClass = $isSunday ? 'row-holiday' : '';
        $countryName = canonicalize_country_name(trim((string) ($b['country'] ?? '')));
    ?>
      <tr class="<?= h($rowClass) ?>">
        <td>
          <strong><?= h($ymd) ?></strong>
          <?php if ($weekday !== ''): ?>
            <span class="day-meta"><?= h($weekday) ?><?= $isSunday ? ' · holiday' : '' ?></span>
          <?php endif; ?>
          <?php if ($isSunday): ?>
            <span class="badge holiday">Holiday</span>
          <?php endif; ?>
        </td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($countryName !== '' ? $countryName : '—') ?> · <?= h($b['language'] ?: '—') ?></td>
        <td class="actions">
          <a class="btn small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a>
          <?php if ($countryName !== ''): ?>
            <a class="btn small secondary" href="index.php?page=admin_prospects&amp;country=<?= urlencode($countryName) ?>&amp;created_by=<?= (int) $b['user_id'] ?>">In DB</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-state"><p>No adds yet<?= $personLabel !== '' ? ' for ' . h($personLabel) : '' ?>.</p></div>
  <?php endif; ?>
</div>

<?php if ($userFilter <= 0): ?>
<div class="card">
  <h2 style="margin-top:0">No work days</h2>
  <p class="muted">Active teammates with no sites submitted on a workday (last 21 days). Sundays are holidays and are not listed here.</p>
  <?php if ($missed): ?>
  <?php
    $missedShown = array_slice($missed, 0, 100);
    $missedExtra = count($missed) - count($missedShown);
  ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($missedShown as $m): ?>
      <tr class="row-no-work">
        <td>
          <strong><?= h($m['miss_date']) ?></strong>
          <span class="day-meta"><?= h($m['weekday']) ?></span>
        </td>
        <td><?= h($m['full_name'] ?: $m['username']) ?></td>
        <td><span class="badge no-work">No sites submitted</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($missedExtra > 0): ?>
    <p class="muted">Showing 100 of <?= (int) count($missed) ?> missed days.</p>
  <?php endif; ?>
  <?php else: ?>
  <p class="muted" style="margin:0">Everyone active submitted sites on each workday in this period — or there are no active teammates yet.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
