<?php
// Global inventory removed — sites live inside each project
require_admin();
flash('ok', 'Build inventory inside each project folder.');
redirect('index.php?page=admin_projects');
