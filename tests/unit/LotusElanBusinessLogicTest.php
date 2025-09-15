<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lotus Elan Registry - Business Logic Tests
 * Tests specific to Lotus Elan chassis validation, series logic, and registry functionality
 */
class LotusElanBusinessLogicTest extends TestCase
{
    public function testChassisNumberValidationFormats(): void
    {
        // Test valid Lotus Elan chassis number formats
        $validChassis = [
            '12345678',      // Standard 8-digit format
            '12345678X',     // 8-digit with suffix
            '1234567890',    // 10-digit format
            'ELAN123456',    // ELAN prefix format
            '26/1234',       // Series format with slash
            '26-1234',       // Series format with dash
        ];
        
        foreach ($validChassis as $chassis) {
            $this->assertTrue($this->isValidLotusElanChassis($chassis), "Chassis '$chassis' should be valid");
        }
    }
    
    public function testChassisNumberValidationInvalid(): void
    {
        // Test invalid chassis numbers
        $invalidChassis = [
            '',              // Empty
            '123',           // Too short
            '12345',         // Too short
            'ABC',           // Non-numeric without proper format
            '123456789012345', // Too long
            'FORD123456',    // Wrong manufacturer
            'ELAN',          // Prefix only
        ];
        
        foreach ($invalidChassis as $chassis) {
            $this->assertFalse($this->isValidLotusElanChassis($chassis), "Chassis '$chassis' should be invalid");
        }
    }
    
    public function testModelYearValidation(): void
    {
        // Lotus Elan production years: 1962-1975, 1989-1995
        $validYears = [1962, 1965, 1970, 1973, 1975, 1989, 1991, 1995];
        $invalidYears = [1961, 1976, 1988, 1996, 2000, 2023];
        
        foreach ($validYears as $year) {
            $this->assertTrue($this->isValidLotusElanYear($year), "Year $year should be valid for Lotus Elan");
        }
        
        foreach ($invalidYears as $year) {
            $this->assertFalse($this->isValidLotusElanYear($year), "Year $year should be invalid for Lotus Elan");
        }
    }
    
    public function testSeriesClassification(): void
    {
        // Test Series 1-4 classification based on year ranges
        $this->assertEquals('Series 1', $this->getElanSeries(1962));
        $this->assertEquals('Series 1', $this->getElanSeries(1964));
        $this->assertEquals('Series 2', $this->getElanSeries(1965));
        $this->assertEquals('Series 3', $this->getElanSeries(1966));
        $this->assertEquals('Series 4', $this->getElanSeries(1969));
        $this->assertEquals('Series 4', $this->getElanSeries(1973));
        $this->assertEquals('M100', $this->getElanSeries(1989));
        $this->assertEquals('M100', $this->getElanSeries(1995));
        
        // Invalid years should return null
        $this->assertNull($this->getElanSeries(1976));
        $this->assertNull($this->getElanSeries(1988));
    }
    
    public function testEngineTypeValidation(): void
    {
        // Valid Lotus Elan engine types
        $validEngines = [
            '1.6L Twin Cam',
            '1.6L DOHC',
            '1558cc',
            '1.6 Turbo',
            'Twin Cam 1600',
        ];
        
        foreach ($validEngines as $engine) {
            $this->assertTrue($this->isValidElanEngine($engine), "Engine '$engine' should be valid");
        }
        
        // Invalid engine types
        $invalidEngines = [
            'V8',
            '2.0L',
            'Diesel',
            '1.0L',
            'Electric',
        ];
        
        foreach ($invalidEngines as $engine) {
            $this->assertFalse($this->isValidElanEngine($engine), "Engine '$engine' should be invalid for Elan");
        }
    }
    
    public function testColorCodeValidation(): void
    {
        // Test Lotus factory color codes
        $validColors = [
            'Lotus White',
            'British Racing Green',
            'Yellow',
            'Red',
            'Blue',
            'Black',
            'Silver',
        ];
        
        foreach ($validColors as $color) {
            $this->assertTrue($this->isValidLotusColor($color), "Color '$color' should be valid");
        }
    }
    
