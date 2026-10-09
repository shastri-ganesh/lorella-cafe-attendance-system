# Lorella Speciality Cafe — Security Architecture & Threat Model

## 1. Security Overview & Defense-in-Depth
Security is baked into the foundation of the Lorella Attendance platform rather than applied retrospectively. Because workforce attendance governs compensation, hours, and access to physical venues, all critical state decisions are strictly **server-authoritative**.

```
+------------------------------------------------------------------------+
|                      Client Request (Browser / PWA)                    |
|                      - Submits GPS lat/lng/accuracy                    |
|                      - Submits live selfie stream (multipart)          |
+------------------------------------------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
|                      Perimeter & Network Security                      |
|                      - HTTPS Only (Secure Cookie Flag)                 |
|                      - SameSite=Lax, HttpOnly Cookies                  |
|                      - Sliding Window Rate Limiting (Brute-Force Guard)|
+------------------------------------------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
|                      State-Changing CSRF Guard                         |
|                      - Timing-safe hash_equals() validation            |
+------------------------------------------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
|                      Identity & RBAC Middleware                        |
|                      - Session ID regeneration                         |
|                      - Mandatory RFC 6238 2FA Check (Privileged Roles) |
|                      - Café Branch Scoping Verification                |
+------------------------------------------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
|                      Server-Authoritative Validation                   |
|  - Server determines user identity from verified session               |
|  - Server determines assigned café & fetches official GPS anchor        |
|  - Server calculates Haversine distance & checks accuracy buffer       |
|  - Server generates official timestamp from NTP/system clock           |
|  - Server validates selfie MIME (finfo) & writes to non-public storage |
+------------------------------------------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
|                         Database & Audit Trail                         |
|                      - Strict PDO prepared statements (zero concat)    |
|                      - Immutable audit_logs journal entry              |
+------------------------------------------------------------------------+
```

---

## 2. Authentication Lifecycle & Session Architecture

### 2.1. Password Hashing & Dynamic Rehashing
- Algorithm: `PASSWORD_ARGON2ID` (with fallback to Bcrypt `cost=12` on systems lacking Argon2id in runtime).
- Parameters: `memory_cost = 65536 KB` (64MB), `time_cost = 4`, `threads = 2`.
- Automatic Rehashing: `AuthService::needsRehash()` evaluates hashes on successful authentication. If legacy parameters or algorithms are detected (e.g. outdated cost factors), the password is automatically rehashed and upgraded in the database without disrupting the user.

### 2.2. Session Hardening & Lifecycle
- `session.use_strict_mode = 1`: Reject uninitialized session IDs supplied by clients.
- `session.use_only_cookies = 1`: Disallow session ID transmission via query strings or URL rewriting.
- `session_regenerate_id(true)`: Invoked immediately upon password authentication, authentication state transitions, or privilege escalations to eliminate session fixation vulnerabilities.
- Cookie flags: `HttpOnly = true` (blocks client-side JavaScript access / XSS theft), `SameSite = Lax` (CSRF defense), `Secure = true` (enforced when HTTPS is active).
- `localStorage` is explicitly forbidden for authentication state or sensitive tokens; identity relies solely on server-authoritative PHP sessions.

### 2.3. Login Protection & Account Lockout
- Managed collaboratively by `RateLimitMiddleware` (IP/path sliding window) and `UserRepository::incrementFailedAttempts()` (per-account counter).
- Generic Error Policy: Authentication failures return generic error messages (`"Invalid credentials."`) with HTTP 401. The system never reveals whether an email exists, whether a password is incorrect, or whether an account is inactive.
- Lockout Threshold: After 5 consecutive failed login attempts, the account is locked for 15 minutes (`locked_until`).
- Lockout Enforcement: While locked, login requests return HTTP 423 (`"Account is temporarily locked due to excessive failed attempts. Please try again later."`).
- Counter Reset: Successful authentication immediately resets `failed_attempts = 0` and clears `locked_until`.
- Audit Trails: Failed attempts, successful logins, and account lockouts are logged with client IP and User-Agent via `AuditService`.

### 2.4. Trusted Server-Side Identity Model
- Clients are never permitted to declare or override their identity, role, café scope, or authentication state through request headers or body parameters (`user_id`, `role`, `cafe_id`, `auth_state`).
- Identity and authorization boundaries are populated strictly on the server from validated database records:
  - `$_SESSION['auth_state']`: `'authenticated'` (Staff / post-2FA Privileged) or `'2fa_pending'`.
  - `$_SESSION['user_id']`: Numeric verified primary key (granted only after complete authentication).
  - `$_SESSION['user_role']`: Server-resolved role slug.
  - `$_SESSION['user_cafe_ids']`: Authorized café branch primary keys.

