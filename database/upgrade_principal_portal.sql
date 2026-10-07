-- v1.0.0: Run once on existing MySQL/MariaDB installations before deploying the principal portal.
-- New installations use schema.sql. No existing users, approvals, or reviewer IDs are rewritten.
ALTER TABLE users MODIFY role ENUM('admin', 'teacher', 'student', 'parent', 'principal') NOT NULL;

INSERT IGNORE INTO rbac_roles (role_key, label, description, is_system)
VALUES ('principal', 'Principal', 'Reviews, approves and releases report cards.', 1);
INSERT IGNORE INTO rbac_permissions (permission_key, label, category)
VALUES ('report_cards.review', 'Review and Release Report Cards (Principal)', 'grades');
INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1 FROM rbac_roles r CROSS JOIN rbac_permissions p
WHERE r.role_key = 'principal' AND p.permission_key = 'report_cards.review';

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_role VARCHAR(30) NOT NULL,
    action_name VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT DEFAULT NULL,
    details_json TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_actor (actor_user_id),
    INDEX idx_activity_logs_role (actor_role),
    INDEX idx_activity_logs_action (action_name),
    INDEX idx_activity_logs_target (target_type, target_id),
    INDEX idx_activity_logs_created_at (created_at),
    CONSTRAINT fk_activity_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- submitted_admin is retained as the storage value for pending principal review.
-- Sign in as Admin and create an active Principal account through Manage Users.
