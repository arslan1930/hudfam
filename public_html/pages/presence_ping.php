<?php
/**
 * Heartbeat endpoint for task presence chips.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required.', 'others' => [], 'count' => 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.', 'others' => [], 'count' => 0]);
    exit;
}

$taskKey = trim((string) ($_POST['task_key'] ?? ''));
if ($taskKey === '') {
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $taskKey = trim((string) ($json['task_key'] ?? ''));
        }
    }
}

$result = ping_task_presence($taskKey, $user);
if (empty($result['ok'])) {
    http_response_code(400);
}
echo json_encode($result);
exit;
