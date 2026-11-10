<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

$id = $_GET['id'] ?? '';
$job = Database::get()->getJob($id);
if (!$job) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

echo json_encode([
    'id' => $job['id'],
    'status' => $job['status'],
    'progress' => (int)$job['progress'],
    'stage' => $job['stage'],
    'score' => $job['score'] !== null ? (int)$job['score'] : null,
    'error' => $job['error'],
]);
