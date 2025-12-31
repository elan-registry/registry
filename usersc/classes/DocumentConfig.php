<?php

declare(strict_types=1);

/**
 * Document Configuration and Metadata
 *
 * Centralizes document categories, metadata, and access control configuration
 * for the unified documentation system.
 *
 * @package ElanRegistry\Documentation
 * @version 2.9.0
 * @author Jim Boone
 */

namespace ElanRegistry\Documentation;

class DocumentConfig
{
    /**
     * Get document categories and their configuration
     *
     * @return array Document categories with paths, files, and permissions
     */
    public static function getCategories(): array
    {
        return [
            'faq' => [
                'path' => 'faq/',
                'documents' => [
                    'ADD_CAR_GUIDE.md',
                    'CAR_TRANSFER_USER_GUIDE.md',
                    'CAR_TRANSFER_FAQ.md',
                    'PRIVACY.md',
                    'IDENTIFICATION_GUIDE.md'
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
    }

    /**
     * Get document metadata and display information
     *
     * @return array Document information including titles, icons, and descriptions
     */
    public static function getDocumentInfo(): array
    {
        return [
            // User documents
            'ADD_CAR_GUIDE.md' => [
                'title' => 'How to Add Your Car',
                'icon' => 'fas fa-car',
                'description' => 'Step-by-step guide to register your Lotus Elan or +2',
                'breadcrumb' => 'Add Car Guide',
                'category' => 'faq'
            ],
            'CAR_TRANSFER_USER_GUIDE.md' => [
                'title' => 'Car Transfer User Guide',
                'icon' => 'fas fa-exchange-alt',
                'description' => 'Complete guide for requesting ownership transfers',
                'breadcrumb' => 'User Guide',
                'category' => 'faq'
            ],
            'CAR_TRANSFER_FAQ.md' => [
                'title' => 'Car Transfer FAQ',
                'icon' => 'fas fa-question-circle',
                'description' => 'Frequently asked questions about transfers',
                'breadcrumb' => 'FAQ',
                'category' => 'faq'
            ],
            'PRIVACY.md' => [
                'title' => 'Privacy Policy',
                'icon' => 'fas fa-shield-alt',
                'description' => 'How we protect and use your information',
                'breadcrumb' => 'Privacy Policy',
                'category' => 'faq'
            ],
            'IDENTIFICATION_GUIDE.md' => [
                'title' => 'Lotus Elan Identification Guide',
                'icon' => 'fas fa-search',
                'description' => 'Complete guide to identifying Lotus Elan models and variants',
                'breadcrumb' => 'Identification Guide',
                'category' => 'faq'
            ],

            // Admin documents
            'CAR_TRANSFER_ADMIN_GUIDE.md' => [
                'title' => 'Car Transfer Administrator Guide',
                'icon' => 'fas fa-book',
                'description' => 'Comprehensive administrative procedures',
                'breadcrumb' => 'Admin Guide',
                'category' => 'admin'
            ],
            'CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md' => [
                'title' => 'Car Transfer Quick Reference',
                'icon' => 'fas fa-tachometer-alt',
                'description' => 'Daily admin tasks and quick fixes',
                'breadcrumb' => 'Quick Reference',
                'category' => 'admin'
            ],
            'CAR_TRANSFER_TROUBLESHOOTING.md' => [
                'title' => 'Car Transfer Troubleshooting',
                'icon' => 'fas fa-wrench',
                'description' => 'Systematic diagnostic procedures',
                'breadcrumb' => 'Troubleshooting',
                'category' => 'admin'
            ],
            'DATABASE.md' => [
                'title' => 'Database Schema Documentation',
                'icon' => 'fas fa-database',
                'description' => 'Complete database documentation',
                'breadcrumb' => 'Database Schema',
                'category' => 'admin'
            ],
            'PRD.md' => [
                'title' => 'Product Requirements Document',
                'icon' => 'fas fa-file-contract',
                'description' => 'Feature specifications and requirements',
                'breadcrumb' => 'PRD',
                'category' => 'admin'
            ],
            'EMAIL_STYLING_GUIDELINES.md' => [
                'title' => 'Email Styling Guidelines',
                'icon' => 'fas fa-envelope',
                'description' => 'Email template standards',
                'breadcrumb' => 'Email Guidelines',
                'category' => 'admin'
            ],
            'SPAM_CLEANUP_SYSTEM.md' => [
                'title' => 'Spam Cleanup System',
                'icon' => 'fas fa-broom',
                'description' => 'Automated cleanup system documentation',
                'breadcrumb' => 'Spam Cleanup',
                'category' => 'admin'
            ]
        ];
    }

    /**
     * Validate document access and get document information
     *
     * @param string $doc Document filename
     * @return array|null Document info if valid, null if invalid
     */
    public static function validateDocument(string $doc): ?array
    {
        $categories = self::getCategories();
        $documentInfo = self::getDocumentInfo();

        // Check if document exists in our configuration
        if (!isset($documentInfo[$doc])) {
            return null;
        }

        $info = $documentInfo[$doc];
        $category = $info['category'];

        // Verify document is in the correct category
        if (!isset($categories[$category]) || !in_array($doc, $categories[$category]['documents'])) {
            return null;
        }

        return [
            'info' => $info,
            'category' => $category,
            'categoryConfig' => $categories[$category],
            'path' => __DIR__ . '/../../docs/' . $categories[$category]['path'] . $doc
        ];
    }

    /**
     * Check if user has permission to access a document
     *
     * @param array $documentData Document data from validateDocument()
     * @param object $user UserSpice user object
     * @return bool True if user has access
     */
    public static function hasAccess(array $documentData, object $user): bool
    {
        if (!$documentData['categoryConfig']['requiresAdmin']) {
            return true; // Public document
        }

        // Check admin permissions (Administrator=2, Editor=3)
        if (!$user->isLoggedIn()) {
            return false;
        }

        return isRegistryAdmin($user->data()->id);
    }

    /**
     * Get breadcrumb navigation for a document
     *
     * @param array $documentData Document data from validateDocument()
     * @param string $usUrlRoot Application URL root
     * @return array Breadcrumb items
     */
    public static function getBreadcrumb(array $documentData, string $usUrlRoot): array
    {
        $breadcrumb = [
            ['url' => $usUrlRoot, 'icon' => 'fas fa-home', 'text' => 'Registry'],
            ['url' => 'faq/index.php', 'icon' => 'fas fa-question-circle', 'text' => 'FAQ']
        ];

        if ($documentData['category'] === 'admin') {
            $breadcrumb[] = ['url' => 'faq/admin/index.php', 'icon' => 'fas fa-tools', 'text' => 'Admin Docs'];
        }

        $breadcrumb[] = ['text' => $documentData['info']['breadcrumb'], 'active' => true];

        return $breadcrumb;
    }
}