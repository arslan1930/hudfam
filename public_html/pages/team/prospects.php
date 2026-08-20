<?php
/**
 * Our database browse is Admin-only. Teammates must not view country URL lists.
 */
require_team();
flash('error', 'Our database is private to Admin. Use Filter & add to submit new unique sites.');
redirect('index.php?page=team_prospect_check');
