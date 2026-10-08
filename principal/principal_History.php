<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$principalPage = [
    'id' => 'history',
    'title' => 'Principal Decision History',
    'icon' => 'bi-clock-history',
    'heading' => 'Decision history',
    'description' => 'Review completed release, return, and withdrawal outcomes without exposing mutation controls.',
    'table_heading' => 'Completed decisions',
    'statuses' => ['approved', 'rejected'],
    'actions' => [],
    'empty' => 'No completed Principal decisions match these filters.',
];

require __DIR__ . '/../includes/principal-report-card-page.php';
