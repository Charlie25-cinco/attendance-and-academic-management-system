<?php
require_once __DIR__ . '/../functions/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin access is required.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST.']); exit; }
requireCsrfToken();
$ids = json_decode((string)($_POST['report_card_ids'] ?? '[]'), true);
try {
    $db = (new Database())->getConnection();
    $count = (new \BshsAms\Grade\AdminReportCardRelease($db))->decide(
        (int)$_SESSION['user_id'], is_array($ids) ? $ids : [], strtolower(trim((string)($_POST['decision'] ?? ''))), trim((string)($_POST['remarks'] ?? ''))
    );
    echo json_encode(['success' => true, 'message' => $count . ' report card(s) updated.']);
} catch (DomainException $e) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Admin report-card release failed.'); http_response_code(500); echo json_encode(['success' => false, 'message' => 'The decision could not be saved.']);
}
