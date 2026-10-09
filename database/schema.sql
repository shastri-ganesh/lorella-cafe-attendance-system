-- =====================================================================
-- Lorella Speciality Cafe - Database Schema (MySQL 8.0+)
-- Staff Attendance & Management System
-- Normalized, Multi-Café Scoped, Server-Authoritative Architecture
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `leave_requests`;
DROP TABLE IF EXISTS `leave_types`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `recovery_codes`;
DROP TABLE IF EXISTS `two_factor_auth`;
DROP TABLE IF EXISTS `user_cafes`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `cafes`;
DROP TABLE IF EXISTS `roles`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. ROLES
-- ---------------------------------------------------------------------
CREATE TABLE `roles` (
    `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(30) NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. CAFES (Multi-Branch Support)
-- ---------------------------------------------------------------------
CREATE TABLE `cafes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `address` TEXT NOT NULL,
    `latitude` DECIMAL(10, 8) NOT NULL,
    `longitude` DECIMAL(11, 8) NOT NULL,
    `radius_meters` DECIMAL(8, 2) NOT NULL DEFAULT 100.00,
    `timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Kolkata',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cafes_code` (`code`),
    KEY `idx_cafes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. USERS
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` TINYINT UNSIGNED NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(25) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `last_login_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_id` (`role_id`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. USER_CAFES (Staff/Manager Café Assignments)
-- ---------------------------------------------------------------------
CREATE TABLE `user_cafes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `cafe_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_cafe` (`user_id`, `cafe_id`),
    KEY `idx_user_cafes_cafe` (`cafe_id`),
    CONSTRAINT `fk_user_cafes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_cafes_cafe` FOREIGN KEY (`cafe_id`) REFERENCES `cafes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. TWO_FACTOR_AUTH (TOTP 2FA)
-- ---------------------------------------------------------------------
CREATE TABLE `two_factor_auth` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `secret` VARCHAR(64) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `confirmed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_2fa_user_id` (`user_id`),
    CONSTRAINT `fk_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. RECOVERY_CODES (2FA Single-Use Backup Codes)
-- ---------------------------------------------------------------------
CREATE TABLE `recovery_codes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recovery_codes_user` (`user_id`),
    CONSTRAINT `fk_recovery_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. ATTENDANCE (Server-Authoritative Records)
-- ---------------------------------------------------------------------
CREATE TABLE `attendance` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `cafe_id` INT UNSIGNED NOT NULL,
    `attendance_date` DATE NOT NULL,
    
    -- Check-In Data (Server validated)
    `check_in_at` DATETIME NOT NULL,
    `check_in_latitude` DECIMAL(10, 8) NOT NULL,
    `check_in_longitude` DECIMAL(11, 8) NOT NULL,
    `check_in_accuracy` DECIMAL(8, 2) NOT NULL,
    `check_in_distance_meters` DECIMAL(8, 2) NOT NULL,
    `check_in_photo_path` VARCHAR(255) NOT NULL,
    `check_in_device_info` VARCHAR(255) NULL,

    -- Check-Out Data (Server validated)
    `check_out_at` DATETIME NULL,
    `check_out_latitude` DECIMAL(10, 8) NULL,
    `check_out_longitude` DECIMAL(11, 8) NULL,
    `check_out_accuracy` DECIMAL(8, 2) NULL,
    `check_out_distance_meters` DECIMAL(8, 2) NULL,
    `check_out_photo_path` VARCHAR(255) NULL,
    `check_out_device_info` VARCHAR(255) NULL,

    -- Status & Calculations
    `total_work_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('checked_in', 'completed', 'auto_closed', 'flagged') NOT NULL DEFAULT 'checked_in',
    `is_late` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_att_user_date` (`user_id`, `attendance_date`),
    KEY `idx_att_cafe_date` (`cafe_id`, `attendance_date`),
    KEY `idx_att_status` (`status`),
    KEY `idx_att_date` (`attendance_date`),
    CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_att_cafe` FOREIGN KEY (`cafe_id`) REFERENCES `cafes` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. LEAVE_TYPES
-- ---------------------------------------------------------------------
CREATE TABLE `leave_types` (
    `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `days_per_year` TINYINT UNSIGNED NOT NULL DEFAULT 12,
    `is_paid` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_leave_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. LEAVE_REQUESTS
-- ---------------------------------------------------------------------
CREATE TABLE `leave_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `cafe_id` INT UNSIGNED NOT NULL,
    `leave_type_id` TINYINT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `total_days` DECIMAL(4, 1) NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED NULL,
    `reviewed_at` DATETIME NULL,
    `rejection_reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_leave_user` (`user_id`),
    KEY `idx_leave_cafe` (`cafe_id`),
    KEY `idx_leave_status` (`status`),
    CONSTRAINT `fk_leave_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_leave_cafe` FOREIGN KEY (`cafe_id`) REFERENCES `cafes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('attendance', 'leave', 'security', 'system') NOT NULL DEFAULT 'system',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_read` (`user_id`, `is_read`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. AUDIT_LOGS
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `cafe_id` INT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_cafe` (`cafe_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. SYSTEM_SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cafe_id` INT UNSIGNED NULL,
    `setting_key` VARCHAR(80) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `data_type` ENUM('string', 'integer', 'float', 'boolean', 'json') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) NULL,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_cafe_key` (`cafe_id`, `setting_key`),
    CONSTRAINT `fk_settings_cafe` FOREIGN KEY (`cafe_id`) REFERENCES `cafes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_settings_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. RATE_LIMITS (Brute-Force Protection)
-- ---------------------------------------------------------------------
CREATE TABLE `rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rate_key` VARCHAR(64) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `window_expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_key` (`rate_key`),
    KEY `idx_rate_expires` (`window_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
