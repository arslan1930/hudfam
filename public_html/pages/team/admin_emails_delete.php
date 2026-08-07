<?php
/**
 * Team tool: search Sites with emails - Admin and delete site+emails or one email.
 */
$user = require_team();
ensure_sites_with_emails_schema();

$base = 'index.php?page=team_admin_emails_delete';

// JSON suggest
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'suggestions' => search_sites_with_emails_admin_suggestions($q, 20),
    ]);
    exit;
}

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
            flash('ok', (string) ($payload['message'] ?? 'Done.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not complete.'));
        }
        redirect($base);
    };

    if ($action === 'delete_row') {
        $result = delete_sites_with_emails_admin_row((int) post('site_id'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $json([
            'ok' => true,
            'message' => 'Deleted site + emails for ' . (string) $result['domain'] . ' from Sites with emails - Admin.',
            'domain' => (string) $result['domain'],
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        $result = remove_email_from_sites_with_emails_admin(
            (int) post('site_id'),
            (string) post('email')
        );
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not remove email.')], 400);
        }
        $json([
            'ok' => true,
            'message' => 'Removed ' . (string) $result['removed'] . ' from ' . (string) $result['domain'] . '. Site name kept.',
            'domain' => (string) $result['domain'],
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'site_id' => (int) post('site_id'),
            'mode' => 'email',
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

render_header('Delete from Admin emails', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Delete from Admin emails'],
]);
?>
<div class="topbar">
  <div>
    <h1>Delete Site name or Email</h1>
    <p class="muted">
      Search live against <strong>Sites with emails - Admin</strong>.
      Select a match, then delete the whole site row or remove one email only.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
  </div>
</div>

<div class="card swe-admin-delete-card">
  <label class="swe-admin-delete-label" for="swe-admin-delete-q">
    Search site name or email
  </label>
  <div class="swe-admin-delete-search" data-swe-admin-delete
       data-suggest-url="<?= h($base) ?>&amp;ajax=suggest"
       data-post-url="<?= h($base) ?>">
    <input id="swe-admin-delete-q" type="search" class="swe-admin-delete-input"
           placeholder="Type site or email…"
           autocomplete="off" spellcheck="false" data-no-draft
           title="Type to search · Arrow keys · Enter to select">
    <ul class="swe-admin-delete-suggest" data-swe-suggest hidden></ul>
  </div>
  <p class="help" id="swe-admin-delete-status" hidden></p>

  <div class="swe-admin-delete-selected" data-swe-selected hidden>
    <h2 style="margin-top:1rem">Selected</h2>
    <p class="help">Both site name and emails are shown. Choose what to remove from Admin.</p>
    <div class="swe-admin-delete-panel">
      <div>
        <div class="muted" style="font-size:0.82rem">Site name</div>
        <div class="swe-admin-delete-domain" data-swe-sel-domain></div>
        <div class="muted" data-swe-sel-country style="margin-top:0.25rem"></div>
      </div>
      <div>
        <div class="muted" style="font-size:0.82rem;margin-bottom:0.35rem">Emails</div>
        <ul class="swe-admin-delete-emails" data-swe-sel-emails></ul>
        <p class="help" data-swe-no-emails hidden>No emails on this site.</p>
      </div>
    </div>
    <div class="actions" style="margin-top:0.85rem;flex-wrap:wrap;gap:0.5rem">
      <button type="button" class="btn danger" data-swe-delete-row>
        Delete site + all emails
      </button>
      <button type="button" class="btn secondary" data-swe-clear-sel>Clear selection</button>
    </div>
  </div>
</div>

<script src="<?= h(script_asset_url('js/admin-emails-delete.js')) ?>" defer></script>
<?php render_footer('team'); ?>
