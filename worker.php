<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/AuditEngine.php';

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "Usage: php worker.php <job_id>\n");
    exit(1);
}

$logDir = Database::dataDir() . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/worker-' . $jobId . '.log';
$log = function ($msg) use ($logFile) {
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
};

$db = Database::get();
$job = $db->getJob($jobId);
if (!$job) {
    $log('Job not found: ' . $jobId);
    exit(1);
}

$log('Starting audit for ' . $job['url']);

try {
    $engine = new AuditEngine($jobId);
    $report = $engine->run($job['url']);
    $reportDir = Database::dataDir() . '/reports/' . $jobId;
    if (!is_dir($reportDir)) @mkdir($reportDir, 0775, true);
    @file_put_contents($reportDir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->finishJob($jobId, $report['composite_score'], json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $log('Finished. Score: ' . $report['composite_score']);
} catch (Throwable $e) {
    $log('FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $db->failJob($jobId, $e->getMessage());
    exit(1);
}
