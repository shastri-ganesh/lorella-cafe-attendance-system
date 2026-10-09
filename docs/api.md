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
- `400 Bad Request`: Malformed payload or validation error.
- `401 Unauthorized`: Session absent or expired.
- `403 Forbidden`: Authenticated, but role or café boundary disallowed access.
- `404 Not Found`: Target entity or endpoint does not exist.
- `419 Authentication Timeout`: Missing or invalid CSRF token.
- `422 Unprocessable Content`: Missing mandatory fields.
- `429 Too Many Requests`: Rate limit exceeded.
- `500 Internal Server Error`: Unhandled application exception.

---

## 2. Endpoint Catalog

### 2.1. Authentication (`/api/auth`)

#### `GET /api/auth/csrf`
- **Description**: Returns fresh CSRF token for Single-Page and PWA clients.
- **Auth**: Public.
- **Response**:
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
- **Description**: Primary credential authentication.
- **Auth**: Public (Rate-limited to 5 attempts / 15 minutes).
- **Request Body**:
```json
{
  "email": "employee@lorellacafe.com",
  "password": "Password123!"
}
```
- **Response (Standard Staff)**:
```json
{
  "success": true,
  "message": "Authentication successful",
  "data": {
    "user": { "id": 12, "name": "Alex Smith", "role": "staff" },
    "requires_2fa": false
  }
}
```
- **Response (Privileged Role requiring 2FA)**:
```json
{
  "success": true,
  "message": "Two-factor verification required",
  "data": {
    "requires_2fa": true
  }
}
```

#### `POST /api/auth/2fa/verify`
- **Description**: Validates RFC 6238 TOTP code or recovery code.
- **Auth**: Session with `2fa_pending=true`.
- **Request Body**:
```json
{
  "code": "489123"
}
```

#### `POST /api/auth/logout`
- **Description**: Terminates session, clears session cookies.
- **Auth**: Session.

#### `GET /api/auth/me`
- **Description**: Returns current authenticated user profile and café assignments.
- **Auth**: Session.

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
