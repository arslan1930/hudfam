<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function reject_reasons(): array
{
    return [
        'already_used' => 'Already used',
        'casino_links' => 'Casino links',
        'weak_site' => 'Weak site',
        'mfa' => 'MFA (Made for advertising)',
        'bad_niche' => 'Bad niche fit',
        'price_high' => 'Price too high',
        'low_metrics' => 'Low traffic / metrics',
        'other' => 'Other',
    ];
}

function site_statuses(): array
{
    return [
        'draft' => 'Draft',
        'negotiating' => 'Negotiating',
        'agreed' => 'Agreed',
        'sent' => 'Sent',
        'rejected' => 'Rejected',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'blocked' => 'Blocked',
    ];
}

function badge(string $status): string
{
    return '<span class="badge ' . h($status) . '">' . h($status) . '</span>';
}

function money_or_dash($value): string
{
    return $value === null || $value === '' ? '—' : h((string) $value);
}
