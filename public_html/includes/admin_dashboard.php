<?php
/**
 * Admin dashboard aggregations — no schema changes; uses existing tables/columns.
 */

/**
 * @return list<string>
 */
function admin_dashboard_range_keys(): array
{
    return ['today', '7d', '30d', 'all'];
}

function admin_dashboard_normalize_range(string $range): string
{
    $range = strtolower(trim($range));
    return in_array($range, admin_dashboard_range_keys(), true) ? $range : '7d';
}

/**
 * @return array{
 *   key:string,label:string,
 *   current_start:?string,current_end:string,
 *   prev_start:?string,prev_end:?string,
 *   trend_label:string
 * }
 */
function admin_dashboard_range_window(string $range): array
{
    $range = admin_dashboard_normalize_range($range);
    $end = date('Y-m-d 23:59:59');
    $today = date('Y-m-d');

    if ($range === 'today') {
        return [
            'key' => 'today',
            'label' => 'Today',
            'current_start' => $today . ' 00:00:00',
            'current_end' => $end,
            'prev_start' => date('Y-m-d 00:00:00', strtotime('-1 day')),
            'prev_end' => date('Y-m-d 23:59:59', strtotime('-1 day')),
            'trend_label' => 'vs yesterday',
        ];
    }
    if ($range === '30d') {
        return [
            'key' => '30d',
            'label' => '30 days',
            'current_start' => date('Y-m-d 00:00:00', strtotime('-29 days')),
            'current_end' => $end,
            'prev_start' => date('Y-m-d 00:00:00', strtotime('-59 days')),
            'prev_end' => date('Y-m-d 23:59:59', strtotime('-30 days')),
            'trend_label' => 'vs prior 30 days',
        ];
    }
    if ($range === 'all') {
        return [
            'key' => 'all',
            'label' => 'All time',
            'current_start' => null,
            'current_end' => $end,
            'prev_start' => date('Y-m-d 00:00:00', strtotime('-13 days')),
            'prev_end' => date('Y-m-d 23:59:59', strtotime('-7 days')),
            'trend_label' => 'last 7 days',
        ];
    }
    // 7d default
    return [
        'key' => '7d',
        'label' => '7 days',
        'current_start' => date('Y-m-d 00:00:00', strtotime('-6 days')),
        'current_end' => $end,
        'prev_start' => date('Y-m-d 00:00:00', strtotime('-13 days')),
        'prev_end' => date('Y-m-d 23:59:59', strtotime('-7 days')),
        'trend_label' => 'vs prior 7 days',
    ];
}

