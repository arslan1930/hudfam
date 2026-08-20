<?php
/**
 * Team single-site form removed from Team nav — Admin owns Our database.
 */
$user = require_team();
if (!is_admin($user)) {
    flash('error', 'Our database is Admin-only. Use Filter & add instead.');
    redirect('index.php?page=team_prospect_check');
}
redirect('index.php?page=admin_prospects');
