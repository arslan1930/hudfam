<?php
// Country folders are project-scoped via inventory filters now.
require_team();
flash('ok', 'Filter by country inside a project inventory.');
redirect('index.php?page=team_projects');
