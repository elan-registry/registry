<?php

declare(strict_types=1);

/**
 * Universal Document Viewer - Documentation System
 *
 * Displays markdown documents in a formatted view with proper permissions.
 * Handles both user-facing and administrative documentation.
 *
 * @package ElanRegistry
 * @version 2.9.0
 * @author Jim Boone
 */

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get the document parameter
$doc = $_GET['doc'] ?? '';

// Define document categories and their allowed files
$documentCategories = [
    'faq' => [
        'path' => 'faq/',
        'documents' => [
            'CAR_TRANSFER_USER_GUIDE.md',
            'CAR_TRANSFER_FAQ.md',
            'PRIVACY.md'
        ],
        'requiresAdmin' => false
    ],
    'admin' => [
        'path' => 'faq/admin/',
        'documents' => [
            'CAR_TRANSFER_ADMIN_GUIDE.md',
            'CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md',
            'CAR_TRANSFER_TROUBLESHOOTING.md',
            'DATABASE.md',
            'PRD.md',
            'EMAIL_STYLING_GUIDELINES.md',
            'SPAM_CLEANUP_SYSTEM.md'
        ],
        'requiresAdmin' => true
    ]
];

// Find which category the document belongs to
$category = null;
$documentPath = null;

foreach ($documentCategories as $catName => $catInfo) {
    if (in_array($doc, $catInfo['documents'])) {
        $category = $catName;
        $documentPath = __DIR__ . '/' . $catInfo['path'] . $doc;

        // Check admin permissions if required (Administrator=1, Editor=2)
        if ($catInfo['requiresAdmin']) {
            if (!$user->isLoggedIn() || !hasPerm([1, 2], $user->data()->id)) {
                Redirect::to($us_url_root . 'users/login.php');
            }
        }
        break;
    }
}

if (!$category || !file_exists($documentPath)) {
    header('HTTP/1.0 404 Not Found');
    die('Document not found.');
}

// Read the markdown content with error handling
try {
    $markdownContent = file_get_contents($documentPath);
    if ($markdownContent === false) {
        throw new RuntimeException('Failed to read document file');
    }
} catch (Exception $e) {
    error_log("Document read error: " . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    die('Error reading document file.');
}

// Enhanced markdown to HTML converter
function markdownToHtml(string $markdown): string {
    // Headers
    $markdown = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $markdown);
    $markdown = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $markdown);
    $markdown = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $markdown);
    $markdown = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $markdown);
    $markdown = preg_replace('/^##### (.*?)$/m', '<h5>$1</h5>', $markdown);

    // Bold and italic
    $markdown = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $markdown);
    $markdown = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $markdown);

    // Links
    $markdown = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $markdown);

    // Code blocks
    $markdown = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $markdown);
    $markdown = preg_replace('/`([^`]+)`/', '<code>$1</code>', $markdown);

    // Lists - handle nested properly
    $lines = explode("\n", $markdown);
    $inList = false;
    $listDepth = 0;

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        // Bullet lists
        if (preg_match('/^(\s*)- (.*)$/', $line, $matches)) {
            $indent = strlen($matches[1]);
            $content = $matches[2];

            if (!$inList) {
                $lines[$i] = '<ul><li>' . $content . '</li>';
                $inList = true;
                $listDepth = $indent;
            } else {
                $lines[$i] = '<li>' . $content . '</li>';
            }
        }
        // Numbered lists
        elseif (preg_match('/^(\s*)\d+\. (.*)$/', $line, $matches)) {
            $indent = strlen($matches[1]);
            $content = $matches[2];

            if (!$inList) {
                $lines[$i] = '<ol><li>' . $content . '</li>';
                $inList = true;
                $listDepth = $indent;
            } else {
                $lines[$i] = '<li>' . $content . '</li>';
            }
        } else {
            if ($inList && trim($line) === '') {
                // Keep list open for empty lines
                continue;
            } elseif ($inList) {
                // Close the list
                $lines[$i-1] .= '</ul>';
                $inList = false;
            }
        }
    }

    // Close any remaining open list
    if ($inList) {
        $lines[count($lines)-1] .= '</ul>';
    }

    $markdown = implode("\n", $lines);

    // Line breaks and paragraphs
    $markdown = preg_replace('/\n\n+/', '</p><p>', $markdown);
    $markdown = '<p>' . $markdown . '</p>';

    // Clean up extra p tags around headers and lists
    $cleanupPatterns = [
        '/<p><h([1-6])/' => '<h$1',
        '/<\/h([1-6])><\/p>/' => '</h$1>',
        '/<p><ul>/' => '<ul>',
        '/<\/ul><\/p>/' => '</ul>',
        '/<p><ol>/' => '<ol>',
        '/<\/ol><\/p>/' => '</ol>',
        '/<p><pre>/' => '<pre>',
        '/<\/pre><\/p>/' => '</pre>',
        '/<p><\/p>/' => '',
        '/<p>\s*<\/p>/' => ''
    ];

    foreach ($cleanupPatterns as $pattern => $replacement) {
        $markdown = preg_replace($pattern, $replacement, $markdown);
    }

    return $markdown;
}

