<?php
/**
 * Email campaign data (Emails DATA → Email campaign data).
 *
 * Model:
 *   Project  → many Country sheets (Admin adds only the countries they need)
 *   Country sheet → site + email rows (paginated)
 *   Communication Team → one search bar per project (searches all countries in it;
 *     deletes update the corresponding country sheet, same as before)
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
        "CREATE TABLE IF NOT EXISTS email_campaign_projects (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(180) NOT NULL,
          team_search_visible TINYINT(1) NOT NULL DEFAULT 1,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_project_name (name),
          INDEX (team_search_visible),
          INDEX (updated_at),
          CONSTRAINT fk_email_campaign_project_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_sheets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(180) NOT NULL,
          project_id INT NULL,
          project_name VARCHAR(180) NOT NULL DEFAULT '',
          team_search_visible TINYINT(1) NOT NULL DEFAULT 1,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_sheet_name (name),
          INDEX (updated_at),
          INDEX (team_search_visible),
          INDEX (project_id),
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
          email_sent TINYINT(1) NOT NULL DEFAULT 0,
          email_sent_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_sheet_domain (sheet_id, domain),
          INDEX (sheet_id),
          INDEX idx_email_campaign_sheet_id (sheet_id, id),
          INDEX idx_email_campaign_sheet_sent (sheet_id, email_sent),
          INDEX (domain),
          INDEX (country),
          CONSTRAINT fk_email_campaign_row_sheet
            FOREIGN KEY (sheet_id) REFERENCES email_campaign_sheets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Older installs: composite index for paginated sheet browsing + emailed checkpoint columns.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM email_campaign_rows')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $have = array_fill_keys(array_map('strval', $cols), true);
        if (!isset($have['email_sent'])) {
            $pdo->exec(
                'ALTER TABLE email_campaign_rows
                 ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER email4,
                 ADD INDEX idx_email_campaign_sheet_sent (sheet_id, email_sent)'
            );
        }
        if (!isset($have['email_sent_at'])) {
            $pdo->exec(
                'ALTER TABLE email_campaign_rows
                 ADD COLUMN email_sent_at TIMESTAMP NULL DEFAULT NULL AFTER email_sent'
            );
        }
        $idx = $pdo->query('SHOW INDEX FROM email_campaign_rows')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $haveIdx = [];
        foreach ($idx as $row) {
            $haveIdx[(string) ($row['Key_name'] ?? '')] = true;
        }
        if (empty($haveIdx['idx_email_campaign_sheet_id'])) {
            $pdo->exec('ALTER TABLE email_campaign_rows ADD INDEX idx_email_campaign_sheet_id (sheet_id, id)');
        }
        if (empty($haveIdx['idx_email_campaign_sheet_sent']) && isset($have['email_sent'])) {
            // Column may have existed without index on very old installs.
            try {
                $pdo->exec(
                    'ALTER TABLE email_campaign_rows
                     ADD INDEX idx_email_campaign_sheet_sent (sheet_id, email_sent)'
                );
            } catch (Throwable $e) {
                // ignore duplicate index
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Domains removed on purpose from a sheet — archive import must never re-add them.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_excluded_domains (
          id INT AUTO_INCREMENT PRIMARY KEY,
          sheet_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          excluded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_excluded (sheet_id, domain),
          INDEX (sheet_id),
          INDEX (domain),
          CONSTRAINT fk_email_campaign_excluded_sheet
            FOREIGN KEY (sheet_id) REFERENCES email_campaign_sheets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Older installs: project name / visibility / project_id + multi-country uniqueness.
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
        if (!isset($have['project_id'])) {
            $pdo->exec(
                "ALTER TABLE email_campaign_sheets
                 ADD COLUMN project_id INT NULL AFTER name,
                 ADD INDEX (project_id)"
            );
        }
        $pdo->exec(
            "UPDATE email_campaign_sheets
             SET project_name = name
             WHERE TRIM(project_name) = ''"
        );
        migrate_email_campaign_sheets_into_projects();
    } catch (Throwable $e) {
        // ignore migration hiccups
    }

    // Communication Team / Admin: reusable outreach text per project.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_drafts (
          id INT AUTO_INCREMENT PRIMARY KEY,
          project_id INT NOT NULL,
          category VARCHAR(40) NOT NULL DEFAULT 'custom',
          title VARCHAR(180) NOT NULL,
          subject VARCHAR(255) NOT NULL DEFAULT '',
          body MEDIUMTEXT NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          created_by INT NULL,
          updated_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (project_id),
          INDEX idx_email_campaign_draft_cat (project_id, category),
          INDEX (updated_at),
          CONSTRAINT fk_email_campaign_draft_project
            FOREIGN KEY (project_id) REFERENCES email_campaign_projects(id) ON DELETE CASCADE,
          CONSTRAINT fk_email_campaign_draft_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Existing installs: track who last edited (Admin-only delete still uses role check).
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM email_campaign_drafts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $have = array_fill_keys(array_map('strval', $cols), true);
        if (!isset($have['updated_by'])) {
            $pdo->exec(
                'ALTER TABLE email_campaign_drafts
                 ADD COLUMN updated_by INT NULL AFTER created_by'
            );
        }
        if (!isset($have['subject'])) {
            $pdo->exec(
                "ALTER TABLE email_campaign_drafts
                 ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT '' AFTER title"
            );
        }
    } catch (Throwable $e) {
        // ignore migration hiccups
    }
}

/**
 * One-time: group legacy country sheets into projects by project_name,
 * then allow the same country under different projects.
 */
function migrate_email_campaign_sheets_into_projects(): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    $pdo = db();

    $sheets = $pdo->query(
        'SELECT id, name, project_name, team_search_visible, created_by, project_id
         FROM email_campaign_sheets
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($sheets === []) {
        // Still ensure unique key shape for empty DBs created with old UNIQUE(name).
        email_campaign_ensure_project_country_unique_key();
        return;
    }

    $findProject = $pdo->prepare('SELECT id FROM email_campaign_projects WHERE name=? LIMIT 1');
    $insProject = $pdo->prepare(
        'INSERT INTO email_campaign_projects (name, team_search_visible, created_by)
         VALUES (?,?,?)'
    );
    $linkSheet = $pdo->prepare(
        'UPDATE email_campaign_sheets
         SET project_id=?, project_name=?, team_search_visible=?, updated_at=updated_at
         WHERE id=?'
    );

    foreach ($sheets as $sheet) {
        if ((int) ($sheet['project_id'] ?? 0) > 0) {
            continue;
        }
        $projectName = trim((string) ($sheet['project_name'] ?? ''));
        if ($projectName === '') {
            $projectName = trim((string) ($sheet['name'] ?? 'Project'));
        }
        if (mb_strlen($projectName) > 180) {
            $projectName = mb_substr($projectName, 0, 180);
        }
        $findProject->execute([$projectName]);
        $projectId = (int) $findProject->fetchColumn();
        if ($projectId < 1) {
            $insProject->execute([
                $projectName,
                (int) ($sheet['team_search_visible'] ?? 1) ? 1 : 0,
                !empty($sheet['created_by']) ? (int) $sheet['created_by'] : null,
            ]);
            $projectId = (int) $pdo->lastInsertId();
        }
        // Keep sheet denormalized fields in sync with project for older readers.
        $proj = $pdo->prepare('SELECT name, team_search_visible FROM email_campaign_projects WHERE id=?');
        $proj->execute([$projectId]);
        $p = $proj->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkSheet->execute([
            $projectId,
            (string) ($p['name'] ?? $projectName),
            (int) ($p['team_search_visible'] ?? 1) ? 1 : 0,
            (int) $sheet['id'],
        ]);
    }

    email_campaign_ensure_project_country_unique_key();
}

