<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\DocumentConfig;

use PHPUnit\Framework\Attributes\Group;

/**
 * DocumentConfig class tests
 *
 * Tests document configuration, validation, and access control.
 * CRITICAL: Access control for admin-only documents.
 */
#[Group('fast')]
#[Group('security')]
final class DocumentConfigTest extends TestCase
{
    // ============================================================
    // CATEGORY CONFIGURATION TESTS
    // ============================================================

    public function testGetCategoriesReturnsGuidesCategory(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertArrayHasKey('guides', $categories);
        $this->assertFalse($categories['guides']['requiresAdmin']);
    }

    public function testGetCategoriesReturnsReferenceCategory(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertArrayHasKey('reference', $categories);
        $this->assertFalse($categories['reference']['requiresAdmin']);
    }

    public function testGetCategoriesReturnsAdminCategory(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertArrayHasKey('admin', $categories);
        $this->assertTrue($categories['admin']['requiresAdmin']);
    }

    public function testGuidesCategoryHasDocuments(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertNotEmpty($categories['guides']['documents']);
        $this->assertContains('CAR_TRANSFER_USER_GUIDE.md', $categories['guides']['documents']);
    }

    public function testReferenceCategoryHasDocuments(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertNotEmpty($categories['reference']['documents']);
        $this->assertContains('IDENTIFICATION_GUIDE.md', $categories['reference']['documents']);
    }

    public function testAdminCategoryHasDocuments(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertNotEmpty($categories['admin']['documents']);
        $this->assertContains('CAR_TRANSFER_ADMIN_GUIDE.md', $categories['admin']['documents']);
    }

    // ============================================================
    // DOCUMENT INFO TESTS
    // ============================================================

    public function testGetDocumentInfoReturnsAllDocuments(): void
    {
        $info = DocumentConfig::getDocumentInfo();

        $this->assertNotEmpty($info);
        $this->assertArrayHasKey('CAR_TRANSFER_USER_GUIDE.md', $info);
        $this->assertArrayHasKey('CAR_TRANSFER_ADMIN_GUIDE.md', $info);
    }

    public function testDocumentInfoHasRequiredFields(): void
    {
        $info = DocumentConfig::getDocumentInfo();

        foreach ($info as $doc => $data) {
            $this->assertArrayHasKey('title', $data, "Document $doc missing title");
            $this->assertArrayHasKey('icon', $data, "Document $doc missing icon");
            $this->assertArrayHasKey('description', $data, "Document $doc missing description");
            $this->assertArrayHasKey('breadcrumb', $data, "Document $doc missing breadcrumb");
            $this->assertArrayHasKey('category', $data, "Document $doc missing category");
        }
    }

    public function testUserDocumentsHaveGuidesCategory(): void
    {
        $info = DocumentConfig::getDocumentInfo();
        $userDocs = ['CAR_TRANSFER_USER_GUIDE.md', 'CAR_TRANSFER_FAQ.md', 'PRIVACY.md'];

        foreach ($userDocs as $doc) {
            $this->assertEquals('guides', $info[$doc]['category']);
        }
    }

    public function testIdentificationGuideHasReferenceCategory(): void
    {
        $info = DocumentConfig::getDocumentInfo();

        $this->assertEquals('reference', $info['IDENTIFICATION_GUIDE.md']['category']);
    }

    public function testAdminDocumentsHaveAdminCategory(): void
    {
        $info = DocumentConfig::getDocumentInfo();
        $adminDocs = ['CAR_TRANSFER_ADMIN_GUIDE.md', 'DATABASE.md'];

        foreach ($adminDocs as $doc) {
            $this->assertEquals('admin', $info[$doc]['category']);
        }
    }

    // ============================================================
    // DOCUMENT VALIDATION TESTS (SECURITY CRITICAL)
    // ============================================================

    public function testValidateDocumentReturnsNullForUnknownDocument(): void
    {
        $result = DocumentConfig::validateDocument('NONEXISTENT.md');

        $this->assertNull($result);
    }

    public function testValidateDocumentReturnsNullForEmptyString(): void
    {
        $result = DocumentConfig::validateDocument('');

        $this->assertNull($result);
    }

    public function testValidateDocumentReturnsInfoForValidDocument(): void
    {
        $result = DocumentConfig::validateDocument('CAR_TRANSFER_USER_GUIDE.md');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('categoryConfig', $result);
        $this->assertArrayHasKey('path', $result);
    }

