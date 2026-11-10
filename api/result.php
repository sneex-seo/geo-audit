<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

$id = $_GET['id'] ?? '';
$job = Database::get()->getJob($id);
if (!$job) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
if (!$job['result_json']) { http_response_code(202); echo json_encode(['error' => 'Not ready', 'status' => $job['status']]); exit; }

echo $job['result_json'];
