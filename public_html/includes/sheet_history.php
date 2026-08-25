<?php
/**
 * Session undo/redo for sheet row removes, plus Select all / Remove selected toolbar.
 */

function parse_posted_id_list($value, int $max = 2000): array
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[\s,]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $ids = [];
    foreach ($parts as $part) {
        $n = (int) $part;
        if ($n > 0) {
            $ids[$n] = $n;
        }
        if (count($ids) >= $max) {
            break;
        }
    }
    return array_values($ids);
}

function sheet_history_key(string $kind, string $id): string
{
    $kind = preg_replace('/[^a-z0-9_]+/i', '', $kind) ?: 'sheet';
    $id = preg_replace('/[^a-z0-9:_-]+/i', '', $id) ?: '0';
    return $kind . ':' . $id;
}

/**
 * @return array{undo:list<array<string,mixed>>,redo:list<array<string,mixed>>}
 */
function &sheet_history_bucket(string $key): array
{
    if (!isset($_SESSION['sheet_history']) || !is_array($_SESSION['sheet_history'])) {
        $_SESSION['sheet_history'] = [];
    }
    if (!isset($_SESSION['sheet_history'][$key]) || !is_array($_SESSION['sheet_history'][$key])) {
        $_SESSION['sheet_history'][$key] = ['undo' => [], 'redo' => []];
    }
    if (!isset($_SESSION['sheet_history'][$key]['undo']) || !is_array($_SESSION['sheet_history'][$key]['undo'])) {
        $_SESSION['sheet_history'][$key]['undo'] = [];
    }
    if (!isset($_SESSION['sheet_history'][$key]['redo']) || !is_array($_SESSION['sheet_history'][$key]['redo'])) {
        $_SESSION['sheet_history'][$key]['redo'] = [];
    }
    return $_SESSION['sheet_history'][$key];
}

/**
 * @return array{can_undo:bool,can_redo:bool}
 */
function sheet_history_state(string $key): array
{
    $bucket = sheet_history_bucket($key);
    return [
        'can_undo' => $bucket['undo'] !== [],
        'can_redo' => $bucket['redo'] !== [],
    ];
}

/**
 * @param array<string,mixed> $entry
 */
function sheet_history_push(string $key, array $entry): void
{
    $bucket = &sheet_history_bucket($key);
    $bucket['undo'][] = $entry;
    $bucket['redo'] = [];
    $max = 40;
    if (count($bucket['undo']) > $max) {
        $bucket['undo'] = array_slice($bucket['undo'], -$max);
    }
}

/**
 * @param list<array<string,mixed>> $rows
 */
function sheet_history_push_remove(string $kind, string $id, array $rows, array $extra = []): void
{
    if ($rows === []) {
        return;
    }
    sheet_history_push(sheet_history_key($kind, $id), [
        'op' => 'remove',
        'kind' => $kind,
        'id' => $id,
        'rows' => $rows,
    ] + $extra);
}

/**
 * @param list<array<string,mixed>> $before
 * @param list<array<string,mixed>> $after
 */
function sheet_history_push_emailed(string $kind, string $id, array $before, array $after, array $extra = []): void
{
    if ($before === [] && $after === []) {
        return;
    }
    sheet_history_push(sheet_history_key($kind, $id), [
        'op' => 'emailed',
        'kind' => $kind,
        'id' => $id,
        'before' => $before,
        'after' => $after,
    ] + $extra);
}

/**
 * @return array{ok:bool,error?:string,op?:string,count?:int,reload?:bool,can_undo?:bool,can_redo?:bool}
 */
function sheet_history_apply_undo(string $key): array
{
    $bucket = &sheet_history_bucket($key);
    if ($bucket['undo'] === []) {
        return ['ok' => false, 'error' => 'Nothing to undo.'] + sheet_history_state($key);
    }
    $entry = array_pop($bucket['undo']);
    $result = sheet_history_apply_entry($entry, 'undo');
    if (empty($result['ok'])) {
        $bucket['undo'][] = $entry;
        return $result + sheet_history_state($key);
    }
    $bucket['redo'][] = $entry;
    return $result + ['reload' => true] + sheet_history_state($key);
}

/**
 * @return array{ok:bool,error?:string,op?:string,count?:int,reload?:bool,can_undo?:bool,can_redo?:bool}
 */
