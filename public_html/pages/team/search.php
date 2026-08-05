<?php
$user = require_team();
ensure_country_catalog_schema();

$country = trim((string) (post('country') ?: get('country')));
$superQ = trim((string) (post('sq') ?: get('sq')));
$countryGroups = country_catalog_countries_grouped();
$lookup = null;
$addedId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $country = trim((string) post('country'));
    if ($country === '') {
        flash('error', 'Select a country first.');
    } elseif ($action === 'add_to_country') {
        $domain = normalize_domain((string) post('domain'));
        $res = add_domain_to_country_catalog(
            $country,
            $domain,
            $user,
            trim((string) post('language')),
            (string) post('region'),
            trim((string) post('niche')),
            trim((string) post('notes'))
        );
        if ($res['ok']) {
            flash('ok', "Added {$domain} to {$country} catalog.");
            $addedId = (int) $res['id'];
            $superQ = $domain;
        } else {
            flash('error', $res['error'] ?: 'Could not add.');
            $superQ = $domain;
        }
    }
}

if ($superQ !== '' && $country !== '') {
    $lookup = lookup_domain_in_country_catalog($superQ, $country);
}

render_header('Catalog search', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Catalog search'],
]); ?>
<div class="topbar">
  <div>
    <h1>Search a country catalog</h1>
    <p class="muted">
      Select the <strong>country</strong> first, then search.
      Results come from Admin’s global country catalog (DR, DA, traffic, status, comments).
      If missing, you can add the domain to that country.
    </p>
  </div>
  <a class="btn secondary" href="index.php?page=team_projects">Projects</a>
</div>

<form class="card super-search" method="get" action="index.php">
  <input type="hidden" name="page" value="team_search">
  <label for="country">Country catalog <span class="help">(required)</span></label>
  <select id="country" name="country" required>
    <option value="">— Select country —</option>
    <?php foreach ($countryGroups as $regionCode => $block): ?>
      <?php if (empty($block['countries'])) {
          continue;
      } ?>
      <optgroup label="<?= h($block['label']) ?>">
        <?php foreach ($block['countries'] as $c): ?>
          <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </optgroup>
    <?php endforeach; ?>
  </select>
  <p class="help">Grouped by Europe, North America, and English markets.</p>

  <label for="sq" style="margin-top:0.8rem">Website / domain</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" placeholder="example.com" <?= $country === '' ? '' : 'autofocus' ?>>
    <button class="btn" type="submit">Search</button>
    <?php if ($superQ !== '' || $country !== ''): ?>
      <a class="btn secondary" href="index.php?page=team_search">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($superQ !== '' && $country === ''): ?>
<div class="card">
  <p class="muted">Choose a country above, then search again.</p>
</div>
<?php endif; ?>

<?php if ($lookup !== null): ?>
  <?php
    $domain = $lookup['domain'] !== '' ? $lookup['domain'] : $superQ;
    $known = $lookup['in_catalog'];
    $row = $lookup['catalog'];
  ?>
<div class="card">
  <h2>Result · <?= h($country) ?> · <?= h($domain) ?></h2>
  <?php if ($known && $row): ?>
    <p class="help" style="margin-bottom:0.75rem">
      Already in the <strong><?= h($country) ?></strong> catalog — do not add again.
    </p>
    <table>
      <tbody>
        <tr><th>Domain</th><td><strong><?= h($row['domain']) ?></strong></td></tr>
        <tr><th>Country</th><td><?= h($row['country'] ?: '—') ?></td></tr>
        <tr><th>Language / region</th><td><?= h($row['language'] ?: '—') ?> · <?= h($row['region'] ?: '—') ?></td></tr>
        <tr><th>Niche</th><td><?= h($row['niche'] ?: '—') ?></td></tr>
        <tr><th>DR / DA / Traffic</th>
          <td><?= h((string) ($row['dr'] ?? '—')) ?> / <?= h((string) ($row['da'] ?? '—')) ?> / <?= h((string) ($row['traffic'] ?? '—')) ?></td></tr>
        <tr><th>Quote / Agreed</th>
          <td><?= money_or_dash($row['publisher_quote_price'] ?? null) ?>
            / <?= money_or_dash($row['backlink_price'] ?? null) ?> <?= h($row['currency'] ?? '') ?></td></tr>
        <tr><th>Status</th><td><?= badge($row['status']) ?></td></tr>
        <tr><th>Order status</th>
          <td><?= h(inventory_order_statuses()[$row['order_status']] ?? ($row['order_status'] ?: '—')) ?></td></tr>
        <?php if (trim((string) ($row['admin_comments'] ?? '')) !== ''): ?>
        <tr><th>Comments</th><td><?= h($row['admin_comments']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">Not in the <?= h($country) ?> catalog<?= $lookup['in_inventory'] ? ' (found in Our inventory — still can add to this country catalog)' : '' ?>.</p>
    <form method="post" class="form-grid" style="margin-top:1rem">
      <input type="hidden" name="action" value="add_to_country">
      <input type="hidden" name="country" value="<?= h($country) ?>">
      <input type="hidden" name="domain" value="<?= h($domain) ?>">
      <div><label>Language</label><input name="language" placeholder="optional"></div>
      <div><label>Region</label>
        <select name="region">
          <option value="">—</option>
          <?php foreach (regions() as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Niche</label><input name="niche" placeholder="optional"></div>
      <div class="full"><label>Note for Admin</label><textarea name="notes" rows="2" placeholder="optional"></textarea></div>
      <div class="full actions">
        <button class="btn" type="submit">Add <?= h($domain) ?> to <?= h($country) ?></button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php if ($lookup['in_inventory'] && $lookup['inventory']): ?>
<div class="card">
  <h2>Also in Our inventory</h2>
  <table>
    <thead><tr><th>Domain</th><th>Country / lang</th><th>Status</th></tr></thead>
    <tbody>
      <tr>
        <td><strong><?= h($lookup['inventory']['domain']) ?></strong></td>
        <td><?= h($lookup['inventory']['country'] ?: '—') ?> · <?= h($lookup['inventory']['language'] ?: '—') ?></td>
        <td><?= h($lookup['inventory']['status'] ?: '—') ?></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php render_footer('team'); ?>