/** Drop global one-country uniqueness; enforce unique country per project. */
function email_campaign_ensure_project_country_unique_key(): void
{
    $pdo = db();
    try {
        $idx = $pdo->query('SHOW INDEX FROM email_campaign_sheets')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $have = [];
        foreach ($idx as $row) {
            $have[(string) ($row['Key_name'] ?? '')] = true;
        }
        if (!empty($have['uniq_email_campaign_sheet_name'])) {
            $pdo->exec('ALTER TABLE email_campaign_sheets DROP INDEX uniq_email_campaign_sheet_name');
        }
        if (empty($have['uniq_email_campaign_project_country'])) {
            // Nullable project_id rows would collide; force orphans into a placeholder project first.
            $pdo->exec(
                "UPDATE email_campaign_sheets s
                 SET project_id = (
                   SELECT p.id FROM email_campaign_projects p
                   WHERE p.name = IF(TRIM(s.project_name)='', s.name, s.project_name)
                   LIMIT 1
                 )
                 WHERE project_id IS NULL"
            );
            $pdo->exec(
                'ALTER TABLE email_campaign_sheets
                 ADD UNIQUE KEY uniq_email_campaign_project_country (project_id, name)'
            );
        }
    } catch (Throwable $e) {
        // ignore — Hostinger may already have the right key
    }
}

/**
 * Normalize a site name the same way campaign rows store domain.
 */
function normalize_email_campaign_domain(string $domainRaw): string
{
    $host = function_exists('extract_host_candidate')
        ? extract_host_candidate($domainRaw)
        : trim($domainRaw);
    $domain = function_exists('to_root_domain') ? to_root_domain($host) : strtolower(trim($host));
    return trim((string) $domain);
}

/**
 * Remember a domain so Final/Admin archive import will not bring it back.
 */
function exclude_email_campaign_domain(int $sheetId, string $domainRaw): bool
{
    ensure_email_campaign_schema();
    $domain = normalize_email_campaign_domain($domainRaw);
    if ($sheetId < 1 || $domain === '' || str_starts_with($domain, '__blank_')) {
        return false;
    }
    db()->prepare(
        'INSERT INTO email_campaign_excluded_domains (sheet_id, domain)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE excluded_at = VALUES(excluded_at)'
    )->execute([$sheetId, $domain]);
    return true;
}

function clear_email_campaign_domain_exclusion(int $sheetId, string $domainRaw): bool
{
    ensure_email_campaign_schema();
    $domain = normalize_email_campaign_domain($domainRaw);
    if ($sheetId < 1 || $domain === '') {
        return false;
    }
    $st = db()->prepare(
        'DELETE FROM email_campaign_excluded_domains WHERE sheet_id=? AND domain=?'
    );
    $st->execute([$sheetId, $domain]);
    return $st->rowCount() > 0;
}

