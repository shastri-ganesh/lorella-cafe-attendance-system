# Lorella Speciality Cafe — Database Architecture & Data Dictionary

## 1. Overview & Principles
The database is built on **MySQL 8.0+** using the **InnoDB** storage engine. It adheres to strict third normal form (3NF) principles while incorporating specialized composite indexing for rapid location queries, daily reporting, and tenant isolation.

### Key Architectural Decisions:
1. **UTF8MB4 Encoding**: `utf8mb4_unicode_ci` is enforced across all tables for multi-language names and emoji compatibility.
2. **Strict Foreign Key Constraints**: Enforces referential integrity. User deletion cascades to ephemeral records (sessions, recovery codes) while preserving attendance history with `RESTRICT` to prevent accidental audit record destruction.
3. **Decoupled Scoping**: Multi-café assignments use a dedicated `user_cafes` relational junction table to support employees working across multiple branches or floating managers.
4. **Server-Authoritative Precision**: Geographic coordinates are stored using `DECIMAL(10, 8)` for latitude and `DECIMAL(11, 8)` for longitude, providing sub-millimeter precision.

---

## 2. Entity Relationship Overview

```
 [roles] (1) <---------- (M) [users]
                              ^   ^
                              |   |--- (1) <---> (1) [two_factor_auth]
                              |   |--- (1) <---> (M) [recovery_codes]
                              |   |--- (1) <---> (M) [notifications]
                              |
                              +--- (M) <---> (M) [cafes] (via user_cafes)
                              |
                              +--- (1) <---> (M) [attendance] (tied to cafes)
                              |
                              +--- (1) <---> (M) [leave_requests] (tied to leave_types & cafes)
                              |
                              +--- (1) <---> (M) [audit_logs]
```

---

## 3. Data Dictionary

### 3.1. `roles`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | TINYINT UNSIGNED | PK, AUTO_INC | Role unique identifier |
| `slug` | VARCHAR(30) | UNIQUE, NOT NULL | Role slug (`super_admin`, `admin`, `manager`, `staff`) |
| `name` | VARCHAR(50) | NOT NULL | Human-readable role name |
| `description` | VARCHAR(255) | NULL | Scope and permission summary |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Creation timestamp |

### 3.2. `cafes`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INC | Café branch unique identifier |
| `name` | VARCHAR(100) | NOT NULL | Branch commercial name (e.g., "Lorella Flagship") |
| `code` | VARCHAR(20) | UNIQUE, NOT NULL | Short branch code (e.g., `LOR-MAIN-01`) |
| `address` | TEXT | NOT NULL | Physical street address |
| `latitude` | DECIMAL(10, 8) | NOT NULL | Geofence center latitude |
| `longitude` | DECIMAL(11, 8) | NOT NULL | Geofence center longitude |
| `radius_meters` | DECIMAL(8, 2) | NOT NULL, DEFAULT 100.00 | Allowed physical attendance radius in meters |
| `timezone` | VARCHAR(50) | NOT NULL, DEFAULT 'Asia/Kolkata' | Branch timezone identifier |
| `status` | ENUM | NOT NULL, DEFAULT 'active' | `'active'` or `'inactive'` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Record creation timestamp |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | Record last modified timestamp |

### 3.3. `users`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INC | User identifier |
| `role_id` | TINYINT UNSIGNED | FK -> roles.id | Assigned system role |
| `full_name` | VARCHAR(100) | NOT NULL | Staff member full name |
| `email` | VARCHAR(150) | UNIQUE, NOT NULL | Login email address |
| `phone` | VARCHAR(25) | NULL | Contact mobile number |
| `password_hash` | VARCHAR(255) | NOT NULL | Argon2id / Bcrypt password hash |
| `status` | ENUM | NOT NULL, DEFAULT 'active' | `'active'`, `'inactive'`, `'suspended'` |
| `failed_attempts`| TINYINT UNSIGNED | NOT NULL, DEFAULT 0 | Consecutive failed login counter |
| `locked_until` | DATETIME | NULL | Timestamp until account lockout expires |
| `last_login_at` | DATETIME | NULL | Timestamp of most recent login |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Account creation timestamp |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | Account last modified timestamp |

