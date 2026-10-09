# Lorella Speciality Cafe — Testing & Quality Assurance Strategy

## 1. Testing Philosophy
The Lorella Attendance Platform prioritizes deterministic unit testing for core algorithms (geofencing calculations, RFC 6238 TOTP math, environment parsing) alongside systematic end-to-end security verification. Because the codebase maintains a zero-dependency architecture for shared hosting portability, tests are executed using lightweight native PHP test harnesses without requiring external test runners.

---

## 2. Automated Test Suite (`tests/test_foundation.php`)
The foundation suite validates mathematical invariants, environment parsing, and security helpers.

### Running the Test Suite:
```bash
php tests/test_foundation.php
```

### Components Tested:
1. **`App\Services\GeofenceService`**:
   - Haversine calculation against known physical coordinates (e.g., verifying that 111.19 km per degree of latitude matches spherical trigonometry within ±0.05%).
   - Accuracy buffer handling: Ensures low-accuracy GPS (> 150m) is rejected.
   - Border tolerance: Validates points 5 meters inside vs 50 meters outside café boundaries.
2. **`App\Services\TotpService`**:
   - RFC 4226 / RFC 6238 token calculation.
   - Base32 encoding and decoding symmetry.
   - Time window drift verification (validating code generation at `t-30`, `t`, `t+30`).
   - Recovery code hashing and verification.
3. **`App\Core\Env`**:
   - Parser verification for strings, booleans (`true`/`false`), integer casting, and fallback handling.

---

## 3. Security Verification Checklist

| Security Area | Test Procedure | Expected Result |
|---|---|---|
| **SQL Injection** | Pass SQL metacharacters (`' OR 1=1 --`) into input queries. | PDO prepared statement blocks payload; treated as literal string. |
| **Path Traversal** | Attempt file retrieval with `../../config/database.php`. | Blocked by sanitized paths and Apache `.htaccess` deny rules. |
| **Predictable Uploads** | Inspect generated attendance image names. | Cryptographically random 32-hex character names; non-guessable. |
| **CSRF Defense** | Send `POST /api/leave/request` without `X-CSRF-TOKEN` or mismatching token. | Returns HTTP 419 Authentication Timeout. |
| **Privilege Escalation** | Attempt to hit `/api/reports/daily` as a `staff` user. | Returns HTTP 403 Forbidden. |
| **Multi-Café Boundary** | Manager of Café 1 queries attendance for Café 2. | Filtered or returns HTTP 403 Forbidden. |
| **2FA Bypass** | Attempt to query `/api/auth/me` with `2fa_pending=true`. | Blocked with HTTP 403 until TOTP token is verified. |

---

## 4. Mobile & Browser Compatibility Matrix
Attendance will be accessed by staff on personal smartphones. Verification focuses on:
- **iOS Safari (iOS 16+)**:
  - Permissions prompt for Camera (`getUserMedia`) and GPS Geolocation.
  - Safe area inset handling for bottom navigation (`env(safe-area-inset-bottom)`).
  - PWA "Add to Home Screen" behavior and standalone display mode.
- **Android Chrome (v110+)**:
  - Web App Manifest installation prompt (`beforeinstallprompt`).
  - High-accuracy GPS sensor resolution (`enableHighAccuracy: true`).
  - Live video stream rendering without letterboxing.
