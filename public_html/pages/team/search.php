<?php
$user = require_team();
$superQ = trim((string) get('sq'));
$results = $superQ !== '' ? search_inventory_safe_for_team($superQ, 60) : [];

render_header('Super search', 'team');
?>
<div class="topbar">
  <div>
    <h1>Super search</h1>
    <p class="muted">Check if a website is already in our inventory. You only see site details — not client names, emails, or project info.</p>
  </div>
</div>

<form class="card super-search" method="get" action="index.php">
  <input type="hidden" name="page" value="team_search">
  <label for="sq">Search by domain</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" autofocus
           placeholder="example.com">
    <button class="btn" type="submit">Search</button>
    <?php if ($superQ !== ''): ?>
      <a class="btn secondary" href="index.php?page=team_search">Clear</a>
    <?php endif; ?>
  </div>
  <p class="help">If the domain appears below, do <strong>not</strong> add it again — it is already in the database.</p>
</form>

<?php if ($superQ !== ''): ?>
<div class="card">
  <h2>Results · “<?= h($superQ) ?>” · <?= count($results) ?> domain(s)</h2>
  <table>
    <thead>
      <tr>
        <th>Domain</th>
        <th>Country</th>
        <th>Language</th>
        <th>DR</th>
        <th>DA</th>
        <th>Traffic</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $s): ?>
      <tr>
        <td><strong><?= h($s['domain']) ?></strong></td>
        <td><?= h($s['country'] ?: '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?></td>
        <td><?= h((string) ($s['da'] ?? '—')) ?></td>
        <td><?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td><span class="badge agreed">Already in inventory</span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$results): ?>
      <tr>
        <td colspan="7" class="muted">
          Not found in inventory. You can add it inside the correct
          <a href="index.php?page=team_projects">project</a>.
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
