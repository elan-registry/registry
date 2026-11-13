<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #2246: the real Apache and the real .htaccess must send
 * `X-Robots-Tag: noindex` for the Test host only. The request goes to
 * http://localhost/ with a `Host:` header, so no DNS or network is needed.
 * Run inside the app container. Outside it, the test skips and
 * Issue2246TestHostNoindexRegressionTest still covers the .htaccess rule.
 */
#[Group('integration')]
#[Group('security')]
final class TestHostNoindexHeaderTest extends TestCase
{
    private const BASE = 'http://localhost';

    private static ?string $unreachableReason = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!function_exists('curl_init')) {
            self::$unreachableReason = 'ext-curl is not available';
            return;
        }

        $probe = self::request('/', 'localhost');
        if ($probe === null) {
            self::$unreachableReason = 'Apache at ' . self::BASE . '/ is not reachable';
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>}|null null when the request fails
     */
    private static function request(string $path, string $host): ?array
    {
        $ch = curl_init(self::BASE . $path);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            return null;
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerBlock = substr($raw, 0, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE));

        $headers = [];
        foreach (preg_split('/\r\n/', $headerBlock) ?: [] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: bool}>
     */
    public static function hostPathProvider(): array
    {
        $cases = [];
        // /stories/x is a RewriteRule 301. LiteSpeed omits the header on mod_alias
        // (Redirect, RedirectMatch) 301s, so those are not a cross-server case.
        $paths = ['/' => 200, '/robots.txt' => 200, '/stories/x' => 301, '/no-such-page-2246' => 404];
        $hosts = ['test.elanregistry.org' => true, 'elanregistry.org' => false, 'localhost' => false];

        foreach ($hosts as $host => $expectNoindex) {
            foreach ($paths as $path => $status) {
                $cases[$host . ' ' . $path] = [$host, $path, $status, $expectNoindex];
            }
        }

        return $cases;
    }

    #[DataProvider('hostPathProvider')]
    public function testRobotsTagIsSentOnlyForTestHost(string $host, string $path, int $expectedStatus, bool $expectNoindex): void
    {
        if (self::$unreachableReason !== null) {
            $this->markTestSkipped(
                self::$unreachableReason . '. Issue2246TestHostNoindexRegressionTest covers the .htaccess rule without a skip.'
            );
        }

        $response = self::request($path, $host);
        $this->assertNotNull($response, "Request to {$path} with Host {$host} failed");
        $this->assertSame($expectedStatus, $response['status'], "Status for {$host}{$path}");

        if ($expectNoindex) {
            $this->assertArrayHasKey('x-robots-tag', $response['headers'], "{$host}{$path} must send X-Robots-Tag");
            $this->assertStringContainsString('noindex', $response['headers']['x-robots-tag']);
        } else {
            $this->assertArrayNotHasKey('x-robots-tag', $response['headers'], "{$host}{$path} must not send X-Robots-Tag");
        }
    }
}
