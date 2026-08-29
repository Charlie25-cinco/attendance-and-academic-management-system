-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- FIRST ADMIN ACCOUNT SEED
-- =============================================================================
-- Import after database/schema.sql.
--
-- Temporary admin login:
--   Reference Code: A341227-1
--   Password: Bshsams_341227
--
-- IMPORTANT:
-- Change this password immediately after login. This file intentionally resets
-- the first admin password when re-imported, so keep it out of routine
-- production imports unless you need account recovery.
-- =============================================================================

INSERT IGNORE INTO users (
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
    '$2y$10$WElty8dLDYBeQ0k5Di.Tt.gYXatazNrNgjF1RStXQ93Q5F5cfrNaG',
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
