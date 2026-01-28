<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\DocumentConfig;

/**
 * DocumentConfig class tests
 *
 * Tests document configuration, validation, and access control.
 * CRITICAL: Access control for admin-only documents.
 *
 * @group fast
 * @group security
 */
final class DocumentConfigTest extends TestCase
{
    // ============================================================
    // CATEGORY CONFIGURATION TESTS
    // ============================================================

    public function testGetCategoriesReturnsFaqCategory(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertArrayHasKey('faq', $categories);
        $this->assertFalse($categories['faq']['requiresAdmin']);
    }

    public function testGetCategoriesReturnsAdminCategory(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertArrayHasKey('admin', $categories);
        $this->assertTrue($categories['admin']['requiresAdmin']);
    }

    public function testFaqCategoryHasDocuments(): void
    {
        $categories = DocumentConfig::getCategories();

        $this->assertNotEmpty($categories['faq']['documents']);
        $this->assertContains('CAR_TRANSFER_USER_GUIDE.md', $categories['faq']['documents']);
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

    public function testUserDocumentsHaveFaqCategory(): void
    {
        $info = DocumentConfig::getDocumentInfo();
        $userDocs = ['CAR_TRANSFER_USER_GUIDE.md', 'CAR_TRANSFER_FAQ.md', 'PRIVACY.md'];

        foreach ($userDocs as $doc) {
            $this->assertEquals('faq', $info[$doc]['category']);
        }
    }

    public function testAdminDocumentsHaveAdminCategory(): void
    {
        $info = DocumentConfig::getDocumentInfo();
        $adminDocs = ['CAR_TRANSFER_ADMIN_GUIDE.md', 'DATABASE.md', 'PRD.md'];

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

        $this->assertEquals('faq', $userDoc['category']);
        $this->assertEquals('admin', $adminDoc['category']);
    }

    public function testValidateDocumentPathContainsCorrectDirectory(): void
    {
        $userDoc = DocumentConfig::validateDocument('CAR_TRANSFER_USER_GUIDE.md');
        $adminDoc = DocumentConfig::validateDocument('CAR_TRANSFER_ADMIN_GUIDE.md');

        $this->assertStringContainsString('docs/faq/', $userDoc['path']);
        $this->assertStringContainsString('docs/faq/admin/', $adminDoc['path']);
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
            'category' => 'faq',
            'info' => ['breadcrumb' => 'User Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        $this->assertEquals('Registry', $breadcrumb[0]['text']);
        $this->assertStringContainsString('home', $breadcrumb[0]['icon']);
    }

    public function testGetBreadcrumbIncludesFaq(): void
    {
        $documentData = [
            'category' => 'faq',
            'info' => ['breadcrumb' => 'User Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        $this->assertEquals('FAQ', $breadcrumb[1]['text']);
    }

    public function testGetBreadcrumbAddsAdminLevelForAdminDocs(): void
    {
        $documentData = [
            'category' => 'admin',
            'info' => ['breadcrumb' => 'Admin Guide']
        ];

        $breadcrumb = DocumentConfig::getBreadcrumb($documentData, '/');

        // Should have: Home > FAQ > Admin Docs > Document
        $this->assertCount(4, $breadcrumb);
        $this->assertEquals('Admin Docs', $breadcrumb[2]['text']);
    }

    public function testGetBreadcrumbLastItemIsActive(): void
    {
        $documentData = [
            'category' => 'faq',
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
