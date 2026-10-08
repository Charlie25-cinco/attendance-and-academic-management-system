-- =============================================================================
-- Balingasag Senior High School - Protected System Accounts Seed Template
-- =============================================================================
-- The committed copy is a template and intentionally contains password-hash
-- placeholders so no reusable privileged credential is committed to Git.
--
-- 1. Set DEFAULT_NEW_USER_PASSWORD in .env to the approved shared first-login password.
-- 2. Run: composer run seed:accounts-sql
-- 3. Import storage/generated/seed_system_accounts.sql after database/schema.sql.
--
-- Importing the generated SQL again intentionally resets both bootstrap account
-- passwords. Keep the generated file private and delete it after use.
-- =============================================================================

INSERT INTO users (
    reference_code,
    email,
    password,
    first_name,
    last_name,
    role,
    status,
    created_at,
    updated_at
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
    reference_code,
    email,
    password,
    first_name,
    last_name,
    role,
    status,
    created_at,
    updated_at
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