function sheet_history_apply_redo(string $key): array
{
    $bucket = &sheet_history_bucket($key);
    if ($bucket['redo'] === []) {
        return ['ok' => false, 'error' => 'Nothing to redo.'] + sheet_history_state($key);
    }
    $entry = array_pop($bucket['redo']);
    $result = sheet_history_apply_entry($entry, 'redo');
    if (empty($result['ok'])) {
        $bucket['redo'][] = $entry;
        return $result + sheet_history_state($key);
    }
    $bucket['undo'][] = $entry;
    return $result + ['reload' => true] + sheet_history_state($key);
}

/**
 * @param array<string,mixed> $entry
 * @return array{ok:bool,error?:string,op?:string,count?:int}
 */
function sheet_history_apply_entry(array $entry, string $direction): array
{
    $op = (string) ($entry['op'] ?? '');
    $kind = (string) ($entry['kind'] ?? '');
    if ($op === 'emailed') {
        $flags = $direction === 'undo'
            ? ($entry['before'] ?? [])
            : ($entry['after'] ?? []);
        if (!is_array($flags) || $flags === []) {
            return ['ok' => false, 'error' => 'History entry is empty.'];
        }
        $ok = sheet_history_apply_emailed_flags($kind, $entry, $flags);
        if (!$ok) {
            return [
                'ok' => false,
                'error' => $direction === 'undo'
                    ? 'Could not undo the last emailed change.'
                    : 'Could not redo the last emailed change.',
            ];
        }
        return [
            'ok' => true,
            'op' => $direction === 'undo' ? 'undo' : 'redo',
            'count' => count($flags),
        ];
    }
    $rows = $entry['rows'] ?? [];
    if (!is_array($rows) || $rows === []) {
        return ['ok' => false, 'error' => 'History entry is empty.'];
    }
    if ($op !== 'remove') {
        return ['ok' => false, 'error' => 'Unknown history action.'];
    }
    $restore = $direction === 'undo';
    $count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($restore) {
            $out = sheet_history_restore_row($kind, $entry, $row);
        } else {
            $out = sheet_history_redelete_row($kind, $entry, $row);
        }
        if (!empty($out['ok'])) {
            $count++;
        }
    }
    if ($count < 1) {
        return [
            'ok' => false,
            'error' => $restore ? 'Could not restore the last change.' : 'Could not redo the last change.',
        ];
    }
    return [
        'ok' => true,
        'op' => $restore ? 'undo' : 'redo',
        'count' => $count,
    ];
}

/**
 * @param list<array<string,mixed>> $flags
 */
function sheet_history_apply_emailed_flags(string $kind, array $entry, array $flags): bool
{
    if ($kind === 'campaign' && function_exists('apply_email_campaign_emailed_flags')) {
        $sheetId = (int) ($entry['id'] ?? 0);
        return apply_email_campaign_emailed_flags($sheetId, $flags);
    }
    if ($kind === 'swe' && function_exists('apply_sites_with_emails_admin_emailed_flags')) {
        return apply_sites_with_emails_admin_emailed_flags($flags);
    }
    return false;
}

/**
 * @param array<string,mixed> $entry
 * @param array<string,mixed> $row
 * @return array{ok:bool}
 */
function sheet_history_restore_row(string $kind, array $entry, array $row): array
{
    if ($kind === 'campaign' && function_exists('restore_email_campaign_row_snapshot')) {
        $sheetId = (int) ($entry['id'] ?? ($row['sheet_id'] ?? 0));
        return restore_email_campaign_row_snapshot($sheetId, $row);
    }
    if ($kind === 'swe' && function_exists('restore_site_with_emails_snapshot')) {
        $scope = (string) ($entry['scope'] ?? 'team');
        return restore_site_with_emails_snapshot($scope, $row);
    }
    if ($kind === 'extracted' && function_exists('restore_extracted_site_snapshot')) {
        return restore_extracted_site_snapshot($row);
    }
    if ($kind === 'prospect' && function_exists('restore_prospect_site_snapshot')) {
        return restore_prospect_site_snapshot($row);
    }
    return ['ok' => false];
}

/**
 * @param array<string,mixed> $entry
 * @param array<string,mixed> $row
 * @return array{ok:bool}
 */
