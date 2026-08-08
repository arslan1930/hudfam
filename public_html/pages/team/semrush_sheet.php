<?php
/**
 * Semrush Research · one country sheet (site names only).
 * Site Finding: edit / copy / cut / undo / redo + comments.
 */
$user = require_team();
ensure_semrush_research_schema();

$country = trim((string) get('country'));
$canon = $country !== '' ? resolve_canonical_country($country) : null;
if (!$canon) {
    flash('error', 'Select a Semrush Research country.');
    redirect(semrush_hub_url(false));
}
$country = $canon['name'];
$isAdmin = is_admin($user);
$base = semrush_sheet_url($country, false);
$hub = semrush_hub_url(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $json = static function (array $payload, int $code = 200) use ($wantsJson, $base): void {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);
            exit;
        }
        if (!empty($payload['ok'])) {
            flash('ok', (string) ($payload['message'] ?? 'Saved.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not complete.'));
        }
        redirect($base);
    };

    if ($action === 'autosave_sites' || $action === 'save_sites') {
        $result = set_semrush_domains_from_text($country, (string) post('sites_text'), $user);
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not save.')], 400);
        }
        $total = (int) ($result['total'] ?? 0);
        // Empty sheet: country disappears from hub (no rows left).
        $json([
            'ok' => true,
            'total' => $total,
            'inserted' => (int) ($result['inserted'] ?? 0),
            'removed' => (int) ($result['removed'] ?? 0),
            'domains' => $result['domains'] ?? [],
            'empty' => $total < 1,
            'message' => 'Saved ' . $total . ' site name' . ($total === 1 ? '' : 's') . '.',
            'redirect' => $total < 1 ? $hub : null,
        ]);
    }

    if ($action === 'add_comment') {
        $result = add_semrush_comment($country, (string) post('body'), $user);
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not add comment.')], 400);
        }
        $json(['ok' => true, 'message' => 'Comment added.', 'id' => (int) ($result['id'] ?? 0)]);
    }

    if ($action === 'delete_comment') {
        $result = delete_semrush_comment((int) post('comment_id'), $user);
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not delete.')], 403);
        }
        $json(['ok' => true, 'message' => 'Comment deleted.']);
    }

    if ($action === 'clear_all') {
        $result = clear_semrush_country($country);
        flash(
            !empty($result['ok']) ? 'ok' : 'error',
            !empty($result['ok'])
                ? ('Cleared Semrush Research for ' . $country
                    . ' (sites + comments). Extracted Sites were not changed.')
                : (string) ($result['error'] ?? 'Could not clear.')
        );
        redirect($hub);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$domains = list_semrush_domains_for_country($country);
$sitesText = implode("\n", $domains);
$comments = list_semrush_comments($country);
$total = count($domains);

// Team: if Admin cleared the country, bounce to hub.
if ($total < 1 && !$isAdmin) {
    flash('error', 'No sites in this Semrush Research country yet.');
    redirect($hub);
}

render_header('Semrush · ' . $country, 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Semrush Research', 'href' => $hub],
    ['label' => $country],
]);
?>
<div class="topbar">
  <div>
    <h1><?= h($country) ?> · Semrush Research</h1>
    <p class="muted">
      <span id="semrush_count_label"><?= (int) $total ?></span> site name<?= $total === 1 ? '' : 's' ?> ·
      from Extracting Results Push (copy) · edit, copy, cut, undo/redo · comments below
    </p>
  </div>
  <div class="actions">
    <?php render_task_presence('semrush:' . $country, 'Others on Semrush · ' . $country); ?>
    <a class="btn secondary" href="<?= h($hub) ?>">All countries</a>
    <?php if ($isAdmin): ?>
      <a class="btn secondary" href="<?= h(semrush_hub_url(true)) ?>">Admin seed</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 style="margin:0 0 0.45rem">Site names</h2>
  <p class="help" style="margin-top:0">
    One site name per line. Changes <strong>autosave</strong>.
    <strong>Undo</strong>/<strong>Redo</strong> work while you stay on this page.
    Cut/copy with your keyboard as usual.
  </p>
  <div
    class="domains-paste"
    id="semrush_sites_shell"
    data-country="<?= h($country) ?>"
    data-post-url="<?= h($base) ?>"
  >
    <div class="domains-paste-head">
      <label for="semrush_sites_text">Sites</label>
      <div class="sites-list-actions">
        <button type="button" class="btn secondary small" id="semrush_undo_btn" disabled>Undo</button>
        <button type="button" class="btn secondary small" id="semrush_redo_btn" disabled>Redo</button>
        <button type="button" class="btn secondary small" id="semrush_copy_all">Copy all</button>
      </div>
    </div>
    <textarea
      id="semrush_sites_text"
      class="inventory-box"
      rows="18"
      spellcheck="false"
      aria-label="Semrush site names"
      data-no-draft
    ><?= h($sitesText) ?></textarea>
    <p class="muted" style="margin:0.35rem 0 0">
      <span id="semrush_footer_count"><?= (int) $total ?> site<?= $total === 1 ? '' : 's' ?></span>
      <span id="semrush_autosave_label" class="help" style="margin-left:0.5rem"></span>
    </p>
    <p class="help" id="semrush_list_status" hidden></p>
  </div>
  <form method="post" action="<?= h($base) ?>" style="margin-top:0.85rem"
        onsubmit="return confirm('Clear ALL site names and comments for <?= h($country) ?>? Extracted Sites stay unchanged.');">
    <input type="hidden" name="action" value="clear_all">
    <button class="btn danger small" type="submit">Clear country</button>
  </form>
</div>

<div class="card" style="margin-top:1rem" id="semrush-comments">
  <h2 style="margin:0 0 0.45rem">Comments</h2>
  <p class="help" style="margin-top:0">Notes for this country sheet (visible to Site Finding + Admin).</p>
  <form method="post" action="<?= h($base) ?>#semrush-comments" class="semrush-comment-form" autocomplete="off">
    <input type="hidden" name="action" value="add_comment">
    <label for="semrush_comment_body">Add comment</label>
    <textarea id="semrush_comment_body" name="body" rows="3" required maxlength="4000"
              placeholder="e.g. Checked top 50 — skip casino / edu…"></textarea>
    <p class="actions" style="margin-top:0.65rem">
      <button class="btn" type="submit">Post comment</button>
    </p>
  </form>

  <?php if ($comments === []): ?>
  <p class="muted" style="margin:0.85rem 0 0">No comments yet.</p>
  <?php else: ?>
  <ul class="semrush-comment-list">
    <?php foreach ($comments as $c):
        $cid = (int) $c['id'];
        $who = trim((string) ($c['full_name'] ?: $c['username'] ?: 'User'));
        $canDel = $isAdmin || ((int) ($c['created_by'] ?? 0) === (int) ($user['id'] ?? 0));
        ?>
      <li>
        <div class="semrush-comment-meta">
          <strong><?= h($who) ?></strong>
          <span class="muted"><?= h(substr((string) $c['created_at'], 0, 16)) ?></span>
        </div>
        <div class="semrush-comment-body"><?= nl2br(h((string) $c['body'])) ?></div>
        <?php if ($canDel): ?>
        <form method="post" action="<?= h($base) ?>#semrush-comments" class="semrush-comment-delete"
              onsubmit="return confirm('Delete this comment?');">
          <input type="hidden" name="action" value="delete_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <button class="btn secondary small" type="submit">Delete</button>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<script src="<?= h(script_asset_url('js/semrush-sheet.js')) ?>" defer></script>
<?php render_footer('team'); ?>
