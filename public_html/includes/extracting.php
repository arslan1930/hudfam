<?php
/**
 * Extracting sites — second path for team adds.
 * Path 1: prospect_sites (admin country database)
 * Path 2: extract_batches / extract_batch_sites (Sites list + Extracting Results)
 *
 * One batch per country, created only when a teammate adds new unique sites.
 */

function ensure_extract_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('txf_schema_is_current') && txf_schema_is_current(__FUNCTION__, __FILE__)) {
        return;
    }
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_batches (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL,
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          site_count INT NOT NULL DEFAULT 0,
          results_text MEDIUMTEXT NULL,
          emptied_at TIMESTAMP NULL DEFAULT NULL,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_country (country),
          INDEX (updated_at),
          INDEX (emptied_at),
          CONSTRAINT fk_extract_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_batch_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          batch_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          prospect_site_id INT NULL,
          added_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_batch_domain (batch_id, domain),
          INDEX (domain),
          INDEX (added_by),
          CONSTRAINT fk_ebs_batch FOREIGN KEY (batch_id) REFERENCES extract_batches(id) ON DELETE CASCADE,
          CONSTRAINT fk_ebs_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL,
          CONSTRAINT fk_ebs_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Existing installs: add emptied_at for 1-hour empty-row cleanup.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM extract_batches LIKE 'emptied_at'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec('ALTER TABLE extract_batches ADD COLUMN emptied_at TIMESTAMP NULL DEFAULT NULL AFTER results_text');
            $pdo->exec('ALTER TABLE extract_batches ADD INDEX idx_extract_emptied_at (emptied_at)');
        }
    } catch (Throwable $e) {
        // ignore if permissions/table differ
    }
    foreach ([
        'last_pushed_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'sites_writer_id' => 'INT NULL DEFAULT NULL',
        'sites_writer_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ] as $colName => $ddl) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM extract_batches LIKE " . $pdo->quote($colName))->fetch(PDO::FETCH_ASSOC);
            if (!$col) {
                $pdo->exec("ALTER TABLE extract_batches ADD COLUMN {$colName} {$ddl}");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (function_exists('txf_schema_mark_current')) {
        txf_schema_mark_current(__FUNCTION__);
    }
}

/**
 * Permanently remove country rows that stayed empty for 1+ hour.
 */
function purge_expired_empty_extract_batches(): void
{
    ensure_extract_schema();
    try {
        db()->exec(
            'DELETE FROM extract_batches
             WHERE site_count < 1
               AND emptied_at IS NOT NULL
               AND emptied_at < (NOW() - INTERVAL 1 HOUR)'
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Get existing country extract batch, or create one (first sites for this country).
 */
function get_or_create_extract_batch(
    string $country,
    array $user,
    string $language = '',
    string $region = ''
): int {
    ensure_extract_schema();
    $country = trim($country);
    if ($country === '') {
        throw new InvalidArgumentException('Country is required for Extracting sites.');
    }
    $stmt = db()->prepare('SELECT id FROM extract_batches WHERE country=? LIMIT 1');
    $stmt->execute([$country]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        if ($language !== '' || $region !== '') {
            db()->prepare(
                'UPDATE extract_batches
                 SET language = IF(?<>\'\', ?, language),
                     region = IF(?<>\'\', ?, region),
                     updated_at = NOW()
                 WHERE id=?'
            )->execute([$language, $language, $region, $region, $id]);
        }
        return $id;
    }
    db()->prepare(
        'INSERT INTO extract_batches (country, language, region, site_count, created_by)
         VALUES (?,?,?,0,?)'
    )->execute([$country, $language, $region, (int) ($user['id'] ?? 0) ?: null]);
    return (int) db()->lastInsertId();
}

/**
 * Dual path: push newly added domains into the country's Sites list box.
 *
 * @param list<array{domain:string,prospect_site_id?:int|null}>|list<string> $domains
 * @return array{batch_id:int,added:int,failed:int,error:string}
 */
function add_domains_to_extract_sites(
    array $domains,
    array $user,
    string $country,
    string $language = '',
    string $region = ''
): array {
    ensure_extract_schema();
    $rows = [];
    foreach ($domains as $item) {
        if (is_string($item)) {
            $host = extract_host_candidate($item);
            $d = to_root_domain($host);
            if ($d !== '' && is_root_domain($d)) {
                $rows[] = ['domain' => $d, 'prospect_site_id' => null];
            }
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $host = extract_host_candidate((string) ($item['domain'] ?? ''));
        $d = to_root_domain($host);
        if ($d === '' || !is_root_domain($d)) {
            continue;
        }
        $rows[] = [
            'domain' => $d,
            'prospect_site_id' => isset($item['prospect_site_id']) ? (int) $item['prospect_site_id'] : null,
        ];
    }
    if ($rows === []) {
        return ['batch_id' => 0, 'added' => 0, 'failed' => 0, 'error' => ''];
    }

    // Caller must already de-dupe against this country’s Our database
    // (filter_domains_routed_against_prospects / add_prospect_domains). Do not
    // re-check prospect_sites here — rows were often just inserted there.
    $batchId = get_or_create_extract_batch($country, $user, $language, $region);
    $ins = db()->prepare(
        'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE
           prospect_site_id = COALESCE(VALUES(prospect_site_id), prospect_site_id)'
    );
    $added = 0;
    $failed = 0;
    $failMsg = '';
    $uid = (int) ($user['id'] ?? 0) ?: null;
    // Insert newest-first among this batch so ORDER BY id DESC shows paste order at top.
    foreach (array_reverse($rows) as $row) {
        try {
            $ins->execute([
                $batchId,
                $row['domain'],
                $row['prospect_site_id'] ?: null,
                $uid,
            ]);
            // rowCount is 1 for insert, 2 for update on MySQL; only count fresh inserts
            if ($ins->rowCount() === 1) {
                $added++;
            }
        } catch (PDOException $e) {
            $failed++;
            if ($failMsg === '') {
                $failMsg = $e->getMessage();
            }
        }
    }
    refresh_extract_batch_site_count($batchId);

    return ['batch_id' => $batchId, 'added' => $added, 'failed' => $failed, 'error' => $failMsg];
}

/**
 * @return list<array<string,mixed>>
 */
function list_extract_batches(int $limit = 2000): array
{
    ensure_extract_schema();
    purge_expired_empty_extract_batches();
    $limit = max(1, min(10000, $limit));
    // Hide empty countries here; they may still be open on the batch page until leave / 1 hour.
    // Live COUNT so a stale extract_batches.site_count cannot disagree with the Sites list.
    $sql = "SELECT b.*, u.username, u.full_name,
                   w.username AS sites_writer_username, w.full_name AS sites_writer_name,
                   COALESCE(c.n, 0) AS live_site_count
            FROM extract_batches b
            LEFT JOIN users u ON u.id = b.created_by
            LEFT JOIN users w ON w.id = b.sites_writer_id
            LEFT JOIN (
                SELECT batch_id, COUNT(*) AS n FROM extract_batch_sites GROUP BY batch_id
            ) c ON c.batch_id = b.id
            WHERE COALESCE(c.n, 0) > 0
            ORDER BY b.updated_at DESC, b.country ASC
            LIMIT {$limit}";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['site_count'] = (int) ($row['live_site_count'] ?? 0);
        unset($row['live_site_count']);
    }
    unset($row);

    return $rows;
}

/**
 * Cheap Extracting country switcher (id + country). Filled Sites lists only;
 * include $currentId even when that batch is empty so the open sheet stays in the list.
 *
 * @return list<array{id:int,country:string}>
 */
function list_extract_batch_country_nav(int $currentId = 0): array
{
    ensure_extract_schema();
    // Do not purge here: an open empty sheet must stay in the switcher.
    // Hub list_extract_batches() still removes countries empty for 1 hour.
    $currentId = max(0, $currentId);
    $sql = 'SELECT b.id, b.country FROM extract_batches b WHERE (EXISTS (SELECT 1 FROM extract_batch_sites s WHERE s.batch_id = b.id)';
    if ($currentId > 0) {
        $sql .= ' OR b.id = ' . $currentId;
    }
    $sql .= ') ORDER BY b.country ASC, b.id ASC';
    $out = [];
    $seen = [];
    foreach (db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        $raw = trim((string) ($row['country'] ?? ''));
        if ($id < 1 || $raw === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $canon = function_exists('resolve_canonical_country')
            ? resolve_canonical_country($raw)
            : null;
        $out[] = [
            'id' => $id,
            'country' => $canon ? (string) $canon['name'] : $raw,
        ];
    }
    return $out;
}

function stamp_extract_batch_last_pushed(?int $batchId): void
{
    if ($batchId === null || $batchId < 1) {
        return;
    }
    ensure_extract_schema();
    try {
        db()->prepare('UPDATE extract_batches SET last_pushed_at=NOW() WHERE id=?')->execute([$batchId]);
    } catch (Throwable $e) {
        // ignore missing column on very old installs
    }
}

function stamp_extract_sites_writer(int $batchId, ?int $userId): void
{
    if ($batchId < 1) {
        return;
    }
    ensure_extract_schema();
    try {
        db()->prepare(
            'UPDATE extract_batches SET sites_writer_id=?, sites_writer_at=NOW() WHERE id=?'
        )->execute([$userId !== null && $userId > 0 ? $userId : null, $batchId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * @return array{ok:bool,conflict?:bool,error?:string,writer_name?:string,writer_at?:string}|null
 */
function extract_sites_writer_conflict(int $batchId, ?int $actorId, string $clientAt): ?array
{
    $batch = get_extract_batch($batchId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    $dbAt = trim((string) ($batch['sites_writer_at'] ?? ''));
    $dbWriter = (int) ($batch['sites_writer_id'] ?? 0);
    if ($clientAt === '' || $dbAt === '' || $dbWriter < 1) {
        return null;
    }
    if ($actorId !== null && $actorId > 0 && $dbWriter === $actorId) {
        return null;
    }
    if (strcmp($dbAt, $clientAt) <= 0) {
        return null;
    }
    $name = trim((string) (($batch['sites_writer_name'] ?? '') !== ''
        ? $batch['sites_writer_name']
        : ($batch['sites_writer_username'] ?? '')));
    if ($name === '') {
        $name = 'Someone';
    }
    return [
        'ok' => false,
        'conflict' => true,
        'error' => $name . ' saved this Sites list at ' . substr($dbAt, 0, 16)
            . '. Reload to avoid overwriting.',
        'writer_name' => $name,
        'writer_at' => $dbAt,
    ];
}

function get_extract_batch(int $batchId): ?array
{
    ensure_extract_schema();
    $stmt = db()->prepare(
        'SELECT b.*, u.username, u.full_name,
                w.username AS sites_writer_username, w.full_name AS sites_writer_name
         FROM extract_batches b
         LEFT JOIN users u ON u.id = b.created_by
         LEFT JOIN users w ON w.id = b.sites_writer_id
         WHERE b.id=? LIMIT 1'
    );
    $stmt->execute([$batchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return list<string>
 */
function get_extract_batch_domains(int $batchId, int $limit = 50000): array
{
    ensure_extract_schema();
    $limit = max(1, min(100000, $limit));
    // Newest sites first — does not reshuffle older rows relative to each other.
    $stmt = db()->prepare(
        'SELECT domain FROM extract_batch_sites WHERE batch_id=? ORDER BY id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * @return list<array{id:int,domain:string,prospect_site_id:?int,added_by:?int}>
 */
function get_extract_batch_site_rows(int $batchId, int $limit = 50000): array
{
    ensure_extract_schema();
    $limit = max(1, min(100000, $limit));
    $stmt = db()->prepare(
        'SELECT id, domain, prospect_site_id, added_by
         FROM extract_batch_sites
         WHERE batch_id=?
         ORDER BY id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['domain'] = (string) $row['domain'];
        $row['prospect_site_id'] = $row['prospect_site_id'] !== null ? (int) $row['prospect_site_id'] : null;
        $row['added_by'] = $row['added_by'] !== null ? (int) $row['added_by'] : null;
    }
    unset($row);
    return $rows;
}

function refresh_extract_batch_site_count(int $batchId): int
{
    ensure_extract_schema();
    $cnt = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=?');
    $cnt->execute([$batchId]);
    $siteCount = (int) $cnt->fetchColumn();
    if ($siteCount < 1) {
        // Keep emptied_at on first clear so the 1-hour timer does not reset on later saves.
        db()->prepare(
            'UPDATE extract_batches
             SET site_count=0,
                 emptied_at=COALESCE(emptied_at, NOW()),
                 updated_at=NOW()
             WHERE id=?'
        )->execute([$batchId]);
        return 0;
    }
    db()->prepare(
        'UPDATE extract_batches
         SET site_count=?, emptied_at=NULL, updated_at=NOW()
         WHERE id=?'
    )->execute([$siteCount, $batchId]);
    return $siteCount;
}

/**
 * Replace Sites list domains from the editable text box (autosave).
 * Admin Our database is not touched. Empty list stays open on this page;
 * the country is hidden on the Extracting sites index (and purged after 1 hour).
 *
 * @return array{site_count:int,domains:list<string>,removed:int,added:int}
 */
function set_extract_batch_domains_from_text(int $batchId, string $raw, ?int $addedBy = null): array
{
    ensure_extract_schema();
    $parsed = parse_domain_list_strict($raw);
    $wanted = [];
    foreach ($parsed['valid'] as $d) {
        $wanted[$d] = true;
    }
    $newDomains = array_keys($wanted);

    $existing = get_extract_batch_domains($batchId);
    $oldSet = array_fill_keys($existing, true);
    $newSet = array_fill_keys($newDomains, true);

    $toRemove = [];
    foreach ($existing as $d) {
        if (!isset($newSet[$d])) {
            $toRemove[] = $d;
        }
    }
    $toAdd = [];
    foreach ($newDomains as $d) {
        if (!isset($oldSet[$d])) {
            $toAdd[] = $d;
        }
    }

    if ($toRemove !== []) {
        remove_extract_batch_domains($batchId, $toRemove);
    }

    if ($toAdd !== []) {
        $ins = db()->prepare(
            'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
             VALUES (?,?,NULL,?)
             ON DUPLICATE KEY UPDATE domain = VALUES(domain)'
        );
        // Reverse so paste/list order appears at the top (ORDER BY id DESC).
        foreach (array_reverse($toAdd) as $d) {
            try {
                $ins->execute([$batchId, $d, $addedBy]);
            } catch (PDOException $e) {
                // skip
            }
        }
    }

    $siteCount = refresh_extract_batch_site_count($batchId);
    stamp_extract_sites_writer($batchId, $addedBy);
    $fresh = get_extract_batch($batchId) ?? [];
    return [
        'site_count' => $siteCount,
        'domains' => get_extract_batch_domains($batchId),
        'removed' => count($toRemove),
        'added' => count($toAdd),
        'writer_name' => trim((string) (($fresh['sites_writer_name'] ?? '') !== ''
            ? $fresh['sites_writer_name']
            : ($fresh['sites_writer_username'] ?? ''))),
        'writer_at' => (string) ($fresh['sites_writer_at'] ?? ''),
    ];
}

/**
 * Remove domains from Sites list only (admin country DB untouched).
 *
 * @param list<string> $domains
 * @return list<array{domain:string,prospect_site_id:?int,added_by:?int}>
 */
function remove_extract_batch_domains(int $batchId, array $domains): array
{
    ensure_extract_schema();
    $wanted = [];
    foreach ($domains as $d) {
        $n = normalize_domain((string) $d);
        if ($n !== '') {
            $wanted[$n] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }
    $list = array_keys($wanted);
    $placeholders = implode(',', array_fill(0, count($list), '?'));
    $params = array_merge([$batchId], $list);
    $sel = db()->prepare(
        "SELECT domain, prospect_site_id, added_by
         FROM extract_batch_sites
         WHERE batch_id=? AND domain IN ({$placeholders})"
    );
    $sel->execute($params);
    $removed = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($removed === []) {
        return [];
    }
    $del = db()->prepare(
        "DELETE FROM extract_batch_sites WHERE batch_id=? AND domain IN ({$placeholders})"
    );
    $del->execute($params);
    refresh_extract_batch_site_count($batchId);

    $out = [];
    foreach ($removed as $row) {
        $out[] = [
            'domain' => (string) $row['domain'],
            'prospect_site_id' => $row['prospect_site_id'] !== null ? (int) $row['prospect_site_id'] : null,
            'added_by' => $row['added_by'] !== null ? (int) $row['added_by'] : null,
        ];
    }
    return $out;
}

/**
 * @param list<array{domain:string,prospect_site_id?:int|null,added_by?:int|null}> $rows
 */
function restore_extract_batch_domains(int $batchId, array $rows): int
{
    ensure_extract_schema();
    if ($rows === []) {
        return 0;
    }
    $ins = db()->prepare(
        'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE
           prospect_site_id = COALESCE(VALUES(prospect_site_id), prospect_site_id),
           added_by = COALESCE(VALUES(added_by), added_by)'
    );
    $restored = 0;
    foreach (array_reverse($rows) as $row) {
        $host = extract_host_candidate((string) ($row['domain'] ?? ''));
        $domain = to_root_domain($host);
        if ($domain === '' || !is_root_domain($domain)) {
            continue;
        }
        try {
            $ins->execute([
                $batchId,
                $domain,
                !empty($row['prospect_site_id']) ? (int) $row['prospect_site_id'] : null,
                !empty($row['added_by']) ? (int) $row['added_by'] : null,
            ]);
            $restored++;
        } catch (PDOException $e) {
            // skip
        }
    }
    refresh_extract_batch_site_count($batchId);
    return $restored;
}

function save_extract_batch_results(int $batchId, string $resultsText): void
{
    ensure_extract_schema();
    db()->prepare(
        'UPDATE extract_batches SET results_text=?, updated_at=NOW() WHERE id=?'
    )->execute([$resultsText, $batchId]);
}

function count_extract_batches(): int
{
    ensure_extract_schema();
    return (int) db()->query(
        'SELECT COUNT(*) FROM extract_batches b
         WHERE EXISTS (SELECT 1 FROM extract_batch_sites s WHERE s.batch_id = b.id)'
    )->fetchColumn();
}

function extract_request_wants_json(): bool
{
    if ((string) ($_POST['ajax'] ?? '') === '1') {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

function extract_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
