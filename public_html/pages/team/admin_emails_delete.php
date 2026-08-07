<?php
/**
 * Team tool: super search Sites with emails - Admin across all countries.
 * Used by Email Extracting and Communication Team.
 */
$user = require_team();
ensure_sites_with_emails_schema();

$base = 'index.php?page=team_admin_emails_delete';

// Allow Communication Team + Email Extracting (and unscoped team / admin).
if (user_is_department_scoped($user)) {
    $ok = false;
    try {
        $ed = get_department_by_slug('email_extracting');
        $cd = get_department_by_slug('communication');
        if ($ed && user_in_department((int) $user['id'], (int) $ed['id'])) {
            $ok = true;
        }
        if ($cd && user_in_department((int) $user['id'], (int) $cd['id'])) {
            $ok = true;
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        flash('error', 'This tool is for Communication Team or Email Extracting.');
        redirect('index.php?page=team_departments');
    }
}

// JSON suggest — all countries
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'suggestions' => search_sites_with_emails_admin_suggestions($q, 25),
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
        $json([
            'ok' => true,
            'message' => 'Removed ' . (string) $result['removed'] . ' from ' . (string) $result['domain']
                . ($country !== '' ? ' (' . $country . ')' : '')
                . '. Site name kept in Admin.',
            'domain' => (string) $result['domain'],
            'country' => $country,
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'site_id' => $siteId,
            'mode' => 'email',
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$inCommunication = function_exists('user_in_communication_team') && user_in_communication_team($user);

render_header('Sites with emails - Admin · search', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Sites with emails - Admin search'],
]);
?>
<div class="topbar">
  <div>
    <h1>Sites with emails - Admin</h1>
    <p class="muted">
      One <strong>super search</strong> across <strong>all countries</strong>.
      Results show <strong>site + email + country</strong>.
      Choose delete both or remove only email, then press <strong>Enter</strong> (confirm first) —
      the matching country row in Sites with emails - Admin updates.
    </p>
  </div>
  <div class="actions">
    <?php if ($inCommunication): ?>
      <a class="btn secondary" href="index.php?page=team_email_campaigns">Email campaign search</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
    <?php endif; ?>
  </div>
</div>
<?php
render_sites_with_emails_admin_super_search($base);
render_footer('team');
