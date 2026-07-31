<?php

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = dirname(__DIR__) . '/config.php';
    if (!file_exists($path)) {
        return [];
    }
    $config = require $path;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = app_config();
    if (!$config) {
        throw new RuntimeException('Missing config.php. Run install.php first.');
    }
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
