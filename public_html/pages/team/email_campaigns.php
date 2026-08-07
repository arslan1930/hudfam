<?php
/**
 * Communication Team · super search across all country email campaign sheets.
 */
$user = require_team();
ensure_email_campaign_schema();

if (user_is_department_scoped($user) && !user_in_communication_team($user)) {
    flash('error', 'This tool is for Communication Team members.');
    redirect('index.php?page=team_departments');
}

$base = 'index.php?page=team_email_campaigns';

// JSON suggest — all countries
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'suggestions' => search_email_campaign_suggestions_all($q, 25),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $sid = (int) post('sheet_id');
    $rowId = (int) post('row_id');
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

    // Resolve sheet from row when sheet_id missing (super search safety).
    if ($sid < 1 && $rowId > 0) {
        $row = get_email_campaign_row($rowId);
        if ($row) {
            $sid = (int) $row['sheet_id'];
        }
    }

    $sheet = $sid > 0 ? get_email_campaign_sheet($sid) : null;
    if (!$sheet) {
        $json(['ok' => false, 'error' => 'Country email sheet not found.'], 404);
    }
    $countryName = email_campaign_sheet_country($sheet);

    if ($action === 'delete_row') {
        $result = delete_email_campaign_row($sid, $rowId);
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $json([
            'ok' => true,
            'message' => 'Deleted ' . (string) $result['domain'] . ' from ' . $countryName . ' sheet.',
            'domain' => (string) $result['domain'],
            'country' => $countryName,
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        $result = remove_email_from_email_campaign_row($sid, $rowId, (string) post('email'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not remove email.')], 400);
        }
        $json([
            'ok' => true,
            'message' => 'Removed ' . (string) $result['removed'] . ' from ' . (string) $result['domain']
                . ' (' . $countryName . '). Site name kept.',
            'domain' => (string) $result['domain'],
            'country' => $countryName,
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'row_id' => $rowId,
            'sheet_id' => $sid,
            'mode' => 'email',
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

render_header('Campaign search', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Campaign search'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Campaign search', 'Searches all country Email Sheets created by Admin. Results show site + email + country. Enter confirms your chosen action on that country sheet row.') ?></h1>
    <p class="muted">
      One super search across <strong>all country sheets</strong> from Admin → Emails data → Email campaign data.
      Results show <strong>site name + email + country</strong>. Choose delete both or remove only email, then press
      <strong>Enter</strong> (confirm first) — the matching country sheet row updates.
    </p>
  </div>
</div>
<?php
render_email_campaign_super_search($base);
render_footer('team');
