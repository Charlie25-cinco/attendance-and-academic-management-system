<?php
define('PRINCIPAL_GRADE_PORTAL', true);
ob_start();
require __DIR__ . '/../admin/admin_Grade_Approvals_Detail.php';
$html = (string)ob_get_clean();
$html = str_replace(
    ['Admin', 'admin_Grade_Approvals.php', 'admin_Grade_Approvals_Action.php'],
    ['Principal', 'principal_Subject_Grades.php', 'principal_Subject_Grades_Action.php'],
    $html
);
echo $html;
