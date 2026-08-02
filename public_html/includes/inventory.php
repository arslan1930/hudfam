<?php

/**
 * Project-scoped inventory + safe team Super search + bulk import.
 */

function require_project_access(int $projectId, array $user): array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        flash('error', 'Project not found.');
        redirect(is_admin($user) ? 'index.php?page=admin_projects' : 'index.php?page=team_projects');
    }
    if (!is_admin($user)) {
        $chk = db()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
        $chk->execute([$projectId, $user['id']]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo 'You are not assigned to this project.';
            exit;
        }
    }
    return $project;
}

function normalize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#^www\.#i', '', $domain);
    $domain = rtrim(explode('/', $domain)[0], '.');
    return $domain;
}

/**
 * Team Super search — whole database, site details only.
 * Never returns client name, admin comments, project name, emails, or mailboxes.
 */
function search_inventory_safe_for_team(string $q, int $limit = 50): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $domainExact = normalize_domain($q);
    $like = '%' . $domainExact . '%';

    // One row per domain — site metrics only (no client/project/email fields)
    $sql = "SELECT
              s.domain,
              MAX(s.country) AS country,
              MAX(s.language) AS language,
              MAX(s.region) AS region,
              MAX(s.niche) AS niche,
              MAX(s.dr) AS dr,
              MAX(s.da) AS da,
              MAX(s.traffic) AS traffic,
              COUNT(*) AS copies_in_db,
              MAX(s.updated_at) AS updated_at
            FROM sites s
            WHERE s.domain = ? OR s.domain LIKE ? OR s.url LIKE ?
            GROUP BY s.domain
            ORDER BY MAX(s.updated_at) DESC
            LIMIT " . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $domainExact,
        $like,
        '%' . $q . '%',
    ]);
    $rows = $stmt->fetchAll();
    // Prefer exact domain matches first
    usort($rows, static function ($a, $b) use ($domainExact) {
        $ae = ($a['domain'] === $domainExact) ? 0 : (str_starts_with($a['domain'], $domainExact) ? 1 : 2);
        $be = ($b['domain'] === $domainExact) ? 0 : (str_starts_with($b['domain'], $domainExact) ? 1 : 2);
        return $ae <=> $be;
    });
    return $rows;
}

/**
 * Admin Super search within one project (full row including confidential fields).
 */
function search_project_inventory(int $projectId, string $q, int $limit = 50): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $domainExact = normalize_domain($q);

    $sql = "SELECT s.*, u.username owner
            FROM sites s
            LEFT JOIN users u ON u.id = s.assigned_to
            WHERE s.primary_project_id = ?
              AND (
                s.domain LIKE ? OR s.url LIKE ? OR s.niche LIKE ?
                OR s.publisher_email LIKE ? OR s.our_mailbox LIKE ?
                OR s.our_contact_name LIKE ? OR s.outreach_notes LIKE ?
                OR s.admin_comments LIKE ? OR s.inventory_client_name LIKE ?
                OR s.country LIKE ? OR s.language LIKE ?
                OR s.domain = ?
              )
            ORDER BY
              CASE WHEN s.domain = ? THEN 0
                   WHEN s.domain LIKE ? THEN 1
                   ELSE 2 END,
              s.updated_at DESC
            LIMIT " . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $projectId,
        $like, $like, $like,
        $like, $like,
        $like, $like,
        $like, $like,
        $like, $like,
        $domainExact,
        $domainExact,
        $domainExact . '%',
    ]);
    return $stmt->fetchAll();
}

/**
 * Team search inside one project catalog.
 * Returns site metrics + quote/agreed prices for that project only.
 * Never returns client name, admin comments, or other confidential admin fields.
 */
