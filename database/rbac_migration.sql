-- =============================================================================
-- RBAC (Role-Based Access Control) Migration
-- Adds roles, permissions, and role_permissions tables
-- =============================================================================

-- Roles table (extends the ENUM with metadata)
CREATE TABLE IF NOT EXISTS rbac_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(30) UNIQUE NOT NULL COMMENT 'Must match users.role ENUM value',
    label VARCHAR(50) NOT NULL,
    description TEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'System roles cannot be deleted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table
CREATE TABLE IF NOT EXISTS rbac_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role-Permission mapping
CREATE TABLE IF NOT EXISTS rbac_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_rbac_rp_role FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rbac_rp_perm FOREIGN KEY (permission_id) REFERENCES rbac_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
