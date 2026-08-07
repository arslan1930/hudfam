<?php
/**
 * Communication Team · live search on Admin-created email campaign sheets.
 */
$user = require_team();
ensure_email_campaign_schema();

if (user_is_department_scoped($user) && !user_in_communication_team($user)) {
    flash('error', 'This tool is for Communication Team members.');
    redirect('index.php?page=team_departments');
}

$base = 'index.php?page=team_email_campaigns';
$sheetId = (int) get('sheet');

// JSON suggest
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $sid = (int) get('sheet');
    $q = (string) get('q');
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'sheet' => $sid,
        'suggestions' => search_email_campaign_suggestions($sid, $q, 20),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $sid = (int) post('sheet_id');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $json = static function (array $payload, int $code = 200) use ($wantsJson, $base, $sid): void {
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
        redirect($base . ($sid > 0 ? '&sheet=' . $sid : ''));
    };

    if (!get_email_campaign_sheet($sid)) {
        $json(['ok' => false, 'error' => 'Email sheet not found.'], 404);
    }

    if ($action === 'delete_row') {
        $result = delete_email_campaign_row($sid, (int) post('row_id'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $json([
            'ok' => true,
            'message' => 'Deleted site + emails for ' . (string) $result['domain'] . '.',
            'domain' => (string) $result['domain'],
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        $result = remove_email_from_email_campaign_row(
            $sid,
            (int) post('row_id'),
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
            'row_id' => (int) post('row_id'),
            'mode' => 'email',
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

if ($sheetId > 0 && !get_email_campaign_sheet($sheetId)) {
    flash('error', 'Email sheet not found.');
    redirect($base);
}

render_header('Email campaign sheets', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Email campaign sheets'],
]);
?>
<div class="topbar">
  <div>
    <h1>Email campaign sheets</h1>
    <p class="muted">
      Searchbars come from Admin → Emails DATA → Email campaign data.
      Results always show <strong>site name + email</strong> together.
      Choose delete both or remove only email, then press <strong>Enter</strong> (confirm first).
    </p>
  </div>
</div>
<?php
render_email_campaign_search_panels($sheetId > 0 ? $sheetId : null, $base);
if ($sheetId > 0) {
    echo '<p class="actions"><a class="btn secondary" href="' . h($base) . '">Show all sheets</a></p>';
}
render_footer('team');
