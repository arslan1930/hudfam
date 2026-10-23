<?php
$user = require_team();
ensure_sites_with_emails_schema();

if (!team_page_unlocked($user, 'team_sites_emails')) {
    flash('error', 'This tool is for Email Extracting members.');
    redirect(team_home_url());
}

$sweUser = $user;
$swePanel = 'team';
$sweBase = 'index.php?page=team_sites_emails';
require __DIR__ . '/../sites_with_emails_app.php';
