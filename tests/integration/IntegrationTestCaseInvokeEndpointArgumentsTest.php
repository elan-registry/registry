<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * invokeEndpoint() refuses a $server, $method, $post, or $csrfMode argument
 * that would give a request other than the one the test asks for. A
 * harness-owned $server key would override the value from $method, $get, or
 * $endpointFile without notice. A body on a GET would let a test pass where a
 * browser GET is refused.
 * The helper throws before it starts the subprocess, so no request runs.
 */
#[Group('integration')]
final class IntegrationTestCaseInvokeEndpointArgumentsTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/shared/csrf-token.php';

    /** @return array<string, array{array<mixed>, string, array<string, string>, string, string}> */
    public static function invalidArgumentProvider(): array
    {
        $noBody = 'a GET request has no body';

        return [
            'harness-owned server key' => [['REQUEST_METHOD' => 'GET'], 'GET', [], 'omit', 'Use the $method parameter.'],
            'non-string server value' => [['HTTP_X_TEST' => ['a']], 'GET', [], 'omit', "\$server['HTTP_X_TEST'] is array. Use a string value."],
            'unknown method' => [[], 'TRACE', [], 'omit', "unknown method 'TRACE'"],
            'GET with a CSRF token' => [[], 'GET', [], 'valid', $noBody],
            'GET with POST fields' => [[], 'GET', ['field' => 'x'], 'omit', $noBody],
        ];
    }

    /**
     * @param array<mixed> $server
     * @param array<string, string> $post
     */
    #[DataProvider('invalidArgumentProvider')]
    public function testInvokeEndpointRejectsInvalidRequestArguments(array $server, string $method, array $post, string $csrfMode, string $expectedText): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedText);

        $this->invokeEndpoint(self::ENDPOINT, $post, null, $csrfMode, [], $method, $server);
    }
}
