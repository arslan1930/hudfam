<?php
/**
 * Email campaign sheets (Emails DATA → Email campaign data).
 * One sheet per country with an admin-assigned project name.
 * Each sheet can expose its own Communication Team search bar (site + emails delete).
 */

function ensure_email_campaign_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_sheets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(180) NOT NULL,
          project_name VARCHAR(180) NOT NULL DEFAULT '',
          team_search_visible TINYINT(1) NOT NULL DEFAULT 1,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_sheet_name (name),
          INDEX (updated_at),
          INDEX (team_search_visible),
          CONSTRAINT fk_email_campaign_sheet_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_rows (
          id INT AUTO_INCREMENT PRIMARY KEY,
          sheet_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          country VARCHAR(100) NOT NULL DEFAULT '',
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          email1 VARCHAR(255) NOT NULL DEFAULT '',
          email2 VARCHAR(255) NOT NULL DEFAULT '',
          email3 VARCHAR(255) NOT NULL DEFAULT '',
          email4 VARCHAR(255) NOT NULL DEFAULT '',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_sheet_domain (sheet_id, domain),
          INDEX (sheet_id),
          INDEX (domain),
          INDEX (country),
          CONSTRAINT fk_email_campaign_row_sheet
            FOREIGN KEY (sheet_id) REFERENCES email_campaign_sheets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Older installs: add project name + team visibility.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM email_campaign_sheets')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $have = array_fill_keys(array_map('strval', $cols), true);
        if (!isset($have['project_name'])) {
            $pdo->exec(
                "ALTER TABLE email_campaign_sheets
                 ADD COLUMN project_name VARCHAR(180) NOT NULL DEFAULT '' AFTER name"
            );
        }
        if (!isset($have['team_search_visible'])) {
            $pdo->exec(
                "ALTER TABLE email_campaign_sheets
                 ADD COLUMN team_search_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER project_name"
            );
        }
        $pdo->exec(
            "UPDATE email_campaign_sheets
             SET project_name = name
             WHERE TRIM(project_name) = ''"
        );
    } catch (Throwable $e) {
        // ignore migration hiccups
    }
}

/**
 * Sheet "name" is always the canonical country name.
 */
function email_campaign_sheet_country(array $sheet): string
{
    return (string) ($sheet['name'] ?? '');
}

/**
 * Admin-assigned project label shown on the Communication Team search bar.
 */
function email_campaign_sheet_project_name(array $sheet): string
{
    $project = trim((string) ($sheet['project_name'] ?? ''));
    if ($project !== '') {
        return $project;
    }
    return email_campaign_sheet_country($sheet);
}

function email_campaign_sheet_team_visible(array $sheet): bool
{
    return (int) ($sheet['team_search_visible'] ?? 1) === 1;
}

/**
 * @param bool|null $onlyTeamVisible true = only sheets shown to Communication Team
 * @return list<array{id:int,name:string,country:string,project_name:string,team_search_visible:bool,region:string,language:string,row_count:int,with_emails:int,created_at:?string,updated_at:?string}>
 */
