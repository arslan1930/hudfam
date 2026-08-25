<?php
/**
 * Admin · Semrush Research — seed site names per country for Site Finding.
 */
$user = require_admin();
ensure_semrush_research_schema();
seed_countries_if_empty(db());

$hub = semrush_hub_url(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'add_sites') {
        $country = trim((string) post('country'));
        $raw = (string) post('domains');
        $result = add_semrush_domains($country, $raw, $user);
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not add sites.'));
            redirect($hub . '#add-sites');
        }
        $msg = 'Added ' . (int) ($result['inserted'] ?? 0)
            . ' site name(s) to Semrush Research · ' . (string) ($result['country'] ?? $country);
        if ((int) ($result['skipped'] ?? 0) > 0) {
            $msg .= ' · ' . (int) $result['skipped'] . ' already there';
        }
        if ((int) ($result['invalid'] ?? 0) > 0) {
            $msg .= ' · ' . (int) $result['invalid'] . ' invalid skipped';
        }
        flash('ok', $msg . '.');
        $dest = (string) ($result['country'] ?? $country);
        redirect($dest !== '' ? semrush_sheet_url($dest, true) : $hub);
    }
    if ($action === 'clear_country') {
        $result = clear_semrush_country((string) post('country'));
        flash(
            !empty($result['ok']) ? 'ok' : 'error',
            !empty($result['ok'])
                ? ('Cleared Semrush Research for ' . (string) ($result['country'] ?? '') . '.')
                : (string) ($result['error'] ?? 'Could not clear.')
        );
        redirect($hub);
    }
    flash('error', 'Unknown action.');
    redirect($hub);
}

$folders = list_semrush_country_rows();
$addCountry = trim((string) get('country'));
if ($addCountry !== '') {
    $c = resolve_canonical_country($addCountry);
    $addCountry = $c ? $c['name'] : '';
}

render_header('Semrush Research', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Semrush Research'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Semrush Research', 'Optional manual seed for Site Finding. Extracting Results Push also copies site names here with the same country / TLD routing. Site Finding can edit, comment, or clear a full country (sites + comments) without affecting Extracted Sites.') ?></h1>
    <p class="muted"><?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?> with research sites · site names only</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_extracted">Extracted Sites</a>
    <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
    <a class="btn secondary" href="#add-sites">Add sites</a>
  </div>
</div>

<div class="card" id="add-sites">
  <h2 style="margin:0 0 0.45rem">Add site names</h2>
  <p class="help" style="margin-top:0">
    Optional: pick a country and paste site names (one per line). Push from Extracting Results already appends here automatically.
  </p>
  <form method="post" action="<?= h($hub) ?>#add-sites" autocomplete="off"
        data-show-processing="Adding Semrush sites…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_sites">
    <div class="form-grid">
      <?= render_country_typeahead($addCountry, [
          'id' => 'semrush_country',
          'label' => 'Country',
          'required' => true,
      ]) ?>
      <div class="full">
        <?= render_domains_paste_field('domains', '', [
            'id' => 'semrush_domains',
            'label' => 'Site names (root domains)',
            'required' => true,
            'rows' => 12,
            'class' => 'inventory-box',
        ]) ?>
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Add to Semrush Research</button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>

