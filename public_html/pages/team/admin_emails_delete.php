<?php
/**
 * Team tool: super search Sites with emails - Admin across all countries.
 * Used by Email Extracting and Communication Team.
 */
$user = require_team();
ensure_sites_with_emails_schema();

$base = 'index.php?page=team_admin_emails_delete';

// Allow Communication Team + Email Extracting (and unscoped team / admin).
if (!team_page_unlocked($user, 'team_admin_emails_delete')) {
    flash('error', 'This tool is for Communication Team or Email Extracting.');
    redirect('index.php?page=team_departments');
}

// JSON suggest — all countries
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
    try {
        echo json_encode([
            'ok' => true,
            'q' => $q,
            'suggestions' => search_sites_with_emails_admin_suggestions($q, 25),
        ], $flags);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'q' => $q, 'suggestions' => [], 'error' => 'Search failed.'], $flags);
    }
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
        $siteId = (int) post('site_id');
        $before = get_site_with_emails($siteId, 'admin');
        $result = delete_sites_with_emails_admin_row($siteId);
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $country = $before ? (string) $before['country'] : '';
        $json([
            'ok' => true,
            'message' => 'Deleted ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . ' from Sites with emails - Admin.',
            'domain' => (string) $result['domain'],
            'country' => $country,
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        $siteId = (int) post('site_id');
        $before = get_site_with_emails($siteId, 'admin');
        $result = remove_email_from_sites_with_emails_admin($siteId, (string) post('email'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not remove email.')], 400);
        }
        $country = $before ? (string) $before['country'] : '';
        $rowDeleted = !empty($result['row_deleted']);
        $msg = $rowDeleted
            ? 'Removed last email from ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . ' · Admin working-list row deleted · Final keeps its archive copy.'
            : 'Removed ' . (string) $result['removed'] . ' from ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . '. Site name kept in Admin.';
        $json([
            'ok' => true,
            'message' => $msg,
            'domain' => (string) $result['domain'],
            'country' => $country,
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'site_id' => $siteId,
            'mode' => $rowDeleted ? 'row' : 'email',
            'row_deleted' => $rowDeleted,
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$canSitesEmails = team_page_unlocked($user, 'team_sites_emails');
$canCampaigns = team_page_unlocked($user, 'team_email_campaigns');

render_header('Admin emails search', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Admin emails search'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Admin emails search', 'Super search across every country in Sites with emails - Admin. Results always show site + email + country. Enter confirms delete-both or remove-email-only on that country’s row.') ?></h1>
    <p class="muted">
      Search <strong>Sites with emails - Admin</strong> across <strong>all countries</strong>.
      Results show <strong>site + email + country</strong>.
      Choose delete both or remove only email, then press <strong>Enter</strong> (confirm first).
      Removing the <strong>last</strong> email also deletes the Admin working-list row. Final keeps its archive copy.
    </p>
  </div>
  <div class="actions">
    <?php if ($canSitesEmails): ?>
      <a class="btn secondary" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
    <?php endif; ?>
    <?php if ($canCampaigns): ?>
      <a class="btn secondary" href="index.php?page=team_email_campaigns">Campaign search</a>
    <?php endif; ?>
  </div>
</div>
<?= guide_admin_emails_search() ?>
<?php
render_sites_with_emails_admin_super_search($base);
render_footer('team');
