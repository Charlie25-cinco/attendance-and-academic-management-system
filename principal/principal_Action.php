<?php

require_once __DIR__ . '/../functions/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Principal access is required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST for report-card decisions.']);
    exit;
}

requireCsrfToken();

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$rawIds = $_POST['report_card_ids'] ?? [];
if (is_string($rawIds)) {
    $decoded = json_decode($rawIds, true);
    $rawIds = is_array($decoded) ? $decoded : [$rawIds];
}

try {
    $review = new \BshsAms\Grade\ReportCardReview($db);
    $count = $review->review(
        (int)$_SESSION['user_id'],
        is_array($rawIds) ? $rawIds : [],
        strtolower(trim((string)($_POST['decision'] ?? ''))),
        trim((string)($_POST['remarks'] ?? ''))
    );
    echo json_encode([
        'success' => true,
        'message' => $count === 1 ? '1 report card was updated.' : $count . ' report cards were updated.',
    ]);
} catch (DomainException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Principal report-card review failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The decision could not be saved. Please refresh and try again.']);
}
