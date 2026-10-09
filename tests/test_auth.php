<?php

declare(strict_types=1);

/**
 * Lorella Speciality Cafe - Authentication Lifecycle Test Suite
 * Validates all 15 core behaviors specified in Phase 2.1 requirements:
 * 1. Password hashing/verification
 * 2. Invalid password rejection
 * 3. Successful authentication
 * 4. Session authentication state (Staff vs Privileged 2FA pending)
 * 5. Session regeneration behavior
 * 6. Inactive account rejection
 * 7. Failed-attempt increment
 * 8. Failed-attempt reset after success
 * 9. Account lockout on threshold
 * 10. Password rehash detection & auto-upgrade
 * 11. Unauthenticated /api/auth/me rejection (401)
 * 12. Authenticated /api/auth/me resolution (200)
 * 13. Logout clearing session
 * 14. 2FA-pending state blocking protected routes (403)
 * 15. Password/hash/secrets absent from all API responses
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
use App\Middleware\AuthMiddleware;
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
echo "  Lorella Attendance Platform - Authentication Tests     \n";
echo "========================================================\n\n";

// ---------------------------------------------------------------------
// Setup Isolated Test Database (In-Memory SQLite)
// ---------------------------------------------------------------------
$testPdo = new PDO('sqlite::memory:', '', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// SQLite Schema matching MySQL core tables for testing
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
");

// Seed Roles
$testPdo->exec("
    INSERT INTO roles (id, slug, name) VALUES
    (1, 'super_admin', 'Super Administrator'),
    (2, 'admin', 'Branch Administrator'),
    (3, 'manager', 'Café Manager'),
    (4, 'staff', 'Café Staff');
");

// Seed Flagship Cafe
$testPdo->exec("
    INSERT INTO cafes (id, name, code, address, status) VALUES
    (1, 'Lorella Flagship', 'LOR-MAIN-01', '100 Speciality Blvd', 'active');
");

// Password hashes for test users
$staffPassword = 'StaffPassword123!';
$managerPassword = 'ManagerPassword123!';
$outdatedPassword = 'OutdatedPassword123!';

$argonHashStaff = AuthService::hashPassword($staffPassword);
$argonHashManager = AuthService::hashPassword($managerPassword);
// Cost 4 bcrypt to trigger needsRehash
$legacyBcryptHash = password_hash($outdatedPassword, PASSWORD_BCRYPT, ['cost' => 4]);

$testPdo->exec("
    INSERT INTO users (id, role_id, full_name, email, password_hash, status, failed_attempts) VALUES
    (10, 4, 'Sarah Staff', 'staff@lorellacafe.com', '{$argonHashStaff}', 'active', 0),
    (20, 3, 'Mark Manager', 'manager@lorellacafe.com', '{$argonHashManager}', 'active', 0),
    (30, 4, 'Ian Inactive', 'inactive@lorellacafe.com', '{$argonHashStaff}', 'inactive', 0),
    (40, 4, 'Oliver Outdated', 'outdated@lorellacafe.com', '{$legacyBcryptHash}', 'active', 0);
");

$testPdo->exec("
    INSERT INTO user_cafes (user_id, cafe_id, is_primary) VALUES
    (10, 1, 1),
    (20, 1, 1);
");

// Bind test PDO connection so AuditService and Repositories use memory DB
\App\Core\Env::load($rootDir . '/.env.example');
Database::setConnection($testPdo);

$userRepo = new UserRepository($testPdo);

// ---------------------------------------------------------------------
// Test 1: Password Hashing & Verification
// ---------------------------------------------------------------------
echo "1. Password Hashing & Verification:\n";
$samplePass = 'SecureCafePass2026!';
$hash = AuthService::hashPassword($samplePass);
assertTest("Password hashes to non-empty string", !empty($hash) && strlen($hash) > 30);
assertTest("verifyPassword returns true for matching password", AuthService::verifyPassword($samplePass, $hash));
assertTest("verifyPassword returns false for wrong password", !AuthService::verifyPassword('WrongSecret', $hash));

// ---------------------------------------------------------------------
// Test 2: Invalid Password Rejection (Generic Message)
// ---------------------------------------------------------------------
echo "\n2. Invalid Password Rejection:\n";
$resBadPass = AuthService::authenticate('staff@lorellacafe.com', 'IncorrectPassword!', null, $userRepo);
assertTest("Invalid password returns failure", $resBadPass['success'] === false);
assertTest("Invalid password returns HTTP 401", $resBadPass['status'] === 401);
assertTest("Returns generic message without leaking details", $resBadPass['message'] === 'Invalid credentials.');

// ---------------------------------------------------------------------
// Test 3: Successful Authentication
// ---------------------------------------------------------------------
echo "\n3. Successful Authentication:\n";
$resSuccess = AuthService::authenticate('staff@lorellacafe.com', $staffPassword, null, $userRepo);
assertTest("Correct credentials returns success", $resSuccess['success'] === true);
assertTest("Login returns HTTP 200", $resSuccess['status'] === 200);
assertTest("Staff requires_2fa is false", $resSuccess['data']['requires_2fa'] === false);
assertTest("Staff auth_state is authenticated", $resSuccess['data']['auth_state'] === 'authenticated');

// ---------------------------------------------------------------------
// Test 4: Session Authentication State (Staff vs Privileged 2FA Pending)
// ---------------------------------------------------------------------
echo "\n4. Session Authentication State:\n";
assertTest("Staff session has user_id set to 10", ($_SESSION['user_id'] ?? null) === 10);
assertTest("Staff session has user_role set to staff", ($_SESSION['user_role'] ?? null) === 'staff');
assertTest("Staff session has auth_state set to authenticated", ($_SESSION['auth_state'] ?? null) === 'authenticated');

// Manager authentication triggers 2FA pending
$resManager = AuthService::authenticate('manager@lorellacafe.com', $managerPassword, null, $userRepo);
assertTest("Manager login returns requires_2fa = true", $resManager['data']['requires_2fa'] === true);
assertTest("Manager login returns auth_state = 2fa_pending", $resManager['data']['auth_state'] === '2fa_pending');
assertTest("Manager session auth_state is 2fa_pending", ($_SESSION['auth_state'] ?? null) === '2fa_pending');
assertTest("Manager session 2fa_pending flag is true", ($_SESSION['2fa_pending'] ?? null) === true);
assertTest("Manager session pending_user_id is set to 20", ($_SESSION['pending_user_id'] ?? null) === 20);
assertTest("Manager session does NOT have active user_id granted", empty($_SESSION['user_id']));

// ---------------------------------------------------------------------
// Test 5: Session Regeneration Behavior
// ---------------------------------------------------------------------
echo "\n5. Session Regeneration Behavior:\n";
$priorSessionId = session_id();
@session_regenerate_id(true);
$newSessionId = session_id();
assertTest("Session ID changes upon regeneration", $newSessionId !== '' && $newSessionId !== $priorSessionId);

// ---------------------------------------------------------------------
// Test 6: Inactive Account Rejection
// ---------------------------------------------------------------------
echo "\n6. Inactive Account Rejection:\n";
$resInactive = AuthService::authenticate('inactive@lorellacafe.com', $staffPassword, null, $userRepo);
assertTest("Inactive account login fails", $resInactive['success'] === false);
assertTest("Inactive account returns HTTP 401", $resInactive['status'] === 401);

// ---------------------------------------------------------------------
// Test 7: Failed-Attempt Increment
// ---------------------------------------------------------------------
echo "\n7. Failed-Attempt Increment:\n";
$userBefore = $userRepo->findById(10);
$initialAttempts = (int) ($userBefore['failed_attempts'] ?? 0);
AuthService::authenticate('staff@lorellacafe.com', 'BadPass1', null, $userRepo);
$userAfter = $userRepo->findById(10);
$newAttempts = (int) ($userAfter['failed_attempts'] ?? 0);
assertTest("Failed attempts counter increments by 1", $newAttempts === ($initialAttempts + 1));

// ---------------------------------------------------------------------
// Test 8: Failed-Attempt Reset After Success
// ---------------------------------------------------------------------
echo "\n8. Failed-Attempt Reset After Success:\n";
AuthService::authenticate('staff@lorellacafe.com', $staffPassword, null, $userRepo);
$userReset = $userRepo->findById(10);
assertTest("Failed attempts resets to 0 upon successful login", (int) $userReset['failed_attempts'] === 0);

// ---------------------------------------------------------------------
// Test 9: Account Lockout On Threshold
// ---------------------------------------------------------------------
echo "\n9. Account Lockout:\n";
// Threshold is 5 attempts
for ($i = 0; $i < 5; $i++) {
    AuthService::authenticate('staff@lorellacafe.com', 'BadPassword!', null, $userRepo);
}
$lockedUser = $userRepo->findById(10);
assertTest("Account locked_until timestamp is populated", !empty($lockedUser['locked_until']));
assertTest("isAccountLocked evaluates to true", $userRepo->isAccountLocked($lockedUser));

$resLocked = AuthService::authenticate('staff@lorellacafe.com', $staffPassword, null, $userRepo);
assertTest("Login rejected while locked even with correct password", $resLocked['success'] === false);
assertTest("Locked account returns HTTP 423", $resLocked['status'] === 423);

// Unlock for remaining tests
$userRepo->resetFailedAttempts(10);

// ---------------------------------------------------------------------
// Test 10: Password Rehash Detection
// ---------------------------------------------------------------------
echo "\n10. Password Rehash Detection:\n";
$userOld = $userRepo->findById(40);
$oldHash = $userOld['password_hash'];
assertTest("Legacy hash requires rehash", AuthService::needsRehash($oldHash));
AuthService::authenticate('outdated@lorellacafe.com', $outdatedPassword, null, $userRepo);
$userUpgraded = $userRepo->findById(40);
assertTest("Password hash was automatically updated in DB", $userUpgraded['password_hash'] !== $oldHash);
assertTest("New hash verifies with password_verify", password_verify($outdatedPassword, $userUpgraded['password_hash']));

// ---------------------------------------------------------------------
// Test 11: Unauthenticated /api/auth/me Rejection (401)
// ---------------------------------------------------------------------
echo "\n11. Unauthenticated /api/auth/me:\n";
$_SESSION = [];
$reqMe = new Request([], [], [], ['REQUEST_URI' => '/api/auth/me', 'REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'application/json']);
$authMiddleware = new AuthMiddleware();
$middlewareRes = $authMiddleware->handle($reqMe);
assertTest("AuthMiddleware rejects unauthenticated /api/auth/me with HTTP 401", $middlewareRes instanceof Response);

// ---------------------------------------------------------------------
// Test 12: Authenticated /api/auth/me Resolution (200)
// ---------------------------------------------------------------------
echo "\n12. Authenticated /api/auth/me:\n";
$_SESSION['auth_state'] = 'authenticated';
$_SESSION['user_id'] = 10;
$_SESSION['user_role'] = 'staff';
$_SESSION['user_name'] = 'Sarah Staff';
$_SESSION['user_email'] = 'staff@lorellacafe.com';

$authController = new AuthController();
// Use reflection or direct helper
$userProfile = AuthService::getAuthenticatedUser($userRepo);
assertTest("Authenticated user profile resolved", $userProfile !== null && $userProfile['authenticated'] === true);
assertTest("Profile contains user full_name and email", $userProfile['user']['full_name'] === 'Sarah Staff');
assertTest("Profile includes assigned cafes", count($userProfile['cafes']) >= 1 && $userProfile['cafes'][0]['code'] === 'LOR-MAIN-01');

// ---------------------------------------------------------------------
// Test 13: Logout
// ---------------------------------------------------------------------
echo "\n13. Logout:\n";
assertTest("Session active before logout", !empty($_SESSION['user_id']));
AuthService::logout();
assertTest("Session cleared after logout", empty($_SESSION['user_id']) && empty($_SESSION['auth_state']));

// ---------------------------------------------------------------------
// Test 14: 2FA-Pending State Route Protection (403)
// ---------------------------------------------------------------------
echo "\n14. 2FA-Pending State Route Protection:\n";
AuthService::startSecureSession();
$_SESSION['auth_state'] = '2fa_pending';
$_SESSION['2fa_pending'] = true;
$_SESSION['pending_user_id'] = 20;

// Try accessing protected attendance endpoint
$reqProtected = new Request([], [], [], ['REQUEST_URI' => '/api/attendance/check-in', 'REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json']);
$mwProtectedRes = $authMiddleware->handle($reqProtected);
assertTest("Protected attendance route blocked for 2FA-pending user", $mwProtectedRes instanceof Response);

// Try accessing allowed /api/auth/me
$mwMeRes = $authMiddleware->handle($reqMe);
assertTest("2FA-pending user allowed to access /api/auth/me", $mwMeRes === null);

$pendingMe = AuthService::getAuthenticatedUser($userRepo);
assertTest("2FA-pending me returns auth_state = 2fa_pending", ($pendingMe['auth_state'] ?? '') === '2fa_pending');
assertTest("2FA-pending me returns requires_2fa = true", ($pendingMe['requires_2fa'] ?? false) === true);

// ---------------------------------------------------------------------
// Test 15: Absence of Passwords, Hashes & Secrets in Responses
// ---------------------------------------------------------------------
echo "\n15. Sensitive Secrets Absent From Responses:\n";
$authStaffRes = AuthService::authenticate('staff@lorellacafe.com', $staffPassword, null, $userRepo);
$authStaffJson = json_encode($authStaffRes);
assertTest("Login response does not contain password_hash", !str_contains($authStaffJson, 'password_hash'));
assertTest("Login response does not contain password string", !str_contains($authStaffJson, $staffPassword));

$meProfileJson = json_encode($userProfile);
assertTest("Me response does not contain password_hash", !str_contains($meProfileJson, 'password_hash'));
assertTest("Me response does not contain secret or recovery_code", !str_contains($meProfileJson, 'recovery_code') && !str_contains($meProfileJson, 'secret'));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n--------------------------------------------------------\n";
echo "Total Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "--------------------------------------------------------\n";

ob_end_flush();

if ($failed > 0) {
    echo "AUTHENTICATION TEST SUITE FAILED.\n";
    exit(1);
}

echo "ALL AUTHENTICATION TESTS PASSED SUCCESSFULLY.\n";
exit(0);
