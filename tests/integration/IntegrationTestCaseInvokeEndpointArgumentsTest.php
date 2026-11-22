<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * invokeEndpoint() refuses a $server or $method argument that would give a
 * request other than the one the test asks for. A harness-owned $server key
 * would override the value from $method, $get, or $endpointFile without notice.
 * The helper throws before it starts the subprocess, so no request runs.
 */
#[Group('integration')]
final class IntegrationTestCaseInvokeEndpointArgumentsTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/shared/csrf-token.php';

    /** @return array<string, array{array<mixed>, string, string}> */
    public static function invalidArgumentProvider(): array
    {
        return [
            'harness-owned server key' => [['REQUEST_METHOD' => 'GET'], 'GET', 'Use the $method parameter.'],
            'non-string server value' => [['HTTP_X_TEST' => ['a']], 'GET', "\$server['HTTP_X_TEST'] is array. Use a string value."],
            'unknown method' => [[], 'TRACE', "unknown method 'TRACE'"],
        ];
    }

    /**
     * @param array<mixed> $server
     */
    #[DataProvider('invalidArgumentProvider')]
    public function testInvokeEndpointRejectsInvalidServerOrMethod(array $server, string $method, string $expectedText): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedText);

        $this->invokeEndpoint(self::ENDPOINT, [], null, 'omit', [], $method, $server);
    }
}
