<?php

declare(strict_types=1);

/**
 * Document Embed Page
 *
 * Embeds a selected document (PDF) in an iframe for viewing.
 * Public page — documents under `docs/<subdir>/assets/` are publicly served;
 * `securePage()` is called for permission-table registration consistency, not
 * to require login. Uses Bootstrap for layout.
 */

// SEO metadata for the reference PDFs (#1538). Adding an entry here also
// requires adding the matching asset URL to SitemapService::STATIC_PAGES —
// tests/unit/system/PdfViewerDocumentMetadataConsistencyTest.php enforces
// that both lists stay in sync with each other and with the files actually
// present under docs/reference/assets/. Keys are case-sensitive exact
// filenames — a file added with an uppercase .PDF extension (allowed by the
// extension check below) needs its key written with matching case, or the
// lookup silently misses and falls back to the generic title/description.
/** @var array<string, array{title: string, description: string}> $document_metadata */
$document_metadata = [
    'Elan_26_36_Workshop_Manual.pdf' => [
        'title'       => 'Lotus Elan Workshop Manual — Types 26 & 36',
        'description' => 'The full Lotus Elan workshop manual covering maintenance and repair for Series 1–4 and Sprint (Types 26/36) models. Cleaned up and shared by ElanDNA.',
    ],
    'elan_s1_s2_coupe_masterpartslist.pdf' => [
        'title'       => '1966 Lotus Elan Parts List — Series 1, Series 2 & Coupe',
        'description' => 'The 1966 factory parts list for the Lotus Elan Series 1, Series 2, and Coupe models. Cleaned up and shared by ElanDNA.',
    ],
    '2016 Jan Elan Engine Types.pdf' => [
        'title'       => 'Lotus Elan & Plus 2 Engine Types — Club Lotus Reference',
        'description' => 'A Club Lotus reference (January 2016) describing the Lotus Elan and Elan Plus 2 engine types.',
    ],
    'Engine number breakdown (Miles Wilkins).pdf' => [
        'title'       => 'Lotus Elan Engine Number Breakdown — Miles Wilkins',
        'description' => 'A guide by Miles Wilkins for identifying Lotus Elan engine types and production years from engine number sequences.',
    ],
    '2014 Jul Elan Gearknobs.pdf' => [
        'title'       => 'Lotus Elan Gear Knobs — Club Lotus Technical Article',
        'description' => 'A Club Lotus technical article (July 2014) describing the various types of gear knobs fitted to the Lotus Elan and Elan Plus 2.',
    ],
    '2014 Oct Elan and Plus 2 Steering Wheels.pdf' => [
        'title'       => 'Lotus Elan & Plus 2 Steering Wheels — Club Lotus Technical Article',
        'description' => 'A Club Lotus technical article (October 2014) describing the various types of steering wheels fitted to the Lotus Elan and Elan Plus 2.',
    ],
    '2019_Jan_The_Elan_Super_Safety.pdf' => [
        'title'       => 'The Lotus Elan Super Safety — Club Lotus Technical Article',
        'description' => 'A Club Lotus technical article (January 2019) describing the Lotus Elan Super Safety.',
    ],
    'Lotus Elan Plus 2 serial numbers.pdf' => [
        'title'       => 'Lotus Elan Plus 2 Serial Numbers',
        'description' => 'Serial number sequences for the Lotus Elan Plus 2 — a reference for dating and identifying cars.',
    ],
    'All Elan and Elan Plus 2 Paint Codes.pdf' => [
        'title'       => 'Lotus Elan Paint Codes PDF — Official Factory Reference',
        'description' => 'Official factory paint codes for all Elan and Plus 2 models — downloadable PDF for offline reference.',
    ],
];

// Exact-match lookup against the fixed dictionary above — safe before
// init.php (no $abs_us_root/file_exists() yet) because it's an isset()
// check against a small hardcoded key set: request input can only ever
// select one of *our* literal title/description values or match nothing,
// never inject content into $pageTitle itself. Used twice: once below on
// raw $_GET (pre-init.php, for $pageTitle/$pageDescription) and again after
// the full validation block on the sanitized $document/$asset_subdir (for
// the render body) — same rule both times, so it's defined once here.
$lookup_doc_metadata = static function (string $subdir, string $doc) use ($document_metadata): ?array {
    return ($subdir === 'reference' && isset($document_metadata[$doc])) ? $document_metadata[$doc] : null;
};

// Requiring subdir === 'reference' avoids showing a doc-specific title
// before validation completes — for the 404 cases (wrong/omitted subdir)
// the raw $requested_subdir never equals 'reference' verbatim, and the
// legacy-alias case (e.g. subdir=reference/assets) 301-redirects below
// before any title would be sent, so neither leaks a premature title.
$requested_doc    = is_string($_GET['doc'] ?? null) ? $_GET['doc'] : '';
$requested_subdir = is_string($_GET['subdir'] ?? null) ? $_GET['subdir'] : '';
$provisional_doc_metadata = $lookup_doc_metadata($requested_subdir, $requested_doc);

