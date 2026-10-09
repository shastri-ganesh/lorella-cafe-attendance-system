# Lorella Speciality Cafe — System Architecture Specification

## 1. Architectural Philosophy & Overview
The **Lorella Speciality Cafe Staff Attendance & Management System** is designed as a high-performance, maintainable, framework-independent enterprise web application. It addresses real-world café operations across multiple branches while adhering to strict security, auditability, and mobile usability standards.

The application uses **PHP 8.4+** on the backend and **Bootstrap 5 + Vanilla JavaScript** on the frontend, structured cleanly without heavy full-stack frameworks (such as Laravel, Node.js, or React) to ensure complete transparency, minimal memory footprint, and seamless deployment on standard LAMP/Hostinger hosting environments.

```
+-------------------------------------------------------------------------+
|                               Client Layer                              |
|           Mobile Browsers (iOS Safari, Android Chrome) / PWA Shell      |
|           Bootstrap 5 + Lorella Design Tokens + Vanilla JS ApiClient    |
+-------------------------------------------------------------------------+
                                     |  HTTPS (JSON / Multipart)
                                     v
+-------------------------------------------------------------------------+
|                              Gateway Layer                              |
|   Root & Public .htaccess (Hostinger Apache / LiteSpeed Rewrite Engine) |
|   - Blocks direct HTTP access to /app, /config, /storage, /database     |
|   - Security Headers (nosniff, SAMEORIGIN, camera/geolocation policies) |
+-------------------------------------------------------------------------+
                                     |
                                     v
+-------------------------------------------------------------------------+
|                         Application Core (PHP 8.4)                      |
|                                                                         |
|   [public/index.php]                                                    |
|           |                                                             |
|           v                                                             |
|   [Autoloader] ---> [Env Loader] ---> [Secure Session Bootstrap]        |
|           |                                                             |
|           v                                                             |
|   [Router & Pipeline]                                                   |
|           |                                                             |
|   +---------------+------------------+------------------+               |
|   | RateLimit MW  | AuthMiddleware   | RoleMiddleware   | CsrfMiddleware|
|   +---------------+------------------+------------------+               |
|           |                                                             |
|           v                                                             |
|   [Thin Controllers] (Request validation & JSON response formatting)    |
|           |                                                             |
|           v                                                             |
|   [Domain Services] (Business Logic)                                    |
|   - AuthService (Argon2id, Session Lifecycle)                           |
|   - TotpService (Pure PHP RFC 6238 Engine, Base32, Recovery Codes)      |
|   - GeofenceService (Spherical Haversine, GPS Drift Tolerance)          |
|   - AttendanceService (Server-authoritative check-in/out calculations)  |
|   - AuditService (Security & Operational audit trail logger)            |
|           |                                                             |
|           v                                                             |
|   [Data Repositories] (PDO Prepared Statements, zero string concat)     |
|   - UserRepository, CafeRepository, AttendanceRepository, AuditRepo     |
|           |                                                             |
|           v                                                             |
|   [Database Layer] MySQL 8.0+ (InnoDB, Foreign Keys, Scoped Indexes)    |
+-------------------------------------------------------------------------+
```

---

## 2. Directory Structure & Layer Separation

```
/
├── public/                  # Web server document root (publicly accessible)
│   ├── index.php            # Unified Front Controller (Web & REST API)
│   ├── .htaccess            # Rewrites and HTTP security headers
│   ├── manifest.json        # PWA Web App Manifest
│   └── sw.js                # Service Worker (UI shell cache; live-network API)
├── app/                     # Protected application code
│   ├── Core/                # Autoloader, Env, Database, Request, Response, Router
│   ├── Controllers/         # Thin HTTP Controllers
│   ├── Services/            # Pure domain logic and calculations
│   ├── Repositories/        # Database access and PDO prepared queries
│   ├── Middleware/          # Auth, RBAC, CSRF, and Rate Limiting guards
│   ├── Validators/          # Request validation rules
│   ├── Helpers/             # Utility functions
│   └── Models/              # Data entities and DTOs
├── config/                  # Configuration files (app, database, security)
├── database/                # Schema definitions and migrations
│   ├── schema.sql           # Complete MySQL 8+ DDL schema
│   ├── seed.sql             # Baseline roles, leave types, flagship cafe, settings
│   └── migrations/          # Versioned schema updates
├── assets/                  # Frontend assets
│   ├── css/
│   │   └── lorella-theme.css# Lorella design tokens, Burgundy/Cream palette
│   └── js/
│       ├── api-client.js    # Reusable fetch client with CSRF injection
│       └── app.js           # PWA registration and connectivity hooks
├── storage/                 # Protected file storage (Blocked by .htaccess)
│   ├── uploads/attendance/  # Private attendance selfie captures
│   ├── logs/                # Application and error logs
│   └── cache/               # Ephemeral cache
├── scripts/                 # CLI operational scripts
│   └── create_admin.php     # Secure Super Admin provisioning script
├── docs/                    # Architectural and developer documentation
└── tests/                   # Verification and unit tests
```

---

## 3. Multi-Café Tenancy Model

The system is engineered for multi-branch café operations:
1. **Global Entity Scoping**:
   - `SUPER_ADMIN` holds unrestricted visibility across all branches and system settings.
   - `ADMIN` and `MANAGER` are assigned to specific cafés via the `user_cafes` join table.
   - Every attendance log and leave request is strictly tied to a `cafe_id` and verified against the user's active assignment.
2. **Horizontal Privilege Enforcement**:
   - Middleware ensures a manager for *Café Downtown* cannot query or approve attendance for *Café Uptown*.
   - All database queries for managerial and staff roles include strict parameterized `WHERE cafe_id = :cafe_id` clauses derived from server-verified session state.

---

## 4. Hostinger Deployment Strategy

1. **Shared Hosting Root vs. Public Subfolder**:
   - If Hostinger Document Root can be pointed to `/public`, all non-public directories (`/app`, `/config`, `/storage`, `/database`) sit securely above web root.
   - If Hostinger Document Root is fixed to `public_html` (the project root), the root `.htaccess` transparently routes web traffic into `/public/` while returning HTTP 403 Forbidden for any requests targeting `/app`, `/config`, `/database`, `/storage`, or `.env`.
2. **Upload Protection**:
   - Storage directory has a dedicated `.htaccess` (`Require all denied`). Selfie photos are never directly accessible via predictable URLs, preventing unauthorized image harvesting.
