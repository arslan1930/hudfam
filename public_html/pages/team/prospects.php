<?php
/**
 * Our database browsing is Admin-only on this MVP.
 * Team uses Filter & add; keep routes so old bookmarks redirect cleanly.
 */
$user = require_team();
if (!is_admin($user)) {
    flash('error', 'Our database is Admin-only. Use Filter & add to check and save unique sites.');
    redirect('index.php?page=team_prospect_check');
}
redirect('index.php?page=admin_prospects');
