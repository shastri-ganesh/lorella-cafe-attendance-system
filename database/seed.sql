-- =====================================================================
-- Lorella Speciality Cafe - Database Seed Data (MySQL 8.0+)
-- Baseline Roles, System Settings, Leave Types, Flagship Branch
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. BASELINE ROLES
-- ---------------------------------------------------------------------
INSERT INTO `roles` (`id`, `slug`, `name`, `description`) VALUES
(1, 'super_admin', 'Super Administrator', 'Full system access, multi-café management, security and audit logs'),
(2, 'admin', 'Branch Administrator', 'Branch-level operational management, staff, leave, and reports'),
(3, 'manager', 'Café Manager', 'Shift and daily attendance management for assigned branch staff'),
(4, 'staff', 'Café Staff', 'Attendance check-in/out, leave requests, and personal records');

-- ---------------------------------------------------------------------
-- 2. FLAGSHIP CAFÉ BRANCH TEMPLATE
-- ---------------------------------------------------------------------
INSERT INTO `cafes` (`id`, `name`, `code`, `address`, `latitude`, `longitude`, `radius_meters`, `timezone`, `status`) VALUES
(1, 'Lorella Flagship Cafe', 'LOR-MAIN-01', '100 Speciality Boulevard, Ground Floor, Central District', 12.97159870, 77.59456270, 100.00, 'Asia/Kolkata', 'active');

-- ---------------------------------------------------------------------
-- 3. LEAVE TYPES
-- ---------------------------------------------------------------------
INSERT INTO `leave_types` (`id`, `name`, `code`, `days_per_year`, `is_paid`, `status`) VALUES
(1, 'Annual Paid Leave', 'annual', 12, 1, 'active'),
(2, 'Sick Leave', 'sick', 10, 1, 'active'),
(3, 'Casual Leave', 'casual', 6, 1, 'active'),
(4, 'Unpaid Leave', 'unpaid', 30, 0, 'active');

-- ---------------------------------------------------------------------
-- 4. SYSTEM SETTINGS
-- ---------------------------------------------------------------------
INSERT INTO `system_settings` (`cafe_id`, `setting_key`, `setting_value`, `data_type`, `description`) VALUES
(NULL, 'late_grace_period_minutes', '15', 'integer', 'Grace period in minutes before check-in is flagged as late arrival'),
(NULL, 'geofence_accuracy_buffer_meters', '20', 'integer', 'Dynamic tolerance buffer added to geofence radius for indoor GPS drift'),
(NULL, 'require_selfie_attendance', 'true', 'boolean', 'Enforce live selfie photo capture during attendance check-in and check-out'),
(NULL, 'totp_mandatory_roles', '["super_admin","admin","manager"]', 'json', 'Roles requiring mandatory RFC 6238 two-factor authentication enrollment');

-- ---------------------------------------------------------------------
-- 5. SECURE FIRST-ADMIN PROVISIONING NOTICE
-- Notice: In strict accordance with security requirements, NO DEFAULT
-- SUPER ADMIN ACCOUNT with a known password is seeded into the database.
-- Run the following CLI provisioning command to securely create the first admin:
--
--     php scripts/create_admin.php
--
-- ---------------------------------------------------------------------
