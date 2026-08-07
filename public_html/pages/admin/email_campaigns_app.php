<?php
/**
 * Admin · Emails data · Email campaign data
 * One editable sheet per country (site names + emails).
 * Always connected to Communication Team super search.
 *
 * Expects: $user, $base (Emails data hub URL)
 */
ensure_email_campaign_schema();
ensure_sites_with_emails_schema();
seed_countries_if_empty(db());

$campBase = $base . '&folder=email_campaigns';
$sheetId = isset($sheetId) ? (int) $sheetId : (int) get('sheet');
$countryParam = (string) get('country');

// Open by country name shortcut
if ($sheetId < 1 && $countryParam !== '') {
    $byCountry = get_email_campaign_sheet_by_country($countryParam);
    if ($byCountry) {
        redirect($campBase . '&sheet=' . (int) $byCountry['id']);
    }
}

// --- Sheet detail (editable grid for one country) ---
if ($sheetId > 0) {
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        flash('error', 'Email sheet not found.');
        redirect($campBase);
    }
    $sheetCountry = email_campaign_sheet_country($sheet);
    $canon = resolve_canonical_country($sheetCountry);
    if ($canon) {
        $sheetCountry = $canon['name'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $back = $campBase . '&sheet=' . $sheetId;
        try {
            if ($action === 'save_sheet') {
                $result = save_email_campaign_sheet_grid(
                    $sheetId,
                    (array) ($_POST['row_id'] ?? []),
                    (array) ($_POST['domain'] ?? []),
                    (array) ($_POST['email1'] ?? []),
                    (array) ($_POST['email2'] ?? []),
                    (array) ($_POST['email3'] ?? []),
                    (array) ($_POST['email4'] ?? [])
                );
                $msg = 'Saved ' . (int) $result['saved'] . ' row' . ((int) $result['saved'] === 1 ? '' : 's')
                    . ' for ' . $sheetCountry . '.';
                if ($result['errors'] !== []) {
                    $msg .= ' Some rows skipped: ' . implode('; ', array_slice($result['errors'], 0, 5));
                    flash('error', $msg);
                } else {
                    flash('ok', $msg . ' Available in Communication Team super search.');
                }
                redirect($back);
            }
            if ($action === 'add_row') {
                save_email_campaign_sheet_grid(
                    $sheetId,
                    (array) ($_POST['row_id'] ?? []),
                    (array) ($_POST['domain'] ?? []),
                    (array) ($_POST['email1'] ?? []),
                    (array) ($_POST['email2'] ?? []),
                    (array) ($_POST['email3'] ?? []),
                    (array) ($_POST['email4'] ?? [])
                );
                add_blank_email_campaign_rows($sheetId, 1);
                flash('ok', 'Row added.');
                redirect($back . '#sheet-bottom');
            }
            if ($action === 'delete_row') {
                save_email_campaign_sheet_grid(
                    $sheetId,
                    (array) ($_POST['row_id'] ?? []),
                    (array) ($_POST['domain'] ?? []),
                    (array) ($_POST['email1'] ?? []),
                    (array) ($_POST['email2'] ?? []),
                    (array) ($_POST['email3'] ?? []),
                    (array) ($_POST['email4'] ?? [])
                );
                $rid = (int) post('delete_row_id');
                $del = delete_email_campaign_row($sheetId, $rid);
                flash($del['ok'] ? 'ok' : 'error', $del['ok']
                    ? 'Removed row' . (isset($del['domain']) && !str_starts_with((string) $del['domain'], '__blank_')
                        ? ' for ' . $del['domain'] : '') . '.'
                    : (string) ($del['error'] ?? 'Could not remove row.'));
                redirect($back);
            }
            if ($action === 'paste') {
                $result = paste_email_campaign_rows($sheetId, (string) post('paste_text'));
                $msg = 'Pasted into ' . $sheetCountry . ': '
                    . (int) $result['added'] . ' new, ' . (int) $result['updated'] . ' updated.';
                if ($result['errors'] !== []) {
                    $msg .= ' Issues: ' . implode('; ', array_slice($result['errors'], 0, 5));
                    flash('error', $msg);
                } else {
                    flash('ok', $msg);
                }
                redirect($back);
            }
            if ($action === 'import') {
                $source = (string) post('source') === 'admin' ? 'admin' : 'admin_all';
                $result = import_email_campaign_sheet_from_swe($sheetId, $source, $sheetCountry);
                $label = $source === 'admin' ? 'Sites with emails - Admin' : 'All sites with emails - Final';
                flash(
                    'ok',
                    'Imported ' . $sheetCountry . ' from ' . $label . ': '
                    . (int) $result['imported'] . ' new, '
                    . (int) $result['updated'] . ' updated.'
                );
                redirect($back);
            }
            if ($action === 'delete_sheet') {
                delete_email_campaign_sheet($sheetId);
                flash('ok', 'Deleted email sheet for ' . $sheetCountry . '.');
                redirect($campBase);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
    }

    $rows = list_email_campaign_rows($sheetId);
    if ($rows === []) {
        add_blank_email_campaign_rows($sheetId, 8);
        $rows = list_email_campaign_rows($sheetId);
    }
    $filledCount = 0;
    foreach ($rows as $r) {
        if (!str_starts_with((string) $r['domain'], '__blank_')) {
            $filledCount++;
        }
    }
    $formAction = $campBase . '&sheet=' . $sheetId;

    render_header('Email sheet · ' . $sheetCountry, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails data', 'href' => $base],
        ['label' => 'Email campaign data', 'href' => $campBase],
        ['label' => $sheetCountry],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= label_with_info($sheetCountry, 'Country Email Sheet: enter site names with emails next to them. Communication Team finds these rows in the campaign super search.') ?></h1>
        <p class="muted">
          Country email sheet · site names + emails ·
          Communication Team super search ·
          <?= (int) $filledCount ?> site<?= (int) $filledCount === 1 ? '' : 's' ?>
          <?php if ($canon): ?>
            · <?= h((string) $canon['language']) ?> · <?= h((string) $canon['region']) ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?= h($campBase) ?>">All countries</a>
      </div>
    </div>

    <div class="card">
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0"><?= label_with_info('Sites · Emails', 'Each row is a site name with up to 4 emails. Save the sheet so Communication Team can search and update these rows.') ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            Enter site names with emails next to them for <strong><?= h($sheetCountry) ?></strong>.
            Save — Communication Team can find them in the all-countries super search.
          </p>
        </div>
      </div>

      <form method="post" action="<?= h($formAction) ?>" id="camp-sheet-form" autocomplete="off">
        <input type="hidden" name="action" value="save_sheet" id="camp-sheet-action">
        <input type="hidden" name="delete_row_id" id="camp-delete-row-id" value="">
        <div class="table-wrap">
          <table class="camp-sheet-table" id="camp-sheet-table">
            <thead>
              <tr>
                <th class="camp-col-site">Site name</th>
                <th>Email 1</th>
                <th>Email 2</th>
                <th>Email 3</th>
                <th>Email 4</th>
                <th class="camp-col-actions"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $rid = (int) $r['id'];
                $domain = (string) $r['domain'];
                if (str_starts_with($domain, '__blank_')) {
                    $domain = '';
                }
                ?>
              <tr>
                <td>
                  <input type="hidden" name="row_id[]" value="<?= $rid ?>">
                  <input class="camp-cell" name="domain[]" value="<?= h($domain) ?>"
                         placeholder="example.com" spellcheck="false" autocomplete="off">
                </td>
                <td><input class="camp-cell" type="text" inputmode="email" name="email1[]"
                           value="<?= h((string) $r['email1']) ?>" placeholder="email@" spellcheck="false" autocomplete="off"></td>
                <td><input class="camp-cell" type="text" inputmode="email" name="email2[]"
                           value="<?= h((string) $r['email2']) ?>" spellcheck="false" autocomplete="off"></td>
                <td><input class="camp-cell" type="text" inputmode="email" name="email3[]"
                           value="<?= h((string) $r['email3']) ?>" spellcheck="false" autocomplete="off"></td>
                <td><input class="camp-cell" type="text" inputmode="email" name="email4[]"
                           value="<?= h((string) $r['email4']) ?>" spellcheck="false" autocomplete="off"></td>
                <td class="camp-col-actions">
                  <button type="submit" class="btn secondary small"
                          onclick="document.getElementById('camp-sheet-action').value='delete_row';document.getElementById('camp-delete-row-id').value='<?= $rid ?>';return confirm('Remove this row?');">
                    Remove
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="actions" style="margin-top:0.85rem;flex-wrap:wrap;gap:0.5rem" id="sheet-bottom">
          <button class="btn" type="submit"
                  onclick="document.getElementById('camp-sheet-action').value='save_sheet';document.getElementById('camp-delete-row-id').value='';">
            Save sheet
          </button>
          <button class="btn secondary" type="submit"
                  onclick="document.getElementById('camp-sheet-action').value='add_row';document.getElementById('camp-delete-row-id').value='';">
            Add row
          </button>
        </div>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Paste site + emails', 'Paste many lines at once. Format: site.com, email1, email2 (commas, tabs, or spaces).') ?></h2>
      <p class="help">One line per site for <strong><?= h($sheetCountry) ?></strong>: <code>site.com, email1@x.com</code></p>
      <form method="post" action="<?= h($formAction) ?>">
        <input type="hidden" name="action" value="paste">
        <textarea name="paste_text" class="inventory-box" rows="6"
                  placeholder="example.com, hello@example.com&#10;other.org contact@other.org"></textarea>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Add pasted rows</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Import ' . $sheetCountry . ' from archive (optional)', 'Copies only this country’s rows from Sites with emails - Final or Admin into this campaign sheet. Archives are not changed.') ?></h2>
      <p class="help">Copies only this country’s rows from Final / Admin into this sheet.</p>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Import <?= h($sheetCountry) ?> into this sheet?');">
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Source</label>
        <select id="camp_import_source" name="source">
          <option value="admin_all">All sites with emails - Final</option>
          <option value="admin">Sites with emails - Admin</option>
        </select>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn secondary" type="submit">Import country into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2><?= label_with_info('Communication Team', 'Always connected: this country sheet appears in their campaign super search. Deletes there update this sheet.') ?></h2>
      <p class="help">
        This country sheet is included in the <strong>Communication Team super search</strong>
        (all countries). Their remove/update actions change rows in <strong><?= h($sheetCountry) ?></strong>.
      </p>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Danger zone</h2>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Delete the <?= h($sheetCountry) ?> email sheet and all its rows?');">
        <input type="hidden" name="action" value="delete_sheet">
        <button class="btn danger" type="submit">Delete country sheet</button>
      </form>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- List + create by country ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'create') {
            $id = create_email_campaign_sheet((string) post('country'), (int) ($user['id'] ?? 0));
            $sheet = get_email_campaign_sheet($id);
            $countryName = $sheet ? email_campaign_sheet_country($sheet) : (string) post('country');
            if (count_email_campaign_rows($id) < 1) {
                add_blank_email_campaign_rows($id, 8);
            }
            flash('ok', 'Email sheet ready for ' . $countryName . '. Connected to Communication Team super search.');
            redirect($campBase . '&sheet=' . $id);
        }
        if ($action === 'delete') {
            $id = (int) post('id');
            $sheet = get_email_campaign_sheet($id);
            if (!$sheet) {
                flash('error', 'Sheet not found.');
            } else {
                $countryName = email_campaign_sheet_country($sheet);
                delete_email_campaign_sheet($id);
                flash('ok', 'Deleted email sheet for ' . $countryName . '.');
            }
            redirect($campBase);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($campBase);
    }
}

$sheets = list_email_campaign_sheets();
$existingCountries = [];
foreach ($sheets as $s) {
    $existingCountries[mb_strtolower((string) $s['country'])] = true;
}
$allCountries = list_countries(null, true);
$availableCountries = [];
foreach ($allCountries as $c) {
    $name = (string) $c['name'];
    if (!isset($existingCountries[mb_strtolower($name)])) {
        $availableCountries[] = $c;
    }
}

render_header('Email campaign data', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Emails data', 'href' => $base],
    ['label' => 'Email campaign data'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Email campaign data', 'One Email Sheet per country. Fill site names + emails; Communication Team searches all countries in one bar.') ?></h1>
    <p class="muted">
      One Email Sheet per country (site names + emails).
      Communication Team searches all countries in one super search bar.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="<?= h($base) ?>">All folders</a>
  </div>
</div>

<div class="orders-layout">
  <section class="card">
    <h2><?= label_with_info('Country sheets', 'Open a country to edit its site + email rows. Each country has one sheet.') ?></h2>
    <?php if (!$sheets): ?>
      <div class="empty-state">
        <p>No country sheets yet.</p>
        <p class="muted">Create an Email Sheet for a country on the right, then fill site names + emails.</p>
      </div>
    <?php else: ?>
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <label class="sheet-search" for="camp-country-search">
          <span class="visually-hidden">Search countries</span>
          <input id="camp-country-search" type="search" placeholder="Search countries…"
                 autocomplete="off" spellcheck="false" data-no-draft>
        </label>
      </div>
      <table class="extracted-country-table" id="camp-country-table">
        <thead>
          <tr>
            <th>Country</th>
            <th class="num">Sites</th>
            <th class="num">With emails</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sheets as $s):
            $cName = (string) $s['country'];
            $hay = mb_strtolower($cName . ' ' . (string) $s['language'] . ' ' . (string) $s['region']);
            ?>
          <tr data-camp-country-row data-search="<?= h($hay) ?>">
            <td>
              <a class="extracted-country-link" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">
                <?= h($cName) ?>
              </a>
            </td>
            <td class="num"><?= (int) $s['row_count'] ?></td>
            <td class="num muted"><?= (int) $s['with_emails'] ?></td>
            <td class="num">
              <a class="btn small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open</a>
              <form method="post" action="<?= h($campBase) ?>" style="display:inline"
                    onsubmit="return confirm(<?= h(json_encode('Delete sheet for ' . $cName . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <script>
      (function () {
        var input = document.getElementById('camp-country-search');
        if (!input) return;
        input.addEventListener('input', function () {
          var q = String(input.value || '').trim().toLowerCase();
          document.querySelectorAll('[data-camp-country-row]').forEach(function (row) {
            row.hidden = !(!q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1);
          });
        });
      })();
      </script>
    <?php endif; ?>
  </section>

  <section class="card" id="create-email-sheet">
    <h2><?= label_with_info('Create an Email Sheet', 'Pick a country that does not have a sheet yet. It is automatically available to Communication Team search.') ?></h2>
    <p class="muted" style="margin-top:0">
      Pick a country. That country gets its own sheet of site names + emails,
      and joins the Communication Team all-countries super search.
    </p>
    <?php if (!$availableCountries): ?>
      <div class="empty-state">
        <p>Every country already has a sheet.</p>
      </div>
    <?php else: ?>
    <form method="post" action="<?= h($campBase) ?>" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <label for="new_camp_country">Country</label>
      <select id="new_camp_country" name="country" required>
        <option value="">Select country…</option>
        <?php foreach ($availableCountries as $c): ?>
          <option value="<?= h((string) $c['name']) ?>">
            <?= h((string) $c['name']) ?>
            <?php if (trim((string) ($c['default_language'] ?? '')) !== ''): ?>
              · <?= h((string) $c['default_language']) ?>
            <?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="actions" style="margin-top:1rem">
        <button class="btn" type="submit">Create an Email Sheet</button>
      </p>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php
render_footer('admin');
