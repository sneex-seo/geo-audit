<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: text/html; charset=utf-8');

$id = (string)($_GET['id'] ?? '');
$job = $id !== '' ? Database::get()->getJob($id) : null;
if (!$job || empty($job['result_json'])) {
    http_response_code(404);
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Звіт не готовий</title></head>'
        . '<body style="font-family:sans-serif;padding:24px">Звіт ще не готовий або не знайдений.</body></html>';
    exit;
}
$report = json_decode((string)$job['result_json'], true);
if (!is_array($report)) {
    http_response_code(500);
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Помилка звіту</title></head>'
        . '<body style="font-family:sans-serif;padding:24px">Не вдалося прочитати збережений звіт.</body></html>';
    exit;
}

$autoPrint = !isset($_GET['noprint']);
require __DIR__ . '/../views/print.php';
