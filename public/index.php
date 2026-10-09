<?php

declare(strict_types=1);

/**
 * Lorella Speciality Cafe - Staff Attendance & Management Platform
 * Public Front Controller
 */

define('LORELLA_START', microtime(true));
define('ROOT_PATH', dirname(__DIR__));

// Step 1: Bootstrap Autoloader
require_once ROOT_PATH . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(ROOT_PATH . '/app');

// Step 2: Load Environment Variables
\App\Core\Env::load(ROOT_PATH . '/.env');

// Step 3: Configure Timezone
$appConfig = require ROOT_PATH . '/config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'Asia/Kolkata');

// Step 4: Configure Secure Session
\App\Services\AuthService::startSecureSession();

// Step 5: Initialize Request, Response, and Router
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;

use App\Controllers\AuthController;
use App\Controllers\AttendanceController;
use App\Controllers\CafeController;
use App\Controllers\UserController;
use App\Controllers\LeaveController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;

$request = Request::createFromGlobals();
$router = new Router();

// ---------------------------------------------------------------------
// System & Health Endpoints
// ---------------------------------------------------------------------
$router->get('/health', function () {
    return Response::jsonSuccess([
        'status' => 'healthy',
        'app' => 'Lorella Speciality Cafe',
        'php_version' => PHP_VERSION,
        'timestamp' => date('c'),
    ], 'Lorella Attendance System online');
});

// ---------------------------------------------------------------------
// REST API Route Group (/api)
// ---------------------------------------------------------------------
$router->group(['prefix' => '/api'], function (Router $api) {

    // Authentication Routes
    $api->group(['prefix' => '/auth'], function (Router $auth) {
        $auth->get('/csrf', [AuthController::class, 'csrfToken']);
        $auth->post('/login', [AuthController::class, 'login'], [
            RateLimitMiddleware::per(5, 900), // Max 5 attempts per 15 min window
        ]);
        $auth->post('/2fa/verify', [AuthController::class, 'verifyTwoFactor']);
        $auth->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
        $auth->get('/me', [AuthController::class, 'me'], [AuthMiddleware::class]);
    });

    // Attendance Routes (Protected by AuthMiddleware)
    $api->group(['prefix' => '/attendance', 'middleware' => [AuthMiddleware::class]], function (Router $att) {
        $att->get('/status', [AttendanceController::class, 'status']);
        $att->get('/history', [AttendanceController::class, 'history']);
        $att->post('/check-in', [AttendanceController::class, 'checkIn']);
        $att->post('/check-out', [AttendanceController::class, 'checkOut']);
    });

    // Café Management Routes
    $api->group(['prefix' => '/cafes', 'middleware' => [AuthMiddleware::class]], function (Router $cafes) {
        $cafes->get('/', [CafeController::class, 'index']);
        $cafes->get('/{id}', [CafeController::class, 'show']);
    });

    // User / Staff Management Routes
    $api->group(['prefix' => '/users', 'middleware' => [AuthMiddleware::class]], function (Router $users) {
        $users->get('/', [UserController::class, 'index']);
        $users->get('/{id}', [UserController::class, 'show']);
    });

    // Leave Management Routes
    $api->group(['prefix' => '/leave', 'middleware' => [AuthMiddleware::class]], function (Router $leave) {
        $leave->get('/', [LeaveController::class, 'index']);
        $leave->post('/request', [LeaveController::class, 'create']);
    });

    // Reports & Analytics (Managers & Admins only)
    $api->group([
        'prefix' => '/reports',
        'middleware' => [AuthMiddleware::class, RoleMiddleware::requireRole(['super_admin', 'admin', 'manager'])],
    ], function (Router $reports) {
        $reports->get('/daily', [ReportController::class, 'daily']);
        $reports->get('/monthly', [ReportController::class, 'monthly']);
    });

    // System Settings (Super Admin only)
    $api->group([
        'prefix' => '/settings',
        'middleware' => [AuthMiddleware::class, RoleMiddleware::requireRole('super_admin')],
    ], function (Router $settings) {
        $settings->get('/', [SettingsController::class, 'index']);
    });
});

// ---------------------------------------------------------------------
// Web Presentation Shell (Landing / App container)
// ---------------------------------------------------------------------
$router->get('/', function () {
    // Phase 1 Foundation View
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#4A1521">
    <meta name="description" content="Lorella Speciality Cafe Staff Attendance and Management System">
    <title>Lorella Speciality Cafe &bull; Staff Attendance</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lorella-theme.css">
</head>
<body class="lorella-app-body">
    <header class="lorella-navbar">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="brand-crest">&#10022;</span>
                <span class="brand-name">LORELLA</span>
                <span class="brand-sub">Speciality Cafe</span>
            </div>
            <div class="system-badge">
                <span class="status-indicator"></span> System Online
            </div>
        </div>
    </header>

    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="lorella-card text-center p-4 p-md-5">
                    <div class="brand-monogram mb-3">L</div>
                    <h1 class="lorella-title mb-2">Staff Attendance System</h1>
                    <p class="lorella-subtitle mb-4">Architecture & Foundation Initialized</p>

                    <div class="foundation-status text-start mb-4">
                        <div class="status-row">
                            <span class="status-dot success"></span>
                            <span>PHP 8.4 REST Architecture</span>
                            <span class="badge-soft ms-auto">Verified</span>
                        </div>
                        <div class="status-row">
                            <span class="status-dot success"></span>
                            <span>MySQL 8+ Normalized Schema</span>
                            <span class="badge-soft ms-auto">Ready</span>
                        </div>
                        <div class="status-row">
                            <span class="status-dot success"></span>
                            <span>Argon2id & RFC 6238 TOTP Engine</span>
                            <span class="badge-soft ms-auto">Active</span>
                        </div>
                        <div class="status-row">
                            <span class="status-dot success"></span>
                            <span>Haversine Geofencing Service</span>
                            <span class="badge-soft ms-auto">Calibrated</span>
                        </div>
                        <div class="status-row">
                            <span class="status-dot success"></span>
                            <span>Hostinger Deployment Isolation</span>
                            <span class="badge-soft ms-auto">Secured</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-center gap-3">
                        <a href="/health" class="lorella-btn-primary" target="_blank">Health Check</a>
                        <a href="/api/auth/csrf" class="lorella-btn-secondary" target="_blank">CSRF Status</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="lorella-footer text-center py-4">
        <p class="mb-0 text-muted small">&copy; 2026 Lorella Speciality Cafe &bull; Staff Management Platform</p>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
HTML;
    return Response::create($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});

// Step 6: Dispatch Request
$router->dispatch($request);
