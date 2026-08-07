<?php
/**
 * Email campaign sheets (Emails DATA → Email campaign data).
 * Admin creates named sheets; Communication Team searches/updates them.
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

function normalize_email_campaign_sheet_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Sheet name is required.');
    }
    if (mb_strlen($name) > 180) {
        throw new InvalidArgumentException('Sheet name is too long (max 180 characters).');
    }
    return $name;
}

/**
 * @return list<array{id:int,name:string,row_count:int,with_emails:int,created_at:?string,updated_at:?string}>
 */
function list_email_campaign_sheets(): array
{
    ensure_email_campaign_schema();
    $sql = "SELECT s.id, s.name, s.created_at, s.updated_at,
                   COUNT(r.id) AS row_count,
                   COALESCE(SUM(
                     CASE WHEN r.email1<>'' OR r.email2<>'' OR r.email3<>'' OR r.email4<>'' THEN 1 ELSE 0 END
                   ), 0) AS with_emails
            FROM email_campaign_sheets s
            LEFT JOIN email_campaign_rows r ON r.sheet_id = s.id
            GROUP BY s.id, s.name, s.created_at, s.updated_at
            ORDER BY s.updated_at DESC, s.name ASC";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
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

function create_email_campaign_sheet(string $name, int $actorId = 0): int
{
    ensure_email_campaign_schema();
    $name = normalize_email_campaign_sheet_name($name);
    try {
        db()->prepare(
            'INSERT INTO email_campaign_sheets (name, created_by) VALUES (?, ?)'
        )->execute([$name, $actorId > 0 ? $actorId : null]);
    } catch (PDOException $e) {
        throw new InvalidArgumentException('A sheet named “' . $name . '” already exists.');
    }
    return (int) db()->lastInsertId();
}

function rename_email_campaign_sheet(int $id, string $name): void
{
    ensure_email_campaign_schema();
    $name = normalize_email_campaign_sheet_name($name);
    $sheet = get_email_campaign_sheet($id);
    if (!$sheet) {
        throw new InvalidArgumentException('Sheet not found.');
    }
    try {
        db()->prepare(
            'UPDATE email_campaign_sheets SET name=?, updated_at=NOW() WHERE id=?'
        )->execute([$name, $id]);
    } catch (PDOException $e) {
        throw new InvalidArgumentException('A sheet named “' . $name . '” already exists.');
    }
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
function search_email_campaign_suggestions(int $sheetId, string $q, int $limit = 20): array
{
    ensure_email_campaign_schema();
    $q = trim(mb_strtolower($q));
    if ($q === '' || mb_strlen($q) < 2) {
        return [];
    }
    if (!get_email_campaign_sheet($sheetId)) {
        return [];
    }
    $limit = max(1, min(40, $limit));
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        "SELECT id, domain, country, email1, email2, email3, email4
         FROM email_campaign_rows
         WHERE sheet_id=?
           AND (
             domain LIKE ?
             OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?
           )
         ORDER BY
           CASE
             WHEN domain = ? THEN 0
             WHEN domain LIKE ? THEN 1
             WHEN email1 = ? OR email2 = ? OR email3 = ? OR email4 = ? THEN 2
             ELSE 3
           END,
           domain ASC
         LIMIT {$limit}"
    );
    $stmt->execute([
        $sheetId,
        $like, $like, $like, $like, $like,
        $q,
        $q . '%',
        $q, $q, $q, $q,
    ]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $domain = (string) $row['domain'];
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
        }
        $emailPreview = $emails !== [] ? implode(', ', $emails) : '(no emails)';
        $out[] = [
            'id' => (int) $row['id'],
            'domain' => $domain,
            'country' => (string) $row['country'],
            'emails' => $emails,
            'match_type' => $matchType,
            'matched_value' => $matched,
            'label' => $domain . ' · ' . $emailPreview,
        ];
    }
    return $out;
}

/**
 * @return array{ok:bool,error?:string,domain?:string}
 */
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
 * Render Communication Team live-search panels for all (or one) campaign sheets.
 */
function render_email_campaign_search_panels(?int $onlySheetId = null, string $postBase = 'index.php?page=team_email_campaigns'): void
{
    ensure_email_campaign_schema();
    $sheets = list_email_campaign_sheets();
    if ($onlySheetId !== null && $onlySheetId > 0) {
        $sheets = array_values(array_filter(
            $sheets,
            static fn ($s) => (int) $s['id'] === $onlySheetId
        ));
    }
    if ($sheets === []) {
        echo '<div class="card"><div class="empty-state">';
        echo '<p>No email sheets yet.</p>';
        echo '<p class="muted">When Admin creates an Email Sheet under Emails DATA → Email campaign data, its search bar appears here.</p>';
        echo '</div></div>';
        return;
    }
    foreach ($sheets as $s) {
        $sid = (int) $s['id'];
        $name = (string) $s['name'];
        $uid = 'camp-search-' . $sid . '-' . substr(md5($postBase . $sid), 0, 6);
        ?>
  <div class="card camp-search-card" style="margin-bottom:1rem"
       data-camp-search
       data-sheet-id="<?= $sid ?>"
       data-sheet-name="<?= h($name) ?>"
       data-suggest-url="<?= h($postBase) ?>&amp;ajax=suggest&amp;sheet=<?= $sid ?>"
       data-post-url="<?= h($postBase) ?>">
    <h2 style="margin-top:0"><?= h($name) ?></h2>
    <p class="help muted" style="margin-top:0">
      <?= (int) $s['row_count'] ?> site<?= (int) $s['row_count'] === 1 ? '' : 's' ?> in sheet ·
      live search · site + email together · Enter confirms
    </p>
    <label class="swe-admin-delete-label" for="<?= h($uid) ?>">Search site name or email</label>
    <div class="swe-admin-delete-search">
      <input id="<?= h($uid) ?>" type="search" class="swe-admin-delete-input" data-camp-q
             placeholder="Type site or email…"
             autocomplete="off" spellcheck="false" data-no-draft
             title="Type to search · Arrow keys · Enter to select / confirm">
      <ul class="swe-admin-delete-suggest" data-camp-suggest hidden></ul>
    </div>
    <p class="help camp-status" data-camp-status hidden></p>
    <div class="swe-admin-delete-selected" data-camp-selected hidden>
      <h3 style="margin-top:1rem">Selected</h3>
      <p class="help">Site name and emails stay together. Pick an action, then press Enter (confirm first).</p>
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
