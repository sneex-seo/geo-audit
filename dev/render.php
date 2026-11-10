<?php
/**
 * Рендер в'юх звіту з JSON-фікстури без БД і авторизації (лише CLI).
 *
 *   php dev/render.php <fixture.json> <out.html> [web|print|running|error]
 *
 * web     - views/report.php (CSS вбудовується inline, щоб файл відкривався з диска)
 * print   - views/print.php без автодруку
 * running - сторінка звіту у стані «Виконується» (без результату)
 * error   - сторінка звіту у стані «Помилка»
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$fixture = $argv[1] ?? '';
$outFile = $argv[2] ?? '';
$mode = $argv[3] ?? 'web';
if ($fixture === '' || $outFile === '' || !in_array($mode, ['web', 'print', 'running', 'error'], true)) {
    fwrite(STDERR, "Usage: php dev/render.php <fixture.json> <out.html> [web|print|running|error]\n");
    exit(1);
}
$raw = @file_get_contents($fixture);
$report = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($report)) {
    fwrite(STDERR, "Cannot read fixture JSON: $fixture\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/helpers.php';

$now = time();
$job = [
    'id' => (string)($report['meta']['job_id'] ?? 'демо'),
    'url' => (string)($report['url'] ?? 'https://example.com'),
    'status' => 'done',
    'progress' => 100,
    'stage' => 'completed',
    'created_at' => $now - 52,
    'updated_at' => $now,
    'finished_at' => $now,
    'score' => $report['composite_score'] ?? null,
    'result_json' => $raw,
    'error' => null,
];
if ($mode === 'running') {
    $job = array_merge($job, ['status' => 'running', 'progress' => 42, 'stage' => 'Running: Structured Data', 'finished_at' => null, 'score' => null, 'result_json' => null]);
    $report = null;
} elseif ($mode === 'error') {
    $job = array_merge($job, ['status' => 'error', 'progress' => 10, 'stage' => 'Fetching homepage', 'score' => null, 'result_json' => null, 'error' => 'Cannot reach URL: HTTP 0 Could not resolve host']);
    $report = null;
}

ob_start();
if ($mode === 'print') {
    $autoPrint = false;
    require $root . '/views/print.php';
} else {
    $inlineCss = (string)file_get_contents($root . '/assets/style.css');
    require $root . '/views/report.php';
}
$html = (string)ob_get_clean();

if (!is_dir(dirname($outFile))) @mkdir(dirname($outFile), 0775, true);
file_put_contents($outFile, $html);
echo "OK $mode -> $outFile (" . strlen($html) . " bytes)\n";