function list_email_campaign_sheets(?bool $onlyTeamVisible = null): array
{
    ensure_email_campaign_schema();
    $sql = "SELECT s.id, s.name, s.project_name, s.team_search_visible, s.created_at, s.updated_at,
                   COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_' THEN 1 ELSE 0 END), 0) AS row_count,
                   COALESCE(SUM(
                     CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_'
                               AND (r.email1<>'' OR r.email2<>'' OR r.email3<>'' OR r.email4<>'')
                          THEN 1 ELSE 0 END
                   ), 0) AS with_emails
            FROM email_campaign_sheets s
            LEFT JOIN email_campaign_rows r ON r.sheet_id = s.id";
    if ($onlyTeamVisible === true) {
        $sql .= ' WHERE s.team_search_visible = 1';
    } elseif ($onlyTeamVisible === false) {
        $sql .= ' WHERE s.team_search_visible = 0';
    }
    $sql .= ' GROUP BY s.id, s.name, s.project_name, s.team_search_visible, s.created_at, s.updated_at
              ORDER BY s.project_name ASC, s.name ASC';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $country = (string) $row['name'];
        $canon = resolve_canonical_country($country);
        $project = trim((string) ($row['project_name'] ?? ''));
        if ($project === '') {
            $project = $canon ? $canon['name'] : $country;
        }
        $out[] = [
            'id' => (int) $row['id'],
            'name' => $canon ? $canon['name'] : $country,
            'country' => $canon ? $canon['name'] : $country,
            'project_name' => $project,
            'team_search_visible' => (int) ($row['team_search_visible'] ?? 1) === 1,
            'region' => $canon ? (string) $canon['region'] : '',
            'language' => $canon ? (string) $canon['language'] : '',
            'row_count' => (int) $row['row_count'],
            'with_emails' => (int) $row['with_emails'],
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
    return $out;
}

function get_email_campaign_sheet(int $id): ?array
{
    ensure_email_campaign_schema();
    $stmt = db()->prepare('SELECT * FROM email_campaign_sheets WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_email_campaign_sheet_by_country(string $country): ?array
{
    ensure_email_campaign_schema();
    $canon = require_canonical_country($country);
    $stmt = db()->prepare('SELECT * FROM email_campaign_sheets WHERE name=? LIMIT 1');
    $stmt->execute([$canon['name']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create (or return existing) country sheet. One sheet per country.
 * Assigns a project name and optionally exposes a Communication Team search bar.
 */
function create_email_campaign_sheet(
    string $country,
    int $actorId = 0,
    string $projectName = '',
    bool $teamSearchVisible = true
): int {
    ensure_email_campaign_schema();
    $canon = require_canonical_country($country);
    $name = $canon['name'];
    $project = trim($projectName);
    if ($project === '') {
        $project = $name;
    }
    if (mb_strlen($project) > 180) {
        $project = mb_substr($project, 0, 180);
    }
    $existing = get_email_campaign_sheet_by_country($name);
    if ($existing) {
        // One sheet per country — return existing; edit project/visibility on the sheet page.
        return (int) $existing['id'];
    }
    try {
        db()->prepare(
            'INSERT INTO email_campaign_sheets (name, project_name, team_search_visible, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $name,
            $project,
            $teamSearchVisible ? 1 : 0,
            $actorId > 0 ? $actorId : null,
        ]);
    } catch (PDOException $e) {
        $again = get_email_campaign_sheet_by_country($name);
        if ($again) {
            return (int) $again['id'];
        }
        throw new InvalidArgumentException('Could not create sheet for “' . $name . '”.');
    }
    return (int) db()->lastInsertId();
}

/**
 * Update project name and whether Communication Team sees this sheet’s search bar.
 *
 * @return array{ok:bool,error?:string}
 */
function update_email_campaign_sheet_settings(
    int $sheetId,
    string $projectName,
    bool $teamSearchVisible
): array {
    ensure_email_campaign_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    $project = trim($projectName);
    if ($project === '') {
        return ['ok' => false, 'error' => 'Project name is required.'];
    }
    if (mb_strlen($project) > 180) {
        $project = mb_substr($project, 0, 180);
    }
    db()->prepare(
        'UPDATE email_campaign_sheets
         SET project_name=?, team_search_visible=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$project, $teamSearchVisible ? 1 : 0, $sheetId]);
    return ['ok' => true];
}

function set_email_campaign_sheet_team_visible(int $sheetId, bool $visible): array
{
    ensure_email_campaign_schema();
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    return update_email_campaign_sheet_settings(
        $sheetId,
        email_campaign_sheet_project_name($sheet),
        $visible
    );
}

/** @deprecated Use update_email_campaign_sheet_settings() for project name. */
function rename_email_campaign_sheet(int $id, string $name): void
{
    $sheet = get_email_campaign_sheet($id);
    if (!$sheet) {
        return;
    }
    update_email_campaign_sheet_settings(
        $id,
        $name !== '' ? $name : email_campaign_sheet_project_name($sheet),
        email_campaign_sheet_team_visible($sheet)
    );
}

function delete_email_campaign_sheet(int $id): bool
{
    ensure_email_campaign_schema();
    $stmt = db()->prepare('DELETE FROM email_campaign_sheets WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function count_email_campaign_rows(int $sheetId): int
{
    ensure_email_campaign_schema();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM email_campaign_rows
         WHERE sheet_id=? AND LEFT(domain, 8) <> '__blank_'"
    );
    $stmt->execute([$sheetId]);
    return (int) $stmt->fetchColumn();
}

function touch_email_campaign_sheet(int $sheetId): void
{
    ensure_email_campaign_schema();
    db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
}

/**
 * Remove legacy blank placeholder rows (old spreadsheet workflow).
 */
function purge_blank_email_campaign_rows(int $sheetId): int
{
    ensure_email_campaign_schema();
    $st = db()->prepare(
        "DELETE FROM email_campaign_rows WHERE sheet_id=? AND LEFT(domain, 8) = '__blank_'"
    );
    $st->execute([$sheetId]);
    $n = $st->rowCount();
    if ($n > 0) {
        touch_email_campaign_sheet($sheetId);
    }
    return $n;
}

/**
 * @return list<array<string,mixed>>
 */
function list_email_campaign_rows(int $sheetId): array
{
    ensure_email_campaign_schema();
    purge_blank_email_campaign_rows($sheetId);
    $stmt = db()->prepare(
        "SELECT * FROM email_campaign_rows
         WHERE sheet_id=? AND LEFT(domain, 8) <> '__blank_'
         ORDER BY id ASC"
    );
    $stmt->execute([$sheetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return expand_packed_email_slots_in_campaign_rows($rows);
}

/**
 * Expand packed multi-email cells into email1–4 (same rule as Sites with emails).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function expand_packed_email_slots_in_campaign_rows(array $rows): array
{
    if ($rows === [] || !function_exists('email_slots_from_row')) {
        return $rows;
    }
    $upd = db()->prepare(
        'UPDATE email_campaign_rows
         SET email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=?'
    );
    $out = [];
    foreach ($rows as $row) {
        $slots = email_slots_from_row($row);
        $cur = [
            (string) ($row['email1'] ?? ''),
            (string) ($row['email2'] ?? ''),
            (string) ($row['email3'] ?? ''),
            (string) ($row['email4'] ?? ''),
        ];
        if ($slots !== $cur) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                try {
                    $upd->execute([$slots[0], $slots[1], $slots[2], $slots[3], $id]);
                } catch (Throwable $e) {
                    // still show expanded values
                }
            }
            $row['email1'] = $slots[0];
            $row['email2'] = $slots[1];
            $row['email3'] = $slots[2];
            $row['email4'] = $slots[3];
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * @deprecated Blank placeholder rows are no longer used — sheets use Add site row.
 */
function add_blank_email_campaign_rows(int $sheetId, int $count = 1): int
{
    unset($count);
    purge_blank_email_campaign_rows($sheetId);
    return 0;
}

/**
 * Save one site + up to 4 emails row (Sites with emails workflow).
 * Clearing the last email deletes the whole row.
 *
 * @return array{ok:bool,error?:string,id?:int,domain?:string,row_deleted?:bool,emails?:list<string>}
 */
function save_email_campaign_row(
    int $sheetId,
    int $rowId,
    string $domainRaw,
    array $emails
): array {
    ensure_email_campaign_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    $existing = get_email_campaign_row($rowId, $sheetId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Row not found.'];
    }
    // Legacy blank placeholders: drop them instead of keeping spreadsheet empties.
    if (str_starts_with((string) ($existing['domain'] ?? ''), '__blank_')) {
        db()->prepare('DELETE FROM email_campaign_rows WHERE id=? AND sheet_id=?')->execute([$rowId, $sheetId]);
        return ['ok' => true, 'id' => $rowId, 'domain' => '', 'row_deleted' => true, 'emails' => []];
    }

    $sheet = get_email_campaign_sheet($sheetId);
    $sheetCountry = $sheet ? email_campaign_sheet_country($sheet) : '';

    $domainRaw = trim($domainRaw);
    if ($domainRaw === '') {
        return ['ok' => false, 'error' => 'Site name is required.'];
    }

    $host = extract_host_candidate($domainRaw);
    $domain = to_root_domain($host);
    if ($domain === '' || !function_exists('is_root_domain') || !is_root_domain($domain)) {
        if ($domain === '' || !str_contains($domain, '.')) {
            return ['ok' => false, 'error' => 'Enter a valid site name (root domain).'];
        }
    }
    $norm = normalize_email_slots($emails);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => (string) ($norm['error'] ?? 'Invalid email.')];
    }
    /** @var array{0:string,1:string,2:string,3:string} $slots */
    $slots = $norm['slots'] ?? ['', '', '', ''];
    $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
    if (!$hasEmail) {
        db()->prepare('DELETE FROM email_campaign_rows WHERE id=? AND sheet_id=?')->execute([$rowId, $sheetId]);
        touch_email_campaign_sheet($sheetId);
        return [
            'ok' => true,
            'id' => $rowId,
            'domain' => $domain,
            'row_deleted' => true,
            'emails' => [],
        ];
    }

    $dup = db()->prepare(
        'SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? AND id<>? LIMIT 1'
    );
    $dup->execute([$sheetId, $domain, $rowId]);
    if ((int) $dup->fetchColumn() > 0) {
        return ['ok' => false, 'error' => $domain . ' already exists in this sheet.'];
    }

    db()->prepare(
        'UPDATE email_campaign_rows
         SET domain=?, country=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=? AND sheet_id=?'
    )->execute([$domain, $sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3], $rowId, $sheetId]);
    touch_email_campaign_sheet($sheetId);
    return [
        'ok' => true,
        'id' => $rowId,
        'domain' => $domain,
        'row_deleted' => false,
        'emails' => array_values(array_filter($slots, static fn ($e) => $e !== '')),
        'slots' => $slots,
    ];
}

/**
 * Insert a new filled row (or upsert by domain).
 *
 * @return array{ok:bool,error?:string,id?:int,domain?:string}
 */
function upsert_email_campaign_row(int $sheetId, string $domainRaw, array $emails): array
{
    ensure_email_campaign_schema();
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    $sheetCountry = email_campaign_sheet_country($sheet);
    $host = extract_host_candidate($domainRaw);
    $domain = to_root_domain($host);
    if ($domain === '' || (function_exists('is_root_domain') && !is_root_domain($domain))) {
        if ($domain === '' || !str_contains($domain, '.')) {
            return ['ok' => false, 'error' => 'Enter a valid site name (root domain).'];
        }
    }
    $norm = normalize_email_slots($emails);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => (string) ($norm['error'] ?? 'Invalid email.')];
    }
    /** @var array{0:string,1:string,2:string,3:string} $slots */
    $slots = $norm['slots'] ?? ['', '', '', ''];
    $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
    if (!$hasEmail) {
        return ['ok' => false, 'error' => 'Add at least one email — each site must have email data.'];
    }

    $find = db()->prepare(
        'SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? LIMIT 1'
    );
    $find->execute([$sheetId, $domain]);
    $existingId = (int) $find->fetchColumn();
    if ($existingId > 0) {
        db()->prepare(
            'UPDATE email_campaign_rows
             SET country=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
             WHERE id=? AND sheet_id=?'
        )->execute([$sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3], $existingId, $sheetId]);
        touch_email_campaign_sheet($sheetId);
        return ['ok' => true, 'id' => $existingId, 'domain' => $domain];
    }

    db()->prepare(
        'INSERT INTO email_campaign_rows
           (sheet_id, domain, country, email1, email2, email3, email4)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$sheetId, $domain, $sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3]]);
    $id = (int) db()->lastInsertId();
    touch_email_campaign_sheet($sheetId);
    return ['ok' => true, 'id' => $id, 'domain' => $domain];
}

