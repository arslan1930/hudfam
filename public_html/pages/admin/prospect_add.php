<?php
/**
 * Legacy route: Add sites now lives inside Our database.
 */
require_admin();
$country = trim((string) get('country'));
if ($country !== '' && resolve_canonical_country($country) !== null) {
    $canon = resolve_canonical_country($country);
    redirect('index.php?page=admin_prospects&country=' . urlencode($canon['name']) . '#add-sites');
}
redirect('index.php?page=admin_prospects#add-sites');
