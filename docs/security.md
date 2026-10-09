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

## 2. Authentication Architecture

### 2.1. Password Hashing
- Algorithm: `PASSWORD_ARGON2ID` (with fallback to Bcrypt `cost=12` on systems lacking Argon2id).
- Parameters: `memory_cost = 65536 KB`, `time_cost = 4`, `threads = 2`.
- Automatic Rehashing: `AuthService::needsRehash()` evaluates hashes on successful login and automatically upgrades them if security parameters have escalated.

### 2.2. Session Hardening
- `session.use_strict_mode = 1`: Reject uninitialized session IDs provided by clients.
- `session.use_only_cookies = 1`: Disallow session ID passing via URL parameters.
- `session_regenerate_id(true)`: Invoked immediately upon login, privilege escalation, or 2FA verification to defeat session fixation attacks.
- Cookie flags: `HttpOnly = true` (mitigates XSS cookie theft), `SameSite = Lax` (mitigates CSRF), `Secure = true` (enforced when HTTPS is active).

### 2.3. Brute-Force & Account Lockout
- Managed via `RateLimitMiddleware` and `users.failed_attempts` tracking.
- After 5 consecutive invalid credentials within a 15-minute window, the account is locked for 15 minutes (`locked_until`).
- Lockouts and failed login bursts are audited with IP address and User-Agent.

---

## 3. Two-Factor Authentication (RFC 6238 TOTP)

### 3.1. Role Policy
- Mandatory for: `super_admin`, `admin`, `manager`.
- Optional/Exempt for: `staff`.

### 3.2. Two-Stage Authentication Workflow
1. User provides valid primary credentials (email + password).
2. If the user's role requires 2FA or 2FA is enabled:
   - Full session is NOT granted.
   - An ephemeral session state `$_SESSION['2fa_pending'] = true` is established.
   - `AuthMiddleware` rejects any access to privileged API routes until 2FA is satisfied.
3. User enters 6-digit TOTP code (or single-use backup recovery code).
4. `TotpService::verifyCode()` performs time-step calculation with `±1` window tolerance (compensating for 30 seconds of client clock drift).
5. Upon verification, `$_SESSION['2fa_pending']` is cleared and `session_regenerate_id(true)` is invoked.

### 3.3. Backup Recovery Codes
- 8 high-entropy, single-use codes (`XXXX-XXXX`) generated during enrollment.
- Stored exclusively as password hashes (`password_hash()`) in `recovery_codes`.
- Once verified, the record is marked with `used_at = NOW()` and cannot be replayed.

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