function sheet_history_redelete_row(string $kind, array $entry, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    if ($kind === 'campaign' && function_exists('delete_email_campaign_row')) {
        $sheetId = (int) ($entry['id'] ?? ($row['sheet_id'] ?? 0));
        $domain = (string) ($row['domain'] ?? '');
        if ($id > 0) {
            $del = delete_email_campaign_row($sheetId, $id, false);
            if (!empty($del['ok'])) {
                return ['ok' => true];
            }
        }
        if ($domain !== '' && function_exists('get_email_campaign_row_by_domain')) {
            $found = get_email_campaign_row_by_domain($sheetId, $domain);
            if ($found) {
                $del = delete_email_campaign_row($sheetId, (int) $found['id'], false);
                return ['ok' => !empty($del['ok'])];
            }
        }
        return ['ok' => false];
    }
    if ($kind === 'swe' && function_exists('delete_site_with_emails')) {
        $scope = (string) ($entry['scope'] ?? 'team');
        if ($id > 0 && delete_site_with_emails($id, $scope)) {
            return ['ok' => true];
        }
        $country = (string) ($row['country'] ?? '');
        $domain = (string) ($row['domain'] ?? '');
        if ($country !== '' && $domain !== '' && function_exists('find_site_with_emails_id')) {
            $foundId = find_site_with_emails_id($country, $domain, $scope);
            if ($foundId > 0 && delete_site_with_emails($foundId, $scope)) {
                return ['ok' => true];
            }
        }
        return ['ok' => false];
    }
    if ($kind === 'extracted' && function_exists('delete_extracted_site')) {
        if ($id > 0 && delete_extracted_site($id)) {
            return ['ok' => true];
        }
        $country = (string) ($row['country'] ?? '');
        $domain = (string) ($row['domain'] ?? '');
        if ($country !== '' && $domain !== '') {
            $found = db()->prepare('SELECT id FROM extracted_sites WHERE country=? AND domain=? LIMIT 1');
            $found->execute([$country, $domain]);
            $foundId = (int) $found->fetchColumn();
            if ($foundId > 0 && delete_extracted_site($foundId)) {
                return ['ok' => true];
            }
        }
        return ['ok' => false];
    }
    if ($kind === 'prospect' && function_exists('delete_prospect_site_by_id')) {
        if ($id > 0 && delete_prospect_site_by_id($id)) {
            return ['ok' => true];
        }
        $country = (string) ($row['country'] ?? '');
        $domain = (string) ($row['domain'] ?? '');
        if ($domain !== '') {
            $found = db()->prepare('SELECT id FROM prospect_sites WHERE country=? AND domain=? LIMIT 1');
            $found->execute([$country, $domain]);
            $foundId = (int) $found->fetchColumn();
            if ($foundId > 0 && delete_prospect_site_by_id($foundId)) {
                return ['ok' => true];
            }
        }
        return ['ok' => false];
    }
    return ['ok' => false];
}

function ui_icon_undo(): string
{
    return '<span class="sheet-history-glyph" aria-hidden="true">↶</span>';
}

function ui_icon_redo(): string
{
    return '<span class="sheet-history-glyph" aria-hidden="true">↷</span>';
}

function render_undo_redo_arrow_buttons(string $undoId, string $redoId, string $sizeClass = 'small'): void
{
    $size = $sizeClass !== '' ? ' ' . $sizeClass : '';
    echo '<button type="button" class="btn secondary sheet-history-btn' . h($size) . '" id="' . h($undoId)
        . '" disabled title="Undo" aria-label="Undo">' . ui_icon_undo()
        . '<span class="sheet-history-text">Undo</span></button>';
    echo '<button type="button" class="btn secondary sheet-history-btn' . h($size) . '" id="' . h($redoId)
        . '" disabled title="Redo" aria-label="Redo">' . ui_icon_redo()
        . '<span class="sheet-history-text">Redo</span></button>';
}

/**
 * Undo / Redo arrows + Select all / Remove selected for a sheet of rows.
 *
 * @param array{
 *   q?:string,p?:int,sent?:string,filter?:string,country?:string,
 *   can_undo?:bool,can_redo?:bool,show_select?:bool
 * } $opts
 */