---

## 3. Role-Based Access Control (RBAC) & Multi-Tenancy

### 3.1. The Four System Roles
The system strictly defines four roles with granular server-enforced boundaries:

| Role | Slug | Hierarchy Weight | Scope | Key Capabilities & Boundaries |
|---|---|:---:|---|---|
| **Super Admin** | `super_admin` | 4 | Global | Full platform access; manages all cafés, admins, managers, staff; global reports, audit logs, and system settings. |
| **Admin** | `admin` | 3 | Assigned Café(s) | Manages managers and staff within authorized café scope; views attendance, manages leave, and views reports for assigned branches. Cannot manage Super Admins or modify global settings. |
| **Manager** | `manager` | 2 | Assigned Café(s) | Manages floor staff within assigned café; views staff attendance, manages leave requests, and views café-level reports. Cannot manage Admins or access other cafés. |
| **Staff** | `staff` | 1 | Assigned Café Context | Accesses own profile, checks in/out (future), views own attendance history, submits own leave requests. Strictly prohibited from accessing other employees' records or administrative endpoints. |

### 3.2. Centralized RBAC Enforcement (`RoleMiddleware`)
All route authorization is enforced through centralized middleware:
- **`RoleMiddleware::requireRole(array|string $roles)`**: Validates that `$_SESSION['user_role']` matches allowed roles. Unauthorized requests trigger an immutable audit event (`UNAUTHORIZED_ROLE_ACCESS_ATTEMPT`) and terminate with HTTP 403.
- **`RoleMiddleware::requireCafeAccess(string $paramName = 'id')`**: Verifies whether the authenticated user has access to a specific branch. Super Admins possess global bypass; Admins, Managers, and Staff are strictly restricted to branches listed in `$_SESSION['user_cafe_ids']`. Unauthorized attempts log `UNAUTHORIZED_CAFE_ACCESS_ATTEMPT` and terminate with HTTP 403.
- **`RoleMiddleware::requireSelfOrRole(array $privilegedRoles, string $paramName = 'id')`**: Enforces that staff can access only their own user ID, while authorized privileged roles can manage records.
- **Role Escalation Protection (`canManageRole`, `canAssignRole`)**:
  - A user cannot manage or edit accounts of equal or higher hierarchy level.
  - A user cannot assign or promote anyone (including themselves) to a role higher than or equal to their own level.

---

## 4. Two-Factor Authentication (RFC 6238 TOTP)

### 4.1. Mandatory 2FA Policy
- **Staff**: Single-stage password authentication at this phase.
- **Privileged Roles (`manager`, `admin`, `super_admin`)**: Mandatory RFC 6238 TOTP two-factor authentication. A privileged session cannot perform any operational action until 2FA is successfully verified.

### 4.2. TOTP Cryptographic Specifications
- **Algorithm**: RFC 6238 (TOTP) / RFC 4226 (HOTP) using HMAC-SHA1.
- **Time Step**: 30 seconds.
- **Token Format**: 6 numeric decimal digits.
- **Secret Entropy**: 128-bit (16 cryptographically secure random bytes encoded as RFC 4648 Base32).
- **Drift Window Tolerance**: Configurable via `config/security.php` (default `+/- 1` time window = 30s forward/backward tolerance) to accommodate device clock drift.
- **Authenticator App Compatibility**: Google Authenticator, Microsoft Authenticator, Authy, Apple Passwords, 1Password, Bitwarden.

### 4.3. Dependency-Free Local QR Provisioning (`QrCodeService`)
- Provisioning URIs follow standard format:
  `otpauth://totp/Lorella%20Speciality%20Cafe:<ACCOUNT>?secret=<SECRET>&issuer=Lorella%20Speciality%20Cafe`
- **Zero Third-Party Dependency Guarantee**: TOTP secrets and provisioning URIs are **never sent to external QR generation services** (such as Google Charts or public APIs).
- QR codes are synthesized locally by `App\Services\QrCodeService`, a pure-PHP vector SVG engine generating clean, high-contrast, scalable `<svg>` markup and Base64 Data URIs.

### 4.4. 2FA Lifecycle & Verification Workflows

