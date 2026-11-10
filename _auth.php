<?php
// Необов'язковий вхід. Якщо в .env задано GEO_AUDIT_USER і GEO_AUDIT_PASSWORD, інструмент питає логін і пароль.
// Без них він відкритий: так зручно працювати локально. На публічному сервері пароль варто задати,
// бо кожен аудит ходить по чужих сайтах і може витрачати платні API.
require_once __DIR__ . '/lib/env.php';

$geoUser = (string)getenv('GEO_AUDIT_USER');
$geoPassword = (string)getenv('GEO_AUDIT_PASSWORD');
if ($geoUser !== '' && $geoPassword !== '') {
    $u = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $p = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if (!hash_equals($geoUser, $u) || !hash_equals($geoPassword, $p)) {
        header('WWW-Authenticate: Basic realm="GEO Audit", charset="UTF-8"');
        http_response_code(401);
        exit('Потрібен вхід.');
    }
}
