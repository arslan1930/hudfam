<?php
$user = require_team();
ensure_sites_with_emails_schema();

$sweUser = $user;
$swePanel = 'team';
$sweBase = 'index.php?page=team_sites_emails';
require __DIR__ . '/../sites_with_emails_app.php';
