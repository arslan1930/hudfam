<?php

/**
 * Email campaign inventory — per-country sheets of URL + email.
 * Unique email globally. Replied / dealing / do_not_email are cut from send lists.
 */

function ensure_email_campaign_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS email_campaign_contacts (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL DEFAULT '',
          url VARCHAR(500) NOT NULL DEFAULT '',
          domain VARCHAR(255) NOT NULL DEFAULT '',
          email VARCHAR(190) NOT NULL,
          status ENUM('ready','emailed','replied','dealing','do_not_email') NOT NULL DEFAULT 'ready',
          campaign_wave VARCHAR(120) NOT NULL DEFAULT '',
          notes TEXT,
          last_emailed_at DATETIME NULL,
          replied_at DATETIME NULL,
          created_by INT NULL,
          updated_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_email_campaign_email (email),
          INDEX (country),
          INDEX (status),
          INDEX (domain),
          INDEX (last_emailed_at),
          CONSTRAINT fk_ecc_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_ecc_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function email_campaign_statuses(): array
{
    return [
        'ready' => 'Ready to email',
        'emailed' => 'Emailed',
        'replied' => 'Replied',
        'dealing' => 'Dealing',
        'do_not_email' => 'Do not email',
    ];
}

/** Statuses that must never appear on a send export. */
function email_campaign_cut_statuses(): array
{
    return ['replied', 'dealing', 'do_not_email'];
}

function normalize_campaign_email(string $email): string
{
    $email = strtolower(trim($email));
    $email = preg_replace('/\s+/', '', $email) ?? $email;
    return $email;
}

function normalize_campaign_url(string $url): array
{
    $url = trim($url);
    $domain = function_exists('normalize_domain') ? normalize_domain($url) : strtolower($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url) && $domain !== '') {
        $url = 'https://' . $domain;
    }
    return ['url' => $url, 'domain' => $domain];
}

/**
 * Country sheets with counts (ready / emailed / cut / total).
 *
 * @return list<array{country:string,total:int,ready:int,emailed:int,cut:int}>
 */
function email_campaign_country_sheets(): array
{
    ensure_email_campaign_schema();
    $rows = db()->query(
        "SELECT
            TRIM(country) AS country,
            COUNT(*) AS total,
            SUM(CASE WHEN status='ready' THEN 1 ELSE 0 END) AS ready,
            SUM(CASE WHEN status='emailed' THEN 1 ELSE 0 END) AS emailed,
            SUM(CASE WHEN status IN ('replied','dealing','do_not_email') THEN 1 ELSE 0 END) AS cut_count
         FROM email_campaign_contacts
         GROUP BY TRIM(country)
         ORDER BY CASE WHEN TRIM(country)='' THEN 1 ELSE 0 END, country"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'country' => (string) ($r['country'] ?? ''),
            'total' => (int) $r['total'],
            'ready' => (int) $r['ready'],
            'emailed' => (int) $r['emailed'],
            'cut' => (int) $r['cut_count'],
        ];
    }
    return $out;
}

