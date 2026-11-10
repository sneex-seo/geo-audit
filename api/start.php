<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$url = trim((string)($_POST['url'] ?? ''));
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . ltrim($url, '/');
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
    exit;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host || strpos($host, '.') === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid host']);
    exit;
}

$db = Database::get();
$jobId = $db->createJob($url);

// PHP_BINARY під php-fpm вказує на сам fpm, тому беремо його лише якщо це звичайний php
$phpBin = preg_match('/php(\.exe)?$/i', (string)PHP_BINARY) ? PHP_BINARY : trim(@shell_exec('which php') ?: 'php');
$worker = realpath(__DIR__ . '/../worker.php');
$logDir = Database::dataDir() . '/logs';
@mkdir($logDir, 0775, true);
$logFile = $logDir . '/spawn-' . $jobId . '.log';

$cmd = PHP_OS_FAMILY === 'Windows'
    ? sprintf('start /B "" %s %s %s > %s 2>&1', escapeshellarg($phpBin), escapeshellarg($worker), escapeshellarg($jobId), escapeshellarg($logFile))
    : sprintf('nohup %s %s %s > %s 2>&1 &',
    escapeshellarg($phpBin),
    escapeshellarg($worker),
    escapeshellarg($jobId),
    escapeshellarg($logFile)
);

if (PHP_OS_FAMILY === 'Windows') {
    pclose(popen($cmd, 'r'));
} elseif (function_exists('proc_open')) {
    $proc = @proc_open($cmd, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    if (is_resource($proc)) {
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        proc_close($proc);
    } else {
        @shell_exec($cmd);
    }
} else {
    @shell_exec($cmd);
}

echo json_encode(['ok' => true, 'job_id' => $jobId]);
