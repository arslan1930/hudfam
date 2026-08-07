<?php
/**
 * Admin · Emails DATA · Email campaign data
 * Expects: $user, $base (Emails DATA hub URL), optional $sheetId
 */
ensure_email_campaign_schema();
ensure_sites_with_emails_schema();

$campBase = $base . '&folder=email_campaigns';
$sheetId = isset($sheetId) ? (int) $sheetId : (int) get('sheet');

// --- Sheet detail ---
if ($sheetId > 0) {
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        flash('error', 'Email sheet not found.');
        redirect($campBase);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        try {
            if ($action === 'rename') {
                rename_email_campaign_sheet($sheetId, (string) post('name'));
                flash('ok', 'Sheet name updated.');
                redirect($campBase . '&sheet=' . $sheetId);
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
                redirect($campBase . '&sheet=' . $sheetId);
            }
            if ($action === 'delete_sheet') {
                $name = (string) $sheet['name'];
                delete_email_campaign_sheet($sheetId);
                flash('ok', 'Deleted email sheet “' . $name . '”.');
                redirect($campBase);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect($campBase . '&sheet=' . $sheetId);
        }
    }

    $rowCount = count_email_campaign_rows($sheetId);
    $sheetName = (string) $sheet['name'];

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
          Email sheet for Communication Team search ·
          <?= (int) $rowCount ?> site<?= (int) $rowCount === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?= h($campBase) ?>">All sheets</a>
      </div>
    </div>

    <div class="card">
      <h2>Sheet name</h2>
      <p class="help">This name appears as a search bar on the Communication Team login panel.</p>
      <form method="post" action="<?= h($campBase) ?>&amp;sheet=<?= (int) $sheetId ?>" autocomplete="off">
        <input type="hidden" name="action" value="rename">
        <label for="camp_sheet_name">Name</label>
        <input id="camp_sheet_name" name="name" required maxlength="180"
               value="<?= h($sheetName) ?>" autocomplete="off">
        <p class="actions" style="margin-top:0.85rem">
          <button class="btn" type="submit">Save name</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Fill sheet</h2>
      <p class="help">
        Copy site names + emails into this sheet so Communication Team can search and update them.
        Import does not change the original Admin / Final archives.
      </p>
      <form method="post" action="<?= h($campBase) ?>&amp;sheet=<?= (int) $sheetId ?>"
            onsubmit="return confirm('Import sites + emails into “<?= h($sheetName) ?>”? Existing matching site names in this sheet will be updated.');">
        <input type="hidden" name="action" value="import">
        <label for="camp_import_source">Import from</label>
        <select id="camp_import_source" name="source">
          <option value="admin_all">All sites with emails - Final</option>
          <option value="admin">Sites with emails - Admin</option>
        </select>
        <p class="actions" style="margin-top:0.85rem">
          <button class="btn" type="submit">Import into sheet</button>
        </p>
      </form>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Communication Team</h2>
      <p class="help">
        Members of <strong>Communication Team</strong> see a live search bar named
        <strong><?= h($sheetName) ?></strong> on their panel. They can search site or email,
        then delete both or remove only an email (with confirmation).
      </p>
    </div>

    <div class="card" style="margin-top:1rem">
      <h2>Danger zone</h2>
      <form method="post" action="<?= h($campBase) ?>&amp;sheet=<?= (int) $sheetId ?>"
            onsubmit="return confirm('Delete email sheet “<?= h($sheetName) ?>” and all its rows? Communication Team will lose this search bar.');">
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
            flash('ok', 'Email sheet created. It now appears for Communication Team.');
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
      Create named email sheets. Each sheet adds a live search bar for Communication Team.
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
        <p class="muted">Create one on the right — Communication Team will get a search bar with that name.</p>
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
              </div>
            </div>
            <div class="order-client-actions">
              <a class="btn small" href="<?= h($campBase) ?>&amp;sheet=<?= (int) $s['id'] ?>">Open</a>
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
      Name the sheet, then import sites + emails. Communication Team sees this name as their search bar.
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