$pageTitle = $provisional_doc_metadata['title']
    ?? 'Document Viewer — Lotus Elan Registry Reference Library';
$pageDescription = $provisional_doc_metadata['description']
    ?? 'View and download PDF reference documents from the Lotus Elan Registry technical library, including workshop manuals, parts lists, and technical articles.';

require_once '../users/init.php';

// Hint for the nav active-state matcher (see usersc/templates/customizer/file_nav_custom.php).
// Must be set before elanregistry_prep.php, which triggers the nav render.
$nav_section = ($requested_subdir === 'stories') ? 'stories' : 'reference';

// #1960: each PDF keeps its own canonical. The header renders before the
// validation block below, so this repeats its doc and subdir checks and also
// requires the file to exist. Only a request that the page will serve can put
// subdir and doc in the canonical. Any other request gets the default
// canonical with no query string.
$is_servable_doc = static function (string $subdir, string $doc) use ($abs_us_root, $us_url_root): bool {
    if (!in_array($subdir, ['reference', 'stories'], true)
        || strpos($doc, '..') !== false
        || strpos($doc, '/') !== false
        || strpos($doc, '\\') !== false
        || strpos($doc, 'http') === 0
        || basename($doc) !== $doc
        || !in_array(pathinfo($doc, PATHINFO_EXTENSION), ['pdf', 'PDF'], true)) {
        return false;
    }

    return is_file($abs_us_root . $us_url_root . 'docs/' . $subdir . '/assets/' . $doc);
};

if ($is_servable_doc($requested_subdir, $requested_doc)) {
    $pageCanonicalUrl = \ElanRegistry\CanonicalUrl::build(
        $current_origin,
        $php_self,
        ['subdir' => $requested_subdir, 'doc' => $requested_doc]
    );
}

require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;
use ElanRegistry\LogCategories;

if (!securePage($php_self)) {
    die();
}

// Validate and sanitize document parameter
$document    = '';
$path_parts  = [];
$error_message = '';
$asset_base  = '';
$asset_subdir = '';

if ($requested_doc !== '') {
    // Security: Prevent directory traversal attacks
    if (strpos($requested_doc, '..') !== false ||
        strpos($requested_doc, '/') !== false ||
        strpos($requested_doc, '\\') !== false ||
        strpos($requested_doc, 'http') === 0) {
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid document path attempted: ' . $requested_doc);
        http_response_code(404);
        $error_message = 'Invalid document path.';
    } else {
        // Sanitize the document name
        $document = basename($requested_doc);

        // Additional validation: only allow certain file extensions
        $allowed_extensions = ['pdf', 'PDF'];
        $path_parts = pathinfo($document);

        if (!isset($path_parts['extension']) || !in_array($path_parts['extension'], $allowed_extensions)) {
            logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid document type attempted: ' . $requested_doc);
            http_response_code(404);
            $error_message = 'Invalid document type. Only PDF files are allowed.';
            $document = '';
        }

        // Validate subdir parameter (allowlist only). Distinguishes the legacy indexed
        // alias (normalize via 301 to the equivalent allowlisted subdir), omitted/invalid
        // (404 — no safe default directory exists since #715 renamed docs/assets/ to
        // docs/<subdir>/assets/), and valid.
        $allowed_subdirs = ['reference', 'stories'];

        if (empty($error_message) && !empty($document)) {
            // Legacy indexed URL shape (#1473): subdir=<allowlisted>/assets pre-dates
            // the reference/stories convention (covers reference/assets and
            // stories/assets alike). Not a security event — just an old crawled URL —
            // so normalize with a real 301 and do not log.
            if (str_ends_with($requested_subdir, '/assets')) {
                $legacy_candidate = substr($requested_subdir, 0, -strlen('/assets'));
                if (in_array($legacy_candidate, $allowed_subdirs, true)) {
                    Redirect::sanitized(
                        $us_url_root . 'docs/pdf-viewer.php',
                        ['subdir' => $legacy_candidate, 'doc' => $document],
                        301
                    );
                    exit; // Redirect::sanitized() already exits internally; explicit for defense in depth.
                }
            }

            if ($requested_subdir === '') {
                // Omitted (distinct from present-but-invalid). No safe default
                // directory remains — treat as not-found rather than guessing.
                logger(0, LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, 'Missing subdir parameter for document: ' . $document);
                http_response_code(404);
                $error_message = 'Invalid document path.';
                $document = '';
            } elseif (!in_array($requested_subdir, $allowed_subdirs, true)) {
                // Traversal/scheme-like values are probing, not legacy-URL noise — keep
                // them visible in the security log even though the allowlist already
                // blocks them. subdir has no earlier traversal check (unlike doc above).
                $suspicious = strpos($requested_subdir, '..') !== false
                    || strpos($requested_subdir, '/') !== false
                    || strpos($requested_subdir, '\\') !== false
                    || strpos($requested_subdir, "\0") !== false
                    || stripos($requested_subdir, 'http') === 0;
                logger(
                    0,
                    $suspicious ? LogCategories::LOG_CATEGORY_SECURITY : LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND,
                    'Invalid subdir attempted: ' . $requested_subdir
                );
                http_response_code(404);
                $error_message = 'Invalid document path.';
                $document = '';
            } else {
                $asset_subdir = $requested_subdir;
            }
        }

        $asset_base = $asset_subdir !== '' ? 'docs/' . $asset_subdir . '/assets/' : '';

        // Check if file actually exists
        $file_path = $abs_us_root . $us_url_root . $asset_base . $document;
        if ($error_message === '' && $document !== '' && $asset_subdir !== '' && !file_exists($file_path)) {
            // #1594: an orphaned docs/embed.php still runs on production (deleted
            // from git, but not from disk) and always links here with
            // subdir=reference, without normalizing filename case. That sends us
            // a subdir/case combination that doesn't match the file on disk even
            // though the document exists under a different allowlisted subdir or
            // with different case. Search the allowlisted subdirs case-insensitively
            // before giving up, and 301 to the resolved location so bookmarks/search
            // engines pick up the canonical URL. The common case (direct path
            // exists) never reaches this branch, so it costs nothing when links are
            // already correct.
            $resolved_subdir   = '';
            $resolved_document = '';
            foreach ($allowed_subdirs as $candidate_subdir) {
                // Scoped to $allowed_extensions (not '*') so the search only ever
                // considers files $document could already match — reference/assets/
                // also holds non-PDF companions (.png/.txt) for each document.
                $candidate_dir = $abs_us_root . $us_url_root . 'docs/' . $candidate_subdir . '/assets/';
                $glob_result   = glob($candidate_dir . '*.{' . implode(',', $allowed_extensions) . '}', GLOB_BRACE);
                if ($glob_result === false) {
                    // glob() returning false means the directory couldn't be read
                    // (permissions/disk issue) — distinct from "no matches" and
                    // worth its own log line so this doesn't silently masquerade
                    // as a plain "document not found" if it ever happens.
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'glob() failed reading ' . $candidate_dir);
                    continue;
                }
                foreach ($glob_result as $candidate_path) {
                    if (strcasecmp(basename($candidate_path), $document) === 0) {
                        $resolved_subdir   = $candidate_subdir;
                        $resolved_document = basename($candidate_path);
                        break 2;
                    }
                }
            }

            if ($resolved_subdir !== '' && ($resolved_subdir !== $asset_subdir || $resolved_document !== $document)) {
                Redirect::sanitized(
                    $us_url_root . 'docs/pdf-viewer.php',
                    ['subdir' => $resolved_subdir, 'doc' => $resolved_document],
                    301
                );
                exit; // Redirect::sanitized() already exits internally; explicit for defense in depth.
            }

            logger(0, LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, 'Non-existent document requested: ' . $document);
            http_response_code(404);
            $error_message = 'Document not found.';
            $document = '';
        }
    }
}

