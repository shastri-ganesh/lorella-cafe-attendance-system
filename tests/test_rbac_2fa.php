<?php

declare(strict_types=1);

/**
 * Lorella Speciality Cafe - RBAC & TOTP 2FA Verification Suite
 * Phase 2.2 Automated Security Tests
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register($rootDir . '/app');
ob_start();
@session_start();

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\TotpService;
use App\Services\QrCodeService;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Controllers\AuthController;

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail ? " ({$detail})" : "") . "\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "  Lorella Attendance Platform - RBAC & 2FA Tests        \n";
echo "========================================================\n\n";

// ---------------------------------------------------------------------
// Setup Isolated Test Database (In-Memory SQLite)
// ---------------------------------------------------------------------
$testPdo = new PDO('sqlite::memory:', '', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$testPdo->exec("
    CREATE TABLE roles (
        id INTEGER PRIMARY KEY,
        slug TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        description TEXT
    );

    CREATE TABLE cafes (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        code TEXT UNIQUE NOT NULL,
        address TEXT NOT NULL,
        timezone TEXT DEFAULT 'Asia/Kolkata',
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_id INTEGER NOT NULL,
        full_name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        failed_attempts INTEGER DEFAULT 0,
        locked_until TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE user_cafes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        cafe_id INTEGER NOT NULL,
        is_primary INTEGER DEFAULT 1
    );

    CREATE TABLE two_factor_auth (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER UNIQUE NOT NULL,
        secret TEXT NOT NULL,
        is_enabled INTEGER DEFAULT 0,
        confirmed_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE recovery_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL,
        used_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        cafe_id INTEGER NULL,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NULL,
        old_values TEXT NULL,
        new_values TEXT NULL,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE rate_limits (
        rate_key TEXT PRIMARY KEY,
        hits INTEGER NOT NULL DEFAULT 1,
        window_expires_at TEXT NOT NULL
    );
");

// Seed Roles
$testPdo->exec("
    INSERT INTO roles (id, slug, name) VALUES
    (1, 'super_admin', 'Super Administrator'),
    (2, 'admin', 'Branch Administrator'),
    (3, 'manager', 'Café Manager'),
    (4, 'staff', 'Café Staff');
");

// Seed Cafés: Café 1 (Flagship) and Café 2 (Roastery)
$testPdo->exec("
    INSERT INTO cafes (id, name, code, address, status) VALUES
    (1, 'Lorella Bandra Flagship', 'LOR-BND-01', 'Bandra West, Mumbai', 'active'),
    (2, 'Lorella Juhu Roastery', 'LOR-JUH-02', 'Juhu Tara Road, Mumbai', 'active');
");

$defaultHash = AuthService::hashPassword('SecurePassword123!');

// Seed Users
// User 1: Super Admin (Global)
// User 2: Admin (Assigned only to Café 1)
// User 3: Manager (Assigned only to Café 1)
// User 4: Staff (Assigned only to Café 1)
// User 5: Staff 2 (Assigned to Café 2)
$testPdo->exec("
    INSERT INTO users (id, role_id, full_name, email, password_hash, status) VALUES
    (1, 1, 'Super Admin', 'super@lorella.cafe', '{$defaultHash}', 'active'),
    (2, 2, 'Branch Admin', 'admin@lorella.cafe', '{$defaultHash}', 'active'),
    (3, 3, 'Floor Manager', 'manager@lorella.cafe', '{$defaultHash}', 'active'),
    (4, 4, 'Barista Staff', 'staff1@lorella.cafe', '{$defaultHash}', 'active'),
    (5, 4, 'Roaster Staff', 'staff2@lorella.cafe', '{$defaultHash}', 'active');
");

// Seed Café Assignments
$testPdo->exec("
    INSERT INTO user_cafes (user_id, cafe_id, is_primary) VALUES
    (2, 1, 1),
    (3, 1, 1),
    (4, 1, 1),
    (5, 2, 1);
");

Database::setConnection($testPdo);
$userRepo = new UserRepository($testPdo);

// =====================================================================
// PART 1: RBAC ROLE PERMISSIONS & HIERARCHY
// =====================================================================
echo "1. Role Permissions & Hierarchy Validation:\n";

// Test 1: STAFF cannot access Manager endpoint
$_SESSION = ['user_id' => 4, 'user_role' => 'staff', 'auth_state' => 'authenticated'];
$managerGuard = RoleMiddleware::requireRole(['manager', 'admin', 'super_admin']);
$res = $managerGuard(new Request());
assertTest("STAFF cannot access Manager endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 2: STAFF cannot access Admin endpoint
$adminGuard = RoleMiddleware::requireRole(['admin', 'super_admin']);
$res = $adminGuard(new Request());
assertTest("STAFF cannot access Admin endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 3: STAFF cannot access Super Admin endpoint
$superAdminGuard = RoleMiddleware::requireRole('super_admin');
$res = $superAdminGuard(new Request());
assertTest("STAFF cannot access Super Admin endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 4: MANAGER cannot access Admin-only endpoint
$_SESSION = ['user_id' => 3, 'user_role' => 'manager', 'auth_state' => 'authenticated'];
$adminOnlyGuard = RoleMiddleware::requireRole(['admin', 'super_admin']);
$res = $adminOnlyGuard(new Request());
assertTest("MANAGER cannot access Admin-only endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 5: MANAGER cannot access Super Admin endpoint
$res = $superAdminGuard(new Request());
assertTest("MANAGER cannot access Super Admin endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 6: ADMIN cannot access Super Admin endpoint
$_SESSION = ['user_id' => 2, 'user_role' => 'admin', 'auth_state' => 'authenticated'];
$res = $superAdminGuard(new Request());
assertTest("ADMIN cannot access Super Admin endpoint (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 7: SUPER_ADMIN can access global endpoint
$_SESSION = ['user_id' => 1, 'user_role' => 'super_admin', 'auth_state' => 'authenticated'];
$res = $superAdminGuard(new Request());
assertTest("SUPER_ADMIN can access Super Admin endpoint (allowed)", $res === null);

// =====================================================================
// PART 2: CAFÉ BRANCH MULTI-TENANCY BOUNDARIES
// =====================================================================
echo "\n2. Café Scoping & Multi-Tenancy Boundaries:\n";

// Test 8: Manager can access assigned café (Café 1)
$_SESSION = [
    'user_id' => 3,
    'user_role' => 'manager',
    'user_cafe_ids' => [1],
    'auth_state' => 'authenticated'
];
$cafeGuard = RoleMiddleware::requireCafeAccess('id');
$req = (new Request())->setParams(['id' => 1]);
$res = $cafeGuard($req, ['id' => 1]);
assertTest("Manager can access assigned café 1 (allowed)", $res === null);

// Test 9: Manager cannot access another café (Café 2)
$req = (new Request())->setParams(['id' => 2]);
$res = $cafeGuard($req, ['id' => 2]);
assertTest("Manager cannot access unassigned café 2 (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 10: Admin can access assigned café (Café 1)
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'admin',
    'user_cafe_ids' => [1],
    'auth_state' => 'authenticated'
];
$res = $cafeGuard((new Request())->setParams(['id' => 1]), ['id' => 1]);
assertTest("Admin can access assigned café 1 (allowed)", $res === null);

// Test 11: Admin cannot access another café (Café 2)
$res = $cafeGuard((new Request())->setParams(['id' => 2]), ['id' => 2]);
assertTest("Admin cannot access unassigned café 2 (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 12: Super Admin has global access to all cafés (Café 1 and Café 2)
$_SESSION = [
    'user_id' => 1,
    'user_role' => 'super_admin',
    'user_cafe_ids' => [],
    'auth_state' => 'authenticated'
];
$res1 = $cafeGuard((new Request())->setParams(['id' => 1]), ['id' => 1]);
$res2 = $cafeGuard((new Request())->setParams(['id' => 2]), ['id' => 2]);
assertTest("Super Admin has global access to all cafés (allowed)", $res1 === null && $res2 === null);

// Test 13: Staff cannot access another user's protected resource
$_SESSION = [
    'user_id' => 4,
    'user_role' => 'staff',
    'user_cafe_ids' => [1],
    'auth_state' => 'authenticated'
];
$userGuard = RoleMiddleware::requireSelfOrRole(['super_admin', 'admin', 'manager'], 'id');
$resSelf = $userGuard((new Request())->setParams(['id' => 4]), ['id' => 4]);
$resOther = $userGuard((new Request())->setParams(['id' => 5]), ['id' => 5]);
assertTest("Staff can access own resource (allowed)", $resSelf === null);
assertTest("Staff cannot access another user's resource (HTTP 403)", $resOther instanceof Response && $resOther->getStatusCode() === 403);

// =====================================================================
// PART 3: SPOOFING & ROLE ESCALATION DEFENSE
// =====================================================================
echo "\n3. Spoofing & Role Escalation Defense:\n";

// Test 14: Role spoofing through request body is ignored
$_SESSION = [
    'user_id' => 4,
    'user_role' => 'staff',
    'auth_state' => 'authenticated'
];
$spoofReq = new Request([], ['role' => 'super_admin', 'user_id' => 1]);
$res = $superAdminGuard($spoofReq);
assertTest("Role spoofing through request body is ignored (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 15: cafe_id spoofing rejected for unassigned branch
$spoofCafeReq = (new Request([], ['cafe_id' => 2]))->setParams(['id' => 2]);
$_SESSION = [
    'user_id' => 3,
    'user_role' => 'manager',
    'user_cafe_ids' => [1],
    'auth_state' => 'authenticated'
];
$res = $cafeGuard($spoofCafeReq, ['id' => 2]);
assertTest("cafe_id spoofing rejected against session-authorized branches (HTTP 403)", $res instanceof Response && $res->getStatusCode() === 403);

// Test 16: Role escalation helpers prevent elevation
assertTest("Admin cannot manage Super Admin", !RoleMiddleware::canManageRole('admin', 'super_admin'));
assertTest("Manager cannot manage Admin", !RoleMiddleware::canManageRole('manager', 'admin'));
assertTest("Staff cannot manage Manager", !RoleMiddleware::canManageRole('staff', 'manager'));
assertTest("Manager can manage Staff", RoleMiddleware::canManageRole('manager', 'staff'));
assertTest("Admin can manage Manager and Staff", RoleMiddleware::canManageRole('admin', 'manager') && RoleMiddleware::canManageRole('admin', 'staff'));
assertTest("Super Admin can manage all roles", RoleMiddleware::canManageRole('super_admin', 'admin') && RoleMiddleware::canManageRole('super_admin', 'manager'));
assertTest("Admin cannot assign Super Admin role", !RoleMiddleware::canAssignRole('admin', 'super_admin'));
assertTest("Manager cannot assign Admin or Super Admin", !RoleMiddleware::canAssignRole('manager', 'admin') && !RoleMiddleware::canAssignRole('manager', 'super_admin'));

// =====================================================================
// PART 4: TOTP CORE MATHEMATICS & LOCAL QR CODE
// =====================================================================
echo "\n4. TOTP Core Mathematics & Local QR Code:\n";

// Test 17: Secret generation produces valid RFC 4648 Base32 string
$secret = TotpService::generateSecret(16);
assertTest("Generated secret has valid length (>= 26 chars)", strlen($secret) >= 26);
assertTest("Secret contains only valid Base32 characters", (bool) preg_match('/^[A-Z2-7]+$/', $secret));

// Test 18: RFC 6238 6-digit code generation
$code = TotpService::generateCode($secret);
assertTest("Generated code is exact 6-digit string", strlen($code) === 6 && ctype_digit($code));

// Test 19: Valid code verification
$isValid = TotpService::verifyCode($secret, $code);
assertTest("Generated code verifies successfully", $isValid);

// Test 20: Invalid code rejection
$isInvalid = TotpService::verifyCode($secret, '000000' === $code ? '111111' : '000000');
assertTest("Invalid code is rejected", !$isInvalid);

// Test 21: Expired/future code outside drift window rejected
$futureTimestamp = time() + 300; // 5 minutes in future (10 steps)
$futureCode = TotpService::generateCode($secret, $futureTimestamp);
assertTest("Code outside drift window is rejected", !TotpService::verifyCode($secret, $futureCode, 1, time()));

// Test 22: Clock drift tolerance (+/- 1 step)
$driftTimestamp = time() - 30; // 1 step drift
$driftCode = TotpService::generateCode($secret, $driftTimestamp);
assertTest("Code with 1 step drift verifies with window=1", TotpService::verifyCode($secret, $driftCode, 1, time()));

// Test 23: Pure PHP local SVG QR code generation
$otpauthUri = TotpService::getOtpAuthUri('manager@lorella.cafe', $secret, 'Lorella Cafe');
$svg = TotpService::generateQrCodeSvg($otpauthUri);
assertTest("QR SVG starts with <svg markup", str_starts_with(trim($svg), '<svg'));
assertTest("QR SVG contains closing </svg>", str_contains($svg, '</svg>'));
assertTest("QR SVG contains vector <path> element", str_contains($svg, '<path'));
$xml = simplexml_load_string($svg);
assertTest("QR SVG parses as valid XML document", $xml !== false);

// =====================================================================
// PART 5: TOTP ENROLLMENT LIFECYCLE
// =====================================================================
echo "\n5. TOTP Enrollment Lifecycle:\n";

// Test 24: Unauthenticated enrollment rejected
$_SESSION = [];
$res = AuthService::setupTwoFactor(null, $userRepo);
assertTest("Unauthenticated 2FA setup rejected with HTTP 401", !$res['success'] && $res['status'] === 401);

// Test 25: Staff role enrollment rejected (privileged only)
$_SESSION = ['user_id' => 4, 'user_role' => 'staff', 'auth_state' => 'authenticated'];
$res = AuthService::setupTwoFactor(null, $userRepo);
assertTest("Staff 2FA setup rejected with HTTP 403", !$res['success'] && $res['status'] === 403);

// Test 26: Privileged user enrollment setup succeeds
$_SESSION = [
    'auth_state' => '2fa_pending',
    'pending_user_id' => 3,
    'pending_role' => 'manager',
    '2fa_pending' => true,
];
$setupRes = AuthService::setupTwoFactor(null, $userRepo);
assertTest("Privileged user setup succeeds with HTTP 200", $setupRes['success'] && $setupRes['status'] === 200);
assertTest("Setup returns secret", !empty($setupRes['data']['secret']));
assertTest("Setup returns provisioning_uri", !empty($setupRes['data']['provisioning_uri']));
assertTest("Setup returns local qr_code_svg", !empty($setupRes['data']['qr_code_svg']));

// Test 27: 2FA Status before confirmation reports enabled=false, confirmed=false
$statusRes = AuthService::getTwoFactorStatus($userRepo);
assertTest("Status before confirmation: enabled=false", $statusRes['data']['enabled'] === false);
assertTest("Status before confirmation: confirmed=false", $statusRes['data']['confirmed'] === false);
assertTest("Status before confirmation: required=true for manager", $statusRes['data']['required'] === true);

// Test 28: Enrollment confirmation with invalid code fails
$badConfirm = AuthService::verifyTwoFactor('000000', null, null, $userRepo);
assertTest("Confirmation with invalid code fails with HTTP 400", !$badConfirm['success'] && $badConfirm['status'] === 400);

// Test 29: Enrollment confirmation with valid code succeeds
$generatedSecret = $setupRes['data']['secret'];
$validCode = TotpService::generateCode($generatedSecret);
$confirmRes = AuthService::verifyTwoFactor($validCode, null, null, $userRepo);
assertTest("Confirmation with valid code succeeds", $confirmRes['success'] && $confirmRes['status'] === 200);
assertTest("Confirmation returns 8 recovery codes", count($confirmRes['data']['recovery_codes'] ?? []) === 8);
assertTest("Session upgraded to authenticated upon confirmation", ($_SESSION['auth_state'] ?? '') === 'authenticated');
assertTest("Active user_id granted upon confirmation", ($_SESSION['user_id'] ?? null) === 3);

// Test 30: Status after confirmation reports enabled=true, confirmed=true
$statusAfter = AuthService::getTwoFactorStatus($userRepo);
assertTest("Status after confirmation: enabled=true", $statusAfter['data']['enabled'] === true);
assertTest("Status after confirmation: confirmed=true", $statusAfter['data']['confirmed'] === true);

// =====================================================================
// PART 6: TOTP LOGIN & RECOVERY CODE VERIFICATION
// =====================================================================
echo "\n6. TOTP Login & Recovery Code Verification:\n";

// Test 31: Subsequent login creates 2FA pending state
$loginRes = AuthService::authenticate('manager@lorella.cafe', 'SecurePassword123!', null, $userRepo);
assertTest("Manager login returns requires_2fa=true", $loginRes['data']['requires_2fa'] === true);
assertTest("Manager login reports is_2fa_setup=true", $loginRes['data']['is_2fa_setup'] === true);
assertTest("Session auth_state is 2fa_pending", ($_SESSION['auth_state'] ?? '') === '2fa_pending');

// Test 32: 2FA pending session blocked from all protected business routes
$authMiddleware = new AuthMiddleware();
$blockedAttendance = $authMiddleware->handle(new Request([], [], [], ['REQUEST_URI' => '/api/attendance/status']));
$blockedCafes = $authMiddleware->handle(new Request([], [], [], ['REQUEST_URI' => '/api/cafes/1']));
$blockedUsers = $authMiddleware->handle(new Request([], [], [], ['REQUEST_URI' => '/api/users/1']));
$blockedReports = $authMiddleware->handle(new Request([], [], [], ['REQUEST_URI' => '/api/reports/daily']));
$blockedSettings = $authMiddleware->handle(new Request([], [], [], ['REQUEST_URI' => '/api/settings']));
assertTest("Protected attendance route blocked for 2fa_pending session (HTTP 403)", $blockedAttendance instanceof Response && $blockedAttendance->getStatusCode() === 403);
assertTest("Protected cafés route blocked for 2fa_pending session (HTTP 403)", $blockedCafes instanceof Response && $blockedCafes->getStatusCode() === 403);
assertTest("Protected users route blocked for 2fa_pending session (HTTP 403)", $blockedUsers instanceof Response && $blockedUsers->getStatusCode() === 403);
assertTest("Protected reports route blocked for 2fa_pending session (HTTP 403)", $blockedReports instanceof Response && $blockedReports->getStatusCode() === 403);
assertTest("Protected settings route blocked for 2fa_pending session (HTTP 403)", $blockedSettings instanceof Response && $blockedSettings->getStatusCode() === 403);

// Test 33: Rate limiting blocks brute force on 2FA verification (HTTP 429)
$rateLimiter = \App\Middleware\RateLimitMiddleware::per(3, 60);
$rateReq = new Request([], [], [], ['REMOTE_ADDR' => '192.168.1.100', 'REQUEST_URI' => '/api/auth/2fa/verify']);
$rl1 = $rateLimiter($rateReq);
$rl2 = $rateLimiter($rateReq);
$rl3 = $rateLimiter($rateReq);
$rl4 = $rateLimiter($rateReq);
assertTest("Rate limiting allows attempts 1-3 under threshold", $rl1 === null && $rl2 === null && $rl3 === null);
assertTest("Rate limiting blocks attempt 4 with HTTP 429", $rl4 instanceof Response && $rl4->getStatusCode() === 429);

// Test 34: Login verification with invalid code fails
$invalidVerify = AuthService::verifyTwoFactor('000000', null, null, $userRepo);
assertTest("Login verification with invalid code fails with HTTP 401", !$invalidVerify['success'] && $invalidVerify['status'] === 401);
assertTest("Session remains in 2fa_pending state after failed attempt", ($_SESSION['auth_state'] ?? '') === '2fa_pending');

// Test 34: Login verification with valid recovery code succeeds
$savedRecoveryCodes = $confirmRes['data']['recovery_codes'];
$recoveryCodeToUse = $savedRecoveryCodes[0];

$recoveryLoginRes = AuthService::verifyTwoFactor(null, $recoveryCodeToUse, null, $userRepo);
assertTest("Login verification with valid recovery code succeeds", $recoveryLoginRes['success'] && $recoveryLoginRes['status'] === 200);
assertTest("Session upgraded to authenticated via recovery code", ($_SESSION['auth_state'] ?? '') === 'authenticated');
assertTest("Session user_id is set to 3", ($_SESSION['user_id'] ?? null) === 3);

// Test 35: Recovery code single-use enforcement (replay rejected)
// Put session back into 2fa_pending
$_SESSION = [
    'auth_state' => '2fa_pending',
    'pending_user_id' => 3,
    'pending_role' => 'manager',
    '2fa_pending' => true,
];
$replayRes = AuthService::verifyTwoFactor(null, $recoveryCodeToUse, null, $userRepo);
assertTest("Replay of used recovery code is rejected (HTTP 401)", !$replayRes['success'] && $replayRes['status'] === 401);

// Test 36: Second unused recovery code succeeds
$secondRecoveryCode = $savedRecoveryCodes[1];
$secondLoginRes = AuthService::verifyTwoFactor(null, $secondRecoveryCode, null, $userRepo);
assertTest("Second unused recovery code succeeds", $secondLoginRes['success'] && $secondLoginRes['status'] === 200);

// Test 37: Login verification with valid 6-digit TOTP code
$_SESSION = [
    'auth_state' => '2fa_pending',
    'pending_user_id' => 3,
    'pending_role' => 'manager',
    '2fa_pending' => true,
];
$freshTotpCode = TotpService::generateCode($generatedSecret);
$totpLoginRes = AuthService::verifyTwoFactor($freshTotpCode, null, null, $userRepo);
assertTest("Login verification with valid TOTP code succeeds", $totpLoginRes['success'] && $totpLoginRes['status'] === 200);
assertTest("Session upgraded to authenticated via TOTP code", ($_SESSION['auth_state'] ?? '') === 'authenticated');

// =====================================================================
// PART 7: SENSITIVE SECRETS LEAKAGE PREVENTION
// =====================================================================
echo "\n7. Sensitive Secrets Leakage Prevention:\n";

// Test 38: Verify response does not leak secret or password_hash
assertTest("Verify response does not contain password_hash", !isset($totpLoginRes['data']['user']['password_hash']));
assertTest("Verify response does not contain totp secret", !isset($totpLoginRes['data']['secret']));

// Test 39: Status response does not leak secret
assertTest("Status response does not contain secret", !isset($statusAfter['data']['secret']));
assertTest("Status response does not contain recovery codes", !isset($statusAfter['data']['recovery_codes']));

// Test 40: Recovery code hashes never returned to client
$unusedCodesInDb = $userRepo->getUnusedRecoveryCodes(3);
assertTest("DB stores bcrypt hashes for recovery codes", str_starts_with($unusedCodesInDb[0]['code_hash'], '$2y$'));
assertTest("Recovery login response does not contain code_hash", !isset($secondLoginRes['data']['code_hash']));

echo "\n--------------------------------------------------------\n";
echo "Total Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "--------------------------------------------------------\n";

if ($failed === 0) {
    echo "ALL RBAC & 2FA SECURITY TESTS PASSED SUCCESSFULLY.\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED.\n";
    exit(1);
}
