<?php
/**
 * Our database browse is Admin-only. Quiet redirect — Team uses Filter & add.
 */
require_team();
redirect('index.php?page=team_prospect_check');