```
  +-------------------------------------------------------------------------+
  |                           Password Authentication                       |
  |                           (POST /api/auth/login)                        |
  +-------------------------------------------------------------------------+
                                       |
                   +-------------------+-------------------+
                   | (Staff)                               | (Privileged Role)
                   v                                       v
         +--------------------+                  +--------------------+
         |   Authenticated    |                  |    2FA Pending     |
         | (Full user access) |                  | (Restricted state) |
         +--------------------+                  +--------------------+
                                                           |
                                      +--------------------+--------------------+
                                      | (Not yet enrolled)                      | (Enrolled)
                                      v                                         v
                            +--------------------+                    +--------------------+
                            | POST /2fa/setup    |                    | POST /2fa/verify   |
                            | (Returns secret +  |                    | (TOTP 6-digit or   |
                            |  local SVG QR code)|                    |  Recovery Code)    |
                            +--------------------+                    +--------------------+
                                      |                                         |
                                      v                                         v
                            +--------------------+                    +--------------------+
                            | POST /2fa/verify   |                    | Authenticated      |
                            | (Confirms code &   |                    | - Session regen    |
                            |  issues 8 backup   |                    | - Full privileges  |
                            |  recovery codes)   |                    +--------------------+
                            +--------------------+
```

1. **Enrollment (`POST /api/auth/2fa/setup`)**:
   - Generates Base32 secret, unconfirmed DB record (`is_enabled = 0`), and local SVG QR code.
   - Never exposes secret in system logs or unrelated endpoints.
2. **Enrollment Confirmation (`POST /api/auth/2fa/verify`)**:
   - Verifies user's submitted 6-digit code against unconfirmed secret.
   - Marks record active: `is_enabled = 1, confirmed_at = CURRENT_TIMESTAMP`.
   - Generates 8 single-use recovery codes, stores bcrypt hashes in `recovery_codes`, and displays plain codes to user exactly once.
3. **Login Verification (`POST /api/auth/2fa/verify`)**:
   - Validates submitted 6-digit code or single-use recovery code.
   - If valid: transitions session `auth_state = 'authenticated'`, assigns `user_id` and authorized café IDs, regenerates session ID (`session_regenerate_id(true)`), and audits success.
4. **Backup Recovery Codes**:
   - 8 high-entropy 10-character codes (`XXXX-XXXX`).
   - Stored in database exclusively as bcrypt hashes (`password_hash`).
   - Single-use guarantee: marked `used_at = CURRENT_TIMESTAMP` immediately upon verification; replay attempts return HTTP 401.
5. **Brute-Force & Rate Limiting Defense**:
   - Verification attempts on `/api/auth/2fa/verify` are sliding-window rate limited (10 requests per 5-minute window per IP) returning HTTP 429 upon excess.
   - Timing attacks mitigated via constant-time comparisons (`hash_equals`).
6. **Secret Omission Guarantee**:
   - `password_hash`, `secret`, and recovery code hashes are strictly scrubbed from all API responses and audit logs.

---

## 4. Attendance Security & Server Authority

| Parameter | Client Role | Server Authority Policy |
|---|---|---|
| **Timestamp** | Sends local device time | **Ignored**. Server records `NOW()` in database. |
| **User ID** | None | **Derived exclusively** from verified session ID. |
| **Café ID** | None | **Resolved exclusively** from employee's assigned café. |
| **Geofence Check** | Submits GPS coordinates | **Calculated server-side** using spherical Haversine formula against café coordinates. |
| **GPS Accuracy** | Submits `accuracy` radius | **Validated**. If accuracy > 150m, check-in is rejected due to indoor drift / weak fix. |
| **Status** | None | **Calculated server-side** (cannot check in twice or check out before check in). |

---

## 5. File Upload & Selfie Isolation

1. **Storage Isolation**:
   - Attendance photos are uploaded directly to `/storage/uploads/attendance/`.
   - Direct web server requests to `/storage/` are strictly blocked by Apache (`Require all denied`).
   - Photos can only be accessed through an authenticated proxy endpoint with role verification.
2. **File Validation**:
   - The file's MIME type is inspected using PHP's `finfo_file` (never trusting client-supplied `$_FILES['photo']['type']`).
   - Extensions are constrained to `jpg`, `jpeg`, `png`, and `webp`.
   - File size is capped at 5MB.
3. **Randomized File Naming**:
   - Files are stored using cryptographic hashes: `att_{userId}_{YYYYMMDD}_{type}_{random16bytes}.{ext}`.
   - Original client filenames are discarded entirely to prevent path traversal (`../`) and shell script execution.

---

## 6. CSRF Mitigation
- Single-use or session-bound cryptographic tokens generated via `bin2hex(random_bytes(32))`.
- All mutation requests (`POST`, `PUT`, `DELETE`) verified via `CsrfMiddleware` using `hash_equals()`.
- API endpoints support delivery via `X-CSRF-TOKEN` HTTP header or `_csrf_token` payload.