function search_project_inventory_for_team(int $projectId, string $q, int $limit = 50): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $domainExact = normalize_domain($q);

    $sql = "SELECT
              s.id,
              s.domain,
              s.country,
              s.language,
              s.region,
              s.niche,
              s.dr,
              s.da,
              s.traffic,
              s.publisher_quote_price,
              s.backlink_price,
              s.currency,
              s.status,
              s.our_mailbox,
              s.our_contact_name,
              s.updated_at
            FROM sites s
            WHERE s.primary_project_id = ?
              AND (
                s.domain LIKE ? OR s.url LIKE ? OR s.niche LIKE ?
                OR s.country LIKE ? OR s.language LIKE ?
                OR s.our_mailbox LIKE ? OR s.our_contact_name LIKE ?
                OR s.domain = ?
              )
            ORDER BY
              CASE WHEN s.domain = ? THEN 0
                   WHEN s.domain LIKE ? THEN 1
                   ELSE 2 END,
              s.updated_at DESC
            LIMIT " . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $projectId,
        $like, $like, $like,
        $like, $like,
        $like, $like,
        $domainExact,
        $domainExact,
        $domainExact . '%',
    ]);
    return $stmt->fetchAll();
}

function project_inventory_query(int $projectId, array $filters, int $pageNum = 1, int $per = 50): array
{
    $where = ['s.primary_project_id = ?'];
    $params = [$projectId];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.domain LIKE ? OR s.url LIKE ? OR s.publisher_email LIKE ?
                     OR s.our_mailbox LIKE ? OR s.our_contact_name LIKE ?
                     OR s.niche LIKE ? OR s.outreach_notes LIKE ?
                     OR s.inventory_client_name LIKE ? OR s.admin_comments LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['order_status'])) {
        $where[] = 's.order_status = ?';
        $params[] = $filters['order_status'];
    }
    if (!empty($filters['client_name'])) {
        $where[] = 's.inventory_client_name LIKE ?';
        $params[] = '%' . $filters['client_name'] . '%';
    }
    if (!empty($filters['empty_country'])) {
        $where[] = "(TRIM(COALESCE(s.country,'')) = '')";
    } else {
        apply_site_geo_filters($where, $params, [
            'region' => $filters['region'] ?? '',
            'country' => $filters['country'] ?? '',
            'language' => $filters['language'] ?? '',
        ]);
    }
    if (!empty($filters['mailbox'])) {
        $where[] = 's.our_mailbox = ?';
        $params[] = $filters['mailbox'];
    }

    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM sites s WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT s.*, u.username owner, p.name project_name FROM sites s
         LEFT JOIN users u ON u.id = s.assigned_to
         LEFT JOIN projects p ON p.id = s.primary_project_id
         WHERE $whereSql ORDER BY s.updated_at DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

/** Admin cross-project inventory list. */
function admin_inventory_query(array $filters, int $pageNum = 1, int $per = 50): array
{
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.domain LIKE ? OR s.inventory_client_name LIKE ? OR s.admin_comments LIKE ?
                     OR s.country LIKE ? OR s.language LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['project_id'])) {
        $where[] = 's.primary_project_id = ?';
        $params[] = (int) $filters['project_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['order_status'])) {
        $where[] = 's.order_status = ?';
        $params[] = $filters['order_status'];
    }
    apply_site_geo_filters($where, $params, [
        'region' => $filters['region'] ?? '',
        'country' => $filters['country'] ?? '',
        'language' => $filters['language'] ?? '',
    ]);
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM sites s WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT s.*, p.name project_name, p.client_name project_client
         FROM sites s
         JOIN projects p ON p.id = s.primary_project_id
         WHERE $whereSql ORDER BY s.updated_at DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

function distinct_project_mailboxes(int $projectId): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT our_mailbox FROM sites
         WHERE primary_project_id = ? AND our_mailbox <> ''
         ORDER BY our_mailbox"
    );
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'our_mailbox');
}

function distinct_project_languages(int $projectId): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT language FROM sites
         WHERE primary_project_id = ? AND language <> ''
         ORDER BY language"
    );
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'language');
}

function bulk_csv_headers(): array
{
    return [
        'domain', 'language', 'country', 'da', 'dr', 'traffic',
        'order_status', 'admin_comments', 'client_name',
        'region', 'niche', 'url', 'status',
        'publisher_quote_price', 'backlink_price', 'currency',
        'our_mailbox', 'our_contact_name',
    ];
}

/**
 * Stream-import CSV into a project. Supports 10k+ rows via chunked inserts.
 *
 * @return array{inserted:int,updated:int,skipped:int,errors:string[]}
 */