/**
 * True when the first data cells look like a spreadsheet header, not a site row.
 *
 * @param list<string> $parts
 */
function email_campaign_bulk_line_is_header(array $parts): bool
{
    $first = mb_strtolower(trim((string) ($parts[0] ?? '')));
    if ($first === '') {
        return false;
    }
    if (preg_match(
        '/^(site(\s*name)?|sites|domain|domains|url|urls|website|websites|host)$/u',
        $first
    )) {
        return true;
    }
    $second = mb_strtolower(trim((string) ($parts[1] ?? '')));
    return (bool) preg_match('/^e-?mails?\s*[1-4]?$/', $second);
}

/**
 * Split one pasted / imported line into site + up to 4 emails.
 *
 * @return array{domain:string,emails:array{0:string,1:string,2:string,3:string}}|null
 */
function parse_email_campaign_bulk_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return null;
    }
    // Strip UTF-8 BOM if a pasted chunk still has it.
    if (str_starts_with($line, "\xEF\xBB\xBF")) {
        $line = substr($line, 3);
    }

    if (str_contains($line, "\t")) {
        $parts = preg_split('/\t+/', $line) ?: [];
    } elseif (substr_count($line, ';') >= 1 && substr_count($line, ';') >= substr_count($line, ',')) {
        $parts = preg_split('/\s*;\s*/', $line) ?: [];
    } elseif (str_contains($line, ',')) {
        $parts = str_getcsv($line);
    } else {
        $parts = preg_split('/\s+/', $line) ?: [];
    }
    $parts = array_values(array_map(static fn ($p) => trim((string) $p), $parts));
    // Drop trailing empties but keep middle blanks as empty email slots.
    while ($parts !== [] && end($parts) === '') {
        array_pop($parts);
    }
    if ($parts === [] || email_campaign_bulk_line_is_header($parts)) {
        return null;
    }

    $domainRaw = (string) $parts[0];
    $emails = array_slice($parts, 1, 4);
    // If only one "email" cell but it holds several addresses, expand.
    if (count($emails) === 1 && $emails[0] !== '' && function_exists('split_email_cell')) {
        $split = split_email_cell($emails[0]);
        if (count($split) > 1) {
            $emails = array_slice($split, 0, 4);
        }
    }
    while (count($emails) < 4) {
        $emails[] = '';
    }
    return [
        'domain' => $domainRaw,
        'emails' => [(string) $emails[0], (string) $emails[1], (string) $emails[2], (string) $emails[3]],
    ];
}

