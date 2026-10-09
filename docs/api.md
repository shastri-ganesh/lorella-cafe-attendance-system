# Lorella Speciality Cafe — REST API Specification

## 1. API Architecture & Standards
All API endpoints reside beneath the `/api` prefix and return JSON with standard HTTP status codes.

### 1.1. Response Format

#### Success Format
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": { ... }
}
```

#### Error Format
```json
{
  "success": false,
  "message": "Human-readable explanation of error",
  "errors": [ ... ]
}
```

### 1.2. HTTP Status Code Conventions
- `200 OK`: Request succeeded.
- `201 Created`: Resource successfully created.
- `400 Bad Request`: Missing mandatory fields or malformed JSON payload.
- `401 Unauthorized`: Invalid credentials, session absent, or session expired.
- `403 Forbidden`: Authenticated, but role disallowed access, or 2FA verification pending.
- `404 Not Found`: Target entity or endpoint does not exist.
- `419 Authentication Timeout`: Missing or invalid CSRF token.
- `422 Unprocessable Content`: Validation constraint failure.
- `423 Locked`: Account temporarily locked due to excessive failed login attempts.
- `429 Too Many Requests`: Rate limit exceeded.
- `500 Internal Server Error`: Unhandled application exception.

---

## 2. Endpoint Catalog

### 2.1. Authentication (`/api/auth`)

#### `GET /api/auth/csrf`
- **Description**: Returns fresh CSRF token for Single-Page and PWA clients.
- **Auth**: Public.
- **Response (200 OK)**:
```json
{
  "success": true,
  "message": "CSRF token issued",
  "data": {
    "csrf_token": "9a2f6b8c...32bytes"
  }
}
```

#### `POST /api/auth/login`
- **Description**: Primary credential authentication. Automatically enforces rate limiting (5 attempts/15 min) and account lockout.
- **Auth**: Public.
- **Request Body**:
```json
{
  "login": "employee@lorellacafe.com",
  "password": "SecretPassword123!"
}
```
*(Accepts either `"login"` or `"email"` as identifier)*

- **Response — Standard Staff (200 OK)**:
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "requires_2fa": false,
    "auth_state": "authenticated",
    "user": {
      "id": 10,
      "full_name": "Sarah Staff",
      "email": "staff@lorellacafe.com",
      "role": "staff",
      "status": "active"
    }
  }
}
```

- **Response — Privileged Role Requiring 2FA (200 OK)**:
```json
{
  "success": true,
  "message": "Two-factor authentication verification required.",
  "data": {
    "requires_2fa": true,
    "auth_state": "2fa_pending"
  }
}
```

- **Response — Invalid Credentials (401 Unauthorized)**:
```json
{
  "success": false,
  "message": "Invalid credentials.",
  "errors": []
}
```
*(Generic error preventing user/email enumeration)*

- **Response — Account Locked (423 Locked)**:
```json
{
  "success": false,
  "message": "Account is temporarily locked due to excessive failed attempts. Please try again later.",
  "errors": []
}
```

#### `GET /api/auth/me`
- **Description**: Retrieves sanitized identity profile of currently authenticated user or 2FA pending state.
- **Auth**: Active session (Authenticated or 2FA Pending).

- **Response — Authenticated Staff (200 OK)**:
```json
{
  "success": true,
  "message": "Current authenticated user profile",
  "data": {
    "authenticated": true,
    "auth_state": "authenticated",
    "requires_2fa": false,
    "user": {
      "id": 10,
      "full_name": "Sarah Staff",
      "email": "staff@lorellacafe.com",
      "role": "staff",
      "status": "active"
    },
    "cafes": [
      {
        "id": 1,
        "name": "Lorella Flagship",
        "code": "LOR-MAIN-01",
        "address": "100 Speciality Blvd",
        "timezone": "Asia/Kolkata",
        "is_primary": 1
      }
    ]
  }
}
```

