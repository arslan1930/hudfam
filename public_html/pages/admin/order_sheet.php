<?php
$user = require_admin();
ensure_order_schema();

$clientId = (int) get('id');
$client = $clientId > 0 ? get_order_client($clientId) : null;
$q = $client ? trim((string) $client['name']) : '';
$target = 'index.php?page=admin_orders&folder=completed';
if ($q !== '') {
    $target .= '&q=' . rawurlencode($q);
}
redirect($target);