/**
 * Paste / import lines: site.com,email@x.com  OR  site.com email1 email2 …
 * Tuned for Admin bulk entry (1000+ rows).
 *
 * @return array{added:int,updated:int,skipped:int,errors:list<string>}
 */
function paste_email_campaign_rows(int $sheetId, string $raw): array
{
    ensure_email_campaign_schema();
    @set_time_limit(0);
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    $sheetCountry = email_campaign_sheet_country($sheet);
    $raw = str_replace(["\r\n", "\r"], "\n", (string) $raw);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split('/\n+/', $raw) ?: [];
    $added = 0;
    $updated = 0;
    $skipped = 0;
    /** @var list<string> $errors */
    $errors = [];

    $pdo = db();
    $find = $pdo->prepare(
        'SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? LIMIT 1'
    );
    $upd = $pdo->prepare(
        'UPDATE email_campaign_rows
         SET country=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=? AND sheet_id=?'
    );
    $ins = $pdo->prepare(
        'INSERT INTO email_campaign_rows
           (sheet_id, domain, country, email1, email2, email3, email4)
         VALUES (?,?,?,?,?,?,?)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($lines as $line) {
            $parsed = parse_email_campaign_bulk_line((string) $line);
            if ($parsed === null) {
                continue; // blank, comment, or header
            }
            $domainRaw = $parsed['domain'];
            $host = extract_host_candidate($domainRaw);
            $domain = to_root_domain($host);
            if ($domain === '' || (function_exists('is_root_domain') && !is_root_domain($domain))) {
                if ($domain === '' || !str_contains($domain, '.')) {
                    if (count($errors) < 25) {
                        $errors[] = $domainRaw . ': Enter a valid site name (root domain).';
                    }
                    $skipped++;
                    continue;
                }
            }
            $norm = normalize_email_slots($parsed['emails']);
            if (!$norm['ok']) {
                if (count($errors) < 25) {
                    $errors[] = $domainRaw . ': ' . (string) ($norm['error'] ?? 'Invalid email.');
                }
                $skipped++;
                continue;
            }
            /** @var array{0:string,1:string,2:string,3:string} $slots */
            $slots = $norm['slots'] ?? ['', '', '', ''];
            $hasEmail = $slots[0] !== '' || $slots[1] !== '' || $slots[2] !== '' || $slots[3] !== '';
            if (!$hasEmail) {
                if (count($errors) < 25) {
                    $errors[] = $domainRaw . ': Add at least one email — each site must have email data.';
                }
                $skipped++;
                continue;
            }

            $find->execute([$sheetId, $domain]);
            $existingId = (int) $find->fetchColumn();
            if ($existingId > 0) {
                $upd->execute([
                    $sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3], $existingId, $sheetId,
                ]);
                $updated++;
            } else {
                $ins->execute([
                    $sheetId, $domain, $sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3],
                ]);
                $added++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($added > 0 || $updated > 0) {
        touch_email_campaign_sheet($sheetId);
    }
    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Read a CSV/TSV/TXT/.xlsx upload into paste-compatible text (site + up to 4 emails).
 */
function read_email_campaign_rows_upload(?array $file): string
{
    if (!$file || !is_array($file)) {
        return '';
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('File upload failed. Try again.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Upload missing on server.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > 40 * 1024 * 1024) {
        throw new InvalidArgumentException('File is too large (max 40 MB).');
    }
    $name = mb_strtolower((string) ($file['name'] ?? ''));
    return email_campaign_rows_text_from_file_path($tmp, $name);
}

/**
 * Convert a local CSV / TXT / XLSX path into paste text. Used by upload + tests.
 */
function email_campaign_rows_text_from_file_path(string $path, string $originalName = ''): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('Could not read the file.');
    }
    $name = mb_strtolower($originalName !== '' ? $originalName : basename($path));
    $ext = pathinfo($name, PATHINFO_EXTENSION);

    if ($ext === 'xlsx') {
        return read_email_campaign_xlsx_as_paste_text($path);
    }
    if ($ext === 'xls') {
        throw new InvalidArgumentException(
            'Old Excel .xls is not supported. Save as .xlsx or CSV (Excel → Save As → CSV UTF-8) and try again.'
        );
    }

    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return '';
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    // Binary leftovers (zip/xlsx misnamed as .csv)
    if (str_starts_with($raw, 'PK')) {
        return read_email_campaign_xlsx_as_paste_text($path);
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $firstLine = (string) strtok($raw, "\n");
    $delimiter = ',';
    $tabs = substr_count($firstLine, "\t");
    $semis = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');
    if ($tabs > 0 && $tabs >= $commas && $tabs >= $semis) {
        $delimiter = "\t";
    } elseif ($semis > $commas) {
        $delimiter = ';';
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new InvalidArgumentException('Could not read the uploaded file.');
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $out = [];
    $rowNum = 0;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rowNum++;
        if ($row === [null] || $row === false) {
            continue;
        }
        $parts = array_values(array_map(static fn ($c) => trim((string) $c), $row));
        while ($parts !== [] && end($parts) === '') {
            array_pop($parts);
        }
        if ($parts === []) {
            continue;
        }
        if ($rowNum === 1 && email_campaign_bulk_line_is_header($parts)) {
            continue;
        }
        $domain = (string) ($parts[0] ?? '');
        if ($domain === '') {
            continue;
        }
        $emails = array_slice($parts, 1, 4);
        while (count($emails) < 4) {
            $emails[] = '';
        }
        $out[] = $domain . ',' . implode(',', $emails);
        if (count($out) >= 100000) {
            break;
        }
    }
    fclose($fh);
    return implode("\n", $out);
}

/**
 * Minimal first-sheet .xlsx reader → paste text (site + up to 4 email columns).
 * No external spreadsheet library required.
 */
function read_email_campaign_xlsx_as_paste_text(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new InvalidArgumentException(
            'Excel (.xlsx) needs PHP ZipArchive. Save the file as CSV and import that instead.'
        );
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('Could not open the Excel file. Try exporting as CSV.');
    }

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($ssXml) && $ssXml !== '') {
        $sx = @simplexml_load_string($ssXml);
        if ($sx !== false) {
            $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $siNodes = $sx->xpath('//m:si') ?: [];
            foreach ($siNodes as $si) {
                $texts = $si->xpath('.//m:t') ?: [];
                $buf = '';
                foreach ($texts as $t) {
                    $buf .= (string) $t;
                }
                $shared[] = $buf;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!is_string($sheetXml) || $sheetXml === '') {
        // Fallback: first worksheet_* path
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = (string) $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        throw new InvalidArgumentException('Excel file has no readable worksheet. Save as CSV and try again.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new InvalidArgumentException('Could not parse the Excel worksheet. Save as CSV and try again.');
    }
    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowsXml = $sheet->xpath('//m:sheetData/m:row') ?: [];
    $out = [];
    $rowNum = 0;
    foreach ($rowsXml as $rowXml) {
        $rowNum++;
        $cells = [];
        foreach ($rowXml->xpath('./m:c') ?: [] as $c) {
            $ref = (string) ($c['r'] ?? '');
            if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
                continue;
            }
            $col = 0;
            $letters = $m[1];
            for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
                $col = $col * 26 + (ord($letters[$i]) - 64);
            }
            $colIndex = $col - 1; // 0-based
            if ($colIndex < 0 || $colIndex > 4) {
                continue; // only site + 4 emails
            }
            $type = (string) ($c['t'] ?? '');
            $v = (string) ($c->v ?? '');
            if ($type === 's') {
                $v = $shared[(int) $v] ?? '';
            } elseif ($type === 'inlineStr') {
                $tNodes = $c->xpath('.//m:t') ?: [];
                $v = '';
                foreach ($tNodes as $t) {
                    $v .= (string) $t;
                }
            }
            $cells[$colIndex] = trim($v);
        }
        if ($cells === []) {
            continue;
        }
        $parts = [];
        for ($i = 0; $i <= 4; $i++) {
            $parts[$i] = (string) ($cells[$i] ?? '');
        }
        while ($parts !== [] && end($parts) === '') {
            array_pop($parts);
        }
        if ($parts === []) {
            continue;
        }
        if ($rowNum === 1 && email_campaign_bulk_line_is_header($parts)) {
            continue;
        }
        $domain = (string) ($parts[0] ?? '');
        if ($domain === '') {
            continue;
        }
        $emails = array_slice($parts, 1, 4);
        while (count($emails) < 4) {
            $emails[] = '';
        }
        $out[] = $domain . ',' . implode(',', $emails);
        if (count($out) >= 100000) {
            break;
        }
    }
    return implode("\n", $out);
}

/**
 * Import Admin-uploaded CSV / Excel / TXT into a campaign sheet.
 *
 * @return array{added:int,updated:int,skipped:int,errors:list<string>,lines:int}
 */
function import_email_campaign_rows_from_upload(int $sheetId, ?array $file): array
{
    $text = read_email_campaign_rows_upload($file);
    if (trim($text) === '') {
        throw new InvalidArgumentException('Choose a CSV, Excel (.xlsx), or TXT file with site + emails.');
    }
    $lines = preg_split('/\n+/', trim($text)) ?: [];
    $result = paste_email_campaign_rows($sheetId, $text);
    $result['lines'] = count(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
    return $result;
}

/**
 * Save many rows from the sheet grid (ids + domains + emails).
 *
 * @param list<int|string> $ids
 * @param list<string> $domains
 * @param list<string> $e1
 * @param list<string> $e2
 * @param list<string> $e3
 * @param list<string> $e4
 * @return array{saved:int,errors:list<string>}
 */
function save_email_campaign_sheet_grid(
    int $sheetId,
    array $ids,
    array $domains,
    array $e1,
    array $e2,
    array $e3,
    array $e4
): array {
    $saved = 0;
    $errors = [];
    $n = count($ids);
    for ($i = 0; $i < $n; $i++) {
        $rowId = (int) ($ids[$i] ?? 0);
        if ($rowId < 1) {
            continue;
        }
        $domain = (string) ($domains[$i] ?? '');
        // Skip completely empty placeholder rows
        $row = get_email_campaign_row($rowId, $sheetId);
        if (!$row) {
            continue;
        }
        $isPlaceholder = str_starts_with((string) $row['domain'], '__blank_');
        $emails = [
            (string) ($e1[$i] ?? ''),
            (string) ($e2[$i] ?? ''),
            (string) ($e3[$i] ?? ''),
            (string) ($e4[$i] ?? ''),
        ];
        if ($isPlaceholder && trim($domain) === '' && implode('', $emails) === '') {
            continue;
        }
        $result = save_email_campaign_row($sheetId, $rowId, $domain, $emails);
        if (!$result['ok']) {
            $errors[] = (trim($domain) !== '' ? $domain : ('row #' . $rowId))
                . ': ' . (string) ($result['error'] ?? 'failed');
            continue;
        }
        $saved++;
    }
    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Import rows from Sites with emails Admin or Final into a campaign sheet.
 *
 * @return array{imported:int,updated:int,skipped:int}
 */
function import_email_campaign_sheet_from_swe(int $sheetId, string $sourceScope = 'admin_all', ?string $country = null): array
{
    ensure_email_campaign_schema();
    ensure_sites_with_emails_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    $sourceScope = swe_normalize_scope($sourceScope);
    if (!in_array($sourceScope, ['admin', 'admin_all'], true)) {
        $sourceScope = 'admin_all';
    }
    $table = swe_table($sourceScope);
    $pdo = db();
    if ($country !== null && trim($country) !== '') {
        $canon = resolve_canonical_country(trim($country));
        $countryName = $canon ? $canon['name'] : trim($country);
        $sel = $pdo->prepare("SELECT * FROM {$table} WHERE country=? ORDER BY id ASC");
        $sel->execute([$countryName]);
    } else {
        $sel = $pdo->query("SELECT * FROM {$table} ORDER BY id ASC");
    }

    $ins = $pdo->prepare(
        'INSERT INTO email_campaign_rows
           (sheet_id, domain, country, language, region, email1, email2, email3, email4)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           country = VALUES(country),
           language = VALUES(language),
           region = VALUES(region),
           email1 = VALUES(email1),
           email2 = VALUES(email2),
           email3 = VALUES(email3),
           email4 = VALUES(email4),
           updated_at = NOW()'
    );
    $exists = $pdo->prepare(
        'SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? LIMIT 1'
    );

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $domain = trim((string) ($row['domain'] ?? ''));
        if ($domain === '') {
            $skipped++;
            continue;
        }
        $slots = function_exists('email_slots_from_row')
            ? email_slots_from_row($row)
            : [
                (string) ($row['email1'] ?? ''),
                (string) ($row['email2'] ?? ''),
                (string) ($row['email3'] ?? ''),
                (string) ($row['email4'] ?? ''),
            ];
        // Same rule as Sites with emails: never import a site with empty emails.
        if ($slots[0] === '' && $slots[1] === '' && $slots[2] === '' && $slots[3] === '') {
            $skipped++;
            continue;
        }
        $exists->execute([$sheetId, $domain]);
        $already = (int) $exists->fetchColumn() > 0;
        $ins->execute([
            $sheetId,
            $domain,
            (string) ($row['country'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            $slots[0],
            $slots[1],
            $slots[2],
            $slots[3],
        ]);
        if ($already) {
            $updated++;
        } else {
            $imported++;
        }
    }
    $pdo->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
    return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
}

function get_email_campaign_row(int $rowId, ?int $sheetId = null): ?array
{
    ensure_email_campaign_schema();
    if ($sheetId !== null) {
        $stmt = db()->prepare('SELECT * FROM email_campaign_rows WHERE id=? AND sheet_id=? LIMIT 1');
        $stmt->execute([$rowId, $sheetId]);
    } else {
        $stmt = db()->prepare('SELECT * FROM email_campaign_rows WHERE id=? LIMIT 1');
        $stmt->execute([$rowId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Live suggestions within one country sheet.
 *
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,project_name:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions(int $sheetId, string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, $sheetId, false);
}

/**
 * Search across Communication Team–visible sheets only.
 *
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,project_name:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions_all(string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, null, true);
}

/**
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,project_name:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions_scoped(
    string $q,
    int $limit = 20,
    ?int $sheetId = null,
    bool $onlyTeamVisible = false
): array {
    ensure_email_campaign_schema();
    $q = trim(mb_strtolower($q));
    if ($q === '' || mb_strlen($q) < 2) {
        return [];
    }
    if ($sheetId !== null) {
        $sheet = get_email_campaign_sheet($sheetId);
        if (!$sheet) {
            return [];
        }
        if ($onlyTeamVisible && !email_campaign_sheet_team_visible($sheet)) {
            return [];
        }
    }
    $limit = max(1, min(40, $limit));
    $like = '%' . $q . '%';
    $sql = "SELECT r.id, r.sheet_id, r.domain, r.country, r.email1, r.email2, r.email3, r.email4,
                   s.name AS sheet_country, s.project_name
            FROM email_campaign_rows r
            INNER JOIN email_campaign_sheets s ON s.id = r.sheet_id
            WHERE LEFT(r.domain, 8) <> '__blank_'
              AND (
                r.domain LIKE ?
                OR r.email1 LIKE ? OR r.email2 LIKE ? OR r.email3 LIKE ? OR r.email4 LIKE ?
                OR s.name LIKE ?
                OR s.project_name LIKE ?
              )";
    $params = [$like, $like, $like, $like, $like, $like, $like];
    if ($sheetId !== null) {
        $sql .= ' AND r.sheet_id = ?';
        $params[] = $sheetId;
    } elseif ($onlyTeamVisible) {
        $sql .= ' AND s.team_search_visible = 1';
    }
    $sql .= " ORDER BY
           CASE
             WHEN r.domain = ? THEN 0
             WHEN r.domain LIKE ? THEN 1
             WHEN r.email1 = ? OR r.email2 = ? OR r.email3 = ? OR r.email4 = ? THEN 2
             ELSE 3
           END,
           s.project_name ASC, s.name ASC, r.domain ASC
         LIMIT {$limit}";
    $params = array_merge($params, [$q, $q . '%', $q, $q, $q, $q]);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $domain = (string) $row['domain'];
        $country = trim((string) ($row['country'] ?? ''));
        if ($country === '') {
            $country = (string) ($row['sheet_country'] ?? '');
        }
        $project = trim((string) ($row['project_name'] ?? ''));
        if ($project === '') {
            $project = $country;
        }
        $emails = [];
        foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
            $e = trim((string) ($row[$k] ?? ''));
            if ($e !== '') {
                $emails[] = $e;
            }
        }
        $matchType = 'domain';
        $matched = $domain;
        $domainLower = mb_strtolower($domain);
        if (!str_contains($domainLower, $q)) {
            foreach ($emails as $e) {
                if (str_contains(mb_strtolower($e), $q)) {
                    $matchType = 'email';
                    $matched = $e;
                    break;
                }
            }
            if ($matchType === 'domain' && str_contains(mb_strtolower($country), $q)) {
                $matchType = 'country';
                $matched = $country;
            }
            if ($matchType === 'domain' && str_contains(mb_strtolower($project), $q)) {
                $matchType = 'project';
                $matched = $project;
            }
        }
        $emailPreview = $emails !== [] ? implode(', ', $emails) : '(no emails)';
        $out[] = [
            'id' => (int) $row['id'],
            'sheet_id' => (int) $row['sheet_id'],
            'domain' => $domain,
            'country' => $country,
            'project_name' => $project,
            'emails' => $emails,
            'match_type' => $matchType,
            'matched_value' => $matched,
            'label' => $domain . ' · ' . $emailPreview . ' · ' . $project,
        ];
    }
    return $out;
}

function delete_email_campaign_row(int $sheetId, int $rowId): array
{
    ensure_email_campaign_schema();
    $row = get_email_campaign_row($rowId, $sheetId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Row not found in this email sheet.'];
    }
    db()->prepare('DELETE FROM email_campaign_rows WHERE id=? AND sheet_id=?')->execute([$rowId, $sheetId]);
    db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
    return ['ok' => true, 'domain' => (string) $row['domain']];
}

/**
 * Remove one email; keep site name when other emails remain.
 * If this was the last email on the site, delete the whole row
 * (no empty email rows in campaign sheets).
 *
 * @return array{ok:bool,error?:string,domain?:string,emails?:list<string>,removed?:string,row_deleted?:bool}
 */
function remove_email_from_email_campaign_row(int $sheetId, int $rowId, string $email): array
{
    ensure_email_campaign_schema();
    $row = get_email_campaign_row($rowId, $sheetId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Row not found in this email sheet.'];
    }
    $target = function_exists('normalize_email_value')
        ? normalize_email_value($email)
        : strtolower(trim($email));
    if ($target === '') {
        $target = strtolower(trim($email));
    }
    if ($target === '') {
        return ['ok' => false, 'error' => 'Email is empty.'];
    }

    $slots = [];
    $found = false;
    foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
        $e = strtolower(trim((string) ($row[$k] ?? '')));
        if ($e === '') {
            continue;
        }
        if ($e === $target) {
            $found = true;
            continue;
        }
        $slots[] = $e;
    }
    if (!$found) {
        return ['ok' => false, 'error' => 'That email is not on this site in the sheet.'];
    }

    $domain = (string) $row['domain'];
    // Last email gone → drop the site row (no empty-email sites).
    if ($slots === []) {
        db()->prepare('DELETE FROM email_campaign_rows WHERE id=? AND sheet_id=?')->execute([$rowId, $sheetId]);
        db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
        return [
            'ok' => true,
            'domain' => $domain,
            'emails' => [],
            'removed' => $target,
            'row_deleted' => true,
        ];
    }

    while (count($slots) < 4) {
        $slots[] = '';
    }
    db()->prepare(
        'UPDATE email_campaign_rows
         SET email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
         WHERE id=? AND sheet_id=?'
    )->execute([$slots[0], $slots[1], $slots[2], $slots[3], $rowId, $sheetId]);
    db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);

    $left = array_values(array_filter($slots, static fn ($e) => $e !== ''));
    return [
        'ok' => true,
        'domain' => $domain,
        'emails' => $left,
        'removed' => $target,
        'row_deleted' => false,
    ];
}

function user_in_communication_team(array $user): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    if (($user['role'] ?? '') !== 'team') {
        return false;
    }
    try {
        $dept = get_department_by_slug('communication');
        if (!$dept) {
            return false;
        }
        return user_in_department((int) ($user['id'] ?? 0), (int) $dept['id']);
    } catch (Throwable $e) {
        return false;
    }
}


/**
 * Render Communication Team search bars (one per visible project sheet).
 */
function render_email_campaign_search_panels(?int $onlySheetId = null, string $postBase = 'index.php?page=team_email_campaigns'): void
{
    render_email_campaign_super_search($postBase, $onlySheetId);
}

/**
 * One search bar per Admin-created sheet that is marked visible to Communication Team.
 * Each bar is titled with the admin-assigned project name; delete site+emails as before.
 */
function render_email_campaign_super_search(
    string $postBase = 'index.php?page=team_email_campaigns',
    ?int $onlySheetId = null
): void {
    ensure_email_campaign_schema();
    $sheets = list_email_campaign_sheets(true);
    if ($onlySheetId !== null && $onlySheetId > 0) {
        $sheets = array_values(array_filter(
            $sheets,
            static fn (array $s): bool => (int) $s['id'] === $onlySheetId
        ));
    }
    if ($sheets === []) {
        echo '<div class="card"><div class="empty-state">';
        echo '<p>No project search bars are available yet.</p>';
        echo '<p class="muted">When Admin creates an Email Sheet and turns on “Show to Communication Team”, a search bar appears here with that project name.</p>';
        echo '</div></div>';
        return;
    }

    foreach ($sheets as $s) {
        $sid = (int) $s['id'];
        $project = (string) $s['project_name'];
        $country = (string) $s['country'];
        $sites = (int) $s['row_count'];
        $uid = 'camp-sheet-' . $sid;
        $suggestUrl = $postBase . '&ajax=suggest&sheet_id=' . $sid;
        ?>
  <div class="card camp-search-card" style="margin-bottom:1rem"
       data-camp-search
       data-sheet-id="<?= $sid ?>"
       data-sheet-name="<?= h($project) ?>"
       data-suggest-url="<?= h($suggestUrl) ?>"
       data-post-url="<?= h($postBase) ?>">
    <h2 style="margin-top:0"><?= label_with_info(
        $project,
        'Project search bar for this Email Sheet. Search site + emails, then delete both or remove only email (same as before). Removing the last email also deletes the site row.'
    ) ?></h2>
    <p class="help muted" style="margin-top:0">
      <?= h($country) ?> ·
      <?= $sites ?> site<?= $sites === 1 ? '' : 's' ?> ·
      search site or email · delete both or remove only email
    </p>
    <label class="swe-admin-delete-label" for="<?= h($uid) ?>"><?= label_with_info('Search site name or email', 'Live suggestions for this project sheet. Site name and emails always appear together.') ?></label>
    <div class="swe-admin-delete-search">
      <input id="<?= h($uid) ?>" type="search" class="swe-admin-delete-input" data-camp-q
             placeholder="Type site or email in <?= h($project) ?>…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to search this project · Arrow keys · Enter to select / confirm">
      <ul class="swe-admin-delete-suggest" data-camp-suggest hidden></ul>
    </div>
    <p class="help camp-status" data-camp-status hidden></p>
    <div class="swe-admin-delete-selected" data-camp-selected hidden>
      <h3 style="margin-top:1rem">Selected</h3>
      <p class="help">Site name and emails stay together. Action updates this project sheet.</p>
      <div class="swe-admin-delete-panel">
        <div>
          <div class="muted" style="font-size:0.82rem">Site name</div>
          <div class="swe-admin-delete-domain" data-camp-sel-domain></div>
          <div class="muted" data-camp-sel-country style="margin-top:0.25rem"></div>
        </div>
        <div>
          <div class="muted" style="font-size:0.82rem;margin-bottom:0.35rem">Emails</div>
          <ul class="swe-admin-delete-emails" data-camp-sel-emails></ul>
          <p class="help" data-camp-no-emails hidden>No emails on this site.</p>
        </div>
      </div>
      <fieldset class="camp-action-fieldset">
        <legend class="visually-hidden">Update action</legend>
        <label class="camp-action-choice">
          <input type="radio" name="camp_action_<?= h($uid) ?>" value="row" data-camp-mode checked>
          Delete both (site name + all emails)
        </label>
        <label class="camp-action-choice">
          <input type="radio" name="camp_action_<?= h($uid) ?>" value="email" data-camp-mode>
          Remove only email
        </label>
        <div class="camp-email-pick" data-camp-email-pick hidden>
          <label class="muted" style="font-size:0.82rem" for="camp-email-select-<?= h($uid) ?>">Which email</label>
          <select id="camp-email-select-<?= h($uid) ?>" data-camp-email-select></select>
        </div>
      </fieldset>
      <div class="actions" style="margin-top:0.85rem;flex-wrap:wrap;gap:0.5rem">
        <button type="button" class="btn danger" data-camp-apply>Update (Enter)</button>
        <button type="button" class="btn secondary" data-camp-clear>Clear selection</button>
      </div>
    </div>
  </div>
        <?php
    }
    echo '<script src="' . h(script_asset_url('js/email-campaign-search.js')) . '" defer></script>';
}