// Document information mapping
$documentInfo = [
    // User documents
    'CAR_TRANSFER_USER_GUIDE.md' => [
        'title' => 'Car Transfer User Guide',
        'icon' => 'fas fa-exchange-alt',
        'description' => 'Complete guide for requesting ownership transfers',
        'breadcrumb' => 'User Guide'
    ],
    'CAR_TRANSFER_FAQ.md' => [
        'title' => 'Car Transfer FAQ',
        'icon' => 'fas fa-question-circle',
        'description' => 'Frequently asked questions about transfers',
        'breadcrumb' => 'FAQ'
    ],
    'PRIVACY.md' => [
        'title' => 'Privacy Policy',
        'icon' => 'fas fa-shield-alt',
        'description' => 'How we protect and use your information',
        'breadcrumb' => 'Privacy Policy'
    ],
    // Admin documents
    'CAR_TRANSFER_ADMIN_GUIDE.md' => [
        'title' => 'Car Transfer Administrator Guide',
        'icon' => 'fas fa-book',
        'description' => 'Comprehensive administrative procedures',
        'breadcrumb' => 'Admin Guide'
    ],
    'CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md' => [
        'title' => 'Car Transfer Quick Reference',
        'icon' => 'fas fa-tachometer-alt',
        'description' => 'Daily admin tasks and quick fixes',
        'breadcrumb' => 'Quick Reference'
    ],
    'CAR_TRANSFER_TROUBLESHOOTING.md' => [
        'title' => 'Car Transfer Troubleshooting',
        'icon' => 'fas fa-wrench',
        'description' => 'Systematic diagnostic procedures',
        'breadcrumb' => 'Troubleshooting'
    ],
    'DATABASE.md' => [
        'title' => 'Database Schema Documentation',
        'icon' => 'fas fa-database',
        'description' => 'Complete database documentation',
        'breadcrumb' => 'Database Schema'
    ],
    'PRD.md' => [
        'title' => 'Product Requirements Document',
        'icon' => 'fas fa-file-contract',
        'description' => 'Feature specifications and requirements',
        'breadcrumb' => 'PRD'
    ],
    'EMAIL_STYLING_GUIDELINES.md' => [
        'title' => 'Email Styling Guidelines',
        'icon' => 'fas fa-envelope',
        'description' => 'Email template standards',
        'breadcrumb' => 'Email Guidelines'
    ],
    'SPAM_CLEANUP_SYSTEM.md' => [
        'title' => 'Spam Cleanup System',
        'icon' => 'fas fa-broom',
        'description' => 'Automated cleanup system documentation',
        'breadcrumb' => 'Spam Cleanup'
    ]
];

$info = $documentInfo[$doc];
$htmlContent = markdownToHtml($markdownContent);
$isAdmin = ($category === 'admin');

?>
<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>
        <!-- Navigation Breadcrumb -->
        <div class='row mb-3'>
            <div class='col-12'>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= $us_url_root ?>"><i class="fas fa-home"></i> Registry</a></li>
                        <li class="breadcrumb-item"><a href="faq/index.php"><i class="fas fa-question-circle"></i> FAQ</a></li>
                        <?php if ($isAdmin) { ?>
                            <li class="breadcrumb-item"><a href="faq/admin/index.php"><i class="fas fa-tools"></i> Admin Docs</a></li>
                        <?php } ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($info['breadcrumb'], ENT_QUOTES, 'UTF-8') ?></li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if ($isAdmin) { ?>
        <!-- Admin Warning -->
        <div class='row mb-3'>
            <div class='col-12'>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Administrator Documentation</strong><br>
                    This content is intended for registry administrators and technical staff only.
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- Document Header -->
        <div class='row'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header <?= $isAdmin ? 'bg-danger' : 'bg-primary' ?> text-white'>
                        <h1 class='mb-0'><i class='<?= $info['icon'] ?>'></i> <?= htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class='text-light mb-0'><?= htmlspecialchars($info['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Content -->
        <div class='row mt-4'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-body'>
                        <div class="document-content">
                            <?= $htmlContent ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Footer -->
        <div class='row mt-4 mb-4'>
            <div class='col-12 text-center'>
                <?php if ($isAdmin) { ?>
                    <a href='faq/admin/index.php' class='btn btn-outline-primary mr-2'><i class='fas fa-arrow-left'></i> Back to Admin Docs</a>
                    <a href='<?= $us_url_root ?>app/admin/manage-consolidated.php' class='btn btn-outline-success mr-2'><i class='fas fa-tools'></i> Admin Panel</a>
                <?php } else { ?>
                    <a href='faq/index.php' class='btn btn-outline-primary mr-2'><i class='fas fa-arrow-left'></i> Back to FAQ</a>
                <?php } ?>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-secondary'><i class='fas fa-home'></i> Registry Home</a>
            </div>
        </div>

    </div> <!-- /.container -->
</div><!-- .page-wrapper -->

<style>
.document-content {
    line-height: 1.6;
    font-size: 1.1rem;
}

.document-content h1 {
    color: <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
    border-bottom: 2px solid <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
    padding-bottom: 0.5rem;
    margin-top: 2rem;
    margin-bottom: 1rem;
}

.document-content h2 {
    color: #495057;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 0.3rem;
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}

.document-content h3 {
    color: #6c757d;
    margin-top: 1.25rem;
    margin-bottom: 0.75rem;
}

.document-content h4, .document-content h5 {
    color: #6c757d;
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.document-content ul, .document-content ol {
    margin-bottom: 1rem;
    padding-left: 2rem;
}

.document-content li {
    margin-bottom: 0.25rem;
}

.document-content pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    padding: 1rem;
    margin: 1rem 0;
    overflow-x: auto;
}

.document-content code {
    background-color: #f8f9fa;
    color: #e83e8c;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}

.document-content pre code {
    background-color: transparent;
    color: inherit;
    padding: 0;
}

.document-content blockquote {
    border-left: 4px solid <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
    padding-left: 1rem;
    margin: 1rem 0;
    font-style: italic;
    color: #6c757d;
}

.document-content table {
    width: 100%;
    margin: 1rem 0;
    border-collapse: collapse;
}

.document-content table th,
.document-content table td {
    border: 1px solid #dee2e6;
    padding: 0.75rem;
    text-align: left;
}

.document-content table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.breadcrumb {
    background-color: #f8f9fa;
    border-radius: 0.25rem;
}
</style>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>