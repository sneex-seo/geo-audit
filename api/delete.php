<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
if (!geo_can_delete()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals(geo_csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bad token']);
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_map('strval', $ids));
$deleted = Database::get()->deleteJobs($ids);

// файли звіту і логи видалених аудитів
$dir = Database::dataDir();
foreach ($deleted as $id) {
    $rd = $dir . '/reports/' . $id;
    if (is_dir($rd)) {
        foreach (glob($rd . '/*') ?: [] as $f) @unlink($f);
        @rmdir($rd);
    }
    foreach (['spawn', 'worker'] as $prefix) @unlink($dir . '/logs/' . $prefix . '-' . $id . '.log');
}

echo json_encode(['ok' => true, 'deleted' => $deleted, 'skipped' => array_values(array_diff($ids, $deleted))]);
