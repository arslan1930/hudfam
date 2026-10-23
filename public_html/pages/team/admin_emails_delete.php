<?php
/**
 * Team tool: super search Sites with emails - Admin across all countries.
 * Search / browse only — deleting Admin archive rows is Admin-only.
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

// Team must not mutate Admin email archive.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $msg = 'Deleting Admin email archive rows is Admin-only. Ask an Admin to update Emails data.';
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
    flash('error', $msg);
    redirect($base);
}

$inCommunication = function_exists('user_in_communication_team') && user_in_communication_team($user);

render_header('Admin emails search', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Admin emails search'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Admin emails search', 'Super search across every country in Sites with emails - Admin. Results show site + email + country. Deletes are Admin-only (Emails data).') ?></h1>
    <p class="muted">
      Search <strong>Sites with emails - Admin</strong> across <strong>all countries</strong>.
      Results show <strong>site + email + country</strong>.
      To delete a row or remove an email, ask Admin (Emails data).
    </p>
  </div>
  <div class="actions">
    <?php if ($inCommunication): ?>
      <a class="btn secondary" href="index.php?page=team_email_campaigns">Campaign search</a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=team_sites_emails">Sites with emails - Team</a>
    <?php endif; ?>
  </div>
</div>
<?php
render_sites_with_emails_admin_super_search($base, false);
render_footer('team');
