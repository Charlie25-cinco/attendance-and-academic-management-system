<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$principalPage = [
    'id' => 'pending',
    'title' => 'Pending Report Cards',
    'icon' => 'bi-hourglass-split',
    'heading' => 'Pending report-card review',
    'description' => 'Review adviser submissions after Admin verification, then release them or return them with clear remarks.',
    'table_heading' => 'Awaiting your decision',
    'statuses' => ['submitted_admin'],
    'actions' => ['approve', 'reject'],
    'empty' => 'No report cards are waiting for Principal review.',
];

require __DIR__ . '/../includes/principal-report-card-page.php';