- **Response — Privileged User with 2FA Pending (200 OK)**:
```json
{
  "success": true,
  "message": "Two-factor authentication verification required.",
  "data": {
    "authenticated": false,
    "auth_state": "2fa_pending",
    "requires_2fa": true
  }
}
```

- **Response — Unauthenticated (401 Unauthorized)**:
```json
{
  "success": false,
  "message": "Unauthenticated. Please log in.",
  "errors": []
}
```

#### `POST /api/auth/logout`
- **Description**: Securely clears session variables, revokes session cookie, records audit trail, and destroys session (works for both fully authenticated and 2FA pending sessions).
- **Auth**: Active session.
- **Response (200 OK)**:
```json
{
  "success": true,
  "message": "Logged out successfully",
  "data": []
}
```

#### `POST /api/auth/2fa/verify` *(Phase 2.2 Target)*
- **Description**: Validates RFC 6238 TOTP code or recovery code.
- **Auth**: Session with `auth_state = "2fa_pending"`.
- **Request Body**:
```json
{
  "code": "489123"
}
```

---

### 2.2. Attendance (`/api/attendance`)

#### `POST /api/attendance/check-in`
- **Description**: Records employee check-in with geofence verification and selfie upload.
- **Auth**: Session (`staff`).
- **Content-Type**: `multipart/form-data`
- **Parameters**:
  - `latitude`: `12.971598` (float, mandatory)
  - `longitude`: `77.594562` (float, mandatory)
  - `accuracy`: `12.5` (float meters, mandatory)
  - `photo`: `binary file` (JPEG/PNG/WebP, mandatory)
- **Response (Success)**:
```json
{
  "success": true,
  "message": "Check-in recorded successfully",
  "data": {
    "attendance_id": 842,
    "check_in_at": "2026-10-09 08:58:12",
    "distance_meters": 14.2,
    "is_late": false
  }
}
```

#### `POST /api/attendance/check-out`
- **Description**: Records employee check-out and computes total working hours.
- **Auth**: Session (`staff`).
- **Content-Type**: `multipart/form-data`

#### `GET /api/attendance/status`
- **Description**: Retrieves current shift status (`checked_in` or `checked_out`).
- **Auth**: Session.

#### `GET /api/attendance/history`
- **Description**: Retrieves paginated historical attendance records for user.
- **Auth**: Session.

---

### 2.3. Cafés & Branches (`/api/cafes`)

#### `GET /api/cafes`
- **Description**: Lists active café branches accessible to the user.
- **Auth**: Session.

#### `GET /api/cafes/{id}`
- **Description**: Retrieves café details, boundary coordinates, and radius.
- **Auth**: Session (`super_admin`, `admin`, `manager`).

---

### 2.4. Leave Management (`/api/leave`)

#### `GET /api/leave`
- **Description**: Lists leave requests (own for staff; café-wide for manager).
- **Auth**: Session.

#### `POST /api/leave/request`
- **Description**: Submits new leave application.
- **Auth**: Session (`staff`).
- **Request Body**:
```json
{
  "leave_type_id": 1,
  "start_date": "2026-10-15",
  "end_date": "2026-10-17",
  "reason": "Personal family commitment"
}
```

---

### 2.5. Reports & Analytics (`/api/reports`)

#### `GET /api/reports/daily?date=YYYY-MM-DD&cafe_id=1`
- **Description**: Daily attendance roll call, late arrivals, and absent staff.
- **Auth**: Session (`super_admin`, `admin`, `manager`).

#### `GET /api/reports/monthly?month=YYYY-MM&cafe_id=1`
- **Description**: Aggregated monthly hours and attendance rates.
- **Auth**: Session (`super_admin`, `admin`, `manager`).

---

### 2.6. System Settings (`/api/settings`)

#### `GET /api/settings`
- **Description**: Retrieves system and café configuration values.
- **Auth**: Session (`super_admin`).
