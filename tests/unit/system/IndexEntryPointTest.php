<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The home page must send no bytes before its opening tag. A BOM or a blank
 * line ahead of `<?php` is output before the headers, so the security headers
 * and cookies set later cannot be sent.
 *
 * @see index.php
 */
#[Group('fast')]
#[Group('unit')]
final class IndexEntryPointTest extends TestCase
{
    public function test_indexPhpStartsWithOpeningTagAndNoLeadingBytes(): void
    {
        $path = __DIR__ . '/../../../index.php';
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $this->assertSame(
            '<?php',
            substr($source, 0, 5),
            'index.php must start with <?php: no BOM, whitespace or other leading bytes'
        );
    }
}
