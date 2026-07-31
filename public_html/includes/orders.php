<?php

/**
 * Shared helpers for publication orders / CSV export.
 */

function fetch_orders_query(array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['project_id'])) {
        $where[] = 'p.id = ?';
        $params[] = (int) $filters['project_id'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'c.id = ?';
        $params[] = (int) $filters['client_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'o.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(c.name LIKE ? OR c.email LIKE ? OR o.site_domain LIKE ? OR p.name LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = 'SELECT o.*, c.name AS client_name, c.email AS client_email,
                   p.id AS project_id, p.name AS project_name
            FROM publication_orders o
            JOIN clients c ON c.id = o.client_id
            JOIN projects p ON p.id = c.project_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.updated_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function stream_orders_csv(array $rows, string $filename = 'hudfam_orders.csv'): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // Excel UTF-8 BOM
    fputcsv($out, [
        'Project',
        'Client Name',
        'Client Email',
        'Site',
        'Article URL',
        'Date Sent',
        'Price',
        'Currency',
        'Live URL',
        'Status',
        'Completed At',
        'Notes',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['project_name'],
            $row['client_name'],
            $row['client_email'],
            $row['site_domain'],
            $row['article_url'],
            $row['sent_for_publication_at'],
            $row['client_price'],
            $row['currency'],
            $row['live_url'],
            $row['status'],
            $row['completed_at'],
            $row['admin_notes'],
        ]);
    }
    fclose($out);
    exit;
}

function get_client_or_404(int $id): array
{
    $stmt = db()->prepare(
        'SELECT c.*, p.name AS project_name, p.currency AS project_currency
         FROM clients c JOIN projects p ON p.id = c.project_id WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) {
        flash('error', 'Client folder not found.');
        redirect('index.php?page=admin_clients');
    }
    return $client;
}
