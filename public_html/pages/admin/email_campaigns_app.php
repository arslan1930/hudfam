<?php
/**
 * Admin · Emails DATA · Email campaign data
 * Create/edit sheets of site names + emails; always connected to Communication Team search.
 *
 * Expects: $user, $base (Emails DATA hub URL)
 */
ensure_email_campaign_schema();
ensure_sites_with_emails_schema();

$campBase = $base . '&folder=email_campaigns';
$sheetId = isset($sheetId) ? (int) $sheetId : (int) get('sheet');

// --- Sheet detail (editable grid) ---
if ($sheetId > 0) {
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        flash('error', 'Email sheet not found.');
        redirect($campBase);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $back = $campBase . '&sheet=' . $sheetId;
        try {
            if ($action === 'rename') {
                rename_email_campaign_sheet($sheetId, (string) post('name'));
                flash('ok', 'Sheet name updated. Communication Team search bar uses this name.');
                redirect($back);
            }
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
                $msg = 'Saved ' . (int) $result['saved'] . ' row' . ((int) $result['saved'] === 1 ? '' : 's') . '.';
                if ($result['errors'] !== []) {
                    $msg .= ' Some rows skipped: ' . implode('; ', array_slice($result['errors'], 0, 5));
                    flash('error', $msg);
                } else {
                    flash('ok', $msg . ' Communication Team can search this sheet now.');
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
                flash('ok', 'Row(s) added.');
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
                $msg = 'Pasted: ' . (int) $result['added'] . ' new, ' . (int) $result['updated'] . ' updated.';
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
                $result = import_email_campaign_sheet_from_swe($sheetId, $source);
                $label = $source === 'admin' ? 'Sites with emails - Admin' : 'All sites with emails - Final';
                flash(
                    'ok',
                    'Imported from ' . $label . ': '
                    . (int) $result['imported'] . ' new, '
                    . (int) $result['updated'] . ' updated.'
                );
                redirect($back);
            }
            if ($action === 'delete_sheet') {
                $name = (string) $sheet['name'];
                delete_email_campaign_sheet($sheetId);
                flash('ok', 'Deleted email sheet “' . $name . '”.');
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
    $sheetName = (string) $sheet['name'];
    $formAction = $campBase . '&sheet=' . $sheetId;

    render_header('Email sheet · ' . $sheetName, 'admin');
    render_breadcrumbs([
        ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
        ['label' => 'Emails DATA', 'href' => $base],
        ['label' => 'Email campaign data', 'href' => $campBase],
        ['label' => $sheetName],
    ]);
    ?>
    <div class="topbar">
      <div>
        <h1><?= h($sheetName) ?></h1>
        <p class="muted">
          Site names + emails · always connected to <strong>Communication Team</strong> search ·
          <?= (int) $filledCount ?> site<?= (int) $filledCount === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?= h($campBase) ?>">All sheets</a>
      </div>
    </div>

    <div class="card">
      <form method="post" action="<?= h($formAction) ?>" autocomplete="off" class="camp-rename-form">
        <input type="hidden" name="action" value="rename">
        <label for="camp_sheet_name">Sheet name</label>
        <div class="camp-rename-row">
          <input id="camp_sheet_name" name="name" required maxlength="180"
                 value="<?= h($sheetName) ?>" autocomplete="off">
          <button class="btn secondary" type="submit">Save name</button>
        </div>
        <p class="help">This name is the Communication Team search bar label.</p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
        <div>
          <h2 style="margin:0">Sites · Emails</h2>
          <p class="help" style="margin:0.25rem 0 0">
            Enter a site name with emails next to it. Save the sheet — Communication Team can search it immediately.
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
      <h2>Paste site + emails</h2>
      <p class="help">One line per site: <code>site.com, email1@x.com, email2@x.com</code> (or spaces/tabs).</p>
      <form method="post" action="<?= h($formAction) ?>">
        <input type="hidden" name="action" value="paste">
        <textarea name="paste_text" class="inventory-box" rows="6"
                  placeholder="example.com, hello@example.com&#10;other.org contact@other.org info@other.org"></textarea>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn" type="submit">Add pasted rows</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Import from archive (optional)</h2>
      <p class="help">Copy from Final / Admin archives into this sheet. Does not change the archives.</p>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Import into “<?= h($sheetName) ?>”? Matching site names will be updated.');">
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Source</label>
        <select id="camp_import_source" name="source">
          <option value="admin_all">All sites with emails - Final</option>
          <option value="admin">Sites with emails - Admin</option>
        </select>
        <p class="actions" style="margin-top:0.75rem">
          <button class="btn secondary" type="submit">Import into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Communication Team</h2>
      <p class="help">
        This sheet is <strong>always connected</strong> to the Communication department.
        Members see a live search bar named <strong><?= h($sheetName) ?></strong> on their login panel.
        They search site or email, see both together, then delete both or remove only an email (confirm first).
      </p>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Danger zone</h2>
      <form method="post" action="<?= h($formAction) ?>"
            onsubmit="return confirm('Delete email sheet “<?= h($sheetName) ?>” and all its rows?');">
        <input type="hidden" name="action" value="delete_sheet">
        <button class="btn danger" type="submit">Delete sheet</button>
      </form>
    </div>
    <?php
    render_footer('admin');
    return;
}

// --- List + create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'create') {
            $id = create_email_campaign_sheet((string) post('name'), (int) ($user['id'] ?? 0));
            add_blank_email_campaign_rows($id, 8);
            flash('ok', 'Email sheet created and connected to Communication Team. Add site names + emails below.');
            redirect($campBase . '&sheet=' . $id);
        }
        if ($action === 'delete') {
            $id = (int) post('id');
            $sheet = get_email_campaign_sheet($id);
            if (!$sheet) {
                flash('error', 'Sheet not found.');
            } else {
                delete_email_campaign_sheet($id);
                flash('ok', 'Deleted email sheet “' . (string) $sheet['name'] . '”.');
            }
            redirect($campBase);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($campBase);
    }
}

