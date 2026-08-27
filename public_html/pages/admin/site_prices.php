<?php
/**
 * Admin · Website prices — publisher rate book, one country sheet.
 * PR 1: hub + empty country shell. Grid save / lock ship next.
 */
$user = require_admin();
ensure_site_prices_schema();
seed_countries_if_empty(db());

$hub = site_price_hub_url();
$sheet = trim((string) get('country'));
$inCountry = false;
$countryName = '';

if ($sheet !== '') {
    $canon = resolve_canonical_country($sheet);
    if ($canon === null) {
        flash('error', 'That country is not in the country list.');
        redirect($hub);
    }
    if ($canon['name'] !== $sheet) {
        redirect(site_price_sheet_url($canon['name']));
    }
    $inCountry = true;
    $countryName = $canon['name'];
}

if ($inCountry) {
    $rows = list_site_price_rows($countryName);
    $total = count($rows);
    render_header('Website prices · ' . $countryName, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Website prices', 'href' => $hub],
        ['label' => $countryName],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info(
            $countryName,
            'Publisher prices and statuses for this country. Website, DA, DR, and traffic lock after save. Team edits price and status.'
        ) ?></h1>
        <p class="muted">
          <?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?>
          · Processing stays first, then New, then the rest.
          Adding rows, lock, and drag ship in the next updates.
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?= h($hub) ?>">All countries</a>
      </div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table class="sheet-cards-mobile">
          <thead>
            <tr>
              <th>Website</th>
              <th>Niche</th>
              <th>DA</th>
              <th>DR</th>
              <th>Traffic</th>
              <th>Price</th>
              <th>Status</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="8" class="muted">No sites in this country yet. The add-row sheet ships next.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
                $view = site_price_row_for_viewer($r, $user);
                $stMap = site_price_status_map();
                $st = $stMap[strtolower((string) ($view['status_slug'] ?? 'new'))] ?? null;
                $stLabel = $st ? (string) $st['label'] : (string) ($view['status_slug'] ?? 'New');
                ?>
              <tr>
                <td data-label="Website"><?= h((string) $view['domain']) ?></td>
                <td data-label="Niche"><?= h((string) $view['niche']) ?></td>
                <td data-label="DA"><?= h((string) $view['da']) ?></td>
                <td data-label="DR"><?= h((string) $view['dr']) ?></td>
                <td data-label="Traffic"><?= h((string) $view['traffic']) ?></td>
                <td data-label="Price"><?= h((string) ($view['price_note'] ?? '')) ?></td>
                <td data-label="Status"><?= h($stLabel) ?></td>
                <td data-label="Note"><?= h((string) ($view['extra_note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    render_footer('admin');
    return;
}

$folders = site_price_country_counts();
$grand = 0;
foreach ($folders as $f) {
    $grand += (int) $f['total'];
}

render_header('Website prices', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Website prices'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info(
        'Website prices',
        'Office publisher rate book. One country sheet: prices and statuses. Site name, DA, DR, and traffic lock after they are saved.'
    ) ?></h1>
    <p class="muted">
      <?= (int) $grand ?> site<?= (int) $grand === 1 ? '' : 's' ?>
      in <?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?>.
      Open a country to see that sheet.
    </p>
  </div>
</div>

<?= guide_site_prices() ?>

<div class="card" id="open-country">
  <h2 style="margin:0 0 0.45rem">Open a country sheet</h2>
  <p class="help" style="margin-top:0">Country is chosen here. New rows on that sheet will use this country automatically.</p>
  <form method="get" action="index.php" class="form-grid" autocomplete="off" data-no-draft>
    <input type="hidden" name="page" value="admin_site_prices">
    <?= render_country_typeahead('', [
        'id' => 'site_price_country',
        'label' => 'Country',
        'required' => true,
        'placeholder' => 'Type a country, Enter to select',
    ]) ?>
    <p class="actions" style="margin-top:0.35rem;align-self:end">
      <button class="btn" type="submit">Open sheet</button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>

<?php if ($folders === []): ?>
<div class="card" style="margin-top:1rem">
  <div class="empty-state">
    <p>No country sheets have sites yet.</p>
    <p class="muted">Open a country above to start its sheet. Team can add prices once that update ships.</p>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-top:1rem">
  <div class="invoice-list-toolbar" style="margin-bottom:0.65rem">
    <h2 style="margin:0">Countries with prices</h2>
    <label class="sheet-search" for="site-price-country-search" style="margin:0">
      <span class="visually-hidden">Search countries</span>
      <input id="site-price-country-search" type="search" placeholder="Search country…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type a country · Enter = next match">
    </label>
  </div>
  <div class="table-wrap">
    <table id="site-price-country-table">
      <thead>
        <tr>
          <th>Country</th>
          <th class="num">Sites</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f):
          $c = (string) $f['country'];
          $updated = substr((string) ($f['updated_at'] ?? ''), 0, 16);
          $hay = mb_strtolower(trim($c . ' ' . (int) $f['total'] . ' ' . $updated));
          ?>
        <tr data-site-price-country-row data-search="<?= h($hay) ?>">
          <td><a href="<?= h(site_price_sheet_url($c)) ?>"><strong><?= h($c) ?></strong></a></td>
          <td class="num"><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h($updated) ?></td>
          <td><a class="btn secondary small" href="<?= h(site_price_sheet_url($c)) ?>">Open sheet</a></td>
        </tr>
      <?php endforeach; ?>
        <tr class="sheet-search-empty" data-site-price-country-empty hidden>
          <td colspan="4" class="muted">No countries match your search.</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('site-price-country-search');
  if (!input) return;
  var matchIndex = -1;
  function norm(s) { return String(s || '').trim().toLowerCase(); }
  function visibleRows() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-site-price-country-row]')).filter(function (row) {
      return !row.hidden;
    });
  }
  input.addEventListener('input', function () {
    var q = norm(input.value);
    var any = false;
    matchIndex = -1;
    document.querySelectorAll('[data-site-price-country-row]').forEach(function (row) {
      var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
      row.hidden = !hit;
      row.classList.remove('sheet-search-hit');
      if (hit) any = true;
    });
    var empty = document.querySelector('[data-site-price-country-empty]');
    if (empty) empty.hidden = !q || any;
  });
  input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var rows = visibleRows();
    if (!rows.length) return;
    matchIndex = (matchIndex + 1) % rows.length;
    rows.forEach(function (r) { r.classList.remove('sheet-search-hit'); });
    rows[matchIndex].classList.add('sheet-search-hit');
    try { rows[matchIndex].scrollIntoView({ block: 'nearest' }); } catch (err) { rows[matchIndex].scrollIntoView(true); }
  });
})();
</script>
<?php endif; ?>
<?php
render_footer('admin');
