<?php
/**
 * Site Finding · Semrush Research — country folders of site names
 * (copied from Extracting Results Push + optional Admin seed).
 */
$user = require_team();
ensure_semrush_research_schema();

$hub = semrush_hub_url(false);
$isAdmin = is_admin($user);
$canClear = team_can_clear_semrush_country($user);
$canFilter = team_page_unlocked($user, 'team_prospect_check');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'clear_country') {
        if (!$canClear) {
            flash('error', 'Clear country is for Site Finding and Admin.');
            redirect($hub);
        }
        $result = clear_semrush_country((string) post('country'));
        flash(
            !empty($result['ok']) ? 'ok' : 'error',
            !empty($result['ok'])
                ? ('Cleared Semrush Research for ' . (string) ($result['country'] ?? '')
                    . ' (sites + comments). Extracted Sites were not changed.')
                : (string) ($result['error'] ?? 'Could not clear.')
        );
        redirect($hub);
    }
    flash('error', 'Unknown action.');
    redirect($hub);
}

$folders = list_semrush_country_rows();

render_header('Semrush Research', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Semrush Research'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Semrush Research', 'Site names copied from Extracting Results Push (same country / TLD routing), plus optional Admin seed. Open a country to edit, copy, undo/redo, or comment. Site Finding and Admin can clear the full country batch. Does not change Extracted Sites.') ?></h1>
    <p class="muted">
      <?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?> with research sites · site names only
    </p>
  </div>
  <div class="actions">
    <?php if ($canFilter): ?>
      <a class="btn secondary" href="index.php?page=team_prospect_check">Filter &amp; add</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a class="btn" href="<?= h(semrush_hub_url(true)) ?>">Admin seed sites</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($folders === []): ?>
<div class="card">
  <div class="empty-state">
    <p>No Semrush Research countries yet.</p>
    <p class="muted">
      When Extracting Results are pushed, a copy of those site names lands here (same country / TLD folders).
      <?php if ($isAdmin): ?>
        You can also <a href="<?= h(semrush_hub_url(true)) ?>">seed sites as Admin</a>.
      <?php endif; ?>
    </p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0">Countries</h2>
  </div>
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
          $href = semrush_sheet_url($c, false);
          ?>
        <tr>
          <td><a href="<?= h($href) ?>"><strong><?= h($c) ?></strong></a></td>
          <td><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h(substr((string) $f['updated_at'], 0, 16)) ?></td>
          <td class="actions">
            <a class="btn small" href="<?= h($href) ?>">Open sheet</a>
            <?php if ($canClear): ?>
            <form method="post" action="<?= h($hub) ?>" style="display:inline"
                  onsubmit="return confirm(<?= h(json_encode('Clear ALL Semrush sites and comments for ' . $c . '? Extracted Sites stay unchanged.', JSON_UNESCAPED_UNICODE)) ?>);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_country">
              <input type="hidden" name="country" value="<?= h($c) ?>">
              <button class="btn danger small" type="submit">Clear country</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
