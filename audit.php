<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/helpers.php';

$id = (string)($_GET['id'] ?? '');
$job = $id !== '' ? Database::get()->getJob($id) : null;
if (!$job) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Аудит не знайдено</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="container">'
        . '<div class="card"><h2>Аудит не знайдено</h2><p class="muted">Можливо, посилання застаріло або містить помилку.</p>'
        . '<a class="btn btn-secondary" href="index.php">До списку аудитів</a></div></div></body></html>';
    exit;
}

$report = null;
if (!empty($job['result_json'])) {
    $decoded = json_decode((string)$job['result_json'], true);
    $report = is_array($decoded) ? $decoded : null;
}

header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/views/report.php';
