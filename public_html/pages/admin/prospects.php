<?php
$user = require_admin();
ensure_prospect_schema();
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('our_database', $user);
}
seed_countries_if_empty(db());
if (function_exists('dedupe_countries_catalog')) {
    try {
        dedupe_countries_catalog();
    } catch (Throwable $e) {
        // ignore
    }
}

$sheet = (string) get('country');
if ($sheet === '' && (string) get('sheet') !== '') {
    $sheet = (string) get('sheet');
}
$emptyCountry = ($sheet === '_none');
if (!$emptyCountry && $sheet !== '' && $sheet !== 'all') {
    $canonSheet = resolve_canonical_country($sheet);
    if ($canonSheet !== null && $canonSheet['name'] !== $sheet) {
        redirect('index.php?page=admin_prospects&country=' . urlencode($canonSheet['name']));
    }
    if ($canonSheet === null) {
        flash('error', 'That country folder is not in the country list. Sites only live in existing countries.');
        redirect('index.php?page=admin_prospects');
    }
    $sheet = $canonSheet['name'];
}
$inCountry = ($sheet !== '' && $sheet !== 'all');

$addRaw = '';
$addCountry = $inCountry && !$emptyCountry ? $sheet : trim((string) (post('country') ?: get('add_country')));
$addLanguage = trim((string) (post('language') ?: get('language')));
if ($addCountry !== '') {
    $canonAdd = resolve_canonical_country($addCountry);
    if ($canonAdd !== null) {
        $addCountry = $canonAdd['name'];
        if ($addLanguage === '') {
            $addLanguage = $canonAdd['language'];
        }
    }
}

// --- Remove one site (from super search or elsewhere) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'remove_site') {
    $siteId = (int) post('site_id');
    $returnSuper = trim((string) post('super_q'));
    $returnCountry = trim((string) post('country'));
    $removed = delete_prospect_site_by_id($siteId);
    if (!$removed) {
        flash('error', 'Site not found.');
    } else {
        flash('ok', 'Removed ' . (string) $removed['domain'] . ' from ' . ((string) ($removed['country'] ?: 'No country')) . '.');
    }
    if ($returnSuper !== '') {
        redirect('index.php?page=admin_prospects&super_q=' . rawurlencode($returnSuper) . '#super-search');
    }
    if ($returnCountry !== '') {
        redirect('index.php?page=admin_prospects&country=' . rawurlencode($returnCountry));
    }
    redirect('index.php?page=admin_prospects');
}

// --- Remove by list from Our database (country folder) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'remove_list') {
    $removeCountry = trim((string) post('country'));
    $raw = (string) post('remove_text');
    try {
        if (function_exists('read_extracted_sites_upload')) {
            $fromFile = read_extracted_sites_upload($_FILES['remove_csv'] ?? null);
            if ($fromFile !== '') {
                $raw = trim($raw) !== '' ? ($raw . "\n" . $fromFile) : $fromFile;
            }
        }
        if ($removeCountry === '' || resolve_canonical_country($removeCountry) === null) {
            flash('error', 'Open a country folder first, then remove by list.');
            redirect('index.php?page=admin_prospects');
        }
        $result = remove_prospect_sites_by_list($removeCountry, $raw);
        if ($result['removed'] < 1) {
            flash(
                'error',
                $result['invalid'] > 0
                    ? 'No matching sites removed. Check the list (root domains) and try again.'
                    : 'No sites from that list were found in ' . $result['country'] . '.'
            );
            redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']) . '#remove-by-list');
        }
        $msg = 'Removed ' . (int) $result['removed'] . ' site(s) from Our database · ' . $result['country'];
        if ((int) $result['not_found'] > 0) {
            $msg .= ' · ' . (int) $result['not_found'] . ' not found';
        }
        if ((int) $result['invalid'] > 0) {
            $msg .= ' · ' . (int) $result['invalid'] . ' invalid skipped';
        }
        flash('ok', $msg . '.');
        redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(
            $removeCountry !== ''
                ? 'index.php?page=admin_prospects&country=' . urlencode($removeCountry) . '#remove-by-list'
                : 'index.php?page=admin_prospects'
        );
    }
}

