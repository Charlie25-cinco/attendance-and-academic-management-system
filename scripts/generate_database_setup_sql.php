<?php

require_once __DIR__ . '/../vendor/autoload.php';

$defaultPassword = getDefaultNewUserPassword();
$passwordError = null;
if (!validateStrongPassword($defaultPassword, $passwordError)) {
    fwrite(STDERR, 'DEFAULT_NEW_USER_PASSWORD is not strong enough: ' . $passwordError . "\n");
    exit(1);
}

$resetPath = __DIR__ . '/../database/reset_database.sql';
$schemaPath = __DIR__ . '/../database/schema.sql';
$resetSql = file_get_contents($resetPath);
$schemaSql = file_get_contents($schemaPath);
if (!is_string($resetSql) || !is_string($schemaSql)) {
    fwrite(STDERR, "Unable to read the canonical reset and schema SQL files.\n");
    exit(1);
}

$accountTemplate = <<<'SQL'
-- =============================================================================
-- PROTECTED SYSTEM ACCOUNTS
-- =============================================================================
-- Both accounts use DEFAULT_NEW_USER_PASSWORD and must change it on first login.

INSERT INTO users (
    reference_code, email, password, first_name, last_name, role, status, created_at, updated_at
) VALUES (
    'A341227-1',
    'A341227-1@balingasag.edu.ph',
    '{{DEFAULT_PASSWORD_HASH}}',
    'System',
    'Administrator',
    'admin',
    'active',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password = VALUES(password),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    role = 'admin',
    status = 'active',
    updated_at = NOW();

INSERT INTO users (
    reference_code, email, password, first_name, last_name, role, status, created_at, updated_at
) VALUES (
    'PR341227-1',
    'PR341227-1@balingasag.edu.ph',
    '{{DEFAULT_PASSWORD_HASH}}',
    'School',
    'Principal',
    'principal',
    'active',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password = VALUES(password),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    role = 'principal',
    status = 'active',
    updated_at = NOW();
SQL;

$accountSql = str_replace(
    '{{DEFAULT_PASSWORD_HASH}}',
    password_hash($defaultPassword, PASSWORD_BCRYPT),
    $accountTemplate
);

$generated = <<<'SQL'
-- =============================================================================
-- Balingasag SHS AMS - Generated Complete Reset and Setup
-- =============================================================================
-- WARNING: Importing this file permanently deletes existing application data.
-- Select the intended database before import. This file contains reusable
-- password hashes; do not commit, upload publicly, or retain it after use.
-- =============================================================================

SQL;
$generated .= rtrim($resetSql) . "\n\n";
$generated .= rtrim($schemaSql) . "\n\n";
$generated .= rtrim($accountSql) . "\n";

if (str_contains($generated, '{{')) {
    fwrite(STDERR, "Database setup generation failed because a template placeholder remains.\n");
    exit(1);
}

$outputPath = __DIR__ . '/../database/reset_and_setup.local.sql';
if (file_put_contents($outputPath, $generated, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write generated database setup SQL.\n");
    exit(1);
}
@chmod($outputPath, 0600);
echo "Generated complete database setup: database/reset_and_setup.local.sql\n";
