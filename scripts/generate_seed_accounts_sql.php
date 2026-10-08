<?php

require_once __DIR__ . '/../vendor/autoload.php';

$defaultPassword = getDefaultNewUserPassword();
$passwordError = null;
if (!validateStrongPassword($defaultPassword, $passwordError)) {
    fwrite(STDERR, 'DEFAULT_NEW_USER_PASSWORD is not strong enough: ' . $passwordError . "\n");
    exit(1);
}

$template = <<<'SQL'
-- =============================================================================
-- Balingasag Senior High School - Generated Protected System Accounts
-- =============================================================================
-- Import after database/schema.sql.
-- This local file contains reusable password hashes. Do not commit or share it.
-- Re-importing it intentionally resets both accounts to the configured default.
-- =============================================================================

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

$generated = str_replace(
    '{{DEFAULT_PASSWORD_HASH}}',
    password_hash($defaultPassword, PASSWORD_BCRYPT),
    $template
);
if (str_contains($generated, '{{')) {
    fwrite(STDERR, "Seed SQL generation failed because a template placeholder remains.\n");
    exit(1);
}

$outputPath = __DIR__ . '/../database/seed_system_accounts.local.sql';
if (file_put_contents($outputPath, $generated, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write generated seed SQL.\n");
    exit(1);
}
@chmod($outputPath, 0600);
echo "Generated private seed SQL: database/seed_system_accounts.local.sql\n";
