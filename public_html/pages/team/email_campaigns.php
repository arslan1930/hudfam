<?php
/**
 * Communication Team · one search bar per Admin project (all countries in it).
 */
$user = require_team();
ensure_email_campaign_schema();

if (user_is_department_scoped($user) && !user_in_communication_team($user)) {
    flash('error', 'This tool is for Communication Team members.');
    redirect('index.php?page=team_departments');
}

$base = 'index.php?page=team_email_campaigns';

// JSON suggest — one project (all its countries), or all visible projects
if ((string) get('ajax') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = (string) get('q');
    $projectId = (int) get('project_id');
    $sheetId = (int) get('sheet_id'); // legacy
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
    try {
        if ($projectId > 0) {
            $project = get_email_campaign_project($projectId);
            if (!$project || !email_campaign_project_team_visible($project)) {
                echo json_encode(['ok' => true, 'q' => $q, 'suggestions' => []], $flags);
                exit;
            }
            $suggestions = search_email_campaign_suggestions_for_project($projectId, $q, 25);
        } elseif ($sheetId > 0) {
            $sheet = get_email_campaign_sheet($sheetId);
            if (!$sheet || !email_campaign_sheet_team_visible($sheet)) {
                echo json_encode(['ok' => true, 'q' => $q, 'suggestions' => []], $flags);
                exit;
            }
            $suggestions = search_email_campaign_suggestions($sheetId, $q, 25);
        } else {
            $suggestions = search_email_campaign_suggestions_all($q, 25);
        }
        echo json_encode([
            'ok' => true,
            'q' => $q,
            'suggestions' => $suggestions,
        ], $flags);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'q' => $q, 'suggestions' => [], 'error' => 'Search failed.'], $flags);
    }
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
        $json(['ok' => false, 'error' => 'Project email sheet not found.'], 404);
    }
    if (!email_campaign_sheet_team_visible($sheet)) {
        $json(['ok' => false, 'error' => 'This project search bar is hidden by Admin.'], 403);
    }
    $countryName = email_campaign_sheet_country($sheet);
    $projectName = email_campaign_sheet_project_name($sheet);

    if ($action === 'delete_row') {
        $result = delete_email_campaign_row($sid, $rowId);
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Delete failed.')], 404);
        }
        $json([
            'ok' => true,
            'message' => 'Deleted ' . (string) $result['domain'] . ' from ' . $projectName . '.',
            'domain' => (string) $result['domain'],
            'country' => $countryName,
            'project_name' => $projectName,
            'mode' => 'row',
        ]);
    }

    if ($action === 'delete_email') {
        $result = remove_email_from_email_campaign_row($sid, $rowId, (string) post('email'));
        if (!$result['ok']) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not remove email.')], 400);
        }
        $rowDeleted = !empty($result['row_deleted']);
        $msg = $rowDeleted
            ? 'Removed last email from ' . (string) $result['domain']
                . ' (' . $projectName . ') · site row deleted (no empty-email sites).'
            : 'Removed ' . (string) $result['removed'] . ' from ' . (string) $result['domain']
                . ' (' . $projectName . '). Site name kept.';
        $json([
            'ok' => true,
            'message' => $msg,
            'domain' => (string) $result['domain'],
            'country' => $countryName,
            'project_name' => $projectName,
            'emails' => $result['emails'] ?? [],
            'removed' => (string) ($result['removed'] ?? ''),
            'row_id' => $rowId,
            'sheet_id' => $sid,
            'mode' => $rowDeleted ? 'row' : 'email',
            'row_deleted' => $rowDeleted,
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$visibleCount = count(list_email_campaign_projects(true));

render_header('Campaign search', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Campaign search'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Campaign search', 'One search bar per Admin project shown to Communication Team. Each project can include many countries. Search the whole project, then delete — updates the matching country sheet.') ?></h1>
    <p class="muted">
      <?= (int) $visibleCount ?> project search bar<?= (int) $visibleCount === 1 ? '' : 's' ?>
      from Admin → Emails data → Email campaign data.
      Each bar covers <strong>all countries</strong> Admin added to that project.
      Delete both or remove only email — removing the <strong>last</strong> email also deletes the site row.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_email_campaigns_drafts">Campaign drafts</a>
  </div>
</div>
<?php
render_email_campaign_super_search($base);
render_footer('team');