function bulk_import_sites_csv(int $projectId, string $tmpPath, int $createdBy): array
{
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $fh = fopen($tmpPath, 'rb');
    if (!$fh) {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Could not open CSV file.']];
    }

    // Strip UTF-8 BOM
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Empty CSV.']];
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $header = str_getcsv($first);
    $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
    $map = [];
    foreach ($header as $i => $name) {
        $map[$name] = $i;
    }
    if (!isset($map['domain'])) {
        fclose($fh);
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV must include a domain column.']];
    }

    $validOrder = array_keys(inventory_order_statuses());
    $validStatus = array_keys(site_statuses());

    $sql = 'INSERT INTO sites (
              domain, primary_project_id, language, country, da, dr, traffic,
              order_status, admin_comments, inventory_client_name,
              region, niche, url, status,
              publisher_quote_price, backlink_price, currency,
              our_mailbox, our_contact_name, created_by, assigned_to
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              language=VALUES(language),
              country=VALUES(country),
              da=VALUES(da),
              dr=VALUES(dr),
              traffic=VALUES(traffic),
              order_status=VALUES(order_status),
              admin_comments=VALUES(admin_comments),
              inventory_client_name=VALUES(inventory_client_name),
              region=VALUES(region),
              niche=VALUES(niche),
              url=VALUES(url),
              status=VALUES(status),
              publisher_quote_price=VALUES(publisher_quote_price),
              backlink_price=VALUES(backlink_price),
              currency=VALUES(currency),
              our_mailbox=VALUES(our_mailbox),
              our_contact_name=VALUES(our_contact_name)';
    $stmt = db()->prepare($sql);

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $line = 1;
    $batch = 0;

    $cell = static function (array $row, array $map, string $key): string {
        if (!isset($map[$key])) {
            return '';
        }
        $i = $map[$key];
        return isset($row[$i]) ? trim((string) $row[$i]) : '';
    };

    db()->beginTransaction();
    try {
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if ($row === [null] || $row === false) {
                continue;
            }
            // Skip blank lines
            if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $domain = normalize_domain($cell($row, $map, 'domain'));
            if ($domain === '') {
                $skipped++;
                if (count($errors) < 30) {
                    $errors[] = "Line {$line}: missing domain";
                }
                continue;
            }

            $orderStatus = strtolower($cell($row, $map, 'order_status'));
            if ($orderStatus !== '' && !in_array($orderStatus, $validOrder, true)) {
                $orderStatus = '';
            }
            $status = strtolower($cell($row, $map, 'status') ?: 'draft');
            if (!in_array($status, $validStatus, true)) {
                $status = 'draft';
            }

            $da = $cell($row, $map, 'da');
            $dr = $cell($row, $map, 'dr');
            $traffic = $cell($row, $map, 'traffic');
            $quote = $cell($row, $map, 'publisher_quote_price');
            $agreed = $cell($row, $map, 'backlink_price');

            // Detect insert vs update
            $exists = db()->prepare(
                'SELECT id FROM sites WHERE primary_project_id=? AND domain=? LIMIT 1'
            );
            $exists->execute([$projectId, $domain]);
            $wasExisting = (bool) $exists->fetchColumn();

            try {
                $stmt->execute([
                    $domain,
                    $projectId,
                    $cell($row, $map, 'language'),
                    $cell($row, $map, 'country'),
                    $da === '' ? null : (int) $da,
                    $dr === '' ? null : (int) $dr,
                    $traffic === '' ? null : (int) $traffic,
                    $orderStatus,
                    $cell($row, $map, 'admin_comments') !== '' ? $cell($row, $map, 'admin_comments') : $cell($row, $map, 'comments'),
                    $cell($row, $map, 'client_name'),
                    $cell($row, $map, 'region'),
                    $cell($row, $map, 'niche'),
                    $cell($row, $map, 'url'),
                    $status,
                    $quote === '' ? null : $quote,
                    $agreed === '' ? null : $agreed,
                    $cell($row, $map, 'currency') ?: 'EUR',
                    $cell($row, $map, 'our_mailbox'),
                    $cell($row, $map, 'our_contact_name'),
                    $createdBy,
                    $createdBy,
                ]);
                if ($wasExisting) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (Throwable $e) {
                $skipped++;
                if (count($errors) < 30) {
                    $errors[] = "Line {$line} ({$domain}): " . $e->getMessage();
                }
            }

            $batch++;
            if ($batch % 250 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $errors[] = 'Import aborted: ' . $e->getMessage();
    }
    fclose($fh);

    return compact('inserted', 'updated', 'skipped', 'errors');
}

/**
 * Plain domain names for a project's catalog (Filter Box 1).
 *
 * @return array{domains:string[],total:int,truncated:bool}
 */
function list_project_domain_names(int $projectId, int $maxDisplay = 25000): array
{
    $count = db()->prepare('SELECT COUNT(*) FROM sites WHERE primary_project_id=?');
    $count->execute([$projectId]);
    $total = (int) $count->fetchColumn();
    $stmt = db()->prepare(
        'SELECT domain FROM sites WHERE primary_project_id=? ORDER BY domain ASC LIMIT ' . (int) $maxDisplay
    );
    $stmt->execute([$projectId]);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return [
        'domains' => $domains,
        'total' => $total,
        'truncated' => $total > count($domains),
    ];
}

/**
 * Check pasted domains against one project's catalog only.
 *
 * @return array{existing:string[],new:string[],invalid:int,total_input:int}
 */
function filter_domains_against_project(int $projectId, array $domains): array
{
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $existing = [];
    $new = [];
    if (!$domains) {
        return ['existing' => [], 'new' => [], 'invalid' => 0, 'total_input' => 0];
    }

    $chunkSize = 500;
    $found = [];
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$projectId], $chunk);
        $stmt = db()->prepare(
            "SELECT domain FROM sites WHERE primary_project_id=? AND domain IN ($placeholders)"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $found[$d] = true;
        }
    }
    foreach ($domains as $d) {
        if (isset($found[$d])) {
            $existing[] = $d;
        } else {
            $new[] = $d;
        }
    }
    return [
        'existing' => $existing,
        'new' => $new,
        'invalid' => 0,
        'total_input' => count($domains),
    ];
}

