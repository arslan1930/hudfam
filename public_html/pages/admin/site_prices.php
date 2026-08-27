<?php
/**
 * Admin · Website prices — publisher rate book, one country sheet.
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

$hubActions = ['add_status', 'delete_status'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) post('action'), $hubActions, true)) {
    $viewer = [
        'id' => (int) ($user['id'] ?? 0),
        'role' => (string) ($user['role'] ?? ''),
    ];
    $back = $inCountry ? site_price_sheet_url($countryName) : $hub;
    try {
        if ((string) post('action') === 'add_status') {
            $st = site_price_add_custom_status((string) post('label'), $viewer);
            flash('ok', 'Added status word “' . (string) ($st['label'] ?? '') . '”.');
        } else {
            site_price_delete_custom_status((string) post('slug'), $viewer);
            flash('ok', 'Removed that status word.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back . '#status-words');
}

$sheetActions = ['add_row', 'save_row', 'unlock_row', 'suggest_niche', 'reorder_lane'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) post('action'), $sheetActions, true)) {
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson, $hub): void {
        if ($wantsJson) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($payload['ok'])) {
            flash('ok', (string) ($payload['message'] ?? 'Saved.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not save.'));
        }
        $back = trim((string) ($payload['country'] ?? ''));
        redirect($back !== '' ? site_price_sheet_url($back) : $hub);
    };
    $action = (string) post('action');
    $workCountry = trim((string) post('country'));
    if ($workCountry === '') {
        $workCountry = $countryName;
    }
    $canonWork = $workCountry !== '' ? resolve_canonical_country($workCountry) : null;
    if ($canonWork === null) {
        $jsonOut(['ok' => false, 'error' => 'Open a country sheet first.'], 400);
    }
    $workCountry = $canonWork['name'];
    $viewer = [
        'id' => (int) ($user['id'] ?? 0),
        'role' => (string) ($user['role'] ?? ''),
    ];
    try {
        if ($action === 'suggest_niche') {
            $domain = site_price_normalize_domain((string) post('domain'));
            $niche = site_price_lookup_niche($domain, $workCountry);
            $chips = '';
            if ($niche !== '' && function_exists('prospect_parse_niches') && function_exists('prospect_niche_chip_html')) {
                foreach (prospect_parse_niches($niche) as $label) {
                    $chips .= prospect_niche_chip_html($label);
                }
            }
            $jsonOut(['ok' => true, 'niche' => $niche, 'chips_html' => $chips, 'country' => $workCountry]);
        }
        if ($action === 'add_row') {
            $row = site_price_add_row_for_user([
                'country' => $workCountry,
                'domain' => (string) post('domain'),
                'niche' => (string) post('niche'),
                'da' => (string) post('da'),
                'dr' => (string) post('dr'),
                'traffic' => (string) post('traffic'),
                'price_note' => (string) post('price_note'),
                'extra_note' => (string) post('extra_note'),
                'status_slug' => (string) post('status_slug'),
            ], $viewer);
            $rows = list_site_price_rows($workCountry);
            $jsonOut([
                'ok' => true,
                'id' => (int) $row['id'],
                'domain' => (string) $row['domain'],
                'country' => $workCountry,
                'total' => count($rows),
                'tbody_html' => render_site_price_sheet_tbody($rows, $viewer),
                'message' => 'Added ' . (string) $row['domain'] . '.',
            ]);
        }
        $siteId = (int) post('site_id');
        if ($action === 'save_row') {
            $fields = [
                'niche' => (string) post('niche'),
                'price_note' => (string) post('price_note'),
                'extra_note' => (string) post('extra_note'),
                'status_slug' => (string) post('status_slug'),
            ];
            if (array_key_exists('domain', $_POST)) {
                $fields['domain'] = (string) post('domain');
                $fields['da'] = (string) post('da');
                $fields['dr'] = (string) post('dr');
                $fields['traffic'] = (string) post('traffic');
            }
            $row = site_price_save_row($siteId, $fields, $viewer);
            if ((string) ($row['country'] ?? '') !== $workCountry) {
                throw new RuntimeException('Site not found.');
            }
            $rows = list_site_price_rows($workCountry);
            $jsonOut([
                'ok' => true,
                'id' => (int) $row['id'],
                'country' => $workCountry,
                'total' => count($rows),
                'tbody_html' => render_site_price_sheet_tbody($rows, $viewer),
                'message' => 'Saved.',
            ]);
        }
        if ($action === 'unlock_row') {
            $row = site_price_unlock_row($siteId, $viewer);
            if ((string) ($row['country'] ?? '') !== $workCountry) {
                throw new RuntimeException('Site not found.');
            }
            $rows = list_site_price_rows($workCountry);
            $jsonOut([
                'ok' => true,
                'id' => (int) $row['id'],
                'country' => $workCountry,
                'total' => count($rows),
                'tbody_html' => render_site_price_sheet_tbody($rows, $viewer),
                'message' => 'Unlocked.',
            ]);
        }
        if ($action === 'reorder_lane') {
            $ids = preg_split('/[,\s]+/', trim((string) post('ids'))) ?: [];
            site_price_reorder_lane($workCountry, (string) post('lane'), $ids, $viewer);
            $rows = list_site_price_rows($workCountry);
            $jsonOut([
                'ok' => true,
                'country' => $workCountry,
                'total' => count($rows),
                'tbody_html' => render_site_price_sheet_tbody($rows, $viewer),
                'message' => 'Order saved.',
            ]);
        }
        $jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
    } catch (InvalidArgumentException $e) {
        $jsonOut(['ok' => false, 'error' => $e->getMessage(), 'country' => $workCountry], 400);
    } catch (RuntimeException $e) {
        $code = str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'Only Admin')
            ? 403
            : 400;
        $jsonOut(['ok' => false, 'error' => $e->getMessage(), 'country' => $workCountry], $code);
    }
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
          <span data-site-price-count><?= (int) $total ?> site<?= (int) $total === 1 ? '' : 's' ?></span>
          · Processing stays first, then New, then Other.
          Website, DA, DR, and traffic lock after add. Admin can drag inside a lane.
        </p>
        <p class="help site-price-status-msg" data-site-price-status-msg role="status" hidden></p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?= h($hub) ?>#status-words">Status words</a>
        <a class="btn secondary" href="<?= h($hub) ?>">All countries</a>
      </div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table class="sheet-cards-mobile site-price-sheet" data-site-price-sheet data-country="<?= h($countryName) ?>"
               data-admin="<?= ($user['role'] ?? '') === 'admin' ? '1' : '0' ?>">
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
              <th>Actions</th>
            </tr>
          </thead>
          <tbody data-site-price-tbody>
            <?= render_site_price_sheet_tbody($rows, $user) ?>
          </tbody>
        </table>
      </div>
    </div>
    <?= prospect_niche_taxonomy_script() ?>
    <?= niche_chips_script_tag() ?>
    <?= open_site_script_tag() ?>
    <?= site_prices_script_tag() ?>
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

<?= render_site_price_status_words_card($user) ?>

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
    <p class="muted">Open a country above to start its sheet. Add sites on the country sheet.</p>
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
