<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST for grade decisions.']);
    exit;
}
requireCsrfToken();
define('PRINCIPAL_GRADE_PORTAL', true);
require __DIR__ . '/../admin/admin_Grade_Approvals_Action.php';