$sheets = list_email_campaign_sheets();

render_header('Email campaign data', 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Emails DATA', 'href' => $base],
    ['label' => 'Email campaign data'],
]);
?>
<div class="topbar">
  <div>
    <h1>Email campaign data</h1>
    <p class="muted">
      Create a sheet of site names + emails. It always connects to Communication Team for live search.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="<?= h($base) ?>">All folders</a>
  </div>
</div>

<div class="orders-layout">
  <section class="card">
    <h2>Email sheets</h2>
    <?php if (!$sheets): ?>
      <div class="empty-state">
        <p>No email sheets yet.</p>
        <p class="muted">Create one on the right, fill site names + emails, and Communication Team gets the search bar.</p>
      </div>
    <?php else: ?>
      <ul class="order-client-list">
        <?php foreach ($sheets as $s): ?>
          <li class="order-client-row">
            <div class="order-client-main">
              <a class="order-client-name" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">
                <?= h($s['name']) ?>
              </a>
              <div class="order-client-meta muted">
                <span><?= (int) $s['row_count'] ?> site<?= (int) $s['row_count'] === 1 ? '' : 's' ?></span>
                <span><?= (int) $s['with_emails'] ?> with email<?= (int) $s['with_emails'] === 1 ? '' : 's' ?></span>
                <span>Communication Team</span>
              </div>
            </div>
            <div class="order-client-actions">
              <a class="btn small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open sheet</a>
              <form method="post" action="<?= h($campBase) ?>"
                    onsubmit="return confirm(<?= h(json_encode('Delete sheet “' . $s['name'] . '”?', JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn secondary small" type="submit">Delete</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" id="create-email-sheet">
    <h2>Create an Email Sheet</h2>
    <p class="muted" style="margin-top:0">
      Name it, then fill site names with emails next to them.
      The sheet is automatically connected to the Communication department search panel.
    </p>
    <form method="post" action="<?= h($campBase) ?>" autocomplete="off">
      <input type="hidden" name="action" value="create">
      <label for="new_camp_name">Sheet name</label>
      <input id="new_camp_name" name="name" required maxlength="180"
             placeholder="e.g. March outreach" autocomplete="off">
      <p class="actions" style="margin-top:1rem">
        <button class="btn" type="submit">Create an Email Sheet</button>
      </p>
    </form>
  </section>
</div>
<?php
render_footer('admin');
