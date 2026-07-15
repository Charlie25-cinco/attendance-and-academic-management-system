<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'health') {
    $db = apiDb();
    apiJson([
        'ok' => true,
        'message' => 'Attendance and Academic mobile API is running',
        'date' => date('Y-m-d'),
        'database' => $db ? 'connected' : 'disconnected',
    ]);
}