// --- Add sites into Our database (same panel) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'add_sites') {
    $addRaw = (string) post('urls');
    $addCountry = trim((string) post('country'));
    $addLanguage = trim((string) post('language'));
    try {
        if ($addCountry === '' || resolve_canonical_country($addCountry) === null) {
            flash('error', 'Select a country folder first (type to search, then Enter).');
            redirect('index.php?page=admin_prospects');
        }
        $canon = require_canonical_country($addCountry);
        $addCountry = $canon['name'];
        if (trim($addRaw) === '') {
            flash('error', 'Paste at least one root domain.');
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $parsed = parse_domain_list_strict($addRaw);
        if ($parsed['invalid_count'] > 0) {
            flash('error', 'Remove invalid lines first (Clean errors). Root domains only — e.g. example.com or my-site.co.uk.');
            $_SESSION['admin_prospects_add_draft'] = [
                'country' => $addCountry,
                'language' => $addLanguage,
                'urls' => $parsed['valid_text'] !== ''
                    ? $parsed['valid_text'] . "\n" . implode("\n", array_column($parsed['invalid'], 'raw'))
                    : $addRaw,
            ];
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $result = admin_add_urls_to_database($addRaw, $user, $addCountry, $addLanguage);
        if ($result['total'] <= 0) {
            flash('error', 'No valid root domains found. Example: example.com or my-site.co.uk');
            redirect('index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites');
        }
        $msg = 'Saved ' . (int) $result['total'] . ' site(s) to ' . $result['country'] . '.';
        $msg .= ' New: ' . (int) $result['inserted'] . '.';
        if ((int) $result['updated'] > 0) {
            $msg .= ' Already in this country (kept/updated): ' . (int) $result['updated'] . '.';
        }
        flash('ok', $msg);
        unset($_SESSION['admin_prospects_add_draft']);
        redirect('index.php?page=admin_prospects&country=' . urlencode($result['country']));
    } catch (Throwable $e) {
        flash('error', 'Could not save sites. ' . $e->getMessage());
        redirect(
            $addCountry !== ''
                ? 'index.php?page=admin_prospects&country=' . urlencode($addCountry) . '#add-sites'
                : 'index.php?page=admin_prospects#add-sites'
        );
    }
}

// Restore draft after Clean-errors style validation failure
$draft = $_SESSION['admin_prospects_add_draft'] ?? null;
if (is_array($draft)) {
    if ($addRaw === '' && !empty($draft['urls'])) {
        $addRaw = (string) $draft['urls'];
    }
    if ($addCountry === '' && !empty($draft['country'])) {
        $addCountry = (string) $draft['country'];
    }
    if ($addLanguage === '' && !empty($draft['language'])) {
        $addLanguage = (string) $draft['language'];
    }
    unset($_SESSION['admin_prospects_add_draft']);
}

// --- Country folders (default) ---
if (!$inCountry && !$emptyCountry) {
    $superQ = trim((string) get('super_q'));
    $superResults = $superQ !== '' ? search_prospect_sites_global($superQ, 200) : [];
    // Group matches by country for “present in multiple places”
    $superByCountry = [];
    foreach ($superResults as $hit) {
        $cKey = trim((string) ($hit['country'] ?? ''));
        if ($cKey === '') {
            $cKey = '_none';
        }
        $superByCountry[$cKey][] = $hit;
    }

    $folders = prospect_country_folders();
    $byRegion = [];
    // Preserve region order from regions()
    foreach (regions() as $regionKey => $regionLabel) {
        $byRegion[$regionLabel] = [];
    }
    foreach ($folders as $f) {
        $label = (string) ($f['region_label'] ?? 'Other');
        if (!isset($byRegion[$label])) {
            $byRegion[$label] = [];
        }
        $byRegion[$label][] = $f;
    }
    // Drop empty market groups
    foreach ($byRegion as $k => $list) {
        if ($list === []) {
            unset($byRegion[$k]);
        }
    }
    $grandTotal = 0;
    foreach ($folders as $f) {
        $grandTotal += (int) $f['total'];
    }

    render_header('Our database', 'admin');
    ?>
    <?php render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Our database'],
    ]); ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info('Our database', 'Country folders of unique sites. Team Filter & add writes here. A New badge appears until you open this section.') ?></h1>
        <p class="muted">Each country is its own site database. Team adds merge into these same folders. <?= (int) $grandTotal ?> sites total.</p>
      </div>
      <div class="actions">
        <a class="btn" href="#super-search">Super search</a>
        <a class="btn secondary" href="#add-sites">Add sites</a>
        <a class="btn secondary" href="index.php?page=admin_prospect_batches">Add history</a>
      </div>
    </div>

    <div class="card" id="super-search">
      <h2>Super search</h2>
      <p class="help">
        Search any site across <strong>all country databases</strong>.
        If it exists in more than one country, every place is listed.
      </p>
      <form method="get" action="index.php" class="super-search-form">
        <input type="hidden" name="page" value="admin_prospects">
        <label class="visually-hidden" for="super_q">Site name</label>
        <div class="super-search-row">
          <input id="super_q" name="super_q" type="search" value="<?= h($superQ) ?>"
                 placeholder="example.com" required autocomplete="off" spellcheck="false" data-no-draft>
          <button class="btn" type="submit">Super search</button>
          <?php if ($superQ !== ''): ?>
            <a class="btn secondary" href="index.php?page=admin_prospects">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($superQ !== ''): ?>
        <?php if (!$superResults): ?>
          <div class="empty-state" style="margin-top:0.85rem">
            <p>No matches for “<?= h($superQ) ?>” in any country database.</p>
          </div>
        <?php else: ?>
          <p class="help" style="margin-top:0.85rem">
            Found <strong><?= count($superResults) ?></strong> match<?= count($superResults) === 1 ? '' : 'es' ?>
            in <strong><?= count($superByCountry) ?></strong> countr<?= count($superByCountry) === 1 ? 'y' : 'ies' ?>.
          </p>
          <div class="table-wrap" style="margin-top:0.55rem">
            <table class="super-search-table">
              <thead>
                <tr>
                  <th>Site</th>
                  <th>Country</th>
                  <th>Language</th>
                  <th>Added</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($superResults as $hit):
                  $hitCountry = trim((string) ($hit['country'] ?? ''));
                  $countryHref = $hitCountry !== '' ? $hitCountry : '_none';
                  $countryLabel = $hitCountry !== ''
                      ? (function_exists('prospect_folder_display_label')
                          ? prospect_folder_display_label($hitCountry, (string) ($hit['region'] ?? ''))
                          : $hitCountry)
                      : 'No country';
                  $openUrl = 'index.php?page=admin_prospects&country=' . rawurlencode($countryHref)
                      . '&q=' . rawurlencode((string) $hit['domain']);
                  ?>
                <tr>
                  <td><strong><?= h((string) $hit['domain']) ?></strong></td>
                  <td>
                    <?= h($countryLabel) ?>
                    <?php if ($countryLabel !== $hitCountry && $hitCountry !== ''): ?>
                      <span class="muted"> · <?= h($hitCountry) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= h((string) ($hit['language'] ?: '—')) ?></td>
                  <td class="muted"><?= h(substr((string) ($hit['created_at'] ?? ''), 0, 10)) ?></td>
                  <td class="actions">
                    <a class="btn small" href="<?= h($openUrl) ?>">Go to site</a>
                    <form method="post" action="index.php?page=admin_prospects#super-search" class="inline-form"
                          onsubmit="return confirm('Remove <?= h((string) $hit['domain']) ?> from <?= h($hitCountry !== '' ? $hitCountry : 'No country') ?>?');">
                      <input type="hidden" name="action" value="remove_site">
                      <input type="hidden" name="site_id" value="<?= (int) $hit['id'] ?>">
                      <input type="hidden" name="super_q" value="<?= h($superQ) ?>">
                      <button class="btn secondary small danger" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?= render_page_purpose(
        'Our database — one folder per country',
        'Sites are stored separately for each country.',
        'Open a country folder to browse, or use Add sites below to paste into a country database.',
        [
            'Add sites with the form below (Our database only).',
            'Or open a country folder to browse and add more there.',
            'Team Filter & add checks against the same country lists.',
        ]
    ) ?>

    <div class="card" id="add-sites">
      <h2>Add sites</h2>
      <p class="help">Paste root domains into one country’s database. Use Clean errors for https/paths/subdomains.</p>
      <form method="post" action="index.php?page=admin_prospects#add-sites">
        <input type="hidden" name="action" value="add_sites">
        <div class="form-grid">
          <?= render_country_typeahead($addCountry, [
              'id' => 'add_country',
              'label' => 'Country',
              'required' => true,
          ]) ?>
          <?= render_language_typeahead($addLanguage, ['id' => 'add_language']) ?>
        </div>
        <div style="margin-top:0.9rem">
          <?= render_domains_paste_field('urls', $addRaw, [
              'id' => 'urls',
              'label' => 'Sites (root domains)',
              'required' => true,
              'rows' => 12,
          ]) ?>
        </div>
        <p class="actions" style="margin-top:1rem">
          <button class="btn" type="submit">Save to country database</button>
        </p>
      </form>
    </div>
    <?= sites_form_script_tag() ?>

    <?php if ($folders): ?>
    <div class="card prospect-markets-toolbar">
      <div class="invoice-list-toolbar" style="margin-bottom:0">
        <h2 style="margin:0">Markets</h2>
        <label class="sheet-search" for="prospect-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="prospect-country-search" type="search" placeholder="Search country or .de…"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type to filter · Enter = next match">
          <span class="sheet-search-meta muted" data-prospect-country-search-meta hidden></span>
        </label>
      </div>
      <p class="help" style="margin:0.45rem 0 0">
        Europe first · English markets second. Click a market to expand/collapse.
        Europe &amp; North America show TLDs (.de, .us). Sorted by no. of sites.
      </p>
    </div>

    <div id="prospect-markets">
    <?php
    $marketIndex = 0;
    foreach ($byRegion as $regionLabel => $list):
        $marketIndex++;
        $marketId = 'market-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($regionLabel));
        $openByDefault = $marketIndex <= 2; // Europe + English open
        $marketTotal = 0;
        foreach ($list as $f) {
            $marketTotal += (int) $f['total'];
        }
        ?>
      <div class="card prospect-market<?= $openByDefault ? ' is-open' : '' ?>"
           data-prospect-market
           data-market-label="<?= h(mb_strtolower($regionLabel)) ?>">
        <button type="button" class="prospect-market-toggle" data-prospect-market-toggle
                aria-expanded="<?= $openByDefault ? 'true' : 'false' ?>"
                aria-controls="<?= h($marketId) ?>">
          <span class="prospect-market-title"><?= h($regionLabel) ?></span>
          <span class="prospect-market-meta muted">
            <?= count($list) ?> countr<?= count($list) === 1 ? 'y' : 'ies' ?>
            · <?= (int) $marketTotal ?> site<?= (int) $marketTotal === 1 ? '' : 's' ?>
          </span>
          <span class="prospect-market-chevron" aria-hidden="true"></span>
        </button>
        <div class="prospect-market-body" id="<?= h($marketId) ?>" <?= $openByDefault ? '' : 'hidden' ?>>
          <div class="folders" style="margin-top:0.7rem">
            <?php foreach ($list as $f):
                $href = $f['country'] !== '' ? $f['country'] : '_none';
                $label = (string) ($f['display_label'] ?? ($f['country'] !== '' ? $f['country'] : 'No country'));
                $searchHay = mb_strtolower(trim(
                    $label . ' '
                    . (string) ($f['country'] ?? '') . ' '
                    . (string) ($f['code'] ?? '') . ' '
                    . (string) ($f['language'] ?? '') . ' '
                    . (string) $regionLabel . ' '
                    . (int) $f['total'] . ' sites'
                ));
                ?>
              <a class="folder"
                 href="index.php?page=admin_prospects&amp;country=<?= urlencode($href) ?>"
                 data-prospect-country
                 data-search="<?= h($searchHay) ?>"
                 title="<?= h((string) ($f['country'] !== '' ? $f['country'] : 'No country')) ?>">
                <h3>
                  <span class="prospect-folder-count"><?= (int) $f['total'] ?></span>
                  <span class="prospect-folder-label"><?= h($label) ?></span>
                </h3>
                <p class="muted">
                  no. of sites<?= $f['language'] !== '' ? ' · ' . h($f['language']) : '' ?>
                </p>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="help sheet-search-empty" data-prospect-country-empty hidden>No countries in this market match.</p>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="help sheet-search-empty" data-prospect-country-search-empty hidden style="margin-top:0.75rem">
      No countries match your search.
    </p>
    <script>
    (function () {
      var searchInput = document.getElementById('prospect-country-search');
      var matchCards = [];
      var matchIndex = -1;
      var meta = document.querySelector('[data-prospect-country-search-meta]');
      var emptyAll = document.querySelector('[data-prospect-country-search-empty]');

      function clearHits() {
        document.querySelectorAll('[data-prospect-country].sheet-search-hit').forEach(function (el) {
          el.classList.remove('sheet-search-hit');
        });
      }

      function setMarketOpen(market, open) {
        if (!market) return;
        market.classList.toggle('is-open', !!open);
        var body = market.querySelector('.prospect-market-body');
        var btn = market.querySelector('[data-prospect-market-toggle]');
        if (body) body.hidden = !open;
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      document.querySelectorAll('[data-prospect-market-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var market = btn.closest('[data-prospect-market]');
          if (!market) return;
          setMarketOpen(market, !market.classList.contains('is-open'));
        });
      });

      function filterCountries() {
        if (!searchInput) return;
        var q = String(searchInput.value || '').trim().toLowerCase();
        matchCards = [];
        clearHits();
        var anyShown = 0;

        document.querySelectorAll('[data-prospect-market]').forEach(function (market) {
          var shownInMarket = 0;
          market.querySelectorAll('[data-prospect-country]').forEach(function (card) {
            var hay = String(card.getAttribute('data-search') || '');
            var hit = !q || hay.indexOf(q) !== -1;
            card.hidden = !hit;
            if (hit) {
              shownInMarket++;
              anyShown++;
              if (q) matchCards.push(card);
            }
          });
          var empty = market.querySelector('[data-prospect-country-empty]');
          if (empty) empty.hidden = !(q && shownInMarket === 0);
          if (q) {
            if (shownInMarket > 0) setMarketOpen(market, true);
            market.hidden = shownInMarket === 0;
          } else {
            market.hidden = false;
          }
        });

        if (emptyAll) emptyAll.hidden = !(q && anyShown === 0);
        if (meta) {
          if (!q) {
            meta.hidden = true;
            meta.textContent = '';
            matchIndex = -1;
            return;
          }
          meta.hidden = false;
          meta.textContent = !matchCards.length
            ? '0 · Enter = next'
            : (matchIndex >= 0
              ? (matchIndex + 1) + ' of ' + matchCards.length + ' · Enter = next'
              : matchCards.length + ' matches · Enter = next');
        }
      }

      function jump(dir) {
        if (!searchInput || !String(searchInput.value || '').trim()) return;
        filterCountries();
        if (!matchCards.length) return;
        matchIndex = matchIndex < 0
          ? (dir > 0 ? 0 : matchCards.length - 1)
          : (matchIndex + dir + matchCards.length) % matchCards.length;
        var card = matchCards[matchIndex];
        clearHits();
        card.classList.add('sheet-search-hit');
        var market = card.closest('[data-prospect-market]');
        setMarketOpen(market, true);
        card.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (meta) meta.textContent = (matchIndex + 1) + ' of ' + matchCards.length + ' · Enter = next';
      }

      if (searchInput) {
        searchInput.addEventListener('input', function () {
          matchIndex = -1;
          filterCountries();
        });
        searchInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            jump(e.shiftKey ? -1 : 1);
          }
        });
      }
    })();
    </script>
    <?php else: ?>
      <div class="card empty-state"><p>No countries configured. Run upgrade.php once.</p></div>
    <?php endif; ?>
    <?php
    render_footer('admin');
    return;
}