function domain_in_project(int $projectId, string $domain): bool
{
    $domain = normalize_domain($domain);
    if ($domain === '') {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM sites WHERE primary_project_id=? AND domain=? LIMIT 1'
    );
    $stmt->execute([$projectId, $domain]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Country “sheets” for a project catalog (backed by sites.country).
 * Includes countries that already have rows, plus names listed on the project brief.
 *
 * @return list<array{name:string,count:int,is_empty_country:bool}>
 */
function project_country_sheets(int $projectId, string $projectCountriesField = ''): array
{
    $counts = [];
    $stmt = db()->prepare(
        "SELECT TRIM(country) AS country_name, COUNT(*) AS c
         FROM sites WHERE primary_project_id=?
         GROUP BY TRIM(country)
         ORDER BY CASE WHEN TRIM(country)='' OR country IS NULL THEN 1 ELSE 0 END, country_name"
    );
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string) ($row['country_name'] ?? ''));
        $counts[$name] = (int) $row['c'];
    }

    // Suggested sheets from project.countries (e.g. "Germany, Austria")
    foreach (preg_split('/[,;\/|]+/', $projectCountriesField) ?: [] as $part) {
        $name = trim($part);
        if ($name !== '' && !array_key_exists($name, $counts)) {
            $counts[$name] = 0;
        }
    }

    $sheets = [];
    foreach ($counts as $name => $count) {
        $sheets[] = [
            'name' => $name,
            'count' => $count,
            'is_empty_country' => $name === '',
        ];
    }
    usort($sheets, static function ($a, $b) {
        if ($a['is_empty_country'] !== $b['is_empty_country']) {
            return $a['is_empty_country'] ? 1 : -1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $sheets;
}

/**
 * True if domain exists in Our inventory or any project catalog.
 */
function domain_known_globally(string $domain): bool
{
    $domain = normalize_domain($domain);
    if ($domain === '') {
        return false;
    }
    $p = db()->prepare('SELECT 1 FROM prospect_sites WHERE domain=? LIMIT 1');
    $p->execute([$domain]);
    if ($p->fetchColumn()) {
        return true;
    }
    $s = db()->prepare('SELECT 1 FROM sites WHERE domain=? LIMIT 1');
    $s->execute([$domain]);
    return (bool) $s->fetchColumn();
}

/**
 * Split domains into already-known (any catalog or Our inventory) vs new.
 *
 * @return array{existing:string[],new:string[],total_input:int}
 */
function filter_domains_against_catalogs_and_inventory(array $domains): array
{
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    if (!$domains) {
        return ['existing' => [], 'new' => [], 'total_input' => 0];
    }
    $found = [];
    $chunkSize = 500;
    for ($i = 0, $n = count($domains); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($domains, $i, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $ps = db()->prepare("SELECT domain FROM prospect_sites WHERE domain IN ($ph)");
        $ps->execute($chunk);
        foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $found[$d] = true;
        }
        $ss = db()->prepare("SELECT domain FROM sites WHERE domain IN ($ph)");
        $ss->execute($chunk);
        foreach ($ss->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $found[$d] = true;
        }
    }
    $existing = [];
    $new = [];
    foreach ($domains as $d) {
        if (isset($found[$d])) {
            $existing[] = $d;
        } else {
            $new[] = $d;
        }
    }
    return [
        'existing' => $existing,
        'new' => $new,
        'total_input' => count($domains),
    ];
}

/**
 * Team pre-add lookup across all country sheets (all projects) + Our inventory.
 *
 * @return array{domain:string,in_inventory:bool,catalog_rows:array,comments:string[],partial:array}
 */
function lookup_domain_for_team(string $q): array
{
    $domain = normalize_domain($q);
    $out = [
        'domain' => $domain,
        'in_inventory' => false,
        'inventory' => null,
        'catalog_rows' => [],
        'comments' => [],
        'partial' => [],
    ];
    if ($domain === '') {
        return $out;
    }

    try {
        $inv = db()->prepare(
            'SELECT domain, country, language, status, created_at FROM prospect_sites WHERE domain=? LIMIT 1'
        );
        $inv->execute([$domain]);
        $invRow = $inv->fetch();
        if ($invRow) {
            $out['in_inventory'] = true;
            $out['inventory'] = $invRow;
        }
    } catch (Throwable $e) {
        // prospect tables may be missing on old installs
    }

    $cat = db()->prepare(
        "SELECT s.id, s.domain, s.country, s.language, s.region, s.niche,
                s.dr, s.da, s.traffic, s.status, s.warning_flags,
                s.publisher_quote_price, s.backlink_price, s.currency,
                s.primary_project_id, p.name AS project_name, s.updated_at
         FROM sites s
         JOIN projects p ON p.id = s.primary_project_id
         WHERE s.domain = ?
         ORDER BY s.updated_at DESC
         LIMIT 20"
    );
    $cat->execute([$domain]);
    $out['catalog_rows'] = $cat->fetchAll();

    // Latest reject reason for first catalog hit (if any)
    $rejectCode = '';
    if ($out['catalog_rows']) {
        $siteId = (int) $out['catalog_rows'][0]['id'];
        $rj = db()->prepare(
            "SELECT reject_reason_code FROM pitch_items
             WHERE site_id=? AND item_status='rejected' AND reject_reason_code<>''
             ORDER BY updated_at DESC LIMIT 1"
        );
        $rj->execute([$siteId]);
        $rejectCode = (string) ($rj->fetchColumn() ?: '');
    }

    $out['comments'] = team_domain_comments($out, $rejectCode);

    // Partial matches for discovery (metrics only)
    $like = $domain . '%';
    $partial = db()->prepare(
        "SELECT s.domain, MAX(s.country) AS country, MAX(s.language) AS language,
                MAX(s.dr) AS dr, MAX(s.da) AS da, MAX(s.traffic) AS traffic
         FROM sites s
         WHERE s.domain <> ? AND (s.domain LIKE ? OR s.domain LIKE ?)
         GROUP BY s.domain
         ORDER BY s.domain ASC
         LIMIT 15"
    );
    $partial->execute([$domain, $like, '%' . $domain . '%']);
    $out['partial'] = $partial->fetchAll();

    return $out;
}

/**
 * @param array{in_inventory:bool,catalog_rows:array} $hit
 * @return list<string>
 */
function team_domain_comments(array $hit, string $rejectCode = ''): array
{
    $comments = [];
    if (!empty($hit['in_inventory'])) {
        $comments[] = 'Already in Our inventory';
    }
    $rows = $hit['catalog_rows'] ?? [];
    if ($rows) {
        $comments[] = 'We already have it';
        $countries = [];
        foreach ($rows as $r) {
            $c = trim((string) ($r['country'] ?? ''));
            if ($c !== '') {
                $countries[$c] = true;
            }
        }
        if ($countries) {
            $comments[] = 'Country sheet: ' . implode(', ', array_keys($countries));
        }
        $statuses = array_unique(array_map(static fn($r) => (string) ($r['status'] ?? ''), $rows));
        foreach ($statuses as $st) {
            if (in_array($st, ['completed', 'processing', 'sent'], true)) {
                $comments[] = 'Used';
                break;
            }
        }
        foreach ($statuses as $st) {
            if ($st === 'blocked') {
                $comments[] = 'Blocked';
            }
            if ($st === 'rejected') {
                $comments[] = 'Rejected';
            }
        }
        $flags = strtolower(implode(' ', array_column($rows, 'warning_flags')));
        $minTraffic = null;
        foreach ($rows as $r) {
            if ($r['traffic'] !== null && $r['traffic'] !== '') {
                $t = (int) $r['traffic'];
                $minTraffic = $minTraffic === null ? $t : min($minTraffic, $t);
            }
        }
        if (
            $rejectCode === 'low_metrics'
            || str_contains($flags, 'low traffic')
            || str_contains($flags, 'low_metrics')
            || ($minTraffic !== null && $minTraffic < 1000)
        ) {
            $comments[] = 'Low traffic';
        }
        if ($rejectCode === 'already_used') {
            $comments[] = 'Already used';
        } elseif ($rejectCode !== '' && isset(reject_reasons()[$rejectCode])) {
            $comments[] = reject_reasons()[$rejectCode];
        }
        foreach ($rows as $r) {
            $wf = trim((string) ($r['warning_flags'] ?? ''));
            if ($wf !== '' && !in_array($wf, $comments, true)) {
                $comments[] = $wf;
            }
        }
    }
    return array_values(array_unique($comments));
}

/**
 * Insert unique domains into a project's catalog (skips already present).
 *
 * @return array{inserted:int,skipped:int}
 */
function add_domains_to_project(
    int $projectId,
    array $domains,
    array $user,
    string $country = '',
    string $language = '',
    string $region = '',
    string $niche = '',
    string $notes = ''
): array {
    @set_time_limit(0);
    $domains = array_values(array_unique(array_filter(array_map('normalize_domain', $domains))));
    $check = filter_domains_against_project($projectId, $domains);
    $toAdd = $check['new'];
    $skipped = count($check['existing']);
    if (!$toAdd) {
        return ['inserted' => 0, 'skipped' => $skipped];
    }

    if ($country !== '') {
        foreach (list_countries(null, true) as $c) {
            if (strcasecmp($c['name'], $country) === 0) {
                if ($region === '') {
                    $region = $c['region'];
                }
                if ($language === '' && $c['default_language'] !== '') {
                    $language = $c['default_language'];
                }
                break;
            }
        }
    }

    $project = db()->prepare('SELECT currency, client_name FROM projects WHERE id=?');
    $project->execute([$projectId]);
    $proj = $project->fetch() ?: [];
    $currency = (string) ($proj['currency'] ?? 'EUR') ?: 'EUR';
    $clientName = is_admin($user) ? (string) ($proj['client_name'] ?? '') : '';

    $ins = db()->prepare(
        'INSERT INTO sites (
            domain, url, region, country, niche, language, currency, status,
            inventory_client_name, outreach_notes, assigned_to, primary_project_id, created_by
         ) VALUES (?,?,?,?,?,?,?,\'draft\',?,?,?,?,?)'
    );

    $inserted = 0;
    $assignedTo = is_admin($user) ? null : (int) $user['id'];
    db()->beginTransaction();
    try {
        $n = 0;
        foreach ($toAdd as $d) {
            try {
                $ins->execute([
                    $d,
                    '',
                    $region,
                    $country,
                    $niche,
                    $language,
                    $currency,
                    $clientName,
                    $notes,
                    $assignedTo,
                    $projectId,
                    (int) $user['id'],
                ]);
                $inserted++;
            } catch (PDOException $e) {
                $skipped++;
            }
            $n++;
            if ($n % 250 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}
