# Lorella Speciality Cafe — Staff Attendance & Management System

> Production-quality, multi-branch staff attendance and workforce management platform for **Lorella Speciality Cafe**, engineered with server-authoritative geofencing, selfie verification, mandatory TOTP 2FA, and granular role-based access control.

---

## 1. Project Overview & Goals

**Lorella Speciality Cafe** requires a secure, high-precision workforce management system to accurately track staff attendance across multiple café branches. The system is designed to eliminate buddy punching, clock-in spoofing, and inaccurate manual timesheets through:

- **Server-Authoritative Validation**: Timestamps, identity resolution, café scoping, and geofence boundary checks are computed exclusively on the server.
- **Geofenced Verification**: High-accuracy mobile browser GPS validation against branch perimeters using spherical Haversine trigonometry.
- **Selfie Verification**: Live camera capture during check-in/out stored securely in protected storage.
- **Strict Role-Based Multi-Tenancy**: Scoped access ensuring managers only view and manage staff within their assigned branch.
- **Zero-Dependency Portability**: Built with pure PHP 8.4+ and MySQL 8+, designed for turnkey deployment on Hostinger shared or VPS hosting without runtime Node.js or composer build dependencies.

---

## 2. Technology Stack

- **Backend**: PHP 8.4+ (Strict typing, native PSR-4 autoloader, PDO prepared statements)
- **Database**: MySQL 8.0+ (InnoDB, UTF8mb4, foreign key constraints, composite index optimizations)
- **Frontend**: HTML5, CSS3, Bootstrap 5.3+, Vanilla JavaScript (ES6+)
- **Mobile/PWA**: Progressive Web App architecture, Web App Manifest, Service Worker UI caching (with strict live-network policy for attendance validation)
- **Deployment Target**: Hostinger (LAMP/LiteSpeed architecture with Apache mod_rewrite & directory protection)

---

## 3. User Roles & Permission Matrix

| Role | Branch Scope | Permissions | 2FA Requirement |
|---|---|---|---|
| **Super Admin** | System-Wide (All Branches) | Full system configuration, branch management, admin/manager/staff management, global reports, audit logs. | **Mandatory RFC 6238 TOTP** |
| **Admin** | Assigned Café Branch(es) | Branch operational management, staff assignment, attendance oversight, leave approval, branch reports. | **Mandatory RFC 6238 TOTP** |
| **Manager** | Assigned Café Branch | Daily staff shift management, attendance review, leave request approvals, daily branch reports. | **Mandatory RFC 6238 TOTP** |
| **Staff** | Assigned Workplace | Mobile check-in, check-out, personal attendance history, leave request submissions, notifications. | Standard Authentication |

---

## 4. Security Architecture

1. **Password Security**:
   - `Argon2id` (memory: 64MB, time: 4, threads: 2) with automated Bcrypt fallback.
   - Dynamic hash upgrade checks upon successful authentication (`password_needs_rehash`).
2. **Two-Factor Authentication**:
   - Built-in pure PHP RFC 6238 TOTP engine (compatible with Google Authenticator, Microsoft Authenticator, Authy).
   - Single-use hashed backup recovery codes.
   - Intermediate pending session state preventing 2FA bypass on privileged routes.
3. **Session & Cookie Hardening**:
   - `HttpOnly = true`, `SameSite = Lax`, dynamic `Secure` cookie flag.
   - Session ID regeneration (`session_regenerate_id(true)`) upon authentication.
   - Sliding-window rate limiting on login endpoints (max 5 attempts per 15 minutes).
4. **Attendance Media Isolation**:
   - Uploaded photos stored in `/storage/uploads/attendance/`.
   - Direct web server access blocked via `.htaccess` (`Require all denied`).
   - Photos served exclusively via authenticated, role-verified PHP streaming proxies.
5. **CSRF Mitigation**:
   - Timing-safe `hash_equals()` CSRF token verification across all state-changing endpoints (`POST`, `PUT`, `DELETE`).

---

## 5. Project Directory Structure

