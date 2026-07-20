<?php
// Seed default admin account if it doesn't exist.
// Password comes from FIRST_RUN_ADMIN_PASSWORD env var.
// Run this after database/schema.sql is imported.
// Usage: composer run seed:admin

require_once __DIR__ . '/../functions/bootstrap.php';

$adminPassword = getFirstRunAdminPassword();

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$hash = password_hash($adminPassword, PASSWORD_BCRYPT);

// Seed admin (A341227-1)
$stmt = $db->prepare("SELECT id FROM users WHERE reference_code = 'A341227-1' LIMIT 1");
$stmt->execute();
if (!$stmt->fetch()) {
    $stmt = $db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status, created_at, updated_at)
                           VALUES ('A341227-1', 'A341227-1@balingasag.edu.ph', ?, 'System', 'Administrator', 'admin', 'active', NOW(), NOW())");
    $stmt->execute([$hash]);
    echo "Admin account created (ref: A341227-1).\n";
} else {
    echo "Admin account already exists. Skipping.\n";
}