<?php if ($folders === []): ?>
<div class="card" style="margin-top:1rem">
  <div class="empty-state">
    <p>No countries seeded yet.</p>
    <p class="muted">Add site names above — Site Finding will see each country only after it has sites.</p>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-top:1rem">
  <div class="invoice-list-toolbar" style="margin-bottom:0.65rem">
    <h2 style="margin:0">Seeded countries</h2>
    <label class="sheet-search" for="semrush-country-search" style="margin:0">
      <span class="visually-hidden">Search countries</span>
      <input id="semrush-country-search" type="search" placeholder="Search country name…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type a country name · Enter = next match · Shift+Enter = previous">
      <span class="sheet-search-meta muted" data-semrush-country-search-meta hidden></span>
    </label>
  </div>
  <div class="table-wrap">
    <table id="semrush-country-table">
      <thead>
        <tr>
          <th>Country</th>
          <th>Sites</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f):
          $c = (string) $f['country'];
          $sheetHref = semrush_sheet_url($c, true);
          $updated = substr((string) $f['updated_at'], 0, 16);
          $searchHay = mb_strtolower(trim($c . ' ' . (int) $f['total'] . ' ' . $updated));
          ?>
        <tr data-semrush-country-row data-search="<?= h($searchHay) ?>">
          <td><strong><?= h($c) ?></strong></td>
          <td><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h($updated) ?></td>
          <td class="actions">
            <a class="btn secondary small" href="<?= h($sheetHref) ?>">Open sheet</a>
            <a class="btn secondary small" href="<?= h($hub) ?>&amp;country=<?= rawurlencode($c) ?>#add-sites">Add more</a>
            <form method="post" action="<?= h($hub) ?>" style="display:inline"
                  onsubmit="return confirm(<?= h(json_encode('Clear ALL Semrush sites and comments for ' . $c . '? Extracted Sites stay unchanged.', JSON_UNESCAPED_UNICODE)) ?>);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_country">
              <input type="hidden" name="country" value="<?= h($c) ?>">
              <button class="btn danger small" type="submit">Clear</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
        <tr class="sheet-search-empty" data-semrush-country-search-empty hidden>
          <td colspan="4" class="muted">No countries match your search.</td>
        </tr>
      </tbody>
    </table>
  </div>
  <script>
  (function () {
    var input = document.getElementById('semrush-country-search');
    if (!input) return;
    var matchRows = [];
    var matchIndex = -1;
    var meta = document.querySelector('[data-semrush-country-search-meta]');
    var empty = document.querySelector('[data-semrush-country-search-empty]');

    function clearHits() {
      document.querySelectorAll('#semrush-country-table .sheet-search-hit').forEach(function (el) {
        el.classList.remove('sheet-search-hit');
      });
    }

    function filterCountries() {
      var q = String(input.value || '').trim().toLowerCase();
      var rows = document.querySelectorAll('[data-semrush-country-row]');
      var shown = 0;
      matchRows = [];
      clearHits();
      rows.forEach(function (row) {
        var hay = String(row.getAttribute('data-search') || '');
        var hit = !q || hay.indexOf(q) !== -1;
        row.hidden = !hit;
        if (hit) {
          shown++;
          if (q) matchRows.push(row);
        }
      });
      if (empty) empty.hidden = !(q && shown === 0);
      if (matchIndex >= matchRows.length) matchIndex = matchRows.length ? 0 : -1;
      if (meta) {
        if (q) {
          meta.hidden = false;
          meta.textContent = !matchRows.length
            ? '0 · Enter = next'
            : (matchIndex >= 0
              ? (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next'
              : matchRows.length + (matchRows.length === 1 ? ' match' : ' matches') + ' · Enter = next');
        } else {
          meta.hidden = true;
          meta.textContent = '';
          matchIndex = -1;
        }
      }
    }

    function jumpToMatch(dir) {
      var q = String(input.value || '').trim();
      if (!q) return;
      filterCountries();
      if (!matchRows.length) return;
      if (matchIndex < 0) {
        matchIndex = dir > 0 ? 0 : matchRows.length - 1;
      } else {
        matchIndex = (matchIndex + dir + matchRows.length) % matchRows.length;
      }
      var row = matchRows[matchIndex];
      if (!row) return;
      clearHits();
      row.hidden = false;
      row.classList.add('sheet-search-hit');
      row.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (meta) {
        meta.hidden = false;
        meta.textContent = (matchIndex + 1) + ' of ' + matchRows.length + ' · Enter = next';
      }
    }

    input.addEventListener('input', function () {
      matchIndex = -1;
      filterCountries();
    });
    input.addEventListener('search', function () {
      matchIndex = -1;
      filterCountries();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        jumpToMatch(e.shiftKey ? -1 : 1);
      }
    });
  })();
  </script>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
