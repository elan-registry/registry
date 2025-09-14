<?php

use PHPUnit\Framework\TestCase;

/**
 * Test to ensure no serialized data remains in form fields
 * 
 * This test validates that the PHP object injection vulnerability
 * has been eliminated by removing all serialize/unserialize usage.
 */
class SerializedDataRemovalTest extends TestCase
{
    private $projectRoot;
    
    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
    }
    
    /**
     * Test that no serialize() function calls exist in PHP files
     */
    public function testNoSerializeFunctionCalls()
    {
        $phpFiles = $this->getPHPFiles();
        $violationFiles = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/\bserialize\s*\(/', $content)) {
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
    public function testNoUnserializeFunctionCalls()
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
     * Test that contact_owner.php uses secure individual fields instead of serialized data
     */
    public function testContactOwnerUsesSecureFields()
    {
        $contactOwnerFile = $this->projectRoot . '/app/contact/owner.php';
        $this->assertFileExists($contactOwnerFile);
        
        $content = file_get_contents($contactOwnerFile);
        
        // Should contain the secure individual field approach
        $this->assertStringContainsString('from_user_id', $content, 'contact_owner.php should use from_user_id field');
        $this->assertStringContainsString('to_user_id', $content, 'contact_owner.php should use to_user_id field');
        
        // Should not contain any serialize calls
        $this->assertStringNotContainsString('serialize(', $content, 'contact_owner.php should not contain serialize() calls');
    }
    
    /**
     * Test that send-owner-email.php uses secure database lookups
     */
    public function testContactOwnerEmailUsesSecureLookups()
    {
        $contactEmailFile = $this->projectRoot . '/app/contact/send-owner-email.php';
        $this->assertFileExists($contactEmailFile);
        
        $content = file_get_contents($contactEmailFile);
        
        // Should use secure database lookup pattern
        $this->assertStringContainsString('SELECT id, email, fname, lname FROM users WHERE id = ?', $content,
            'send-owner-email.php should use secure database lookups');
        
        // Should not contain unserialize calls
        $this->assertStringNotContainsString('unserialize(', $content, 'send-owner-email.php should not contain unserialize() calls');
    }
    
    /**
     * Test that HTML encoding is used for user ID fields
     */
    public function testUserIdFieldsAreHTMLEncoded()
    {
        $contactOwnerFile = $this->projectRoot . '/app/contact/owner.php';
        $content = file_get_contents($contactOwnerFile);
        
        // Should use htmlspecialchars for security
        $this->assertStringContainsString('htmlspecialchars($from[\'id\'], ENT_QUOTES, \'UTF-8\')', $content,
            'from_user_id field should be HTML encoded');
        $this->assertStringContainsString('htmlspecialchars($to[\'id\'], ENT_QUOTES, \'UTF-8\')', $content,
            'to_user_id field should be HTML encoded');
    }
    
    /**
     * Test that CSRF protection is maintained
     */
    public function testCSRFProtectionMaintained()
    {
        $contactOwnerFile = $this->projectRoot . '/app/contact/owner.php';
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