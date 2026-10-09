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
  - `$_SESSION['auth_state']`: `'authenticated'` (Staff) or `'2fa_pending'` (Privileged).
  - `$_SESSION['user_id']`: Numeric verified primary key.
  - `$_SESSION['user_role']`: Server-resolved role slug.
  - `$_SESSION['user_cafe_ids']`: Authorized café branch primary keys.

---

## 3. Two-Stage Authentication & 2FA Pending State

### 3.1. Role Policy
- **Staff**: Non-privileged role. Upon password verification, granted immediate authenticated session (`auth_state = 'authenticated'`).
- **Privileged Roles (Manager, Admin, Super Admin)**: Mandatory RFC 6238 TOTP 2FA.

### 3.2. Two-Stage Authentication Architecture
1. **Stage 1 (Password Verification)**: User submits email/login and password.
2. **State Transition**:
   - For privileged roles, full access is NOT granted.
   - Session is initialized with:
     ```php
     $_SESSION['auth_state'] = '2fa_pending';
     $_SESSION['2fa_pending'] = true;
     $_SESSION['pending_user_id'] = $userId;
     $_SESSION['pending_role'] = $roleSlug;
     ```
   - Privileged user ID (`$_SESSION['user_id']`) remains unset.
3. **Middleware Enforcement (`AuthMiddleware`)**:
   - `UNAUTHENTICATED`: Requests to protected routes rejected with HTTP 401.
   - `2FA_PENDING`:
     - Access to `/api/auth/me`, `/api/auth/logout`, and `/api/auth/2fa/verify` is permitted.
     - Access to all other routes (attendance, users, cafes, leave, reports, settings) is blocked with HTTP 403 (`"Two-factor authentication verification required."`).
4. **Stage 2 (TOTP Verification — Phase 2.2)**: User enters 6-digit TOTP code; upon successful verification, session state transitions to `auth_state = 'authenticated'` and `session_regenerate_id(true)` is executed.

### 3.3. Backup Recovery Codes
- 8 high-entropy, single-use codes (`XXXX-XXXX`) generated during enrollment.
- Stored exclusively as password hashes (`password_hash()`) in `recovery_codes`.
- Once verified, records are marked with `used_at = CURRENT_TIMESTAMP` and cannot be replayed.

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
