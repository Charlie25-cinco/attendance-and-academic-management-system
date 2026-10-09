<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../auth/login.php');
    exit;
}

$activityRole = 'teacher';
$activityClearUrl = 'teacher_Activity_Logs.php';
require __DIR__ . '/../includes/own_activity_page.php';