// Keyed off the already-validated $document/$asset_subdir (never raw
// $_GET), unlike $provisional_doc_metadata above.
$validated_doc_metadata = $lookup_doc_metadata($asset_subdir, $document);

$h1_fallback = !empty($path_parts['filename']) ? $path_parts['filename'] : 'Document Viewer';

?>
<div id='page-wrapper'>
    <!-- Page Content -->
    <div class='container'>
        <?= DocumentPortalTemplate::renderBreadcrumb($nav_section, $us_url_root, $path_parts['filename'] ?? '', 'fa-file-pdf') ?>
        <div class='card card-default'>
            <div class='card-header'>
                <h1><?= htmlspecialchars($validated_doc_metadata['title'] ?? $h1_fallback, ENT_QUOTES, 'UTF-8') ?></h1>
                <a href="javascript:history.go(-1)">Back ...</a>
            </div>
            <div class='card-body'>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <p>Please <a href="javascript:history.go(-1)">go back</a> and try again.</p>
                <?php elseif (!empty($document)): ?>
                    <p><?= htmlspecialchars(
                        $validated_doc_metadata['description']
                            ?? 'View this PDF document online below, or use the direct download link for offline access.',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></p>
                    <p>
                        <a href="<?= htmlspecialchars($us_url_root . $asset_base . rawurlencode($document), ENT_QUOTES, 'UTF-8') ?>"
                           download class="btn btn-secondary btn-sm">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </p>
                    <iframe style='width:100%; height:100vw;'
                            src='<?= htmlspecialchars($us_url_root . $asset_base . $document, ENT_QUOTES, 'UTF-8') ?>'
                            title='<?= htmlspecialchars($document, ENT_QUOTES, 'UTF-8') ?>'
                            allowfullscreen></iframe>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="fa fa-info-circle"></i>
                        No document specified.
                    </div>
                    <p>Please <a href="javascript:history.go(-1)">go back</a> and select a document.</p>
                <?php endif; ?>
            </div>
        </div>
    </div> <!-- /.container -->
</div><!-- .page-wrapper -->
<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