### 3.4. `user_cafes`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INC | Assignment identifier |
| `user_id` | INT UNSIGNED | FK -> users.id | Staff member ID |
| `cafe_id` | INT UNSIGNED | FK -> cafes.id | Assigned branch café ID |
| `is_primary` | TINYINT(1) | NOT NULL, DEFAULT 1 | Flag indicating primary default workplace |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Assignment timestamp |
*Unique composite key: `(user_id, cafe_id)`*

### 3.5. `two_factor_auth`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INC | Record identifier |
| `user_id` | INT UNSIGNED | UNIQUE, FK -> users.id | User ID |
| `secret` | VARCHAR(64) | NOT NULL | Base32 encoded 128-bit RFC 6238 TOTP secret |
| `is_enabled` | TINYINT(1) | NOT NULL, DEFAULT 0 | Whether 2FA enrollment has been confirmed |
| `confirmed_at` | DATETIME | NULL | Timestamp of initial 2FA code confirmation |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Record creation timestamp |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | Record last modified timestamp |

### 3.6. `recovery_codes`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INC | Recovery code identifier |
| `user_id` | INT UNSIGNED | FK -> users.id | User ID |
| `code_hash` | VARCHAR(255) | NOT NULL | Password hash of single-use backup code |
| `used_at` | DATETIME | NULL | Consumption timestamp (NULL if unconsumed) |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Generation timestamp |

### 3.7. `attendance`
| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INC | Attendance log identifier |
| `user_id` | INT UNSIGNED | FK -> users.id | Employee ID |
| `cafe_id` | INT UNSIGNED | FK -> cafes.id | Branch café ID |
| `attendance_date` | DATE | NOT NULL | Server-determined local date |
| `check_in_at` | DATETIME | NOT NULL | Server-authoritative check-in timestamp |
| `check_in_latitude` | DECIMAL(10, 8)| NOT NULL | Employee check-in latitude |
| `check_in_longitude`| DECIMAL(11, 8)| NOT NULL | Employee check-in longitude |
| `check_in_accuracy` | DECIMAL(8, 2) | NOT NULL | Reported GPS accuracy in meters |
| `check_in_distance_meters`| DECIMAL(8, 2)| NOT NULL | Calculated Haversine distance from café center |
| `check_in_photo_path`| VARCHAR(255) | NOT NULL | Secure path to uploaded check-in selfie |
| `check_in_device_info`| VARCHAR(255) | NULL | Browser / OS fingerprint summary |
| `check_out_at` | DATETIME | NULL | Server-authoritative check-out timestamp |
| `check_out_latitude`| DECIMAL(10, 8)| NULL | Employee check-out latitude |
| `check_out_longitude`|DECIMAL(11, 8)| NULL | Employee check-out longitude |
| `check_out_accuracy`| DECIMAL(8, 2) | NULL | Reported GPS accuracy on checkout |
| `check_out_distance_meters`| DECIMAL(8, 2)| NULL | Calculated distance from café on checkout |
| `check_out_photo_path`|VARCHAR(255) | NULL | Secure path to uploaded check-out selfie |
| `check_out_device_info`|VARCHAR(255)| NULL | Checkout device fingerprint |
| `total_work_minutes`| INT UNSIGNED | NOT NULL, DEFAULT 0 | Computed duration between check-in & check-out |
| `status` | ENUM | NOT NULL | `'checked_in'`, `'completed'`, `'auto_closed'`, `'flagged'` |
| `is_late` | TINYINT(1) | NOT NULL, DEFAULT 0 | Flag indicating arrival past schedule + grace period |
| `notes` | TEXT | NULL | Staff or manager shift remarks |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | Record creation timestamp |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | Record last modified timestamp |

### 3.8. Additional Normalized Tables
- `leave_types`: Supported categories (Annual, Sick, Casual, Unpaid) with allowance rules.
- `leave_requests`: Employee leave applications with reviewer linkage (`reviewed_by`) and approval status.
- `notifications`: User inbox for attendance alerts, leave approvals, and security notices.
- `audit_logs`: Immutable security journal recording actor, action, old/new state JSON diffs, IP, and user-agent.
- `system_settings`: Global and branch-scoped key/value pairs with type-safety (`string`, `integer`, `float`, `boolean`, `json`).
- `rate_limits`: High-speed sliding-window table tracking failed requests per SHA-256 client hash.
