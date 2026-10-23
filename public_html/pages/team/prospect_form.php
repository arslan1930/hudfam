<?php
/**
 * Prospect browse/edit from Our database is Admin-only. Quiet redirect to Filter & add.
 */
require_team();
redirect('index.php?page=team_prospect_check');