```
/
├── public/                  # Public web server document root
│   ├── index.php            # Unified application & API front controller
│   ├── .htaccess            # Apache rewrites and security response headers
│   ├── manifest.json        # PWA Web App Manifest
│   └── sw.js                # Service Worker (UI shell cache; live API network)
├── app/                     # Protected application code
│   ├── Core/                # Autoloader, Env parser, Database, Request, Response, Router
│   ├── Controllers/         # Thin HTTP & API controllers
│   ├── Services/            # Business logic (Auth, Totp, Geofence, Attendance, Audit)
│   ├── Repositories/        # PDO data access layer
│   ├── Middleware/          # Auth, Role RBAC, CSRF, and Rate Limiting guards
│   ├── Validators/          # Request validation
│   ├── Helpers/             # Utilities
│   └── Models/              # Domain entities
├── config/                  # Application, database, and security configuration
├── database/                # MySQL 8+ schema & seed scripts
│   ├── schema.sql           # Complete DDL schema (14 normalized tables)
│   ├── seed.sql             # Roles, leave types, flagship branch, system settings
│   └── migrations/          # Versioned schema migrations
├── assets/                  # Frontend UI assets
│   ├── css/lorella-theme.css# Lorella Burgundy & Cream design system tokens
│   └── js/                  # ApiClient (CSRF fetch wrapper) and App lifecycle
├── storage/                 # Protected file storage (Blocked from direct HTTP access)
│   ├── uploads/attendance/  # Private attendance selfie captures
│   ├── logs/                # Application logs
│   └── cache/               # Cache
├── scripts/                 # CLI operational scripts
│   └── create_admin.php     # Secure first-admin provisioning script
├── docs/                    # Technical architecture & API documentation
│   ├── architecture.md      # Full system architecture specification
│   ├── database.md          # Entity dictionary and schema design
│   ├── security.md          # Threat model and security policies
│   ├── api.md               # REST API endpoints and contract catalog
│   └── testing.md           # Verification strategy and test procedures
├── tests/                   # Native PHP test runners
│   └── test_foundation.php  # Mathematical and architectural verification suite
├── .env.example             # Environment variable configuration template
├── .gitignore               # Strict exclusion of secrets, uploads, logs, OS files
└── README.md                # Project documentation
```

---

## 6. Installation & Setup Guide

### 6.1. Environment Configuration
1. Clone repository to your local development environment or Hostinger directory.
2. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
3. Configure your MySQL credentials and application URL in `.env`:
   ```ini
   DB_HOST=127.0.0.1
   DB_NAME=lorella_attendance
   DB_USER=your_db_username
   DB_PASS=your_db_password
   ```

### 6.2. Database Initialization
Import the schema and baseline seed records into your MySQL 8+ instance:
```bash
mysql -u your_db_username -p lorella_attendance < database/schema.sql
mysql -u your_db_username -p lorella_attendance < database/seed.sql
```

### 6.3. Provision First Super Administrator
In strict adherence to security best practices, no default admin account with a known password is seeded. Run the interactive provisioning tool:
```bash
php scripts/create_admin.php "Super Admin" "admin@lorellacafe.com" "YourStrongPassword123"
```
The script will output:
- Account confirmation
- Base32 2FA Secret Key
- `otpauth://` URI for Google Authenticator / Authy
- 8 single-use backup recovery codes

### 6.4. Run Verification Test Suites
Validate the core mathematical algorithms, autoloader, and authentication lifecycle:
```bash
# Foundation Tests (Geofence, TOTP math, Autoloader, Env)
php tests/test_foundation.php

# Authentication Lifecycle Tests (Login, Sessions, Lockout, 2FA Pending, Rehash)
php tests/test_auth.php
```

---

## 7. Development Roadmap & Status

- [x] **Phase 1: Project Foundation & Architecture**
  - Framework-independent PHP 8.4+ PSR-4 core architecture
  - Normalized MySQL 8+ multi-branch schema & indexes
  - Pure PHP RFC 6238 TOTP engine & Haversine geofence service
  - Security middleware (Auth, RBAC, CSRF, Rate Limiter)
  - REST API contracts and routing foundation
  - Lorella design tokens (Wine, Cream, Gold palette)
  - PWA manifest and service worker shell
  - Complete documentation suite (`/docs`)
- [ ] **Phase 2: Authentication & TOTP 2FA Implementation**
  - [x] **Phase 2.1: Authentication Foundation** *(Completed)*
    - Real login endpoint (`POST /api/auth/login`) with Argon2id & lockout
    - Session lifecycle, regeneration & hardened cookie parameters
    - Two-stage 2FA pending state (`auth_state = '2fa_pending'`)
    - Current authenticated user endpoint (`GET /api/auth/me`)
    - Secure logout endpoint (`POST /api/auth/logout`)
    - Automated test runner (`tests/test_auth.php`)
  - [ ] **Phase 2.2: TOTP 2FA Verification & Enrollment**
    - QR Code generation for 2FA enrollment
    - 2FA verification flow (`POST /api/auth/2fa/verify`)
    - Backup recovery code redemption
    - Interactive login and 2FA UI templates
- [ ] **Phase 3: Geofenced & Live Selfie Attendance Workflow**
  - Camera capture interface (`MediaDevices.getUserMedia`)
  - Geolocation acquisition & client-side accuracy display
  - Server-authoritative check-in and check-out handlers
  - Attendance image storage and stream authorization
- [ ] **Phase 4: Staff & Branch Management (Manager/Admin Portals)**
  - Branch configuration and geofence radius adjustment
  - Staff onboarding, role assignment, and credentials
  - Daily attendance rosters and live status dashboards
- [ ] **Phase 5: Leave Management & Shift Reporting**
  - Leave application and manager review workflow
  - Daily and monthly attendance calculation reports
  - Late arrival tracking and working-hours computation

---

## 8. License
This project is licensed under the MIT License — see the [LICENSE](file:///Users/ganeshshastri/Developer/lorella-cafe-attendance-system/LICENSE) file for details.
