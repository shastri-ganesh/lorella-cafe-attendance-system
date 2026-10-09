<?php

declare(strict_types=1);

/**
 * Lorella Speciality Cafe - Secure Super Admin CLI Provisioning Script
 *
 * Usage:
 *   php scripts/create_admin.php "Jane Doe" "admin@lorellacafe.com" "SecretStrongPassword123"
 * Or run interactively:
 *   php scripts/create_admin.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be executed via the command line interface.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . '/app');
\App\Core\Env::load(dirname(__DIR__) . '/.env');

use App\Core\Database;
use App\Services\AuthService;
use App\Services\TotpService;
use App\Services\AuditService;

echo "========================================================\n";
echo "  Lorella Speciality Cafe - First Admin Provisioner     \n";
echo "========================================================\n\n";

$name = $argv[1] ?? null;
$email = $argv[2] ?? null;
$password = $argv[3] ?? null;

if (!$name) {
    echo "Enter Full Name: ";
    $name = trim((string) fgets(STDIN));
}

if (!$email) {
    echo "Enter Email Address: ";
    $email = trim((string) fgets(STDIN));
}

if (!$password) {
    echo "Enter Strong Password (min 10 characters): ";
    $password = trim((string) fgets(STDIN));
}

if (strlen($name) < 2) {
    echo "Error: Name is too short.\n";
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid email format.\n";
    exit(1);
}

if (strlen($password) < 10) {
    echo "Error: Password must be at least 10 characters long.\n";
    exit(1);
}

try {
    $pdo = Database::getConnection();

    // Verify role exists
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE slug = 'super_admin' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if (!$roleId) {
        echo "Error: 'super_admin' role not found in database. Have you run database/schema.sql and database/seed.sql?\n";
        exit(1);
    }

    // Check if email already exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $checkStmt->execute([':email' => strtolower($email)]);
    if ($checkStmt->fetch()) {
        echo "Error: A user with email '{$email}' already exists.\n";
        exit(1);
    }

    // Hash password securely with Argon2id / Bcrypt
    $passwordHash = AuthService::hashPassword($password);

    // Generate 2FA Secret & Recovery Codes
    $totpSecret = TotpService::generateSecret();
    $recovery = TotpService::generateRecoveryCodes(8);

    // Insert user & 2FA atomically
    Database::transaction(function (PDO $pdo) use ($roleId, $name, $email, $passwordHash, $totpSecret, $recovery) {
        $insertUser = $pdo->prepare("
            INSERT INTO users (role_id, full_name, email, password_hash, status, created_at)
            VALUES (:role_id, :name, :email, :hash, 'active', NOW())
        ");
        $insertUser->execute([
            ':role_id' => $roleId,
            ':name' => $name,
            ':email' => strtolower($email),
            ':hash' => $passwordHash,
        ]);
        $userId = (int) $pdo->lastInsertId();

        // Assign Flagship Café
        $assignCafe = $pdo->prepare("
            INSERT INTO user_cafes (user_id, cafe_id, is_primary) VALUES (:uid, 1, 1)
        ");
        $assignCafe->execute([':uid' => $userId]);

        // Insert 2FA record
        $insert2fa = $pdo->prepare("
            INSERT INTO two_factor_auth (user_id, secret, is_enabled, created_at)
            VALUES (:uid, :secret, 1, NOW())
        ");
        $insert2fa->execute([
            ':uid' => $userId,
            ':secret' => $totpSecret,
        ]);

        // Insert hashed recovery codes
        $insertRc = $pdo->prepare("
            INSERT INTO recovery_codes (user_id, code_hash, created_at)
            VALUES (:uid, :chash, NOW())
        ");
        foreach ($recovery['hashed'] as $codeHash) {
            $insertRc->execute([':uid' => $userId, ':chash' => $codeHash]);
        }

        AuditService::log(
            'SUPER_ADMIN_PROVISIONED',
            'users',
            $userId,
            $userId,
            1,
            [],
            ['email' => $email, 'name' => $name]
        );
    });

    $otpUri = TotpService::getOtpAuthUri($email, $totpSecret, 'Lorella Cafe');

    echo "\nSUCCESS! Super Admin account provisioned securely.\n";
    echo "--------------------------------------------------------\n";
    echo "Name:             {$name}\n";
    echo "Email:            {$email}\n";
    echo "Role:             Super Administrator\n";
    echo "2FA Secret (Base32): {$totpSecret}\n";
    echo "Authenticator URI:   {$otpUri}\n\n";
    echo "Single-Use Backup Recovery Codes (Store securely!):\n";
    foreach ($recovery['plain'] as $index => $code) {
        printf("  [%d] %s\n", $index + 1, $code);
    }
    echo "--------------------------------------------------------\n";
    echo "Provisioning complete.\n";

} catch (\Throwable $e) {
    echo "Provisioning failed: " . $e->getMessage() . "\n";
    exit(1);
}
