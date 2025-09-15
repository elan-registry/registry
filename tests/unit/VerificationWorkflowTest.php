<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verification Workflow Tests
 * Tests the car verification process specific to the Lotus Elan Registry
 */
class VerificationWorkflowTest extends TestCase
{
    public function testVerificationCodeGenerationAndValidation(): void
    {
        // Generate a verification code
        $code = $this->generateVerificationCode();
        
        // Should be 8 characters, alphanumeric
        $this->assertEquals(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
        
        // Should validate correctly
        $this->assertTrue($this->isValidVerificationCode($code));
        
        // Invalid codes should fail
        $this->assertFalse($this->isValidVerificationCode(''));
        $this->assertFalse($this->isValidVerificationCode('12345'));
        $this->assertFalse($this->isValidVerificationCode('invalidcode'));
    }
    
    public function testVerificationEmailGeneration(): void
    {
        $carData = [
            'year' => 1973,
            'model' => 'Lotus Elan',
            'chassis' => '12345678',
            'owner_email' => 'test@example.com'
        ];
        
        $verificationCode = 'ABC12345';
        $emailContent = $this->generateVerificationEmail($carData, $verificationCode);
        
        // Email should contain key information
        $this->assertStringContainsString('1973', $emailContent);
        $this->assertStringContainsString('Lotus Elan', $emailContent);
        $this->assertStringContainsString('12345678', $emailContent);
        $this->assertStringContainsString('ABC12345', $emailContent);
        $this->assertStringContainsString('elanregistry.org', $emailContent);
    }
    
    public function testVerificationLinkGeneration(): void
    {
        $carId = 123;
        $verificationCode = 'ABC12345';
        
        $link = $this->generateVerificationLink($carId, $verificationCode);
        
        $expectedPattern = '/https?:\/\/.*elanregistry\.org.*verify.*car_id=123.*code=ABC12345/';
        $this->assertMatchesRegularExpression($expectedPattern, $link);
    }
    
    public function testVerificationStatusTransitions(): void
    {
        // Test valid status transitions
        $validTransitions = [
            'unverified' => ['pending', 'verified'],
            'pending' => ['verified', 'needs_verification', 'disputed'],
            'verified' => ['needs_verification', 'disputed'],
            'needs_verification' => ['pending', 'verified'],
            'disputed' => ['pending', 'verified', 'needs_verification']
        ];
        
        foreach ($validTransitions as $currentStatus => $allowedNext) {
            foreach ($allowedNext as $nextStatus) {
                $this->assertTrue(
                    $this->isValidStatusTransition($currentStatus, $nextStatus),
                    "Transition from '$currentStatus' to '$nextStatus' should be valid"
                );
            }
        }
        
        // Test invalid transitions
        $this->assertFalse($this->isValidStatusTransition('verified', 'unverified'));
        $this->assertFalse($this->isValidStatusTransition('pending', 'unverified'));
    }
    
    public function testVerificationRequirements(): void
    {
        // Test minimum requirements for verification
        $validCarData = [
            'year' => 1973,
            'model' => 'Lotus Elan',
            'chassis' => '12345678',
            'engine' => '1.6L Twin Cam',
            'color' => 'British Racing Green',
            'images' => ['front.jpg', 'rear.jpg', 'engine.jpg'],
            'owner_email' => 'owner@example.com'
        ];
        
        $this->assertTrue($this->meetsVerificationRequirements($validCarData));
        
        // Test missing required fields
        $incompleteData = $validCarData;
        unset($incompleteData['chassis']);
        $this->assertFalse($this->meetsVerificationRequirements($incompleteData));
        
        $incompleteData = $validCarData;
        $incompleteData['images'] = ['front.jpg']; // Not enough images
        $this->assertFalse($this->meetsVerificationRequirements($incompleteData));
    }
    
    public function testVerificationDocumentValidation(): void
    {
        // Test document types accepted for verification
        $validDocuments = [
            'registration.pdf',
            'title_document.jpg',
            'ownership_papers.png',
            'previous_registration.pdf'
        ];
        
        foreach ($validDocuments as $doc) {
            $this->assertTrue($this->isValidVerificationDocument($doc));
        }
        
        $invalidDocuments = [
            'random_file.txt',
            'document.exe',
            'suspicious.php',
            'script.js'
        ];
        
        foreach ($invalidDocuments as $doc) {
            $this->assertFalse($this->isValidVerificationDocument($doc));
        }
    }
    
    public function testChassisNumberUniquenessValidation(): void
    {
        $testChassis = '12345678';
        
        // Mock database check - chassis not in use
        $this->assertTrue($this->isChassisNumberUnique($testChassis, []));
        
        // Mock database check - chassis already exists
        $existingChassis = ['12345678', '87654321', '11111111'];
        $this->assertFalse($this->isChassisNumberUnique($testChassis, $existingChassis));
        
        // Different chassis should be unique
        $this->assertTrue($this->isChassisNumberUnique('99999999', $existingChassis));
    }
    
    public function testVerificationEmailThrottling(): void
    {
        $carId = 123;
        $emails = [];
        
        // First email should be allowed
        $this->assertTrue($this->canSendVerificationEmail($carId, $emails));
        
        // Add recent email to history
        $emails[] = [
            'car_id' => $carId,
            'sent_at' => time() - 1800, // 30 minutes ago
            'type' => 'verification'
        ];
        
        // Should not allow another email too soon
        $this->assertFalse($this->canSendVerificationEmail($carId, $emails));
        
        // Test with only old emails (clear recent ones)
        $oldEmails = [
            [
                'car_id' => $carId,
                'sent_at' => time() - 7200, // 2 hours ago
                'type' => 'verification'
            ]
        ];
        
        // Should allow after sufficient time
        $this->assertTrue($this->canSendVerificationEmail($carId, $oldEmails));
    }
    
    // Helper methods simulating business logic
    
    private function generateVerificationCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    private function isValidVerificationCode(string $code): bool
    {
        return strlen($code) === 8 && preg_match('/^[A-Z0-9]{8}$/', $code);
    }
    
    private function generateVerificationEmail(array $carData, string $code): string
    {
        return "Dear Owner,\n\n" .
               "Please verify your {$carData['year']} {$carData['model']} registration.\n" .
               "Chassis: {$carData['chassis']}\n" .
               "Verification Code: {$code}\n" .
               "Visit: https://elanregistry.org/verify?code={$code}\n\n" .
               "Best regards,\nElan Registry Team";
    }
    
    private function generateVerificationLink(int $carId, string $code): string
    {
        return "https://elanregistry.org/app/verify/index.php?car_id={$carId}&code={$code}";
    }
    
    private function isValidStatusTransition(string $current, string $next): bool
    {
        $transitions = [
            'unverified' => ['pending', 'verified'],
            'pending' => ['verified', 'needs_verification', 'disputed'],
            'verified' => ['needs_verification', 'disputed'],
            'needs_verification' => ['pending', 'verified'],
            'disputed' => ['pending', 'verified', 'needs_verification']
        ];
        
        return isset($transitions[$current]) && in_array($next, $transitions[$current]);
    }
    
    private function meetsVerificationRequirements(array $carData): bool
    {
        $required = ['year', 'model', 'chassis', 'owner_email'];
        
        foreach ($required as $field) {
            if (empty($carData[$field])) return false;
        }
        
        // Must have at least 3 images
        if (empty($carData['images']) || count($carData['images']) < 3) {
            return false;
        }
        
        return true;
    }
    
    private function isValidVerificationDocument(string $filename): bool
    {
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return in_array($extension, $allowedExtensions);
    }
    
    private function isChassisNumberUnique(string $chassis, array $existingChassis): bool
    {
        return !in_array($chassis, $existingChassis);
    }
    
    private function canSendVerificationEmail(int $carId, array $emailHistory): bool
    {
        $recentEmails = array_filter($emailHistory, function($email) use ($carId) {
            return $email['car_id'] === $carId && 
                   $email['type'] === 'verification' &&
                   $email['sent_at'] > (time() - 3600); // Within last hour
        });
        
        return count($recentEmails) === 0;
    }
}