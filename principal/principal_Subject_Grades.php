<?php
define('PRINCIPAL_GRADE_PORTAL', true);
ob_start();
require __DIR__ . '/../admin/admin_Grade_Approvals.php';
$html = (string)ob_get_clean();
$html = str_replace(
    ['admin_Grade_Approvals_Detail.php', 'admin_Grade_Approvals_Action.php'],
    ['principal_Subject_Grades_Detail.php', 'principal_Subject_Grades_Action.php'],
    $html
);
echo $html;