// --- One country database ---
$countryName = $emptyCountry ? '' : $sheet;
$q = trim((string) get('q'));
$status = (string) get('status');
$pageNum = max(1, (int) get('p', 1));
$inv = prospect_inventory_query([
    'q' => $q,
    'country' => $countryName,
    'status' => $status,
] + ($emptyCountry ? [] : []), $pageNum, 50);

if ($emptyCountry) {
    $where = ["TRIM(p.country)=''"];
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.domain LIKE ? OR p.url LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM prospect_sites p WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $offset = ($pageNum - 1) * 50;
    $stmt = db()->prepare(
        "SELECT p.*, u.username added_by_name, u.full_name added_by_full
         FROM prospect_sites p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE $whereSql ORDER BY p.created_at DESC LIMIT 50 OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $rows = $inv['rows'];
    $total = $inv['total'];
    $pages = $inv['pages'];
}

$sheetLabel = $emptyCountry ? 'No country' : $countryName;
$qs = http_build_query(array_filter([
    'page' => 'admin_prospects',
    'country' => $emptyCountry ? '_none' : $countryName,
    'q' => $q,
    'status' => $status,
], static fn ($v) => $v !== '' && $v !== null));

if (!$emptyCountry && $addCountry === '') {
    $addCountry = $countryName;
}
if (!$emptyCountry && $addLanguage === '') {
    $canonLang = resolve_canonical_country($countryName);
    $addLanguage = $canonLang ? $canonLang['language'] : '';
}

render_header('Our database · ' . $sheetLabel, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Our database', 'href' => 'index.php?page=admin_prospects'],
    ['label' => $sheetLabel],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($sheetLabel) ?></h1>
    <p class="muted"><?= (int) $total ?> URL<?= (int) $total === 1 ? '' : 's' ?> in this country’s database</p>
  </div>
  <div class="actions">
    <?php if (!$emptyCountry): ?>
      <a class="btn" href="#add-sites">Add sites</a>
    <?php endif; ?>
    <a class="btn secondary" href="index.php?page=admin_prospects">All countries</a>
  </div>
</div>

<?php if (!$emptyCountry): ?>
<div class="card" id="add-sites">
  <h2>Add sites to <?= h($countryName) ?></h2>
  <p class="help">Paste root domains into this country’s Our database folder. Use Clean errors for https/paths/subdomains.</p>
  <form method="post" action="index.php?page=admin_prospects&amp;country=<?= urlencode($countryName) ?>#add-sites">
    <input type="hidden" name="action" value="add_sites">
    <input type="hidden" name="country" value="<?= h($countryName) ?>">
    <div class="form-grid">
      <?= render_language_typeahead($addLanguage, ['id' => 'add_language']) ?>
    </div>
    <div style="margin-top:0.9rem">
      <?= render_domains_paste_field('urls', $addRaw, [
          'id' => 'urls',
          'label' => 'Sites (root domains)',
          'required' => true,
          'rows' => 10,
      ]) ?>
    </div>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Save to <?= h($countryName) ?></button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>
<?php endif; ?>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_prospects">
  <input type="hidden" name="country" value="<?= h($emptyCountry ? '_none' : $countryName) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="domain or url…"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (prospect_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <table>
    <thead><tr><th>Domain</th><th>URL</th><th>Language</th><th>Status</th><th>Added by</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><strong><?= h($s['domain']) ?></strong></td>
        <td class="help"><?= h($s['url'] !== '' ? $s['url'] : '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= badge($s['status']) ?></td>
        <td><?= h($s['added_by_full'] ?: $s['added_by_name'] ?: '—') ?></td>
        <td><?= h(substr((string) $s['created_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <p>No sites in this country yet.</p>
      <?php if (!$emptyCountry): ?>
        <a class="btn" href="#add-sites">Add sites above</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="actions" style="margin-top:0.8rem">
      <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
      <span>Page <?= $pageNum ?> / <?= $pages ?></span>
      <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!$emptyCountry): ?>
<div class="card" id="remove-by-list" style="margin-top:1rem">
  <h2>Remove by list</h2>
  <p class="help">
    Paste site names (or upload a 1-column CSV) to remove those exact domains from
    <strong><?= h($countryName) ?></strong> in Our database.
  </p>
  <form
    method="post"
    action="index.php?page=admin_prospects&amp;country=<?= urlencode($countryName) ?>#remove-by-list"
    enctype="multipart/form-data"
    onsubmit="return confirm('Remove all matching sites from this list in <?= h($countryName) ?> (Our database)?');"
  >
    <input type="hidden" name="action" value="remove_list">
    <input type="hidden" name="country" value="<?= h($countryName) ?>">
    <textarea name="remove_text" class="inventory-box" rows="8" placeholder="site-to-remove.com"></textarea>
    <label style="display:block;margin-top:0.6rem">CSV (1 column)</label>
    <input type="file" name="remove_csv" accept=".csv,text/csv,text/plain,.txt">
    <p class="help">One site name per row. Only domains already in this country folder are removed.</p>
    <div class="actions" style="margin-top:0.75rem">
      <button class="btn danger" type="submit">Remove listed sites</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
