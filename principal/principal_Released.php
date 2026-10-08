<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$principalPage = [
    'id' => 'released',
    'title' => 'Released Report Cards',
    'icon' => 'bi-patch-check',
    'heading' => 'Released report cards',
    'description' => 'Monitor report cards currently visible to students and parents, or withdraw a release when correction is required.',
    'table_heading' => 'Released to families',
    'statuses' => ['approved'],
    'actions' => ['withdraw'],
    'empty' => 'No report cards have been released.',
];

require __DIR__ . '/../includes/principal-report-card-page.php';
