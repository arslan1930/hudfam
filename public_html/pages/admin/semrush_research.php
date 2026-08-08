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
        redirect($dest !== '' ? semrush_sheet_url($dest, false) : $hub);
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
    <h1><?= label_with_info('Semrush Research', 'Seed site names per country for Site Finding. Countries only appear to Team after you add sites. Sheets show site names only — Team can edit, copy, undo/redo, and comment.') ?></h1>
    <p class="muted"><?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?> seeded · site names only</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_semrush_research">Team view</a>
    <a class="btn" href="#add-sites">Add sites</a>
  </div>
</div>

<div class="card" id="add-sites">
  <h2 style="margin:0 0 0.45rem">Add site names</h2>
  <p class="help" style="margin-top:0">
    Pick a country and paste Semrush site names (one per line). That country then appears for Site Finding.
  </p>
  <form method="post" action="<?= h($hub) ?>#add-sites" autocomplete="off"
        data-show-processing="Adding Semrush sites…">
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
  <h2 style="margin:0 0 0.65rem">Seeded countries</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Country</th>
          <th>Sites</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f):
          $c = (string) $f['country'];
          $sheetHref = semrush_sheet_url($c, false);
          ?>
        <tr>
          <td><strong><?= h($c) ?></strong></td>
          <td><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h(substr((string) $f['updated_at'], 0, 16)) ?></td>
          <td class="actions">
            <a class="btn small" href="<?= h($sheetHref) ?>">Open sheet</a>
            <a class="btn secondary small" href="<?= h($hub) ?>&amp;country=<?= rawurlencode($c) ?>#add-sites">Add more</a>
            <form method="post" action="<?= h($hub) ?>" style="display:inline"
                  onsubmit="return confirm('Clear ALL Semrush sites and comments for <?= h($c) ?>?');">
              <input type="hidden" name="action" value="clear_country">
              <input type="hidden" name="country" value="<?= h($c) ?>">
              <button class="btn danger small" type="submit">Clear</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_footer('admin'); ?>
