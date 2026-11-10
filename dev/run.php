<?php
declare(strict_types=1);

/**
 * Dev: запуск аудиту без БД.
 *   php dev/run.php <url> [CheckClass] [--no-ai] [--progress]
 * Без CheckClass - повний звіт (JSON). З CheckClass - лише результат цієї перевірки (ctx будується повністю).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_time_limit(0);

require_once __DIR__ . '/../lib/AuditEngine.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, function ($a) { return strpos($a, '--') === 0; }));
$pos = array_values(array_filter($args, function ($a) { return strpos($a, '--') !== 0; }));
$url = $pos[0] ?? '';
$checkClass = $pos[1] ?? null;
if ($url === '') {
    fwrite(STDERR, "Usage: php dev/run.php <url> [CheckClass] [--no-ai] [--progress]\n");
    exit(0);
}
if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');

$opts = ['ai' => !in_array('--no-ai', $flags, true)];
if (in_array('--progress', $flags, true)) {
    $opts['progress'] = function (int $pct, string $stage) { fwrite(STDERR, sprintf("[%3d%%] %s\n", $pct, $stage)); };
}
$json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

$engine = new AuditEngine(null, $opts);
try {
    if ($checkClass === null) {
        $report = $engine->run($url);
        echo json_encode($report, $json), "\n";
        fwrite(STDERR, sprintf("score=%d tier=%s type=%s duration=%dms json=%d bytes\n",
            $report['composite_score'], $report['tier'], $report['site']['type'], $report['meta']['duration_ms'], strlen(json_encode($report))));
    } else {
        $ctx = $engine->buildContext($url);
        $res = $engine->runChecks($ctx['url'], $ctx, [$checkClass]);
        if (!$res) {
            fwrite(STDERR, "Check class not found: $checkClass\n");
            exit(0);
        }
        echo json_encode($res[0], $json), "\n";
        fwrite(STDERR, 'timings: ' . json_encode($engine->timings()) . "\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    echo json_encode(['error' => $e->getMessage()], $json), "\n";
}
exit(0);