function admin_dashboard_sql_count(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_dashboard_sql_sum(string $sql, array $params = []): float
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Count rows with optional datetime window on $column.
 */
function admin_dashboard_count_dated(
    string $tableSql,
    string $dateColumn,
    ?string $start,
    string $end,
    string $extraAnd = '',
    array $extraParams = []
): int {
    $where = ['1=1'];
    $params = [];
    if ($extraAnd !== '') {
        $where[] = '(' . $extraAnd . ')';
        foreach ($extraParams as $p) {
            $params[] = $p;
        }
    }
    if ($start !== null) {
        $where[] = $dateColumn . ' >= ?';
        $params[] = $start;
        $where[] = $dateColumn . ' <= ?';
        $params[] = $end;
    }
    $sql = 'SELECT COUNT(*) FROM ' . $tableSql . ' WHERE ' . implode(' AND ', $where);
    return admin_dashboard_sql_count($sql, $params);
}

/**
 * @return array{value:int,delta:int,trend_label:string}
 */
function admin_dashboard_metric_with_trend(
    callable $countFn,
    array $window
): array {
    $current = (int) $countFn($window['current_start'], $window['current_end']);
    $delta = 0;
    if ($window['key'] === 'all') {
        // For all-time total, trend = activity in the last 7 days.
        $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $delta = (int) $countFn($weekStart, $window['current_end']);
    } elseif ($window['prev_start'] !== null && $window['prev_end'] !== null) {
        $prev = (int) $countFn($window['prev_start'], $window['prev_end']);
        $delta = $current - $prev;
    }
    return [
        'value' => $current,
        'delta' => $delta,
        'trend_label' => (string) $window['trend_label'],
    ];
}

function admin_dashboard_format_delta(int $delta): string
{
    if ($delta > 0) {
        return '+' . number_format($delta);
    }
    if ($delta < 0) {
        return number_format($delta);
    }
    return '0';
}

/**
 * Full dashboard payload for a range key.
 *
 * @return array<string,mixed>
 */
function admin_dashboard_build(string $range, ?array $user = null): array
{
    $window = admin_dashboard_range_window($range);
    $user = $user ?? (function_exists('current_user') ? current_user() : null);

    try {
        ensure_order_schema();
    } catch (Throwable $e) {
    }
    try {
        ensure_invoice_schema();
    } catch (Throwable $e) {
    }
    try {
        ensure_extracted_schema();
    } catch (Throwable $e) {
    }
    try {
        ensure_sites_with_emails_schema();
    } catch (Throwable $e) {
    }
    try {
        ensure_departments_schema();
    } catch (Throwable $e) {
    }
    try {
        ensure_admin_new_data_schema();
    } catch (Throwable $e) {
    }

    $urls = admin_dashboard_metric_with_trend(
        static function (?string $start, string $end): int {
            return admin_dashboard_count_dated('prospect_sites', 'created_at', $start, $end);
        },
        $window
    );
    // For "all", show lifetime total as value (not just window).
    if ($window['key'] === 'all') {
        $urls['value'] = admin_dashboard_count_dated('prospect_sites', 'created_at', null, $window['current_end']);
    }

    $extracted = admin_dashboard_metric_with_trend(
        static function (?string $start, string $end): int {
            return admin_dashboard_count_dated(
                'extracted_sites',
                'created_at',
                $start,
                $end,
                "LEFT(domain, 8) <> '__blank_'"
            );
        },
        $window
    );
    if ($window['key'] === 'all') {
        $extracted['value'] = admin_dashboard_count_dated(
            'extracted_sites',
            'created_at',
            null,
            $window['current_end'],
            "LEFT(domain, 8) <> '__blank_'"
        );
    }

    $emails = admin_dashboard_metric_with_trend(
        static function (?string $start, string $end): int {
            return admin_dashboard_count_dated(
                'sites_with_emails_admin',
                'created_at',
                $start,
                $end,
                "LEFT(domain, 8) <> '__blank_' AND (email1<>'' OR email2<>'' OR email3<>'' OR email4<>'')"
            );
        },
        $window
    );
    if ($window['key'] === 'all') {
        $emails['value'] = admin_dashboard_count_dated(
            'sites_with_emails_admin',
            'created_at',
            null,
            $window['current_end'],
            "LEFT(domain, 8) <> '__blank_' AND (email1<>'' OR email2<>'' OR email3<>'' OR email4<>'')"
        );
    }

    $emailed = admin_dashboard_metric_with_trend(
        static function (?string $start, string $end): int {
            // Prefer email_sent_at when present; fall back to updated_at for older rows.
            $extra = "LEFT(domain, 8) <> '__blank_' AND email_sent=1";
            if ($start === null) {
                return admin_dashboard_count_dated('sites_with_emails_admin', 'created_at', null, $end, $extra);
            }
            return admin_dashboard_sql_count(
                "SELECT COUNT(*) FROM sites_with_emails_admin
                 WHERE LEFT(domain, 8) <> '__blank_' AND email_sent=1
                   AND COALESCE(email_sent_at, updated_at) >= ?
                   AND COALESCE(email_sent_at, updated_at) <= ?",
                [$start, $end]
            );
        },
        $window
    );
    if ($window['key'] === 'all') {
        $emailed['value'] = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE LEFT(domain, 8) <> '__blank_' AND email_sent=1"
        );
    }

    $orders = admin_dashboard_metric_with_trend(
        static function (?string $start, string $end): int {
            return admin_dashboard_count_dated(
                'order_items',
                'created_at',
                $start,
                $end,
                "row_type='site' AND TRIM(site_name) <> ''"
            );
        },
        $window
    );
    if ($window['key'] === 'all') {
        $orders['value'] = admin_dashboard_count_dated(
            'order_items',
            'created_at',
            null,
            $window['current_end'],
            "row_type='site' AND TRIM(site_name) <> ''"
        );
    }

    $teamActive = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM users WHERE role='team' AND is_active=1"
    );

    $unpaidLiveCount = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM order_items
         WHERE row_type='site' AND TRIM(live_url) <> '' AND COALESCE(is_paid, 0)=0"
    );
    $unpaidLiveEuro = admin_dashboard_sql_sum(
        "SELECT COALESCE(SUM(decided_price), 0) FROM order_items
         WHERE row_type='site' AND TRIM(live_url) <> '' AND COALESCE(is_paid, 0)=0"
    );

    $waitingInvoices = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM invoices
         WHERE payment_status='unpaid' AND work_status='done'"
    );
    $waitingEuro = admin_dashboard_sql_sum(
        "SELECT COALESCE(SUM(total_amount), 0) FROM invoices
         WHERE payment_status='unpaid' AND work_status='done'"
    );
    $overdueInvoices = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM invoices
         WHERE payment_status='unpaid' AND work_status='done'
           AND invoice_date < (CURDATE() - INTERVAL 14 DAY)"
    );
    $draftInvoices = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM invoices WHERE work_status='draft'"
    );

    $monthStart = date('Y-m-01 00:00:00');
    $revenueMonth = admin_dashboard_sql_sum(
        "SELECT COALESCE(SUM(total_amount), 0) FROM invoices
         WHERE payment_status='paid'
           AND COALESCE(paid_at, updated_at) >= ?
           AND COALESCE(paid_at, updated_at) <= ?",
        [$monthStart, $window['current_end']]
    );
    $paidEuro = admin_dashboard_sql_sum(
        "SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE payment_status='paid'"
    );
    $unpaidInvoiceEuro = admin_dashboard_sql_sum(
        "SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE payment_status='unpaid'"
    );

    $funnelExtracted = (int) $extracted['value'];
    $funnelEmails = (int) $emails['value'];
    $funnelEmailed = (int) $emailed['value'];
    $funnelOrders = (int) $orders['value'];
    $pct = static function (int $part, int $whole): ?float {
        if ($whole <= 0) {
            return null;
        }
        return round(100 * $part / $whole, 1);
    };

    $deptUnassigned = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM users u
         WHERE u.role='team' AND u.is_active=1
           AND NOT EXISTS (SELECT 1 FROM department_members m WHERE m.user_id = u.id)"
    );
    $deptOpenTasks = admin_dashboard_sql_count(
        "SELECT COUNT(*) FROM department_tasks t
         INNER JOIN departments d ON d.id = t.department_id AND d.is_active=1
         WHERE t.status IN ('open','in_progress')"
    );

    $alerts = [];
    if ($overdueInvoices > 0) {
        $alerts[] = [
            'tone' => 'danger',
            'label' => number_format($overdueInvoices) . ' overdue invoice'
                . ($overdueInvoices === 1 ? '' : 's') . ' (>14 days)',
            // Live list has draft/unpaid/paid only — overdue rows sit in unpaid.
            'href' => 'index.php?page=admin_invoices&filter=unpaid',
        ];
    }
    if ($waitingInvoices > 0) {
        $alerts[] = [
            'tone' => 'warn',
            'label' => number_format($waitingInvoices) . ' waiting invoice'
                . ($waitingInvoices === 1 ? '' : 's')
                . ' · ' . format_euro($waitingEuro),
            'href' => 'index.php?page=admin_invoices&filter=unpaid',
        ];
    }
    if ($draftInvoices > 0) {
        $alerts[] = [
            'tone' => 'neutral',
            'label' => number_format($draftInvoices) . ' draft invoice'
                . ($draftInvoices === 1 ? '' : 's'),
            'href' => 'index.php?page=admin_invoices&filter=draft',
        ];
    }
    if ($unpaidLiveCount > 0) {
        $alerts[] = [
            'tone' => 'neutral',
            'label' => number_format($unpaidLiveCount) . ' unpaid LIVE · ' . format_euro($unpaidLiveEuro),
            'href' => 'index.php?page=admin_orders&folder=completed&status=unpaid',
        ];
    }
    if ($deptUnassigned > 0) {
        $alerts[] = [
            'tone' => 'warn',
            'label' => number_format($deptUnassigned) . ' team awaiting assignment',
            'href' => 'index.php?page=admin_users&awaiting=1',
        ];
    }
    if ($deptOpenTasks > 0) {
        $alerts[] = [
            'tone' => 'neutral',
            'label' => number_format($deptOpenTasks) . ' open department task'
                . ($deptOpenTasks === 1 ? '' : 's'),
            'href' => 'index.php?page=admin_departments',
        ];
    }
    if ($user && function_exists('admin_has_new_data') && admin_has_new_data('emails_admin', $user)) {
        $alerts[] = [
            'tone' => 'warn',
            'label' => 'New Admin emails',
            'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails',
        ];
    }
    if ($user && function_exists('admin_has_new_data') && admin_has_new_data('extracted_sites', $user)) {
        $alerts[] = [
            'tone' => 'neutral',
            'label' => 'New Extracted Sites',
            'href' => 'index.php?page=admin_extracted',
        ];
    }
    if ($user && function_exists('admin_has_new_data') && admin_has_new_data('our_database', $user)) {
        $alerts[] = [
            'tone' => 'neutral',
            'label' => 'New Our database adds',
            'href' => 'index.php?page=admin_prospect_batches',
        ];
    }

    return [
        'window' => $window,
        'updated_at' => date('H:i'),
        'updated_full' => date('Y-m-d H:i'),
        'stats' => [
            'urls' => $urls + ['href' => 'index.php?page=admin_prospects', 'label' => 'URLs'],
            'extracted' => $extracted + ['href' => 'index.php?page=admin_extracted', 'label' => 'Extracted'],
            'emails' => $emails + [
                'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails',
                'label' => 'With emails',
            ],
            'emailed' => $emailed + [
                'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails',
                'label' => 'Emailed',
            ],
            'orders' => $orders + ['href' => 'index.php?page=admin_orders', 'label' => 'Order rows'],
            'team' => [
                'value' => $teamActive,
                'delta' => 0,
                'trend_label' => '',
                'href' => 'index.php?page=admin_users',
                'label' => 'Active team',
            ],
        ],
        'money' => [
            'unpaid_live_count' => $unpaidLiveCount,
            'unpaid_live_euro' => $unpaidLiveEuro,
            'waiting_count' => $waitingInvoices,
            'waiting_euro' => $waitingEuro,
            'overdue_count' => $overdueInvoices,
            'revenue_month' => $revenueMonth,
            'paid_euro' => $paidEuro,
            'unpaid_euro' => $unpaidInvoiceEuro,
        ],
        'funnel' => [
            'extracted' => $funnelExtracted,
            'emails' => $funnelEmails,
            'emailed' => $funnelEmailed,
            'orders' => $funnelOrders,
            'pct_emails' => $pct($funnelEmails, $funnelExtracted),
            'pct_emailed' => $pct($funnelEmailed, $funnelEmails),
            'pct_orders' => $pct($funnelOrders, $funnelEmailed > 0 ? $funnelEmailed : $funnelEmails),
        ],
        'alerts' => $alerts,
        'leaderboard' => admin_dashboard_leaderboard(),
        'recent' => [],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function admin_dashboard_leaderboard(): array
{
    try {
        $users = db()->query(
            "SELECT id, username, full_name FROM users
             WHERE role='team' AND is_active=1
             ORDER BY full_name ASC, username ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if (!$users) {
        return [];
    }

    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('-6 days'));

    $out = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $addedToday = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM prospect_batch_items i
             INNER JOIN prospect_batches b ON b.id = i.batch_id
             WHERE b.user_id=? AND DATE(i.created_at)=?",
            [$uid, $today]
        );
        $addedWeek = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM prospect_batch_items i
             INNER JOIN prospect_batches b ON b.id = i.batch_id
             WHERE b.user_id=? AND DATE(i.created_at) >= ?",
            [$uid, $weekStart]
        );
        $extToday = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM extracted_sites
             WHERE pushed_by=? AND DATE(created_at)=? AND LEFT(domain, 8) <> '__blank_'",
            [$uid, $today]
        );
        $extWeek = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM extracted_sites
             WHERE pushed_by=? AND DATE(created_at) >= ? AND LEFT(domain, 8) <> '__blank_'",
            [$uid, $weekStart]
        );
        $emailToday = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE pushed_by=? AND DATE(created_at)=?
               AND LEFT(domain, 8) <> '__blank_'
               AND (email1<>'' OR email2<>'' OR email3<>'' OR email4<>'')",
            [$uid, $today]
        );
        $emailWeek = admin_dashboard_sql_count(
            "SELECT COUNT(*) FROM sites_with_emails_admin
             WHERE pushed_by=? AND DATE(created_at) >= ?
               AND LEFT(domain, 8) <> '__blank_'
               AND (email1<>'' OR email2<>'' OR email3<>'' OR email4<>'')",
            [$uid, $weekStart]
        );
        $score = $addedWeek + $extWeek + $emailWeek;
        if ($score < 1 && $addedToday < 1 && $extToday < 1 && $emailToday < 1) {
            continue;
        }
        $out[] = [
            'id' => $uid,
            'name' => trim((string) ($u['full_name'] ?: $u['username'])) ?: (string) $u['username'],
            'username' => (string) $u['username'],
            'added_today' => $addedToday,
            'added_week' => $addedWeek,
            'extracted_today' => $extToday,
            'extracted_week' => $extWeek,
            'emailed_today' => $emailToday,
            'emailed_week' => $emailWeek,
            'score' => $score,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']) ?: strcasecmp((string) $a['name'], (string) $b['name']);
    });
    return array_slice($out, 0, 12);
}

