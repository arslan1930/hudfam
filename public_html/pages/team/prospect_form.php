<?php
/**
 * Prospect browse/edit from Our database is Admin-only.
 * Teammates add only via Filter & add.
 */
require_team();
flash('error', 'Our database is private to Admin. Use Filter & add to submit new unique sites.');
redirect('index.php?page=team_prospect_check');
