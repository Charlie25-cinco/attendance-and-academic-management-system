<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Redirect to Enrollments page where SF1 import is now integrated
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Handle download of CSV template (still available at this URL for backward compatibility)
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="SF1_Student_Import_Template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['LRN','Last Name','First Name','Middle Name','Sex (Male/Female)','Grade Level (11 or 12)','Section','Track','Date of Birth (YYYY-MM-DD)','Address','Contact Number (Parent)','Parent/Guardian Name']);
    fputcsv($out, ['123456789012','Dela Cruz','Juan','Reyes','Male','11','HUMILITY','academic','2007-05-15','Balingasag, MisOr','09XXXXXXXXX','Maria Dela Cruz']);
    fputcsv($out, ['123456789013','Santos','Maria','Lopez','Female','12','PATIENCE','techpro','2006-08-22','Balingasag, MisOr','09XXXXXXXXX','Jose Santos']);
    fclose($out);
    exit();
}

header("Location: admin_Enrollments.php");
exit();