function render_sheet_edit_toolbar(string $actionUrl, string $historyKey, array $opts = []): void
{
    $state = sheet_history_state($historyKey);
    $canUndo = !empty($opts['can_undo']) || !empty($state['can_undo']);
    $canRedo = !empty($opts['can_redo']) || !empty($state['can_redo']);
    $showSelect = ($opts['show_select'] ?? true) !== false;
    $q = (string) ($opts['q'] ?? '');
    $p = (int) ($opts['p'] ?? 1);
    $sent = (string) ($opts['sent'] ?? '');
    $filter = (string) ($opts['filter'] ?? '');
    $country = (string) ($opts['country'] ?? '');
    $nav = '<input type="hidden" name="q" value="' . h($q) . '" data-swe-q>'
        . '<input type="hidden" name="p" value="' . $p . '">';
    if ($sent !== '') {
        $nav .= '<input type="hidden" name="sent" value="' . h($sent) . '">';
    }
    if ($filter !== '') {
        $nav .= '<input type="hidden" name="filter" value="' . h($filter) . '">';
    }
    if ($country !== '') {
        $nav .= '<input type="hidden" name="country" value="' . h($country) . '">';
    }
    $createdBy = (int) ($opts['created_by'] ?? 0);
    if ($createdBy > 0) {
        $nav .= '<input type="hidden" name="created_by" value="' . $createdBy . '">';
    }
    $url = h($actionUrl);
    echo '<div class="sheet-edit-toolbar" data-sheet-select-root data-sheet-history-key="' . h($historyKey) . '">';
    echo '<button type="button" class="btn secondary small sheet-history-btn" data-sheet-undo'
        . ($canUndo ? '' : ' disabled')
        . ' title="Undo last change" aria-label="Undo last change">' . ui_icon_undo()
        . '<span class="sheet-history-text">Undo</span></button>';
    echo '<button type="button" class="btn secondary small sheet-history-btn" data-sheet-redo'
        . ($canRedo ? '' : ' disabled')
        . ' title="Redo last change" aria-label="Redo last change">' . ui_icon_redo()
        . '<span class="sheet-history-text">Redo</span></button>';
    if ($showSelect) {
        echo '<button type="button" class="btn secondary small" data-sheet-select-all title="Select all matching rows on this page" aria-label="Select all matching rows on this page">Select all</button>';
        echo '<button type="button" class="btn secondary small danger" data-sheet-remove-selected disabled title="Remove the selected matching rows">Remove selected</button>';
    }
    $csrf = function_exists('csrf_field') ? csrf_field() : '';
    echo '<form id="sheet-shared-undo" class="sheet-history-form" method="post" action="' . $url . '" data-sheet-undo-form hidden>'
        . $csrf . '<input type="hidden" name="action" value="undo_last">' . $nav . '</form>';
    echo '<form id="sheet-shared-redo" class="sheet-history-form" method="post" action="' . $url . '" data-sheet-redo-form hidden>'
        . $csrf . '<input type="hidden" name="action" value="redo_last">' . $nav . '</form>';
    if ($showSelect) {
        echo '<form id="sheet-shared-remove-selected" class="sheet-history-form" method="post" action="' . $url
            . '" data-sheet-remove-selected-form hidden>'
            . $csrf
            . '<input type="hidden" name="action" value="remove_selected">'
            . '<input type="hidden" name="site_ids" value="" data-sheet-site-ids>'
            . $nav . '</form>';
    }
    echo '</div>';
}

function render_sheet_select_th(): void
{
    echo '<th class="swe-col-check sheet-col-check" scope="col">'
        . '<label class="sheet-check sheet-check-all">'
        . '<input type="checkbox" data-sheet-select-all-check title="Select all matching rows on this page" aria-label="Select all matching rows on this page">'
        . '</label></th>';
}

function render_sheet_select_td(int $siteId, string $domain = ''): void
{
    $label = $domain !== '' ? 'Select ' . $domain : 'Select row';
    echo '<td class="swe-td-check sheet-td-check" data-label="Select">'
        . '<label class="sheet-check">'
        . '<input type="checkbox" data-sheet-row-check value="' . (int) $siteId . '" aria-label="' . h($label) . '">'
        . '</label></td>';
}

function render_sheet_tool_menu_open(string $label, string $aria = ''): void
{
    $aria = $aria !== '' ? $aria : $label;
    echo '<details class="sheet-tool-menu">';
    echo '<summary class="btn secondary sheet-tool-menu-summary">' . h($label) . '</summary>';
    echo '<div class="sheet-tool-menu-panel" role="group" aria-label="' . h($aria) . '">';
}

function render_sheet_tool_menu_close(): void
{
    echo '</div></details>';
}

function render_sheet_row_more_open(): void
{
    echo '<details class="sheet-row-more">';
    echo '<summary class="btn secondary small sheet-row-more-btn" title="More actions" aria-label="More actions">';
    echo '<span aria-hidden="true">⋮</span></summary>';
    echo '<div class="sheet-row-more-panel">';
}

function render_sheet_row_more_close(): void
{
    echo '</div></details>';
}
