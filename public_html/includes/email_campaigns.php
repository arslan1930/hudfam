<?php
/**
 * Email campaign sheets (Emails DATA → Email campaign data).
 * One sheet per country. Communication Team uses one super search across all countries;
 * updates apply to the matching country sheet row.
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
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_sheet_name (name),
          INDEX (updated_at),
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
}

/**
 * Sheet "name" is always the canonical country name.
 */
function email_campaign_sheet_country(array $sheet): string
{
    return (string) ($sheet['name'] ?? '');
}

/**
 * @return list<array{id:int,name:string,country:string,region:string,language:string,row_count:int,with_emails:int,created_at:?string,updated_at:?string}>
 */
function list_email_campaign_sheets(): array
{
    ensure_email_campaign_schema();
    $sql = "SELECT s.id, s.name, s.created_at, s.updated_at,
                   COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_' THEN 1 ELSE 0 END), 0) AS row_count,
                   COALESCE(SUM(
                     CASE WHEN r.id IS NOT NULL AND LEFT(r.domain, 8) <> '__blank_'
                               AND (r.email1<>'' OR r.email2<>'' OR r.email3<>'' OR r.email4<>'')
                          THEN 1 ELSE 0 END
                   ), 0) AS with_emails
            FROM email_campaign_sheets s
            LEFT JOIN email_campaign_rows r ON r.sheet_id = s.id
            GROUP BY s.id, s.name, s.created_at, s.updated_at
            ORDER BY s.name ASC";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $country = (string) $row['name'];
        $canon = resolve_canonical_country($country);
        $out[] = [
            'id' => (int) $row['id'],
            'name' => $canon ? $canon['name'] : $country,
            'country' => $canon ? $canon['name'] : $country,
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
 */
function create_email_campaign_sheet(string $country, int $actorId = 0): int
{
    ensure_email_campaign_schema();
    $canon = require_canonical_country($country);
    $name = $canon['name'];
    $existing = get_email_campaign_sheet_by_country($name);
    if ($existing) {
        return (int) $existing['id'];
    }
    try {
        db()->prepare(
            'INSERT INTO email_campaign_sheets (name, created_by) VALUES (?, ?)'
        )->execute([$name, $actorId > 0 ? $actorId : null]);
    } catch (PDOException $e) {
        $again = get_email_campaign_sheet_by_country($name);
        if ($again) {
            return (int) $again['id'];
        }
        throw new InvalidArgumentException('Could not create sheet for “' . $name . '”.');
    }
    return (int) db()->lastInsertId();
}

/** @deprecated Sheets are country-named; renaming is not used. */
function rename_email_campaign_sheet(int $id, string $name): void
{
    // no-op kept for safety — country sheets keep canonical country names
    unset($id, $name);
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
    $stmt = db()->prepare('SELECT COUNT(*) FROM email_campaign_rows WHERE sheet_id=?');
    $stmt->execute([$sheetId]);
    return (int) $stmt->fetchColumn();
}

function touch_email_campaign_sheet(int $sheetId): void
{
    ensure_email_campaign_schema();
    db()->prepare('UPDATE email_campaign_sheets SET updated_at=NOW() WHERE id=?')->execute([$sheetId]);
}

/**
 * @return list<array<string,mixed>>
 */
function list_email_campaign_rows(int $sheetId): array
{
    ensure_email_campaign_schema();
    $stmt = db()->prepare(
        'SELECT * FROM email_campaign_rows WHERE sheet_id=? ORDER BY id ASC'
    );
    $stmt->execute([$sheetId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function add_blank_email_campaign_rows(int $sheetId, int $count = 1): int
{
    ensure_email_campaign_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    $count = max(1, min(50, $count));
    $ins = db()->prepare(
        'INSERT INTO email_campaign_rows (sheet_id, domain, country, email1, email2, email3, email4)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    // Placeholder domains keep UNIQUE(sheet_id, domain) happy until Admin fills them.
    $added = 0;
    for ($i = 0; $i < $count; $i++) {
        $placeholder = '__blank_' . $sheetId . '_' . bin2hex(random_bytes(4));
        $ins->execute([$sheetId, $placeholder, '', '', '', '', '']);
        $added++;
    }
    touch_email_campaign_sheet($sheetId);
    return $added;
}

/**
 * Save one row (site name + up to 4 emails). Blank placeholder domains become real sites.
 *
 * @return array{ok:bool,error?:string,id?:int,domain?:string}
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
    $sheet = get_email_campaign_sheet($sheetId);
    $sheetCountry = $sheet ? email_campaign_sheet_country($sheet) : '';

    $domainRaw = trim($domainRaw);
    // Empty site on a blank placeholder → leave as blank (no error).
    $isPlaceholder = str_starts_with((string) $existing['domain'], '__blank_');
    if ($domainRaw === '') {
        if ($isPlaceholder) {
            $norm = normalize_email_slots($emails);
            if (!$norm['ok']) {
                return ['ok' => false, 'error' => (string) ($norm['error'] ?? 'Invalid email.')];
            }
            /** @var array{0:string,1:string,2:string,3:string} $slots */
            $slots = $norm['slots'] ?? ['', '', '', ''];
            db()->prepare(
                'UPDATE email_campaign_rows
                 SET country=?, email1=?, email2=?, email3=?, email4=?, updated_at=NOW()
                 WHERE id=? AND sheet_id=?'
            )->execute([$sheetCountry, $slots[0], $slots[1], $slots[2], $slots[3], $rowId, $sheetId]);
            touch_email_campaign_sheet($sheetId);
            return ['ok' => true, 'id' => $rowId, 'domain' => (string) $existing['domain']];
        }
        return ['ok' => false, 'error' => 'Site name is required.'];
    }

    $host = extract_host_candidate($domainRaw);
    $domain = to_root_domain($host);
    if ($domain === '' || !function_exists('is_root_domain') || !is_root_domain($domain)) {
        // Fallback if is_root_domain missing: accept non-empty root-like host
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
    return ['ok' => true, 'id' => $rowId, 'domain' => $domain];
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
 * Paste lines: site.com,email@x.com  OR  site.com email1 email2 …
 *
 * @return array{added:int,updated:int,skipped:int,errors:list<string>}
 */
function paste_email_campaign_rows(int $sheetId, string $raw): array
{
    ensure_email_campaign_schema();
    if (!get_email_campaign_sheet($sheetId)) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = preg_split('/\n+/', $raw) ?: [];
    $added = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // CSV / tab / multi-space
        if (str_contains($line, ',') || str_contains($line, "\t")) {
            $parts = preg_split('/[,\t]+/', $line) ?: [];
        } else {
            $parts = preg_split('/\s+/', $line) ?: [];
        }
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
        if ($parts === []) {
            $skipped++;
            continue;
        }
        $domainRaw = (string) $parts[0];
        $emails = array_slice($parts, 1, 4);
        while (count($emails) < 4) {
            $emails[] = '';
        }
        $before = db()->prepare(
            'SELECT id FROM email_campaign_rows WHERE sheet_id=? AND domain=? LIMIT 1'
        );
        $host = extract_host_candidate($domainRaw);
        $domain = to_root_domain($host);
        $existed = false;
        if ($domain !== '') {
            $before->execute([$sheetId, $domain]);
            $existed = (int) $before->fetchColumn() > 0;
        }
        $result = upsert_email_campaign_row($sheetId, $domainRaw, $emails);
        if (!$result['ok']) {
            $errors[] = $domainRaw . ': ' . (string) ($result['error'] ?? 'failed');
            $skipped++;
            continue;
        }
        if ($existed) {
            $updated++;
        } else {
            $added++;
        }
    }
    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
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
        $exists->execute([$sheetId, $domain]);
        $already = (int) $exists->fetchColumn() > 0;
        $ins->execute([
            $sheetId,
            $domain,
            (string) ($row['country'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['region'] ?? ''),
            (string) ($row['email1'] ?? ''),
            (string) ($row['email2'] ?? ''),
            (string) ($row['email3'] ?? ''),
            (string) ($row['email4'] ?? ''),
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
 * Live suggestions: always return site name + emails together.
 *
 * @return list<array{
 *   id:int,domain:string,country:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
/**
 * Live suggestions within one country sheet.
 *
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions(int $sheetId, string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, $sheetId);
}

/**
 * Super search across all country campaign sheets.
 * Results always include site name + emails + country; actions update that sheet row.
 *
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions_all(string $q, int $limit = 20): array
{
    return search_email_campaign_suggestions_scoped($q, $limit, null);
}

/**
 * @return list<array{
 *   id:int,sheet_id:int,domain:string,country:string,emails:list<string>,
 *   match_type:string,matched_value:string,label:string
 * }>
 */
function search_email_campaign_suggestions_scoped(string $q, int $limit = 20, ?int $sheetId = null): array
{
    ensure_email_campaign_schema();
    $q = trim(mb_strtolower($q));
    if ($q === '' || mb_strlen($q) < 2) {
        return [];
    }
    if ($sheetId !== null && !get_email_campaign_sheet($sheetId)) {
        return [];
    }
    $limit = max(1, min(40, $limit));
    $like = '%' . $q . '%';
    $sql = "SELECT r.id, r.sheet_id, r.domain, r.country, r.email1, r.email2, r.email3, r.email4,
                   s.name AS sheet_country
            FROM email_campaign_rows r
            INNER JOIN email_campaign_sheets s ON s.id = r.sheet_id
            WHERE LEFT(r.domain, 8) <> '__blank_'
              AND (
                r.domain LIKE ?
                OR r.email1 LIKE ? OR r.email2 LIKE ? OR r.email3 LIKE ? OR r.email4 LIKE ?
                OR s.name LIKE ?
              )";
    $params = [$like, $like, $like, $like, $like, $like];
    if ($sheetId !== null) {
        $sql .= ' AND r.sheet_id = ?';
        $params[] = $sheetId;
    }
    $sql .= " ORDER BY
           CASE
             WHEN r.domain = ? THEN 0
             WHEN r.domain LIKE ? THEN 1
             WHEN r.email1 = ? OR r.email2 = ? OR r.email3 = ? OR r.email4 = ? THEN 2
             ELSE 3
           END,
           s.name ASC, r.domain ASC
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
        }
        $emailPreview = $emails !== [] ? implode(', ', $emails) : '(no emails)';
        $out[] = [
            'id' => (int) $row['id'],
            'sheet_id' => (int) $row['sheet_id'],
            'domain' => $domain,
            'country' => $country,
            'emails' => $emails,
            'match_type' => $matchType,
            'matched_value' => $matched,
            'label' => $domain . ' · ' . $emailPreview . ' · ' . $country,
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
 * Remove one email; keep site name (and other emails).
 *
 * @return array{ok:bool,error?:string,domain?:string,emails?:list<string>,removed?:string}
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
        'domain' => (string) $row['domain'],
        'emails' => $left,
        'removed' => $target,
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
 * Render Communication Team super search across all country campaign sheets.
 */
function render_email_campaign_search_panels(?int $onlySheetId = null, string $postBase = 'index.php?page=team_email_campaigns'): void
{
    // $onlySheetId kept for BC; super search always covers all countries (or one if set).
    unset($onlySheetId);
    render_email_campaign_super_search($postBase);
}

function render_email_campaign_super_search(string $postBase = 'index.php?page=team_email_campaigns'): void
{
    ensure_email_campaign_schema();
    $sheets = list_email_campaign_sheets();
    $totalSites = 0;
    foreach ($sheets as $s) {
        $totalSites += (int) $s['row_count'];
    }
    $uid = 'camp-super-' . substr(md5($postBase), 0, 6);
    if ($sheets === []) {
        echo '<div class="card"><div class="empty-state">';
        echo '<p>No country email sheets yet.</p>';
        echo '<p class="muted">When Admin creates an Email Sheet for a country under Emails DATA → Email campaign data, you can search it here.</p>';
        echo '</div></div>';
        return;
    }
    ?>
  <div class="card camp-search-card" style="margin-bottom:1rem"
       data-camp-search
       data-sheet-id="0"
       data-sheet-name="All countries"
       data-suggest-url="<?= h($postBase) ?>&amp;ajax=suggest"
       data-post-url="<?= h($postBase) ?>">
    <h2 style="margin-top:0"><?= label_with_info('Super search · all countries', 'Type a site or email. Pick a result, choose delete both or remove only email, then press Enter and confirm. The matching country Email Sheet row updates.') ?></h2>
    <p class="help muted" style="margin-top:0">
      <?= count($sheets) ?> countr<?= count($sheets) === 1 ? 'y' : 'ies' ?> ·
      <?= (int) $totalSites ?> site<?= (int) $totalSites === 1 ? '' : 's' ?> ·
      search site or email across every country sheet · updates that country’s row
    </p>
    <label class="swe-admin-delete-label" for="<?= h($uid) ?>"><?= label_with_info('Search site name or email', 'Live suggestions across every country sheet. Site name and emails always appear together.') ?></label>
    <div class="swe-admin-delete-search">
      <input id="<?= h($uid) ?>" type="search" class="swe-admin-delete-input" data-camp-q
             placeholder="Type site or email (all countries)…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to search all countries · Arrow keys · Enter to select / confirm">
      <ul class="swe-admin-delete-suggest" data-camp-suggest hidden></ul>
    </div>
    <p class="help camp-status" data-camp-status hidden></p>
    <div class="swe-admin-delete-selected" data-camp-selected hidden>
      <h3 style="margin-top:1rem">Selected</h3>
      <p class="help">Site name and emails stay together. Action updates the matching country sheet.</p>
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
    echo '<script src="' . h(script_asset_url('js/email-campaign-search.js')) . '" defer></script>';
}
