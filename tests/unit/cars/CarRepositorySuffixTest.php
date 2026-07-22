<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarRepository::suffixToText()
 */
#[Group('fast')]
final class CarRepositorySuffixTest extends TestCase
{
    public function testSuffixToTextReturnsCorrectDescriptionForA(): void
    {
        $this->assertEquals('S4 FHC UK Market', CarRepository::suffixToText('A'));
    }

    public function testSuffixToTextReturnsCorrectDescriptionForB(): void
    {
        $this->assertEquals('S4 FHC Export', CarRepository::suffixToText('B'));
    }

    public function testSuffixToTextHandlesLowercase(): void
    {
        $this->assertEquals('S4 FHC UK Market', CarRepository::suffixToText('a'));
    }

    public function testSuffixToTextReturnsUnknownForInvalidSuffix(): void
    {
        $this->assertEquals('Unknown suffix: Z', CarRepository::suffixToText('Z'));
        $this->assertEquals('Unknown suffix: X', CarRepository::suffixToText('x'));
    }

    public function testSuffixToTextAllValidSuffixes(): void
    {
        $expected = [
            'A' => 'S4 FHC UK Market',
            'B' => 'S4 FHC Export',
            'C' => 'S4 DHC UK Market',
            'D' => 'S4 DHC Export',
            'E' => 'S4 S/E FHC UK Market',
            'F' => 'S4 S/E FHC Export',
            'G' => 'S4 S/E DHC UK Market',
            'H' => 'S4 S/E DHC Export',
            'J' => 'S4 FHC Federal',
            'K' => 'S4 DHC Federal',
            'L' => '+2S and +2S/130 UK Market',
            'M' => '+2S and +2S/130 Export',
            'N' => '+2S and +2S/130 Federal',
        ];

        foreach ($expected as $suffix => $description) {
            $this->assertEquals($description, CarRepository::suffixToText($suffix), "Failed for suffix: $suffix");
        }
    }
}
