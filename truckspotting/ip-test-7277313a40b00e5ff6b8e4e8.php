<?php
header('Content-Type: application/json'); header('Cache-Control: no-store');
$h = [];
foreach (['REMOTE_ADDR','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','HTTP_CF_CONNECTING_IP','HTTP_FORWARDED','HTTP_X_FORWARDED_PROTO','HTTP_CLIENT_IP'] as $k) $h[$k] = $_SERVER[$k] ?? null;
echo json_encode($h);
