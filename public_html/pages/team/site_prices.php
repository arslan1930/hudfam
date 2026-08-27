<?php
/**
 * Team · Website prices — publisher rate book (Communication Team).
 */
$user = require_team();
if (!team_page_unlocked($user, 'team_site_prices')) {
    flash('error', 'Your login only shows work and tools for your department.');
    redirect('index.php?page=team_departments');
}
site_price_run_page($user, 'team');
