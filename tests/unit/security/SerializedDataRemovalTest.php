<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test to ensure no serialized data remains in form fields
 * 
 * This test validates that the PHP object injection vulnerability
 * has been eliminated by removing all serialize/unserialize usage.
 */
class SerializedDataRemovalTest extends TestCase
{
    private string $projectRoot;
    
    protected function setUp(): void
    {
        $this->projectRoot = dirname(dirname(dirname(__DIR__)));
    }

    /**
     * Test that no serialize() function calls exist in PHP files
     */
    public function testNoSerializeFunctionCalls(): void
    {
        $phpFiles = $this->getPHPFiles();
        $violationFiles = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            // Check for serialize() but exclude jQuery's .serialize() method
            if (preg_match('/\bserialize\s*\(/', $content) && !preg_match('/\.\s*serialize\s*\(/', $content)) {
                $violationFiles[] = $file;
            }
        }
        
        $this->assertEmpty(
            $violationFiles,
            'Found serialize() function calls in: ' . implode(', ', $violationFiles)
        );
    }
    
    /**
     * Test that no unserialize() function calls exist in PHP files
     */
    public function testNoUnserializeFunctionCalls(): void
    {
        $phpFiles = $this->getPHPFiles();
        $violationFiles = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/\bunserialize\s*\(/', $content)) {
                $violationFiles[] = $file;
            }
        }
        
        $this->assertEmpty(
            $violationFiles,
            'Found unserialize() function calls in: ' . implode(', ', $violationFiles)
        );
    }
    
    /**
     * Test that contact_owner.php uses secure individual fields instead of serialized data.
     * The sender (from_user_id) is now derived from the session server-side and must NOT
     * appear as a client-controlled hidden field (#971).
     */
    public function testContactOwnerUsesSecureFields(): void
    {
        $contactOwnerFile = $this->projectRoot . '/app/owner/contact/owner.php';
        $this->assertFileExists($contactOwnerFile);

        $content = file_get_contents($contactOwnerFile);

        // Recipient field must still be present (server cannot know the target without it)
        $this->assertStringContainsString('to_user_id', $content, 'contact_owner.php should pass to_user_id field');

        // from_user_id must NOT appear as a form field — sender is session-derived (#971)
        $this->assertDoesNotMatchRegularExpression(
            '/name=[\'"]from_user_id[\'"]/',
            $content,
            'contact_owner.php must not expose from_user_id as a tamperable form field'
        );

        // Should not contain any serialize calls
        $this->assertStringNotContainsString('serialize(', $content, 'contact_owner.php should not contain serialize() calls');
    }
    
    /**
     * Test that send-owner-email.php uses secure database lookups
     */
    public function testContactOwnerEmailUsesSecureLookups(): void
    {
        $contactEmailFile = $this->projectRoot . '/app/api/contact/send-owner-email.php';
        $this->assertFileExists($contactEmailFile);
        
        $content = file_get_contents($contactEmailFile);
        
        // Should use secure database lookup pattern
        $this->assertStringContainsString('SELECT id, email, fname, lname FROM users WHERE id = ?', $content,
            'send-owner-email.php should use secure database lookups');

        // Sender must be derived from session, not from POST (#971)
        $this->assertStringNotContainsString("Input::get('from_user_id')", $content,
            'send-owner-email.php must not accept from_user_id from POST (#971)');
        $this->assertStringContainsString('$user->data()->id', $content,
            'send-owner-email.php must derive sender identity from session');

        // Should not contain unserialize calls
        $this->assertStringNotContainsString('unserialize(', $content, 'send-owner-email.php should not contain unserialize() calls');
    }
    
    /**
     * Test that HTML encoding is used for the to_user_id field.
     * The from_user_id field was removed (#971) — sender is now session-derived, so
     * there is no client-supplied from field to encode.
     */
    public function testUserIdFieldsAreHTMLEncoded(): void
    {
        $contactOwnerFile = $this->projectRoot . '/app/owner/contact/owner.php';
        $content = file_get_contents($contactOwnerFile);

        $this->assertStringContainsString('htmlspecialchars($to[\'id\'], ENT_QUOTES, \'UTF-8\')', $content,
            'to_user_id field should be HTML encoded');
    }
    
    /**
     * Test that CSRF protection is maintained
     */
    public function testCSRFProtectionMaintained(): void
    {
        $contactOwnerFile = $this->projectRoot . '/app/owner/contact/owner.php';
        $content = file_get_contents($contactOwnerFile);

        // Should maintain CSRF token
        $this->assertStringContainsString('Token::generate()', $content, 'CSRF token should be generated');
        $this->assertStringContainsString('name=\'csrf\'', $content, 'CSRF field should be present');
    }
    
    /**
     * Get all PHP files in the project (excluding vendor and test directories)
     * 
     * @return array Array of PHP file paths
     */
    private function getPHPFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $phpFiles = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                
                // Skip vendor, tests, third-party libraries, and hidden directories
                if (strpos($filePath, '/vendor/') !== false ||
                    strpos($filePath, '/tests/') !== false ||
                    strpos($filePath, '/users/classes/phpmailer/') !== false ||
                    strpos($filePath, '/users/vendor/') !== false ||
                    strpos($filePath, '/.') !== false) {
                    continue;
                }
                
                $phpFiles[] = $filePath;
            }
        }
        
        return $phpFiles;
    }
}