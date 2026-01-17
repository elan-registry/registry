<?php
/**
 * 404 Not Found Error Page
 *
 * Branded error page for the Lotus Elan Registry.
 * Displays when users attempt to access non-existent pages.
 *
 * @package ElanRegistry
 * @since 2.12.0
 */

declare(strict_types=1);

// Set proper HTTP response code
http_response_code(404);

// Try to initialize UserSpice session for personalized navigation
$isLoggedIn = false;
$userName = '';

try {
    if (file_exists(__DIR__ . '/users/init.php')) {
        require_once __DIR__ . '/users/init.php';
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

// Log the 404 error for administrator review
$userId = ($isLoggedIn && isset($userData->id)) ? (int)$userData->id : 0;
$requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
$referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$logMessage = sprintf(
    "404 Not Found | URI: %s | Referer: %s | IP: %s | Method: %s | User-Agent: %s",
    $requestUri,
    $referer,
    $ipAddress,
    $method,
    substr($userAgent, 0, 150)
);

if (function_exists('logger')) {
    logger($userId, LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, $logMessage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>404 Page Not Found - Lotus Elan Registry</title>
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

        .help-text {
            margin-top: 20px;
            font-size: 0.9rem;
            color: #888;
        }

        .help-text a {
            color: var(--elan-red);
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
            <a class="navbar-brand" href="./">
                <img src="usersc/templates/ElanRegistry/assets/images/Lotus-logo-3000x3000.png"
                     alt="Lotus Elan Registry"
                     onerror="this.parentElement.innerHTML='Lotus Elan Registry'">
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="./">Home</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content -->
    <div class="main-content">
        <div class="error-card">
            <div class="card-header">
                <h1>Page Not Found</h1>
            </div>
            <div class="card-body">
                <?php if ($isLoggedIn && $userName): ?>
                <div class="user-greeting">
                    Logged in as <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>


                <div class="error-icon">
                    <!-- Question mark / search icon SVG -->
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="11" cy="11" r="7" stroke="#d9230f" stroke-width="2" fill="none"/>
                        <path d="M15.5 15.5L20 20" stroke="#d9230f" stroke-width="2" stroke-linecap="round"/>
                        <text x="11" y="14" text-anchor="middle" fill="#d9230f" font-size="8" font-weight="bold">?</text>
                    </svg>
                </div>

                <div class="error-code">404</div>
                <h2 class="error-title">Page Not Found</h2>
                <p class="error-message">
                    The page you're looking for doesn't exist or has been moved.<br>
                    The URL might be mistyped or the content may have been removed.
                </p>

                <div class="btn-group" role="group">
                    <a href="./" class="btn btn-elan-red">Return Home</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
