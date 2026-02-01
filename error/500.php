<?php
/**
 * Generic Error Page Handler
 *
 * Displays branded error pages for HTTP errors:
 * 400, 401, 405, 408, 500, 502, 504
 *
 * Gracefully falls back when specific error code not available.
 * Specific 403 and 404 pages are in dedicated files.
 *
 * @package ElanRegistry
 * @since 2.12.0
 */

declare(strict_types=1);

// Get the HTTP status code from server variables
$statusCode = (int)($_SERVER['REDIRECT_STATUS'] ?? http_response_code() ?? 500);

// Set proper HTTP response code
http_response_code($statusCode);

// Anti-clickjacking headers (set explicitly in case init.php fails to load)
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: frame-ancestors 'self'");

// Try to initialize UserSpice session for personalized navigation
$isLoggedIn = false;
$userName = '';

try {
    if (file_exists(__DIR__ . '/../users/init.php')) {
        require_once __DIR__ . '/../users/init.php';
        if (isset($user) && $user->isLoggedIn()) {
            $isLoggedIn = true;
            $userData = $user->data();
            $userName = $userData->fname ?? '';
        }
    }
} catch (Throwable $e) {
    // Silently fail - show anonymous version
    $isLoggedIn = false;
}

// Ensure server globals are available (may not be if init.php failed)
if (!isset($request_uri)) {
    require_once __DIR__ . '/../users/classes/Server.php';
    require_once __DIR__ . '/../usersc/includes/server_globals.php';
}

// Log the error for administrator review
$userId = ($isLoggedIn && isset($userData->id)) ? (int)$userData->id : 0;

// Determine log category based on error code
$logCategoryMap = [
    400 => 'ValidationError',
    401 => 'AccessDenied',
    405 => 'SystemError',
    408 => 'SystemError',
    500 => 'SystemError',
    502 => 'SystemError',
    504 => 'SystemError',
];

$logCategory = $logCategoryMap[$statusCode] ?? 'SystemError';

$logMessage = sprintf(
    "%d Error | URI: %s | Referer: %s | IP: %s | Method: %s | User-Agent: %s",
    $statusCode,
    $request_uri,
    $referer ?: 'direct',
    $remote_addr,
    $method,
    substr($user_agent, 0, 150)
);

if (function_exists('logger')) {
    try {
        logger($userId, $logCategory, $logMessage);
    } catch (Throwable $e) {
        // Silently fail if logging not available
    }
}

// Define error messages for supported status codes
$errorMessages = [
    400 => [
        'title' => 'Bad Request',
        'message' => 'The server cannot process your request due to invalid syntax.',
        'icon_type' => 'warning'
    ],
    401 => [
        'title' => 'Unauthorized',
        'message' => 'You must be authenticated to access this resource.',
        'icon_type' => 'lock'
    ],
    405 => [
        'title' => 'Method Not Allowed',
        'message' => 'The request method is not supported for this resource.',
        'icon_type' => 'warning'
    ],
    408 => [
        'title' => 'Request Timeout',
        'message' => 'Your request took too long to process. Please try again.',
        'icon_type' => 'hourglass'
    ],
    500 => [
        'title' => 'Internal Server Error',
        'message' => 'An unexpected error occurred on the server. Please try again later.',
        'icon_type' => 'error'
    ],
    502 => [
        'title' => 'Bad Gateway',
        'message' => 'The server received an invalid response. Please try again later.',
        'icon_type' => 'error'
    ],
    504 => [
        'title' => 'Gateway Timeout',
        'message' => 'The server took too long to respond. Please try again later.',
        'icon_type' => 'hourglass'
    ],
];

// Use provided error details or defaults
$errorInfo = $errorMessages[$statusCode] ?? [
    'title' => 'Server Error',
    'message' => 'An unexpected error occurred. Please contact support if the problem persists.',
    'icon_type' => 'error'
];

$errorTitle = $errorInfo['title'];
$errorMessage = $errorInfo['message'];
$iconType = $errorInfo['icon_type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($statusCode . ' ' . $errorTitle, ENT_QUOTES, 'UTF-8') ?> - Lotus Elan Registry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --elan-red: #d9230f;
            --elan-green: #469408;
            --elan-dark: #373a3c;
        }

        body {
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar styling to match site */
        .navbar {
            background-color: var(--elan-dark) !important;
        }

        .navbar-brand img {
            height: 40px;
        }

        .navbar-nav .nav-link {
            color: rgba(255,255,255,0.9) !important;
            padding: 0.5rem 1rem;
        }

        .navbar-nav .nav-link:hover {
            color: #fff !important;
        }

        /* Main content area */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        /* Card styling to match site */
        .error-card {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.125);
            border-radius: 4px;
            max-width: 600px;
            width: 100%;
        }

        .error-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0,0,0,0.125);
            padding: 1rem 1.25rem;
        }

        .error-card .card-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 400;
            color: #333;
        }

        .error-card .card-body {
            padding: 1.5rem;
            text-align: center;
        }

        .error-code {
            font-size: 5rem;
            font-weight: 700;
            color: var(--elan-red);
            line-height: 1;
            margin-bottom: 10px;
        }

        .error-title {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 15px;
        }

        .error-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .error-icon {
            margin-bottom: 20px;
        }

        /* Button styling to match site */
        .btn-elan-red {
            background-color: var(--elan-red);
            border-color: var(--elan-red);
            color: #fff;
        }

        .btn-elan-red:hover {
            background-color: #b81d0c;
            border-color: #b81d0c;
            color: #fff;
        }

        .btn-elan-green {
            background-color: var(--elan-green);
            border-color: var(--elan-green);
            color: #fff;
        }

        .btn-elan-green:hover {
            background-color: #3a7a07;
            border-color: #3a7a07;
            color: #fff;
        }

        .user-greeting {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            color: var(--elan-dark);
        }
    </style>
</head>
<body>
    <!-- Navbar matching site design -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/">
                <img src="/usersc/templates/ElanRegistry/assets/images/Lotus-logo-40x40.png"
                     alt="Lotus Elan Registry"
                     onerror="this.parentElement.innerHTML='Lotus Elan Registry'">
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/">Home</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content -->
    <div class="main-content">
        <div class="error-card">
            <div class="card-header">
                <h1><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="card-body">
                <?php if ($isLoggedIn && $userName): ?>
                <div class="user-greeting">
                    Logged in as <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <div class="error-icon">
                    <!-- Generic error icon SVG -->
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="#d9230f" stroke-width="2" fill="none"/>
                        <line x1="12" y1="8" x2="12" y2="12" stroke="#d9230f" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="16" r="0.5" fill="#d9230f"/>
                    </svg>
                </div>

                <div class="error-code"><?= $statusCode ?></div>
                <h2 class="error-title"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="error-message">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div class="btn-group" role="group">
                    <a href="/" class="btn btn-elan-red">Return Home</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
