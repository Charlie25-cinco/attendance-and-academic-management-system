<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'parent') {
    header('Location: ../auth/login.php');
    exit;
}

$activityRole = 'parent';
$activityClearUrl = 'Parent_Activity_Logs.php';
require __DIR__ . '/../includes/own_activity_page.php';