function email_campaign_query(array $filters, int $pageNum = 1, int $per = 50): array
{
    ensure_email_campaign_schema();
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(e.email LIKE ? OR e.url LIKE ? OR e.domain LIKE ? OR e.notes LIKE ? OR e.campaign_wave LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['empty_country'])) {
        $where[] = "(TRIM(COALESCE(e.country,'')) = '')";
    } elseif (isset($filters['country']) && $filters['country'] !== null && $filters['country'] !== '') {
        $where[] = 'e.country = ?';
        $params[] = $filters['country'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['sendable'])) {
        $where[] = "e.status = 'ready'";
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM email_campaign_contacts e WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT e.*, u.username updated_by_name, u.full_name updated_by_full
         FROM email_campaign_contacts e
         LEFT JOIN users u ON u.id = e.updated_by
         WHERE $whereSql
         ORDER BY e.updated_at DESC
         LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

/**
 * @return array{inserted:int,updated:int,skipped:int,errors:string[]}
 */
function email_campaign_import_rows(array $rows, string $country, array $user): array
{
    ensure_email_campaign_schema();
    $ins = db()->prepare(
        'INSERT INTO email_campaign_contacts (country, url, domain, email, status, notes, created_by, updated_by)
         VALUES (?,?,?,?,\'ready\',?,?,?)'
    );
    $upd = db()->prepare(
        'UPDATE email_campaign_contacts
         SET country=?, url=?, domain=?, notes=IF(?<>\'\', ?, notes), updated_by=?, updated_at=NOW()
         WHERE email=? AND status=\'ready\''
    );
    $find = db()->prepare('SELECT id, status FROM email_campaign_contacts WHERE email=? LIMIT 1');
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $i => $row) {
        $email = normalize_campaign_email((string) ($row['email'] ?? ''));
        $rawUrl = (string) ($row['url'] ?? $row['domain'] ?? '');
        $norm = normalize_campaign_url($rawUrl);
        $notes = trim((string) ($row['notes'] ?? ''));
        $rowCountry = trim((string) ($row['country'] ?? '')) ?: $country;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            if (count($errors) < 20) {
                $errors[] = 'Row ' . ($i + 1) . ': invalid email';
            }
            continue;
        }
        if ($norm['domain'] === '' && $norm['url'] === '') {
            $skipped++;
            if (count($errors) < 20) {
                $errors[] = 'Row ' . ($i + 1) . ': URL required';
            }
            continue;
        }

        $find->execute([$email]);
        $existing = $find->fetch();
        if ($existing) {
            if ($existing['status'] !== 'ready') {
                $skipped++; // already cut or emailed — keep history, don't overwrite
                continue;
            }
            $upd->execute([
                $rowCountry,
                $norm['url'],
                $norm['domain'],
                $notes,
                $notes,
                (int) $user['id'],
                $email,
            ]);
            $updated++;
        } else {
            try {
                $ins->execute([
                    $rowCountry,
                    $norm['url'],
                    $norm['domain'],
                    $email,
                    $notes,
                    (int) $user['id'],
                    (int) $user['id'],
                ]);
                $inserted++;
            } catch (PDOException $e) {
                $skipped++;
            }
        }
    }
    return compact('inserted', 'updated', 'skipped', 'errors');
}

/**
 * Parse paste: "url,email" or "url\temail" per line; or two columns CSV.
 *
 * @return list<array{url:string,email:string,notes:string}>
 */
function parse_email_campaign_paste(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = preg_split('/\n+/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with(strtolower($line), 'url') || str_starts_with(strtolower($line), 'domain')) {
            continue;
        }
        $parts = preg_split('/[\t,;]+/', $line) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
        if (count($parts) < 2) {
            continue;
        }
        // Prefer email-looking token as email
        $email = '';
        $url = '';
        foreach ($parts as $p) {
            if ($email === '' && str_contains($p, '@')) {
                $email = $p;
            } elseif ($url === '') {
                $url = $p;
            }
        }
        if ($email === '' || $url === '') {
            continue;
        }
        $out[] = ['url' => $url, 'email' => $email, 'notes' => ''];
    }
    return $out;
}

function email_campaign_set_status(int $id, string $status, array $user, string $notes = '', string $wave = ''): bool
{
    ensure_email_campaign_schema();
    if (!isset(email_campaign_statuses()[$status])) {
        return false;
    }
    $row = db()->prepare('SELECT * FROM email_campaign_contacts WHERE id=?');
    $row->execute([$id]);
    $cur = $row->fetch();
    if (!$cur) {
        return false;
    }
    $extraNotes = trim($notes);
    $newNotes = $extraNotes !== ''
        ? trim((string) $cur['notes'] . ($cur['notes'] ? "\n" : '') . $extraNotes)
        : $cur['notes'];

    $sql = 'UPDATE email_campaign_contacts SET status=?, notes=?, updated_by=?, updated_at=NOW()';
    $params = [$status, $newNotes, (int) $user['id']];
    if ($status === 'emailed') {
        $sql .= ', last_emailed_at=NOW(), campaign_wave=?';
        $params[] = $wave !== '' ? $wave : ('wave-' . date('Y-m-d'));
    }
    if (in_array($status, ['replied', 'dealing'], true)) {
        $sql .= ', replied_at=COALESCE(replied_at, NOW())';
    }
    $sql .= ' WHERE id=?';
    $params[] = $id;
    db()->prepare($sql)->execute($params);
    return true;
}

/**
 * Export Ready contacts for a country; optionally mark them emailed.
 *
 * @return list<array>
 */