    public function testValidateDocumentReturnsCorrectCategory(): void
    {
        $userDoc = DocumentConfig::validateDocument('CAR_TRANSFER_USER_GUIDE.md');
        $adminDoc = DocumentConfig::validateDocument('CAR_TRANSFER_ADMIN_GUIDE.md');
        $refDoc = DocumentConfig::validateDocument('IDENTIFICATION_GUIDE.md');

        $this->assertEquals('guides', $userDoc['category']);
        $this->assertEquals('admin', $adminDoc['category']);
        $this->assertEquals('reference', $refDoc['category']);
    }

    public function testValidateDocumentPathContainsCorrectDirectory(): void
    {
        $userDoc = DocumentConfig::validateDocument('CAR_TRANSFER_USER_GUIDE.md');
        $adminDoc = DocumentConfig::validateDocument('CAR_TRANSFER_ADMIN_GUIDE.md');
        $refDoc = DocumentConfig::validateDocument('IDENTIFICATION_GUIDE.md');

        $this->assertStringContainsString('docs/guides/', $userDoc['path']);
        $this->assertStringContainsString('docs/admin/', $adminDoc['path']);
        $this->assertStringContainsString('docs/reference/', $refDoc['path']);
    }

    // ============================================================
    // ACCESS CONTROL TESTS (SECURITY CRITICAL)
    // ============================================================

    public function testHasAccessAllowsPublicDocumentsForAnyUser(): void
    {
        $documentData = [
            'categoryConfig' => ['requiresAdmin' => false]
        ];

        // Create mock user (not logged in)
        $user = $this->createMockUser(false, false);

        $this->assertTrue(DocumentConfig::hasAccess($documentData, $user));
    }

    public function testHasAccessDeniesAdminDocsForNonLoggedIn(): void
    {
        $documentData = [
            'categoryConfig' => ['requiresAdmin' => true]
        ];

        $user = $this->createMockUser(false, false);

        $this->assertFalse(DocumentConfig::hasAccess($documentData, $user));
    }

    public function testHasAccessDeniesAdminDocsForNonAdmin(): void
    {
        $documentData = [
            'categoryConfig' => ['requiresAdmin' => true]
        ];

        // Logged in but not admin - mock will return false for isRegistryAdmin
        $user = $this->createMockUser(true, false);

        $this->assertFalse(DocumentConfig::hasAccess($documentData, $user));
    }

    public function testHasAccessAllowsAdminDocsForAdmin(): void
    {
        $documentData = [
            'categoryConfig' => ['requiresAdmin' => true]
        ];

        // Logged in admin
        $user = $this->createMockUser(true, true);

        $this->assertTrue(DocumentConfig::hasAccess($documentData, $user));
    }

    // ============================================================
    // BREADCRUMB TESTS
    // ============================================================

    public function testGetBreadcrumbIncludesHome(): void
    {
        $documentData = [
            'category' => 'guides',
            'info' => ['breadcrumb' => 'User Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        $this->assertEquals('Registry', $breadcrumb[0]['text']);
        $this->assertStringContainsString('home', $breadcrumb[0]['icon']);
    }

    public function testGetBreadcrumbIncludesOwnerGuides(): void
    {
        $documentData = [
            'category' => 'guides',
            'info' => ['breadcrumb' => 'User Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        $this->assertEquals('Owner Guides', $breadcrumb[1]['text']);
        $this->assertEquals('guides/index.php', $breadcrumb[1]['url']);
    }

    public function testGetBreadcrumbAddsAdminLevelForAdminDocs(): void
    {
        $documentData = [
            'category' => 'admin',
            'info' => ['breadcrumb' => 'Admin Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        // Should have: Home > Owner Guides > Admin Docs > Document
        $this->assertCount(4, $breadcrumb);
        $this->assertEquals('Admin Docs', $breadcrumb[2]['text']);
        $this->assertEquals('admin/index.php', $breadcrumb[2]['url']);
    }

    public function testGetBreadcrumbLastItemIsActive(): void
    {
        $documentData = [
            'category' => 'guides',
            'info' => ['breadcrumb' => 'User Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        $lastItem = end($breadcrumb);
        $this->assertTrue($lastItem['active']);
        $this->assertEquals('User Guide', $lastItem['text']);
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function createMockUser(bool $isLoggedIn, bool $isAdmin): object
    {
        $user = new class($isLoggedIn, $isAdmin) {
            private bool $loggedIn;
            private bool $admin;
            private object $userData;

            public function __construct(bool $loggedIn, bool $admin)
            {
                $this->loggedIn = $loggedIn;
                $this->admin = $admin;
                $this->userData = (object) ['id' => $admin ? 1 : 999];

                // Set global for isRegistryAdmin function
                global $mockIsRegistryAdmin;
                $mockIsRegistryAdmin = $admin;
            }

            public function isLoggedIn(): bool
            {
                return $this->loggedIn;
            }

            public function data(): object
            {
                return $this->userData;
            }
        };

        return $user;
    }
}
