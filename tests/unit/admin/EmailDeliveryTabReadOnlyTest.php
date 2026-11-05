<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #2102: the Email Delivery tab and its classes only read.
 *
 * The tab never writes plg_sendinblue_messages and never calls Brevo, so no
 * source file of the feature holds a write keyword or a Brevo client. This is
 * a source scan, because a write or an API call would need a live database or
 * a live Brevo account to catch at run time. The behavior side is in
 * DeliveryLogRepositoryIntegrationTest (the table is unchanged after a read).
 *
 * @issue 2102
 */
#[Group('fast')]
final class EmailDeliveryTabReadOnlyTest extends TestCase
{
    /**
     * Patterns that mean a Brevo call: the plugin's functions (sendinblueDeliveryStatus()
     * and its siblings), any Brevo* class of the project, or a raw HTTP call.
     * The text "sendinblue" alone is allowed, because the table is plg_sendinblue_messages.
     *
     * @var array<string, string>
     */
    private const BREVO_PATTERNS = [
        'sendinblueDeliveryStatus' => '/sendinblueDeliveryStatus/i',
        'a sendinblue function call' => '/\bsendinblue[A-Za-z]*\s*\(/i',
        'a Brevo class' => '/\bBrevo[A-Z]\w*/',
        'curl' => '/\bcurl_(init|exec)\b/',
        'file_get_contents of a URL' => '/file_get_contents\s*\(\s*[\'"]https?:/i',
    ];

    /** @var list<string> SQL write keywords, matched as whole words in any case. */
    private const SQL_WRITE_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    /** @return list<string> The files of the feature */
    private static function files(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [$root . '/app/admin/includes/tab-email_delivery.php'];
        foreach (['DeliveryLog*.php', 'DeliveryOutcome*.php'] as $pattern) {
            $found = glob($root . '/usersc/classes/Email/' . $pattern);
            self::assertIsArray($found);
            $files = [...$files, ...$found];
        }

        return $files;
    }

    /** @return array<string, array{string}> */
    public static function fileProvider(): array
    {
        $cases = [];
        foreach (self::files() as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    /**
     * Strip PHP comments, so a comment that says "never writes" does not match.
     */
    private static function code(string $file): string
    {
        $source = (string) file_get_contents($file);
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testFeatureFilesAreFound(): void
    {
        $names = array_map('basename', self::files());

        // A scan over no files passes every check, so name the files it must cover.
        foreach ([
            'tab-email_delivery.php',
            'DeliveryLogRepository.php',
            'DeliveryLogReport.php',
            'DeliveryLogFilter.php',
            'DeliveryLogEntry.php',
            'DeliveryOutcome.php',
            'DeliveryOutcomeResolver.php',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    #[DataProvider('fileProvider')]
    public function testFileHasNoSqlWriteKeyword(string $file): void
    {
        $this->assertFileExists($file);
        $code = self::code($file);

        foreach (self::SQL_WRITE_KEYWORDS as $keyword) {
            $this->assertSame(
                0,
                preg_match('/\b' . $keyword . '\b/i', $code),
                basename($file) . " must not contain {$keyword}"
            );
        }
    }

    #[DataProvider('fileProvider')]
    public function testFileHasNoBrevoCall(string $file): void
    {
        $this->assertFileExists($file);
        $code = self::code($file);

        foreach (self::BREVO_PATTERNS as $label => $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), basename($file) . " must not use {$label}");
        }
    }

    #[DataProvider('fileProvider')]
    public function testFileCallsNoDatabaseWriteMethod(string $file): void
    {
        $code = self::code($file);

        $this->assertSame(
            0,
            preg_match('/->\s*(insert|update|delete)\s*\(/i', $code),
            basename($file) . ' must not call a database write method'
        );
    }
}