    public function testRegistryNumberGeneration(): void
    {
        // Test that registry numbers follow proper format
        $registryNumber = $this->generateRegistryNumber();
        
        $this->assertMatchesRegularExpression('/^ELR\d{6}$/', $registryNumber);
        $this->assertEquals(9, strlen($registryNumber)); // ELR + 6 digits
        
        // Test uniqueness - generate multiple and ensure they're different
        $numbers = [];
        for ($i = 0; $i < 100; $i++) {
            $number = $this->generateRegistryNumber();
            $this->assertNotContains($number, $numbers, 'Registry numbers should be unique');
            $numbers[] = $number;
        }
    }
    
    public function testVerificationCodeGeneration(): void
    {
        // Test verification codes for new registrations
        $verificationCode = $this->generateVerificationCode();
        
        $this->assertEquals(8, strlen($verificationCode));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $verificationCode);
        
        // Test uniqueness
        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $code = $this->generateVerificationCode();
            $this->assertNotContains($code, $codes, 'Verification codes should be unique');
            $codes[] = $code;
        }
    }
    
    public function testCarStatusValidation(): void
    {
        $validStatuses = ['verified', 'pending', 'unverified', 'needs_verification', 'disputed'];
        $invalidStatuses = ['active', 'inactive', 'deleted', 'sold', 'unknown'];
        
        foreach ($validStatuses as $status) {
            $this->assertTrue($this->isValidCarStatus($status), "Status '$status' should be valid");
        }
        
        foreach ($invalidStatuses as $status) {
            $this->assertFalse($this->isValidCarStatus($status), "Status '$status' should be invalid");
        }
    }
    
    // Helper methods that simulate actual business logic
    
    private function isValidLotusElanChassis(string $chassis): bool
    {
        if (empty($chassis)) return false;
        
        // Standard 8-digit format
        if (preg_match('/^\d{8}[A-Z]?$/', $chassis)) return true;
        
        // 10-digit format
        if (preg_match('/^\d{10}$/', $chassis)) return true;
        
        // ELAN prefix format
        if (preg_match('/^ELAN\d{6}$/', $chassis)) return true;
        
        // Series format with separator
        if (preg_match('/^\d{2}[\/\-]\d{4}$/', $chassis)) return true;
        
        return false;
    }
    
    private function isValidLotusElanYear(int $year): bool
    {
        // Original Elan: 1962-1975
        if ($year >= 1962 && $year <= 1975) return true;
        
        // M100 Elan: 1989-1995
        if ($year >= 1989 && $year <= 1995) return true;
        
        return false;
    }
    
    private function getElanSeries(int $year): ?string
    {
        if (!$this->isValidLotusElanYear($year)) return null;
        
        if ($year >= 1989) return 'M100';
        if ($year >= 1969) return 'Series 4';
        if ($year >= 1966) return 'Series 3';
        if ($year >= 1965) return 'Series 2';
        if ($year >= 1962) return 'Series 1';
        
        return null;
    }
    
    private function isValidElanEngine(string $engine): bool
    {
        $validPatterns = [
            '/1\.6.*twin.*cam/i',
            '/1\.6.*dohc/i',
            '/1558.*cc/i',
            '/1\.6.*turbo/i',
            '/twin.*cam.*1600/i',
        ];
        
        foreach ($validPatterns as $pattern) {
            if (preg_match($pattern, $engine)) return true;
        }
        
        return false;
    }
    
    private function isValidLotusColor(string $color): bool
    {
        $validColors = [
            'lotus white', 'british racing green', 'yellow', 'red', 
            'blue', 'black', 'silver'
        ];
        
        return in_array(strtolower($color), $validColors);
    }
    
    private function generateRegistryNumber(): string
    {
        return 'ELR' . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    private function generateVerificationCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    private function isValidCarStatus(string $status): bool
    {
        $validStatuses = ['verified', 'pending', 'unverified', 'needs_verification', 'disputed'];
        return in_array($status, $validStatuses);
    }
}