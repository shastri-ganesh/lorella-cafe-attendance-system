<?php

declare(strict_types=1);

/**
 * Lorella Speciality Cafe - Foundation Test Runner
 * Validates PSR-4 Autoloader, Env parser, Geofence calculations, and RFC 6238 TOTP math.
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register($rootDir . '/app');

use App\Core\Env;
use App\Services\GeofenceService;
use App\Services\TotpService;
use App\Services\AttendanceService;

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "  Lorella Attendance Platform - Foundation Verification \n";
echo "========================================================\n\n";

// ---------------------------------------------------------------------
// Test Suite 1: Autoloader & Core Architecture
// ---------------------------------------------------------------------
echo "1. Testing Architecture & Autoloader:\n";
assertTest("Autoloader loads App\\Core\\Response", class_exists(\App\Core\Response::class));
assertTest("Autoloader loads App\\Core\\Request", class_exists(\App\Core\Request::class));
assertTest("Autoloader loads App\\Core\\Router", class_exists(\App\Core\Router::class));
assertTest("Autoloader loads App\\Services\\GeofenceService", class_exists(GeofenceService::class));
assertTest("Autoloader loads App\\Services\\TotpService", class_exists(TotpService::class));

// ---------------------------------------------------------------------
// Test Suite 2: Environment Parser
// ---------------------------------------------------------------------
echo "\n2. Testing Env Parser:\n";
Env::load($rootDir . '/.env.example');
assertTest("Env retrieves APP_NAME as string", Env::get('APP_NAME') === 'Lorella Speciality Cafe');
assertTest("Env coerces APP_DEBUG to false", Env::get('APP_DEBUG') === false);
assertTest("Env returns fallback for missing key", Env::get('NON_EXISTENT_KEY_XYZ', 'default_val') === 'default_val');

// ---------------------------------------------------------------------
// Test Suite 3: Geofence Service & Haversine Math
// ---------------------------------------------------------------------
echo "\n3. Testing GeofenceService (Haversine Formula):\n";
$cafeLat = 12.97159870;
$cafeLng = 77.59456270;
$radius = 100.0;

// Same point distance must be 0 meters
$distZero = GeofenceService::calculateDistance($cafeLat, $cafeLng, $cafeLat, $cafeLng);
assertTest("Distance to identical coordinates is 0.0m", abs($distZero) < 0.01);

// Known distance test: ~111 meters north (0.001 deg lat shift)
$northLat = $cafeLat + 0.001;
$distNorth = GeofenceService::calculateDistance($cafeLat, $cafeLng, $northLat, $cafeLng);
assertTest("Distance for 0.001 deg latitude is ~111.19m (result: {$distNorth}m)", $distNorth >= 110.0 && $distNorth <= 112.5);

// Point 20m away should be accepted within 100m radius
$evalInside = GeofenceService::evaluateLocation($cafeLat + 0.00018, $cafeLng, 10.0, $cafeLat, $cafeLng, $radius);
assertTest("Employee within café perimeter evaluates as valid", $evalInside['is_valid'] === true);

// Point 500m away should be rejected
$evalOutside = GeofenceService::evaluateLocation($cafeLat + 0.005, $cafeLng, 10.0, $cafeLat, $cafeLng, $radius);
assertTest("Employee 500m away evaluates as outside perimeter", $evalOutside['is_valid'] === false);

// Inaccurate GPS (> 150m) should be rejected regardless of proximity
$evalInaccurate = GeofenceService::evaluateLocation($cafeLat, $cafeLng, 200.0, $cafeLat, $cafeLng, $radius);
assertTest("Unreliable GPS accuracy (> 150m) is rejected", $evalInaccurate['accuracy_acceptable'] === false);

// ---------------------------------------------------------------------
// Test Suite 4: TotpService (RFC 6238 & Base32)
// ---------------------------------------------------------------------
echo "\n4. Testing TotpService (RFC 6238 / RFC 4226):\n";
$testSecret = TotpService::generateSecret();
assertTest("Generated Base32 secret has valid length", strlen($testSecret) >= 16);

// Encode / Decode symmetry
$sampleString = "LorellaCafe2026";
$encoded = TotpService::base32Encode($sampleString);
$decoded = TotpService::base32Decode($encoded);
assertTest("Base32 encode/decode symmetry verified", $decoded === $sampleString);

// Code Generation and Verification
$currentTime = time();
$code = TotpService::generateCode($testSecret, $currentTime);
assertTest("Generated TOTP code is 6 numeric digits", strlen($code) === 6 && ctype_digit($code));
assertTest("Generated TOTP code verifies successfully", TotpService::verifyCode($testSecret, $code, 1, $currentTime));
assertTest("Invalid TOTP code is rejected", !TotpService::verifyCode($testSecret, '000000', 1, $currentTime));

// Time window drift tolerance (+30s)
$pastCode = TotpService::generateCode($testSecret, $currentTime - 30);
assertTest("Code within +/- 1 window tolerance verifies", TotpService::verifyCode($testSecret, $pastCode, 1, $currentTime));

// Recovery Codes
$recovery = TotpService::generateRecoveryCodes(4);
assertTest("Generates requested count of recovery codes", count($recovery['plain']) === 4);
assertTest("Recovery codes hash verifies with password_verify", password_verify($recovery['plain'][0], $recovery['hashed'][0]));

// ---------------------------------------------------------------------
// Test Suite 5: Attendance Calculations
// ---------------------------------------------------------------------
echo "\n5. Testing Attendance Calculation Helpers:\n";
$minutes = AttendanceService::calculateWorkMinutes('2026-10-09 09:00:00', '2026-10-09 17:30:00');
assertTest("Working minutes calculation is exact (510 min = 8.5h)", $minutes === 510);

$isLate = AttendanceService::isArrivalLate('2026-10-09 09:20:00', '2026-10-09 09:00:00', 15);
assertTest("Arrival at 09:20 with 15m grace is late", $isLate === true);

$isNotLate = AttendanceService::isArrivalLate('2026-10-09 09:10:00', '2026-10-09 09:00:00', 15);
assertTest("Arrival at 09:10 with 15m grace is not late", $isNotLate === false);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n--------------------------------------------------------\n";
echo "Total Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "--------------------------------------------------------\n";

if ($failed > 0) {
    echo "TEST SUITE FAILED.\n";
    exit(1);
}

echo "ALL FOUNDATION TESTS PASSED SUCCESSFULLY.\n";
exit(0);