/**
 * Global search across domains, emails, and orders.
 *
 * @return array{q:string,domains:list<array>,emails:list<array>,orders:list<array>}
 */
function admin_global_search(string $q, int $limit = 40): array
{
    $q = trim($q);
    $out = ['q' => $q, 'domains' => [], 'emails' => [], 'orders' => []];
    if ($q === '' || mb_strlen($q) < 2) {
        return $out;
    }
    $like = '%' . $q . '%';
    $limit = max(5, min(80, $limit));

    try {
        $stmt = db()->prepare(
            "SELECT domain, country, 'Our database' AS source, id
             FROM prospect_sites
             WHERE domain LIKE ?
             ORDER BY updated_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out['domains'][] = [
                'domain' => (string) $row['domain'],
                'country' => (string) $row['country'],
                'source' => (string) $row['source'],
                'href' => 'index.php?page=admin_prospects&country=' . rawurlencode((string) $row['country'])
                    . '&q=' . rawurlencode((string) $row['domain']),
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = db()->prepare(
            "SELECT domain, country, 'Extracted' AS source
             FROM extracted_sites
             WHERE domain LIKE ? AND LEFT(domain, 8) <> '__blank_'
             ORDER BY updated_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out['domains'][] = [
                'domain' => (string) $row['domain'],
                'country' => (string) $row['country'],
                'source' => (string) $row['source'],
                'href' => 'index.php?page=admin_extracted&country=' . rawurlencode((string) $row['country'])
                    . '&q=' . rawurlencode((string) $row['domain']),
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = db()->prepare(
            "SELECT id, domain, country, email1, email2, email3, email4
             FROM sites_with_emails_admin
             WHERE LEFT(domain, 8) <> '__blank_'
               AND (domain LIKE ? OR email1 LIKE ? OR email2 LIKE ? OR email3 LIKE ? OR email4 LIKE ?)
             ORDER BY updated_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $emails = [];
            foreach (['email1', 'email2', 'email3', 'email4'] as $k) {
                $e = trim((string) ($row[$k] ?? ''));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
            $out['emails'][] = [
                'domain' => (string) $row['domain'],
                'country' => (string) $row['country'],
                'emails' => $emails,
                'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails&country='
                    . rawurlencode((string) $row['country']) . '&q=' . rawurlencode((string) $row['domain']),
            ];
            if (stripos((string) $row['domain'], $q) !== false) {
                $out['domains'][] = [
                    'domain' => (string) $row['domain'],
                    'country' => (string) $row['country'],
                    'source' => 'Emails Admin',
                    'href' => 'index.php?page=admin_emails_data&folder=sites_with_emails&country='
                        . rawurlencode((string) $row['country']) . '&q=' . rawurlencode((string) $row['domain']),
                ];
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = db()->prepare(
            "SELECT i.id, i.site_name, i.country, i.live_url, i.is_paid, i.decided_price,
                    c.id AS client_id, c.name AS client_name
             FROM order_items i
             INNER JOIN order_clients c ON c.id = i.client_id
             WHERE i.row_type='site'
               AND (i.site_name LIKE ? OR i.live_url LIKE ? OR c.name LIKE ? OR i.country LIKE ?)
             ORDER BY i.updated_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out['orders'][] = [
                'site' => (string) $row['site_name'],
                'client' => (string) $row['client_name'],
                'country' => (string) $row['country'],
                'live_url' => (string) $row['live_url'],
                'paid' => (int) ($row['is_paid'] ?? 0) === 1,
                'price' => (float) ($row['decided_price'] ?? 0),
                'href' => 'index.php?page=admin_order_sheet&id=' . (int) $row['client_id'],
            ];
        }
    } catch (Throwable $e) {
    }

    // Dedupe domains by domain+source
    $seen = [];
    $deduped = [];
    foreach ($out['domains'] as $d) {
        $key = strtolower($d['domain'] . '|' . $d['source']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $d;
    }
    $out['domains'] = array_slice($deduped, 0, $limit);

    return $out;
}