function is_email_campaign_domain_excluded(int $sheetId, string $domainRaw): bool
{
    ensure_email_campaign_schema();
    $domain = normalize_email_campaign_domain($domainRaw);
    if ($sheetId < 1 || $domain === '') {
        return false;
    }
    $st = db()->prepare(
        'SELECT 1 FROM email_campaign_excluded_domains WHERE sheet_id=? AND domain=? LIMIT 1'
    );
    $st->execute([$sheetId, $domain]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * @return list<array{id:int,domain:string,excluded_at:string}>
 */
/**
 * @return list<array{id:int,domain:string,excluded_at:string}>
 */
function list_email_campaign_excluded_domains(int $sheetId, int $limit = 200): array
{
    ensure_email_campaign_schema();
    $limit = max(1, min(2000, $limit));
    $st = db()->prepare(
        "SELECT id, domain, excluded_at
         FROM email_campaign_excluded_domains
         WHERE sheet_id=?
         ORDER BY domain ASC
         LIMIT {$limit}"
    );
    $st->execute([$sheetId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'domain' => (string) ($row['domain'] ?? ''),
            'excluded_at' => (string) ($row['excluded_at'] ?? ''),
        ];
    }
    return $out;
}

function count_email_campaign_excluded_domains(int $sheetId): int
{
    ensure_email_campaign_schema();
    $st = db()->prepare(
        'SELECT COUNT(*) FROM email_campaign_excluded_domains WHERE sheet_id=?'
    );
    $st->execute([$sheetId]);
    return (int) $st->fetchColumn();
}

/**
 * Sheet "name" is always the canonical country name.
 */
function email_campaign_sheet_country(array $sheet): string
{
    return (string) ($sheet['name'] ?? '');
}

function get_email_campaign_project(int $projectId): ?array
{
    ensure_email_campaign_schema();
    if ($projectId < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM email_campaign_projects WHERE id=? LIMIT 1');
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_email_campaign_project_by_name(string $name): ?array
{
    ensure_email_campaign_schema();
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM email_campaign_projects WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Project label for a sheet (from parent project when linked).
 */
function email_campaign_sheet_project_name(array $sheet): string
{
    $projectId = (int) ($sheet['project_id'] ?? 0);
    if ($projectId > 0) {
        $project = get_email_campaign_project($projectId);
        if ($project) {
            $name = trim((string) ($project['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    $project = trim((string) ($sheet['project_name'] ?? ''));
    if ($project !== '') {
        return $project;
    }
    return email_campaign_sheet_country($sheet);
}

function email_campaign_sheet_team_visible(array $sheet): bool
{
    $projectId = (int) ($sheet['project_id'] ?? 0);
    if ($projectId > 0) {
        $project = get_email_campaign_project($projectId);
        if ($project) {
            return (int) ($project['team_search_visible'] ?? 1) === 1;
        }
    }
    return (int) ($sheet['team_search_visible'] ?? 1) === 1;
}

function email_campaign_project_team_visible(array $project): bool
{
    return (int) ($project['team_search_visible'] ?? 1) === 1;
}

/**
 * @return list<array{
 *   id:int,name:string,team_search_visible:bool,country_count:int,row_count:int,
 *   countries:list<string>,created_at:?string,updated_at:?string
 * }>
 */
function list_email_campaign_projects(?bool $onlyTeamVisible = null): array
{
    ensure_email_campaign_schema();
    $sql = "SELECT p.id, p.name, p.team_search_visible, p.created_at, p.updated_at,
                   COUNT(DISTINCT s.id) AS country_count,
                   COALESCE(SUM(
                     CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_' THEN 1 ELSE 0 END
                   ), 0) AS row_count
            FROM email_campaign_projects p
            LEFT JOIN email_campaign_sheets s ON s.project_id = p.id
            LEFT JOIN email_campaign_rows r ON r.sheet_id = s.id";
    if ($onlyTeamVisible === true) {
        $sql .= ' WHERE p.team_search_visible = 1';
    } elseif ($onlyTeamVisible === false) {
        $sql .= ' WHERE p.team_search_visible = 0';
    }
    $sql .= ' GROUP BY p.id, p.name, p.team_search_visible, p.created_at, p.updated_at
              ORDER BY p.name ASC';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $countriesByProject = [];
    $cSt = db()->query(
        'SELECT project_id, name FROM email_campaign_sheets
         WHERE project_id IS NOT NULL
         ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cSt as $c) {
        $pid = (int) ($c['project_id'] ?? 0);
        if ($pid < 1) {
            continue;
        }
        $countriesByProject[$pid][] = (string) $c['name'];
    }
    $out = [];
    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $out[] = [
            'id' => $pid,
            'name' => (string) $row['name'],
            'team_search_visible' => (int) ($row['team_search_visible'] ?? 1) === 1,
            'country_count' => (int) $row['country_count'],
            'row_count' => (int) $row['row_count'],
            'countries' => $countriesByProject[$pid] ?? [],
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
    return $out;
}

/**
 * @return list<array{id:int,name:string,country:string,project_id:int,project_name:string,team_search_visible:bool,region:string,language:string,row_count:int,with_emails:int,created_at:?string,updated_at:?string}>
 */
function list_email_campaign_sheets_for_project(int $projectId): array
{
    return list_email_campaign_sheets(null, $projectId);
}

/**
 * @param bool|null $onlyTeamVisible true = only sheets in projects shown to Communication Team
 * @return list<array{id:int,name:string,country:string,project_id:int,project_name:string,team_search_visible:bool,region:string,language:string,row_count:int,with_emails:int,created_at:?string,updated_at:?string}>
 */
function list_email_campaign_sheets(?bool $onlyTeamVisible = null, ?int $projectId = null): array
{
    ensure_email_campaign_schema();
    $sql = "SELECT s.id, s.name, s.project_id, s.project_name, s.team_search_visible, s.created_at, s.updated_at,
                   p.name AS project_title, p.team_search_visible AS project_team_visible,
                   COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_' THEN 1 ELSE 0 END), 0) AS row_count,
                   COALESCE(SUM(
                     CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_'
                               AND (r.email1<>'' OR r.email2<>'' OR r.email3<>'' OR r.email4<>'')
                          THEN 1 ELSE 0 END
                   ), 0) AS with_emails
            FROM email_campaign_sheets s
            LEFT JOIN email_campaign_projects p ON p.id = s.project_id
            LEFT JOIN email_campaign_rows r ON r.sheet_id = s.id
            WHERE 1=1";
    $params = [];
    if ($projectId !== null && $projectId > 0) {
        $sql .= ' AND s.project_id = ?';
        $params[] = $projectId;
    }
    if ($onlyTeamVisible === true) {
        $sql .= ' AND COALESCE(p.team_search_visible, s.team_search_visible) = 1';
    } elseif ($onlyTeamVisible === false) {
        $sql .= ' AND COALESCE(p.team_search_visible, s.team_search_visible) = 0';
    }
    $sql .= ' GROUP BY s.id, s.name, s.project_id, s.project_name, s.team_search_visible, s.created_at, s.updated_at,
                       p.name, p.team_search_visible
              ORDER BY COALESCE(p.name, s.project_name) ASC, s.name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $country = (string) $row['name'];
        $canon = resolve_canonical_country($country);
        $project = trim((string) ($row['project_title'] ?? ''));
        if ($project === '') {
            $project = trim((string) ($row['project_name'] ?? ''));
        }
        if ($project === '') {
            $project = $canon ? $canon['name'] : $country;
        }
        $visible = isset($row['project_team_visible'])
            ? ((int) $row['project_team_visible'] === 1)
            : ((int) ($row['team_search_visible'] ?? 1) === 1);
        $out[] = [
            'id' => (int) $row['id'],
            'name' => $canon ? $canon['name'] : $country,
            'country' => $canon ? $canon['name'] : $country,
            'project_id' => (int) ($row['project_id'] ?? 0),
            'project_name' => $project,
            'team_search_visible' => $visible,
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

function get_email_campaign_sheet_by_country(string $country, ?int $projectId = null): ?array
{
    ensure_email_campaign_schema();
    $canon = require_canonical_country($country);
    if ($projectId !== null && $projectId > 0) {
        $stmt = db()->prepare(
            'SELECT * FROM email_campaign_sheets WHERE project_id=? AND name=? LIMIT 1'
        );
        $stmt->execute([$projectId, $canon['name']]);
    } else {
        // Legacy helper: first sheet for that country (any project).
        $stmt = db()->prepare('SELECT * FROM email_campaign_sheets WHERE name=? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$canon['name']]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create or return a project by name.
 */
function create_email_campaign_project(
    string $projectName,
    int $actorId = 0,
    bool $teamSearchVisible = true
): int {
    ensure_email_campaign_schema();
    $project = trim($projectName);
    if ($project === '') {
        throw new InvalidArgumentException('Project name is required.');
    }
    if (mb_strlen($project) > 180) {
        $project = mb_substr($project, 0, 180);
    }
    $existing = get_email_campaign_project_by_name($project);
    if ($existing) {
        return (int) $existing['id'];
    }
    try {
        db()->prepare(
            'INSERT INTO email_campaign_projects (name, team_search_visible, created_by)
             VALUES (?,?,?)'
        )->execute([
            $project,
            $teamSearchVisible ? 1 : 0,
            $actorId > 0 ? $actorId : null,
        ]);
    } catch (PDOException $e) {
        $again = get_email_campaign_project_by_name($project);
        if ($again) {
            return (int) $again['id'];
        }
        throw new InvalidArgumentException('Could not create project “' . $project . '”.');
    }
    return (int) db()->lastInsertId();
}

/**
 * Add a country sheet under a project (or return the existing one).
 */
function add_email_campaign_country_to_project(int $projectId, string $country, int $actorId = 0): int
{
    ensure_email_campaign_schema();
    $project = get_email_campaign_project($projectId);
    if (!$project) {
        throw new InvalidArgumentException('Project not found.');
    }
    $canon = require_canonical_country($country);
    $name = $canon['name'];
    $existing = get_email_campaign_sheet_by_country($name, $projectId);
    if ($existing) {
        return (int) $existing['id'];
    }
    $visible = email_campaign_project_team_visible($project) ? 1 : 0;
    $projectName = (string) $project['name'];
    try {
        db()->prepare(
            'INSERT INTO email_campaign_sheets
               (name, project_id, project_name, team_search_visible, created_by)
             VALUES (?,?,?,?,?)'
        )->execute([
            $name,
            $projectId,
            $projectName,
            $visible,
            $actorId > 0 ? $actorId : null,
        ]);
    } catch (PDOException $e) {
        $again = get_email_campaign_sheet_by_country($name, $projectId);
        if ($again) {
            return (int) $again['id'];
        }
        throw new InvalidArgumentException('Could not add “' . $name . '” to this project.');
    }
    $newId = (int) db()->lastInsertId();
    db()->prepare('UPDATE email_campaign_projects SET updated_at=NOW() WHERE id=?')->execute([$projectId]);
    return $newId;
}

/**
 * Create (or return) a country sheet inside a project.
 * Same country may exist in different projects with different data.
 */
function create_email_campaign_sheet(
    string $country,
    int $actorId = 0,
    string $projectName = '',
    bool $teamSearchVisible = true
): int {
    ensure_email_campaign_schema();
    $canon = require_canonical_country($country);
    $project = trim($projectName);
    if ($project === '') {
        $project = $canon['name'];
    }
    // Reuse existing project by name without changing its visibility/settings.
    // Use update_email_campaign_project_settings() to change project settings explicitly.
    $projectId = create_email_campaign_project($project, $actorId, $teamSearchVisible);
    return add_email_campaign_country_to_project($projectId, $canon['name'], $actorId);
}

/**
 * @return array{ok:bool,error?:string}
 */
function update_email_campaign_project_settings(
    int $projectId,
    string $projectName,
    bool $teamSearchVisible
): array {
    ensure_email_campaign_schema();
    if (!get_email_campaign_project($projectId)) {
        return ['ok' => false, 'error' => 'Project not found.'];
    }
    $project = trim($projectName);
    if ($project === '') {
        return ['ok' => false, 'error' => 'Project name is required.'];
    }
    if (mb_strlen($project) > 180) {
        $project = mb_substr($project, 0, 180);
    }
    $clash = get_email_campaign_project_by_name($project);
    if ($clash && (int) $clash['id'] !== $projectId) {
        return ['ok' => false, 'error' => 'Another project already uses that name.'];
    }
    db()->prepare(
        'UPDATE email_campaign_projects
         SET name=?, team_search_visible=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$project, $teamSearchVisible ? 1 : 0, $projectId]);
    // Keep denormalized sheet fields in sync.
    db()->prepare(
        'UPDATE email_campaign_sheets
         SET project_name=?, team_search_visible=?, updated_at=NOW()
         WHERE project_id=?'
    )->execute([$project, $teamSearchVisible ? 1 : 0, $projectId]);
    return ['ok' => true];
}

/**
 * Update project settings via any sheet in that project (legacy sheet settings form).
 *
 * @return array{ok:bool,error?:string}
 */
function update_email_campaign_sheet_settings(
    int $sheetId,
    string $projectName,
    bool $teamSearchVisible
): array {
    ensure_email_campaign_schema();
    $sheet = get_email_campaign_sheet($sheetId);
    if (!$sheet) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    $projectId = (int) ($sheet['project_id'] ?? 0);
    if ($projectId < 1) {
        $projectId = create_email_campaign_project(
            $projectName !== '' ? $projectName : email_campaign_sheet_country($sheet),
            (int) ($sheet['created_by'] ?? 0),
            $teamSearchVisible
        );
        db()->prepare('UPDATE email_campaign_sheets SET project_id=? WHERE id=?')
            ->execute([$projectId, $sheetId]);
    }
    return update_email_campaign_project_settings($projectId, $projectName, $teamSearchVisible);
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

function set_email_campaign_project_team_visible(int $projectId, bool $visible): array
{
    $project = get_email_campaign_project($projectId);
    if (!$project) {
        return ['ok' => false, 'error' => 'Project not found.'];
    }
    return update_email_campaign_project_settings(
        $projectId,
        (string) $project['name'],
        $visible
    );
}

function delete_email_campaign_project(int $projectId): bool
{
    ensure_email_campaign_schema();
    // Sheets cascade via app delete (rows/exclusions cascade from sheets).
    $sheets = list_email_campaign_sheets_for_project($projectId);
    foreach ($sheets as $s) {
        delete_email_campaign_sheet((int) $s['id']);
    }
    $stmt = db()->prepare('DELETE FROM email_campaign_projects WHERE id=?');
    $stmt->execute([$projectId]);
    return $stmt->rowCount() > 0;
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

/**
 * @return array{total:int,sent:int,unsent:int}
 */
function count_email_campaign_sent_stats(int $sheetId): array
{
    ensure_email_campaign_schema();
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN email_sent=1 THEN 1 ELSE 0 END), 0) AS sent
         FROM email_campaign_rows
         WHERE sheet_id=? AND LEFT(domain, 8) <> '__blank_'"
    );
    $stmt->execute([$sheetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int) ($row['total'] ?? 0);
    $sent = (int) ($row['sent'] ?? 0);
    return [
        'total' => $total,
        'sent' => $sent,
        'unsent' => max(0, $total - $sent),
    ];
}

/**
 * Mark one campaign sheet row emailed / not emailed.
 *
 * @return array{ok:bool,error?:string,domain?:string,email_sent?:bool,sheet_id?:int}
 */
function set_email_campaign_row_email_sent(int $sheetId, int $rowId, bool $sent): array
{
    ensure_email_campaign_schema();
    $row = get_email_campaign_row($rowId, $sheetId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found on this Email sheet.'];
    }
    if ($sent) {
        db()->prepare(
            'UPDATE email_campaign_rows
             SET email_sent=1, email_sent_at=NOW()
             WHERE id=? AND sheet_id=?'
        )->execute([$rowId, $sheetId]);
    } else {
        db()->prepare(
            'UPDATE email_campaign_rows
             SET email_sent=0, email_sent_at=NULL
             WHERE id=? AND sheet_id=?'
        )->execute([$rowId, $sheetId]);
    }
    touch_email_campaign_sheet($sheetId);
    return [
        'ok' => true,
        'domain' => (string) $row['domain'],
        'email_sent' => $sent,
        'sheet_id' => $sheetId,
    ];
}

/**
 * Checkpoint: mark every row on this sheet with id <= $rowId as emailed.
 *
 * @return array{ok:bool,error?:string,marked?:int,domain?:string,sheet_id?:int}
 */
function mark_email_campaign_emailed_up_to(int $sheetId, int $rowId): array
{
    ensure_email_campaign_schema();
    $row = get_email_campaign_row($rowId, $sheetId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found on this Email sheet.'];
    }
    $st = db()->prepare(
        "UPDATE email_campaign_rows
         SET email_sent=1, email_sent_at=COALESCE(email_sent_at, NOW())
         WHERE sheet_id=? AND id<=? AND email_sent=0
           AND LEFT(domain, 8) <> '__blank_'"
    );
    $st->execute([$sheetId, $rowId]);
    touch_email_campaign_sheet($sheetId);
    return [
        'ok' => true,
        'marked' => $st->rowCount(),
        'domain' => (string) $row['domain'],
        'sheet_id' => $sheetId,
    ];
}

/**
 * Undo checkpoint: clear emailed marks on every row on this sheet with id <= $rowId.
 *
 * @return array{ok:bool,error?:string,cleared?:int,domain?:string,sheet_id?:int}
 */
function clear_email_campaign_emailed_up_to(int $sheetId, int $rowId): array
{
    ensure_email_campaign_schema();
    $row = get_email_campaign_row($rowId, $sheetId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Site not found on this Email sheet.'];
    }
    $st = db()->prepare(
        "UPDATE email_campaign_rows
         SET email_sent=0, email_sent_at=NULL
         WHERE sheet_id=? AND id<=? AND email_sent=1
           AND LEFT(domain, 8) <> '__blank_'"
    );
    $st->execute([$sheetId, $rowId]);
    touch_email_campaign_sheet($sheetId);
    return [
        'ok' => true,
        'cleared' => $st->rowCount(),
        'domain' => (string) $row['domain'],
        'sheet_id' => $sheetId,
    ];
}

/**
 * Clear every emailed mark on one Email campaign sheet so Admin can resend and re-track.
 *
 * @return array{ok:bool,error?:string,cleared?:int,sheet_id?:int}
 */
function clear_all_email_campaign_emailed(int $sheetId): array
{
    ensure_email_campaign_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        return ['ok' => false, 'error' => 'Sheet not found.'];
    }
    $st = db()->prepare(
        "UPDATE email_campaign_rows
         SET email_sent=0, email_sent_at=NULL
         WHERE sheet_id=? AND email_sent=1
           AND LEFT(domain, 8) <> '__blank_'"
    );
    $st->execute([$sheetId]);
    touch_email_campaign_sheet($sheetId);
    return [
        'ok' => true,
        'cleared' => $st->rowCount(),
        'sheet_id' => $sheetId,
    ];
}

/**
 * Paginated Email Sheet rows — same model as Our database / Sites with emails.
 * Never load 100K rows into one page; use page + optional site/email search.
 *
 * @param array{q?:string,sent?:string} $filters sent: '', '0', '1'
 * @return array{rows:list<array<string,mixed>>,total:int,pages:int,page:int,per_page:int}
 */
function email_campaign_rows_inventory_query(
    int $sheetId,
    array $filters = [],
    int $page = 1,
    int $perPage = 1000
): array {
    ensure_email_campaign_schema();
    purge_blank_email_campaign_rows($sheetId);

    $page = max(1, $page);
    $perPage = max(1, min(1000, $perPage));
    $q = trim((string) ($filters['q'] ?? ''));
    $sentFilter = (string) ($filters['sent'] ?? ''); // '', '0', '1'

    $where = ["sheet_id = ?", "LEFT(domain, 8) <> '__blank_'"];
    $params = [$sheetId];
    if ($q !== '') {
        $where[] = '(domain LIKE ? OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($sentFilter === '0' || $sentFilter === '1') {
        $where[] = 'email_sent = ?';
        $params[] = (int) $sentFilter;
    }
    $whereSql = implode(' AND ', $where);

    $count = db()->prepare("SELECT COUNT(*) FROM email_campaign_rows WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT * FROM email_campaign_rows
         WHERE {$whereSql}
         ORDER BY id ASC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = expand_packed_email_slots_in_campaign_rows($rows);

    return [
        'rows' => $rows,
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'per_page' => $perPage,
    ];
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
 * All rows for a sheet (tests / small ops only). Prefer
 * email_campaign_rows_inventory_query() for UI — sheets can reach 100K rows.
 *
 * @return list<array<string,mixed>>
 */
function list_email_campaign_rows(int $sheetId, int $hardLimit = 5000): array
{
    ensure_email_campaign_schema();
    purge_blank_email_campaign_rows($sheetId);
    $hardLimit = max(1, min(50000, $hardLimit));
    $stmt = db()->prepare(
        "SELECT * FROM email_campaign_rows
         WHERE sheet_id=? AND LEFT(domain, 8) <> '__blank_'
         ORDER BY id ASC
         LIMIT {$hardLimit}"
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
        exclude_email_campaign_domain($sheetId, $domain);
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

    // Manual add / paste means Admin wants this site again — lift archive exclusion.
    clear_email_campaign_domain_exclusion($sheetId, $domain);

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

            // Intentional paste/add lifts “never re-add” exclusion for this domain.
            clear_email_campaign_domain_exclusion($sheetId, $domain);

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
 * Modes:
 * - new_only (default): add domains not on the sheet; never update existing; never re-add excluded.
 * - upsert: add new + update existing emails; still never re-add excluded (deleted on purpose).
 *
 * @return array{
 *   imported:int,updated:int,skipped:int,
 *   skipped_existing:int,skipped_excluded:int,skipped_empty:int,mode:string
 * }
 */
function import_email_campaign_sheet_from_swe(
    int $sheetId,
    string $sourceScope = 'admin_all',
    ?string $country = null,
    string $mode = 'new_only'
): array {
    ensure_email_campaign_schema();
    ensure_sites_with_emails_schema();
    @set_time_limit(0);
    if (!get_email_campaign_sheet($sheetId)) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    $sourceScope = swe_normalize_scope($sourceScope);
    if (!in_array($sourceScope, ['admin', 'admin_all'], true)) {
        $sourceScope = 'admin_all';
    }
    $mode = strtolower(trim($mode));
    if ($mode !== 'upsert') {
        $mode = 'new_only';
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

    $insNew = $pdo->prepare(
        'INSERT IGNORE INTO email_campaign_rows
           (sheet_id, domain, country, language, region, email1, email2, email3, email4)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $insUpsert = $pdo->prepare(
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
    $excluded = $pdo->prepare(
        'SELECT 1 FROM email_campaign_excluded_domains WHERE sheet_id=? AND domain=? LIMIT 1'
    );

    $imported = 0;
    $updated = 0;
    $skippedExisting = 0;
    $skippedExcluded = 0;
    $skippedEmpty = 0;
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $domainRaw = trim((string) ($row['domain'] ?? ''));
        if ($domainRaw === '') {
            $skippedEmpty++;
            continue;
        }
        $domain = normalize_email_campaign_domain($domainRaw);
        if ($domain === '') {
            $domain = $domainRaw;
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
            $skippedEmpty++;
            continue;
        }

        $excluded->execute([$sheetId, $domain]);
        if ((int) $excluded->fetchColumn() > 0) {
            $skippedExcluded++;
            continue;
        }

        $exists->execute([$sheetId, $domain]);
        $already = (int) $exists->fetchColumn() > 0;
        if ($mode === 'new_only' && $already) {
            $skippedExisting++;
            continue;
        }

        $params = [
            $sheetId,
            $domain,
            (string) ($row['country'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            $slots[0],
            $slots[1],
            $slots[2],
            $slots[3],
        ];
        if ($mode === 'new_only') {
            $insNew->execute($params);
            if ($insNew->rowCount() > 0) {
                $imported++;
            } else {
                $skippedExisting++;
            }
        } else {
            $insUpsert->execute($params);
            if ($already) {
                $updated++;
            } else {
                $imported++;
            }
        }
    }
    $pdo->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
    return [
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skippedEmpty + $skippedExisting + $skippedExcluded,
        'skipped_existing' => $skippedExisting,
        'skipped_excluded' => $skippedExcluded,
        'skipped_empty' => $skippedEmpty,
        'mode' => $mode,
    ];
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
    return search_email_campaign_suggestions_scoped($q, $limit, $sheetId, null, false);
}

function search_email_campaign_suggestions_for_project(int $projectId, string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, null, $projectId, false);
}

/**
 * Search across Communication Team–visible projects (all their country sheets).
 *
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,project_name:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions_all(string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, null, null, true);
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
    ?int $projectId = null,
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
    if ($projectId !== null) {
        $project = get_email_campaign_project($projectId);
        if (!$project) {
            return [];
        }
        if ($onlyTeamVisible && !email_campaign_project_team_visible($project)) {
            return [];
        }
    }
    $limit = max(1, min(40, $limit));
    $like = '%' . $q . '%';
    $sql = "SELECT r.id, r.sheet_id, r.domain, r.country, r.email1, r.email2, r.email3, r.email4,
                   s.name AS sheet_country, s.project_name, s.project_id,
                   p.name AS project_title
            FROM email_campaign_rows r
            INNER JOIN email_campaign_sheets s ON s.id = r.sheet_id
            LEFT JOIN email_campaign_projects p ON p.id = s.project_id
            WHERE LEFT(r.domain, 8) <> '__blank_'
              AND (
                r.domain LIKE ?
                OR r.email1 LIKE ? OR r.email2 LIKE ? OR r.email3 LIKE ? OR r.email4 LIKE ?
                OR s.name LIKE ?
                OR s.project_name LIKE ?
                OR p.name LIKE ?
              )";
    $params = [$like, $like, $like, $like, $like, $like, $like, $like];
    if ($sheetId !== null) {
        $sql .= ' AND r.sheet_id = ?';
        $params[] = $sheetId;
    } elseif ($projectId !== null) {
        $sql .= ' AND s.project_id = ?';
        $params[] = $projectId;
    } elseif ($onlyTeamVisible) {
        $sql .= ' AND COALESCE(p.team_search_visible, s.team_search_visible) = 1';
    }
    $sql .= " ORDER BY
           CASE
             WHEN r.domain = ? THEN 0
             WHEN r.domain LIKE ? THEN 1
             WHEN r.email1 = ? OR r.email2 = ? OR r.email3 = ? OR r.email4 = ? THEN 2
             ELSE 3
           END,
           COALESCE(p.name, s.project_name) ASC, s.name ASC, r.domain ASC
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
        $project = trim((string) ($row['project_title'] ?? ''));
        if ($project === '') {
            $project = trim((string) ($row['project_name'] ?? ''));
        }
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
            'label' => $domain . ' · ' . $emailPreview . ' · ' . $country . ' · ' . $project,
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
    $domain = (string) $row['domain'];
    db()->prepare('DELETE FROM email_campaign_rows WHERE id=? AND sheet_id=?')->execute([$rowId, $sheetId]);
    if (!str_starts_with($domain, '__blank_')) {
        exclude_email_campaign_domain($sheetId, $domain);
    }
    db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
    return ['ok' => true, 'domain' => $domain];
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
        exclude_email_campaign_domain($sheetId, $domain);
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
 * Render Communication Team search bars (one per visible project).
 */
function render_email_campaign_search_panels(?int $onlySheetId = null, string $postBase = 'index.php?page=team_email_campaigns'): void
{
    $onlyProjectId = null;
    if ($onlySheetId !== null && $onlySheetId > 0) {
        $sheet = get_email_campaign_sheet($onlySheetId);
        $onlyProjectId = $sheet ? (int) ($sheet['project_id'] ?? 0) : null;
    }
    render_email_campaign_super_search($postBase, $onlyProjectId);
}

/**
 * One search bar per Admin project shown to Communication Team.
 * Searches every country sheet in that project; deletes update the matching country sheet.
 */
function render_email_campaign_super_search(
    string $postBase = 'index.php?page=team_email_campaigns',
    ?int $onlyProjectId = null
): void {
    ensure_email_campaign_schema();
    $projects = list_email_campaign_projects(true);
    if ($onlyProjectId !== null && $onlyProjectId > 0) {
        $projects = array_values(array_filter(
            $projects,
            static fn (array $p): bool => (int) $p['id'] === $onlyProjectId
        ));
    }
    if ($projects === []) {
        echo '<div class="card"><div class="empty-state">';
        echo '<p>No project search bars are available yet.</p>';
        echo '<p class="muted">When Admin creates a project, adds countries, and turns on “Show to Communication Team”, a search bar appears here for the whole project.</p>';
        echo '</div></div>';
        return;
    }

    foreach ($projects as $p) {
        $pid = (int) $p['id'];
        $project = (string) $p['name'];
        $sites = (int) $p['row_count'];
        $countries = $p['countries'] ?? [];
        $countryCount = (int) $p['country_count'];
        $countryPreview = $countries !== []
            ? implode(', ', array_slice($countries, 0, 6)) . (count($countries) > 6 ? '…' : '')
            : 'no countries yet';
        $uid = 'camp-project-' . $pid;
        $suggestUrl = $postBase . '&ajax=suggest&project_id=' . $pid;
        ?>
  <div class="card camp-search-card" style="margin-bottom:1rem"
       data-camp-search
       data-project-id="<?= $pid ?>"
       data-sheet-name="<?= h($project) ?>"
       data-suggest-url="<?= h($suggestUrl) ?>"
       data-post-url="<?= h($postBase) ?>">
    <h2 style="margin-top:0"><?= label_with_info(
        $project,
        'Project search bar. Searches site + emails across every country Admin added to this project. Delete both or remove only email — updates the corresponding country sheet. Removing the last email also deletes the site row.'
    ) ?></h2>
    <p class="help muted" style="margin-top:0">
      <?= (int) $countryCount ?> countr<?= $countryCount === 1 ? 'y' : 'ies' ?>
      (<?= h($countryPreview) ?>) ·
      <?= $sites ?> site<?= $sites === 1 ? '' : 's' ?> ·
      search whole project · delete updates that country’s sheet
    </p>
    <label class="swe-admin-delete-label" for="<?= h($uid) ?>"><?= label_with_info('Search site name or email', 'Live suggestions across all countries in this project. Site name and emails always appear together; country is shown so you know which sheet will be updated.') ?></label>
    <div class="swe-admin-delete-search">
      <input id="<?= h($uid) ?>" type="search" class="swe-admin-delete-input" data-camp-q
             placeholder="Type site or email in <?= h($project) ?>…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to search this whole project · Arrow keys · Enter to select / confirm">
      <ul class="swe-admin-delete-suggest" data-camp-suggest hidden></ul>
    </div>
    <p class="help camp-status" data-camp-status hidden></p>
    <div class="swe-admin-delete-selected" data-camp-selected hidden>
      <h3 style="margin-top:1rem">Selected</h3>
      <p class="help">Site name and emails stay together. Action updates that country’s sheet inside this project.</p>
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

/**
 * Fixed categories for Communication outreach drafts (per project).
 *
 * @return array<string,string> slug => label
 */
function email_campaign_draft_categories(): array
{
    return [
        'first_outreach' => 'First outreach',
        'follow_up' => 'Follow-up',
        'offer' => 'Offer / pricing',
        'reply' => 'Reply',
        'soft_no' => 'Soft no / later',
        'custom' => 'Custom',
    ];
}

function normalize_email_campaign_draft_category(string $category): string
{
    $category = strtolower(trim($category));
    $cats = email_campaign_draft_categories();
    return isset($cats[$category]) ? $category : 'custom';
}

function email_campaign_draft_category_label(string $category): string
{
    $cats = email_campaign_draft_categories();
    $slug = normalize_email_campaign_draft_category($category);
    return $cats[$slug] ?? 'Custom';
}

/**
 * Merge tokens available in draft subject/body.
 *
 * @return array<string,string> token without braces => help label
 */
function email_campaign_draft_token_defs(): array
{
    return [
        'domain' => 'Site domain',
        'site' => 'Site domain (same as domain)',
        'country' => 'Country',
        'language' => 'Language',
        'name' => 'Contact name',
    ];
}

/**
 * Replace {domain}/{site}/{country}/{language}/{name} in HTML or plain text.
 *
 * @param array{domain?:string,site?:string,country?:string,language?:string,name?:string} $vars
 */
function expand_email_campaign_draft_tokens(string $text, array $vars): string
{
    $domain = trim((string) ($vars['domain'] ?? $vars['site'] ?? ''));
    $map = [
        '{domain}' => $domain,
        '{site}' => $domain,
        '{country}' => trim((string) ($vars['country'] ?? '')),
        '{language}' => trim((string) ($vars['language'] ?? '')),
        '{name}' => trim((string) ($vars['name'] ?? '')),
    ];
    return str_ireplace(array_keys($map), array_values($map), $text);
}

/**
 * Tags Communication may use in draft bodies (email-safe formatting).
 *
 * @return list<string>
 */
function email_campaign_draft_allowed_tags(): array
{
    return ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'img'];
}

/** Max inline images (compressed data URIs) per draft. */
function email_campaign_draft_max_images(): int
{
    return 6;
}

/** Max characters for one data:image… src (≈1.5–2 MB compressed). */
function email_campaign_draft_max_image_src_len(): int
{
    return 2500000;
}

function email_campaign_draft_is_safe_data_image_src(string $src): bool
{
    $src = trim(preg_replace('/\s+/', '', $src) ?? $src);
    if ($src === '' || strlen($src) > email_campaign_draft_max_image_src_len()) {
        return false;
    }
    return (bool) preg_match(
        '#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+=*$#',
        $src
    );
}

/** Convert plain outreach text into simple paragraph HTML. */
function email_campaign_draft_plain_to_html(string $plain): string
{
    $plain = trim(str_replace(["\r\n", "\r"], "\n", $plain));
    if ($plain === '') {
        return '';
    }
    $parts = preg_split("/\n{2,}/", $plain) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $out[] = '<p>' . nl2br(h($part), false) . '</p>';
    }
    return implode('', $out);
}

/** Strip draft HTML down to plain text (clipboard / empty checks). */
function email_campaign_draft_html_to_plain(string $html): string
{
    $html = trim(str_replace(["\r\n", "\r"], "\n", $html));
    if ($html === '') {
        return '';
    }
    if (!preg_match('/<[a-zA-Z][^>]*>/', $html)) {
        return $html;
    }
    $withBreaks = preg_replace(
        [
            '/<\s*img\b[^>]*>/i',
            '/<\s*br\s*\/?\s*>/i',
            '/<\s*\/\s*(p|h1|h2|h3)\s*>/i',
            '/<\s*(p|h1|h2|h3)(\s[^>]*)?>/i',
        ],
        ["\n[image]\n", "\n", "\n", "\n"],
        $html
    ) ?? $html;
    $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Keep only email-safe formatting tags + compressed inline images.
 * Scripts, styles, links, and unsafe attributes are stripped.
 * (Regex-based so it works without the PHP xml/DOM extension.)
 */
function sanitize_email_campaign_draft_html(string $html): string
{
    $html = trim(str_replace(["\r\n", "\r"], "\n", $html));
    if ($html === '') {
        return '';
    }
    if (!preg_match('/<[a-zA-Z][^>]*>/', $html)) {
        return email_campaign_draft_plain_to_html($html);
    }

    // Drop dangerous blocks entirely (content included).
    $html = preg_replace(
        '/<\s*(script|style|iframe|object|embed|noscript)\b[^>]*>.*?<\s*\/\s*\1\s*>/is',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '/<\s*(script|style|iframe|object|embed|meta|link|noscript)\b[^>]*\/?\s*>/is',
        '',
        $html
    ) ?? $html;

    // Pull out safe data-URI images before strip_tags (attributes would be wiped).
    $images = [];
    $maxImages = email_campaign_draft_max_images();
    $html = preg_replace_callback(
        '/<\s*img\b([^>]*)\/?\s*>/i',
        static function (array $m) use (&$images, $maxImages): string {
            if (count($images) >= $maxImages) {
                return '';
            }
            $attrs = (string) ($m[1] ?? '');
            $src = '';
            if (preg_match('/\bsrc\s*=\s*("|\')(.*?)\1/is', $attrs, $sm)) {
                $src = html_entity_decode((string) $sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/\bsrc\s*=\s*([^\s>]+)/i', $attrs, $sm)) {
                $src = html_entity_decode((string) $sm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $src = trim(preg_replace('/\s+/', '', $src) ?? $src);
            if (!email_campaign_draft_is_safe_data_image_src($src)) {
                return '';
            }
            $alt = '';
            if (preg_match('/\balt\s*=\s*("|\')(.*?)\1/is', $attrs, $am)) {
                $alt = (string) $am[2];
            }
            $alt = trim(preg_replace('/\s+/', ' ', $alt) ?? $alt);
            if (mb_strlen($alt) > 120) {
                $alt = mb_substr($alt, 0, 120);
            }
            $token = '%%CAMPIMG' . count($images) . '%%';
            $images[] = '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            return $token;
        },
        $html
    ) ?? $html;

    // Keep only formatting tags; unwrap everything else (links, spans, divs…).
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><h1><h2><h3>');

    // Strip attributes from remaining tags (onclick, style, href, etc.).
    $html = preg_replace('/<\s*([a-z0-9]+)\b[^>]*>/i', '<$1>', $html) ?? $html;
    $html = preg_replace('/<\s*\/\s*([a-z0-9]+)\s*>/i', '</$1>', $html) ?? $html;

    // Normalize aliases for consistent email paste.
    $html = preg_replace('/<\s*b\s*>/i', '<strong>', $html) ?? $html;
    $html = preg_replace('/<\s*\/\s*b\s*>/i', '</strong>', $html) ?? $html;
    $html = preg_replace('/<\s*i\s*>/i', '<em>', $html) ?? $html;
    $html = preg_replace('/<\s*\/\s*i\s*>/i', '</em>', $html) ?? $html;

    // Self-close / normalize br.
    $html = preg_replace('/<\s*br\s*>/i', '<br>', $html) ?? $html;

    foreach ($images as $i => $imgHtml) {
        $html = str_replace('%%CAMPIMG' . $i . '%%', $imgHtml, $html);
    }

    $html = trim($html);
    $hasImg = (bool) preg_match('/<\s*img\b/i', $html);
    if (email_campaign_draft_html_to_plain($html) === '' && !$hasImg) {
        return '';
    }
    return $html;
}

/** Safe HTML for rendering a stored draft (legacy plain text supported). */
function email_campaign_draft_body_html(string $body): string
{
    return sanitize_email_campaign_draft_html($body);
}

/**
 * Rich-text editor markup (toolbar + contenteditable + hidden textarea).
 *
 * @param array{placeholder?:string,rows?:int} $opts
 */
function render_email_campaign_draft_editor(
    string $textareaId,
    string $name,
    string $bodyHtml,
    array $opts = []
): void {
    $placeholder = (string) ($opts['placeholder'] ?? 'Write your outreach…');
    $safe = email_campaign_draft_body_html($bodyHtml);
    $emptyClass = $safe === '' ? ' is-empty' : '';
    ?>
    <div class="camp-draft-editor" data-camp-draft-editor
         data-max-images="<?= (int) email_campaign_draft_max_images() ?>">
      <div class="camp-draft-toolbar" role="toolbar" aria-label="Text formatting">
        <button type="button" class="btn secondary small" data-camp-draft-cmd="bold" title="Bold"><strong>B</strong></button>
        <button type="button" class="btn secondary small" data-camp-draft-cmd="italic" title="Italic"><em>I</em></button>
        <button type="button" class="btn secondary small" data-camp-draft-cmd="underline" title="Underline"><span style="text-decoration:underline">U</span></button>
        <span class="camp-draft-toolbar-sep" aria-hidden="true"></span>
        <button type="button" class="btn secondary small" data-camp-draft-cmd="h2" title="Heading">Heading</button>
        <button type="button" class="btn secondary small" data-camp-draft-cmd="h3" title="Subheading">Subhead</button>
        <button type="button" class="btn secondary small" data-camp-draft-cmd="p" title="Normal paragraph">Normal</button>
        <span class="camp-draft-toolbar-sep" aria-hidden="true"></span>
        <button type="button" class="btn secondary small" data-camp-draft-image title="Add image from computer">Image</button>
        <input type="file" accept="image/*" multiple hidden data-camp-draft-image-input>
      </div>
      <p class="help camp-draft-image-hint" style="margin:0;padding:0.35rem 0.65rem 0;font-size:0.8rem">
        Paste a screenshot or add Image — compressed for email paste (max <?= (int) email_campaign_draft_max_images() ?>).
      </p>
      <div class="camp-draft-surface<?= $emptyClass ?>"
           data-camp-draft-surface
           contenteditable="true"
           role="textbox"
           aria-multiline="true"
           aria-label="Draft text"
           data-placeholder="<?= h($placeholder) ?>"><?= $safe ?></div>
      <textarea id="<?= h($textareaId) ?>"
                name="<?= h($name) ?>"
                class="camp-draft-textarea-sync"
                data-camp-draft-sync
                hidden><?= h($safe) ?></textarea>
    </div>
    <?php
}

function get_email_campaign_draft(int $draftId): ?array
{
    ensure_email_campaign_schema();
    if ($draftId < 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT d.*,
                cu.username AS created_by_username,
                cu.full_name AS created_by_name,
                uu.username AS updated_by_username,
                uu.full_name AS updated_by_name
         FROM email_campaign_drafts d
         LEFT JOIN users cu ON cu.id = d.created_by
         LEFT JOIN users uu ON uu.id = d.updated_by
         WHERE d.id=? LIMIT 1'
    );
    $stmt->execute([$draftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function list_email_campaign_drafts(int $projectId, ?string $category = null): array
{
    ensure_email_campaign_schema();
    if ($projectId < 1) {
        return [];
    }
    $sql = 'SELECT d.*,
                   cu.username AS created_by_username,
                   cu.full_name AS created_by_name,
                   uu.username AS updated_by_username,
                   uu.full_name AS updated_by_name
            FROM email_campaign_drafts d
            LEFT JOIN users cu ON cu.id = d.created_by
            LEFT JOIN users uu ON uu.id = d.updated_by
            WHERE d.project_id=?';
    $params = [$projectId];
    if ($category !== null && $category !== '') {
        $sql .= ' AND d.category=?';
        $params[] = normalize_email_campaign_draft_category($category);
    }
    $sql .= ' ORDER BY d.category ASC, d.sort_order ASC, d.title ASC, d.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function count_email_campaign_drafts(int $projectId): int
{
    ensure_email_campaign_schema();
    if ($projectId < 1) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM email_campaign_drafts WHERE project_id=?');
    $stmt->execute([$projectId]);
    return (int) $stmt->fetchColumn();
}

/** Product rule B: Admin, or the draft’s creator, may delete. */
function email_campaign_user_can_delete_draft(?array $user, ?array $draft = null): bool
{
    if (!$user) {
        return false;
    }
    if (function_exists('is_admin') && is_admin($user)) {
        return true;
    }
    if ($draft === null) {
        return false;
    }
    $uid = (int) ($user['id'] ?? 0);
    $createdBy = (int) ($draft['created_by'] ?? 0);
    return $uid > 0 && $createdBy > 0 && $uid === $createdBy;
}

/**
 * Display name for a draft author column (full name, else username).
 */
function email_campaign_draft_person_label(?string $fullName, ?string $username): string
{
    $full = trim((string) $fullName);
    if ($full !== '') {
        return $full;
    }
    $user = trim((string) $username);
    return $user !== '' ? $user : '';
}

/**
 * Short “Created by X · Updated …” line for draft cards.
 */
function email_campaign_draft_attribution(array $draft): string
{
    $created = email_campaign_draft_person_label(
        isset($draft['created_by_name']) ? (string) $draft['created_by_name'] : null,
        isset($draft['created_by_username']) ? (string) $draft['created_by_username'] : null
    );
    $updated = email_campaign_draft_person_label(
        isset($draft['updated_by_name']) ? (string) $draft['updated_by_name'] : null,
        isset($draft['updated_by_username']) ? (string) $draft['updated_by_username'] : null
    );
    $updatedAt = trim((string) ($draft['updated_at'] ?? ''));
    $parts = [];
    if ($created !== '') {
        $parts[] = 'Created by ' . $created;
    }
    if ($updated !== '' && (int) ($draft['updated_by'] ?? 0) > 0) {
        $bits = 'Updated by ' . $updated;
        if ($updatedAt !== '') {
            $bits .= ' · ' . $updatedAt;
        }
        $parts[] = $bits;
    } elseif ($updatedAt !== '' && $created !== '') {
        $parts[] = 'Updated ' . $updatedAt;
    }
    return implode(' · ', $parts);
}

/**
 * Create or update a project draft.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function save_email_campaign_draft(
    int $projectId,
    string $title,
    string $body,
    string $category = 'custom',
    int $draftId = 0,
    int $actorId = 0,
    string $subject = ''
): array {
    ensure_email_campaign_schema();
    if (!get_email_campaign_project($projectId)) {
        return ['ok' => false, 'error' => 'Project not found.'];
    }
    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'error' => 'Draft title is required.'];
    }
    if (mb_strlen($title) > 180) {
        $title = mb_substr($title, 0, 180);
    }
    $subject = trim($subject);
    if (mb_strlen($subject) > 255) {
        $subject = mb_substr($subject, 0, 255);
    }
    $body = sanitize_email_campaign_draft_html($body);
    if ($body === '') {
        return ['ok' => false, 'error' => 'Draft text is required.'];
    }
    $category = normalize_email_campaign_draft_category($category);
    $actor = $actorId > 0 ? $actorId : null;

    if ($draftId > 0) {
        $existing = get_email_campaign_draft($draftId);
        if (!$existing || (int) ($existing['project_id'] ?? 0) !== $projectId) {
            return ['ok' => false, 'error' => 'Draft not found in this project.'];
        }
        db()->prepare(
            'UPDATE email_campaign_drafts
             SET category=?, title=?, subject=?, body=?, updated_by=?, updated_at=NOW()
             WHERE id=? AND project_id=?'
        )->execute([$category, $title, $subject, $body, $actor, $draftId, $projectId]);
        return ['ok' => true, 'id' => $draftId];
    }

    db()->prepare(
        'INSERT INTO email_campaign_drafts (project_id, category, title, subject, body, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $projectId,
        $category,
        $title,
        $subject,
        $body,
        $actor,
        $actor,
    ]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/**
 * Delete a project draft. Product rule B: creator or Admin may delete.
 *
 * @return array{ok:bool,error?:string,title?:string}
 */
function delete_email_campaign_draft(int $projectId, int $draftId, ?array $actor = null): array
{
    ensure_email_campaign_schema();
    $draft = get_email_campaign_draft($draftId);
    if (!$draft || (int) ($draft['project_id'] ?? 0) !== $projectId) {
        return ['ok' => false, 'error' => 'Draft not found in this project.'];
    }
    if ($actor !== null && !email_campaign_user_can_delete_draft($actor, $draft)) {
        return ['ok' => false, 'error' => 'Only the draft creator or Admin can delete this draft.'];
    }
    db()->prepare('DELETE FROM email_campaign_drafts WHERE id=? AND project_id=?')
        ->execute([$draftId, $projectId]);
    return [
        'ok' => true,
        'title' => (string) ($draft['title'] ?? 'Draft'),
    ];
}
