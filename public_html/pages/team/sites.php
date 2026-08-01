<?php
// Global inventory removed — redirect to project picker
$user = require_team();
flash('ok', 'Open a project to work in its inventory (Super search is per project).');
redirect('index.php?page=team_projects');