function email_campaign_export_ready(string $country, bool $markEmailed, array $user, string $wave = ''): array
{
    ensure_email_campaign_schema();
    $params = [];
    $sql = "SELECT * FROM email_campaign_contacts WHERE status='ready'";
    if ($country === '_none') {
        $sql .= " AND TRIM(COALESCE(country,''))=''";
    } elseif ($country !== '' && $country !== 'all') {
        $sql .= ' AND country=?';
        $params[] = $country;
    }
    $sql .= ' ORDER BY country, domain, email';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($markEmailed && $rows) {
        $wave = $wave !== '' ? $wave : ('wave-' . date('Y-m-d'));
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $chunks = array_chunk($ids, 400);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $upd = db()->prepare(
                "UPDATE email_campaign_contacts
                 SET status='emailed', last_emailed_at=NOW(), campaign_wave=?, updated_by=?, updated_at=NOW()
                 WHERE id IN ($ph) AND status='ready'"
            );
            $upd->execute(array_merge([$wave, (int) $user['id']], $chunk));
        }
    }
    return $rows;
}

function lookup_email_campaign(string $q): array
{
    ensure_email_campaign_schema();
    $q = trim($q);
    $email = normalize_campaign_email($q);
    $domain = function_exists('normalize_domain') ? normalize_domain($q) : strtolower($q);
    $exact = null;
    if ($email !== '' && str_contains($email, '@')) {
        $s = db()->prepare('SELECT * FROM email_campaign_contacts WHERE email=? LIMIT 1');
        $s->execute([$email]);
        $exact = $s->fetch() ?: null;
    }
    if (!$exact && $domain !== '') {
        $s = db()->prepare(
            'SELECT * FROM email_campaign_contacts WHERE domain=? OR email=? ORDER BY updated_at DESC LIMIT 20'
        );
        $s->execute([$domain, $email]);
        $rows = $s->fetchAll();
        if ($rows) {
            return ['exact' => null, 'matches' => $rows, 'q' => $q];
        }
    }
    $like = '%' . $q . '%';
    $partial = db()->prepare(
        'SELECT * FROM email_campaign_contacts
         WHERE email LIKE ? OR domain LIKE ? OR url LIKE ?
         ORDER BY updated_at DESC LIMIT 25'
    );
    $partial->execute([$like, $like, $like]);
    return [
        'exact' => $exact,
        'matches' => $exact ? [$exact] : $partial->fetchAll(),
        'q' => $q,
    ];
}

function email_campaign_status_comment(string $status): string
{
    return match ($status) {
        'ready' => 'Ready to email',
        'emailed' => 'Already emailed — do not send again in this wave',
        'replied' => 'Replied — cut from send list',
        'dealing' => 'Dealing — cut from send list',
        'do_not_email' => 'Do not email',
        default => $status,
    };
}

/**
 * Team quick-cut: paste emails only → mark status → removed from Ready send list (record kept).
 *
 * @return array{cut:int,already:int,missing:string[],rows:list<array>}
 */
function email_campaign_quick_cut(string $rawEmails, string $status, array $user, string $notes = ''): array
{
    ensure_email_campaign_schema();
    if (!in_array($status, ['replied', 'dealing', 'do_not_email'], true)) {
        $status = 'replied';
    }
    $rawEmails = str_replace(["\r\n", "\r", ',', ';', "\t"], "\n", $rawEmails);
    $parts = preg_split('/\s+/', $rawEmails) ?: [];
    $emails = [];
    foreach ($parts as $p) {
        $e = normalize_campaign_email($p);
        if ($e !== '' && str_contains($e, '@') && filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $emails[$e] = true;
        }
    }
    $emails = array_keys($emails);
    $cut = 0;
    $already = 0;
    $missing = [];
    $rows = [];
    $find = db()->prepare('SELECT * FROM email_campaign_contacts WHERE email=? LIMIT 1');
    foreach ($emails as $email) {
        $find->execute([$email]);
        $row = $find->fetch();
        if (!$row) {
            $missing[] = $email;
            continue;
        }
        if (in_array($row['status'], email_campaign_cut_statuses(), true)) {
            $already++;
            $rows[] = $row;
            continue;
        }
        email_campaign_set_status((int) $row['id'], $status, $user, $notes);
        $find->execute([$email]);
        $updated = $find->fetch() ?: $row;
        $rows[] = $updated;
        $cut++;
    }
    return compact('cut', 'already', 'missing', 'rows');
}
