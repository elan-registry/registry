<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\ChassisValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the car history and chassis validation endpoints' wiring.
 *
 * Covers:
 * - app/api/cars/history.php      (routing, security and logging wiring)
 * - app/api/cars/chassis-validate.php (wiring plus ChassisValidator behaviour)
 *
 * Neither endpoint file can be require()'d from PHPUnit: every response path
 * ends in ApiResponse::send(), which calls exit and would terminate the test
 * runner. Routing, security and logging wiring (CSRF guard, AJAX-only guard,
 * parameter guards, catch blocks, log categories) cannot be exercised without
 * an HTTP request, so those are asserted against the endpoint file's source
 * text. ChassisValidator is exercised directly because it is pure validation
 * logic with no database or framework dependency.
 *
 * The database-backed behavioural assertions for history.php (Car::exists() /
 * Car::history()) live in tests/integration/CarActionsHistoryAndValidationTest.php.
 *
 * @author Elan Registry Development Team
 */
#[Group('fast')]
#[Group('unit')]
#[Group('car-actions')]
final class CarActionsHistoryAndValidationWiringTest extends TestCase
{
    /** Endpoint paths, relative to the repository root. */
    private const HISTORY_ENDPOINT = 'app/api/cars/history.php';
    private const CHASSIS_ENDPOINT = 'app/api/cars/chassis-validate.php';

    /** Model string in the "series|variant|type" format ChassisValidator parses. */
    private const VALID_MODEL = 'S4|SE|FHC';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Read an endpoint's source text for wiring assertions.
     *
     * @param string $relativePath Path relative to the repository root
     * @return string The endpoint file contents
     */
    private function readEndpointSource(string $relativePath): string
    {
        $filePath = __DIR__ . '/../../../' . $relativePath;
        $this->assertFileExists($filePath, "Endpoint file must exist: {$relativePath}");

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "Endpoint file must be readable: {$relativePath}");

        return $content;
    }

    // =========================================================================
    // history.php — wiring (source inspection)
    // =========================================================================

    /**
     * history.php admits only POST requests carrying a payload, then gates
     * further processing on the `car_history` rate limit rather than a CSRF
     * token.
     *
     * Per ADR-019, history.php is public and read-only, so it deliberately
     * carries no CSRF check — abuse is bounded by rate limiting instead.
     *
     * Source inspection: all guards read request state ($method, $_POST, the
     * rate-limit bucket) that only exists during a real HTTP request.
     */
    public function testHistoryRequiresPostMethodAndRateLimit(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        $this->assertStringContainsString(
            "if (\$method !== 'POST')",
            $content,
            'Endpoint must reject any request method other than POST'
        );
        $this->assertStringContainsString(
            "ApiResponse::error('Method not allowed', 405)->send()",
            $content,
            'A non-POST request must return HTTP 405'
        );

        $this->assertStringContainsString(
            'if (!Input::existsPost())',
            $content,
            'Endpoint must reject a POST with no payload'
        );
        $this->assertStringContainsString(
            "ApiResponse::error('No data received')->send()",
            $content,
            'An empty POST must return an ApiResponse error'
        );

        // Per ADR-019: public, read-only endpoints are rate-limited instead of
        // CSRF-gated. This endpoint must no longer check a CSRF token at all —
        // its presence would mean the removal in #1913 was reverted or
        // reintroduced.
        $this->assertStringNotContainsString(
            'Token::check',
            $content,
            'history.php must not check a CSRF token — per ADR-019 it is rate-limited instead'
        );

        // The negation is part of the asserted literal on purpose: an inverted guard
        // (`if (checkRateLimit(...))`) would reject every legitimate request while a
        // presence-only assertion still passed.
        $this->assertStringContainsString(
            "if (!checkRateLimit('car_history', \$rateUserId))",
            $content,
            'Rate-limit check must reject (not accept) a request over the car_history limit'
        );
        $this->assertStringContainsString(
            "recordRateLimit('car_history', true,",
            $content,
            'An admitted request must be recorded against the car_history limit, or the '
                . 'counter never accumulates and the limit can never trip'
        );
        // The rejection path deliberately does NOT record. recordRateLimit(..., false, ...)
        // would be the only writer of failure rows, and RateLimit::check() counts those
        // against ip_max — so blocked requests would feed a second, independent limit that
        // nothing but the block itself created.
        $this->assertStringNotContainsString(
            "recordRateLimit('car_history', false,",
            $content,
            'A rate-limit rejection must not record a failed attempt'
        );
        // Deliberately loose: pins the action string, the negation and the 429 status,
        // but not the local variable name or the user-facing message — renaming or
        // rewording either is not a behaviour change and must not fail this test.
        $this->assertMatchesRegularExpression(
            '/if \(!checkRateLimit\(\'car_history\',[^)]*\)\) \{\s*ApiResponse::error\([^;]*429\)\s*->withLogging\([^;]*LOG_CATEGORY_SECURITY/s',
            $content,
            'A rate-limit rejection must return a 429 logged under the security category'
        );
    }

    /**
     * history.php rejects a missing car_id with a logged 400.
     *
     * Source inspection: the guard runs before any Car instantiation, so it
     * cannot be reached by calling Car directly.
     */
    public function testHistoryMissingCarIdReturnsError(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        $this->assertStringContainsString(
            'empty($carID)',
            $content,
            'Endpoint must guard against a missing car_id parameter'
        );
        $this->assertStringContainsString(
            "ApiResponse::error('Car ID not provided', 400)",
            $content,
            'Missing car_id must return HTTP 400 via ApiResponse::error()'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_VALIDATION_ERROR',
            $content,
            'Missing car_id must be logged as a validation error'
        );
        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\(\'Car ID not provided\', 400\).*?->withLogging\(/s',
            $content,
            'The missing car_id response must be chained through ->withLogging()'
        );
    }

    /**
     * history.php answers a request for a car that does not exist with a logged
     * 404 that still carries the DataTables payload shape.
     *
     * Source inspection: reaching the branch needs a Car whose exists() is false,
     * which is a database state the unit tier has no connection to establish.
     */
    public function testHistoryCarNotFoundReturnsNotFoundResponse(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        // The negation is part of the asserted literal on purpose: an inverted guard
        // (`if ($car->exists())`) would 404 on every car that does exist while a
        // presence-only assertion still passed.
        $this->assertStringContainsString(
            'if (!$car->exists()) {',
            $content,
            'Endpoint must guard on the car not existing'
        );
        $this->assertStringContainsString(
            "ApiResponse::notFound('Car not found')",
            $content,
            'An unknown car must return HTTP 404 via ApiResponse::notFound()'
        );
        $this->assertMatchesRegularExpression(
            '/ApiResponse::notFound\(\'Car not found\'\).*?->withLogging\([^;]*LOG_CATEGORY_VALIDATION_ERROR/s',
            $content,
            'The not-found response must be logged under the validation-error category'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "Car history requested for non-existent car ID: $carID"',
            $content,
            'The not-found log message must identify the requested car ID'
        );
    }

    /**
     * history.php's success path returns the history under the DataTables record
     * counts the frontend reads.
     *
     * Source inspection: the payload assembly is the endpoint's own wiring —
     * Car::history() is exercised for real in the integration suite, but the
     * mapping of its result into draw/recordsTotal/recordsFiltered exists only
     * here.
     */
    public function testHistorySuccessReturnsDataTablesPayload(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        $this->assertMatchesRegularExpression(
            '/ApiResponse::success\(\'Car history retrieved\'\)\s*->withDataArray\(\[/',
            $content,
            'The success path must emit ApiResponse::success() with a data array payload'
        );
        $this->assertMatchesRegularExpression(
            '/\$count\s*=\s*count\(\$carHist\)/',
            $content,
            'The record count must be derived from the retrieved history, not hardcoded'
        );
        $this->assertStringContainsString(
            "'recordsTotal' => \$count,",
            $content,
            'The success payload must report recordsTotal from the history count'
        );
        $this->assertStringContainsString(
            "'recordsFiltered' => \$count,",
            $content,
            'The success payload must report recordsFiltered from the history count'
        );
    }

    /**
     * history.php converts a thrown ElanRegistryException into a logged 500.
     *
     * Source inspection: forcing a database failure inside Car would require
     * corrupting the schema, so the catch/response wiring is asserted directly.
     */
    public function testHistoryDatabaseExceptionReturnsServerError(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        $this->assertStringContainsString(
            'catch (ElanRegistryException $e)',
            $content,
            'Endpoint must catch the project exception hierarchy'
        );
        $this->assertStringContainsString(
            "ApiResponse::serverError('Failed to load car history')",
            $content,
            'Caught exceptions must return HTTP 500 via ApiResponse::serverError()'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_DATABASE_ERROR',
            $content,
            'Database failures must be logged with the database-error category'
        );
        $this->assertMatchesRegularExpression(
            '/catch \(ElanRegistryException \$e\).*?->withLogging\([^;]*LOG_CATEGORY_DATABASE_ERROR/s',
            $content,
            'The exception path must log via ->withLogging() with the database-error category'
        );
    }

    /**
     * history.php also catches anything outside the project exception hierarchy
     * and logs it under a different category.
     *
     * Source inspection: this is the second catch block, distinct from the
     * ElanRegistryException one above — without it a stray TypeError or PDO
     * failure would escape as an HTML fatal instead of the DataTables-shaped
     * JSON the frontend expects.
     */
    public function testHistoryUnexpectedThrowableReturnsLoggedServerError(): void
    {
        $content = $this->readEndpointSource(self::HISTORY_ENDPOINT);

        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $content,
            'Endpoint must also catch throwables outside the project exception hierarchy'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_SYSTEM_ERROR',
            $content,
            'Unexpected throwables must be logged with the system-error category'
        );
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\).*?ApiResponse::serverError\(\'Failed to load car history\'\)/s',
            $content,
            'The generic catch must return HTTP 500 via ApiResponse::serverError()'
        );
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\).*?->withLogging\([^;]*LOG_CATEGORY_SYSTEM_ERROR/s',
            $content,
            'The generic catch must log via ->withLogging() with the system-error category'
        );
    }

    // =========================================================================
    // chassis-validate.php — behaviour (ChassisValidator::validate())
    // =========================================================================

    /**
     * A genuinely valid chassis number validates successfully.
     *
     * Arrange: "7301019999B" is the canonical post-1970 YYMMBBXXXXC example —
     *          10 numeric characters plus an Elan letter code (A-K excluding I).
     * Act:     run the same validator the endpoint instantiates.
     * Assert:  the result carries every key the endpoint forwards verbatim.
     */
    public function testValidateChassisSuccessReturnsApiResponseWithResult(): void
    {
        $result = (new ChassisValidator())->validate('7301019999B', 1973, self::VALID_MODEL);

        $this->assertTrue($result['valid'], 'Canonical post-1970 chassis must validate');
        $this->assertSame('7301019999B', $result['chassis']);
        $this->assertSame('post_1970', $result['format_type']);
        $this->assertFalse($result['override_used'], 'No override was requested');
        $this->assertSame('', $result['error_reason'], 'A valid chassis must carry no error reason');
    }

    /**
     * A genuinely invalid chassis number fails validation with a reason.
     *
     * Arrange: "ABC123" passes the character allowlist but is not 11 characters,
     *          so the post-1970 format rule rejects it.
     * Act:     run the validator.
     * Assert:  valid is false and a non-empty error_reason explains why — the
     *          endpoint still reports this as an HTTP 200 success payload.
     */
    public function testValidateChassisInvalidReturnsSuccessWithValidationFailure(): void
    {
        $result = (new ChassisValidator())->validate('ABC123', 1973, self::VALID_MODEL);

        $this->assertFalse($result['valid'], 'A 6-character post-1970 chassis must not validate');
        $this->assertSame('ABC123', $result['chassis']);
        $this->assertSame('post_1970', $result['format_type']);
        $this->assertFalse($result['override_used']);
        $this->assertNotEmpty($result['error_reason'], 'A failed validation must explain itself');
        $this->assertStringContainsString('11 characters', $result['error_reason']);
    }

    // =========================================================================
    // chassis-validate.php — wiring (source inspection)
    // =========================================================================

    /**
     * chassis-validate.php refuses non-AJAX requests with a 400.
     *
     * Source inspection: the guard reads a request header, which only exists
     * during a real HTTP request.
     */
    public function testValidateChassisMissingAjaxHeaderReturnsError(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        $this->assertStringContainsString(
            "strtolower(Server::get('HTTP_X_REQUESTED_WITH', '')) !== 'xmlhttprequest'",
            $content,
            'Endpoint must reject requests without the AJAX header, compared case-insensitively'
        );
        $this->assertStringContainsString(
            "ApiResponse::error('Bad Request: AJAX only', 400)",
            $content,
            'Non-AJAX requests must return HTTP 400'
        );
    }

    /**
     * chassis-validate.php rejects a bad CSRF token with a logged 403.
     *
     * Source inspection: Token::check() depends on session state established by
     * a real request.
     */
    public function testValidateChassisCsrfFailureReturnsForbidden(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        // The negation is part of the asserted literal on purpose: an inverted guard
        // (`if (Token::check(...))`) would let every forged request through while a
        // presence-only assertion still passed.
        $this->assertStringContainsString(
            "if (!Token::check(Input::get('csrf')))",
            $content,
            'CSRF check must reject (not accept) an invalid token'
        );
        $this->assertStringContainsString(
            "ApiResponse::forbidden('Invalid CSRF token')",
            $content,
            'CSRF failures must return HTTP 403 via ApiResponse::forbidden()'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_SECURITY',
            $content,
            'CSRF failures must be logged with the security category'
        );
    }

    /**
     * chassis-validate.php rejects missing chassis/year/model with a 400 that
     * still carries the validation-result shape the frontend expects.
     *
     * Source inspection: the guard runs before ChassisValidator is constructed.
     */
    public function testValidateChassisMissingParametersReturnsError(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        // `if (` is part of the literal so the condition must actually gate a response,
        // not merely appear somewhere in the file.
        $this->assertStringContainsString(
            'if (empty($chassis) || $year === 0 || empty($model))',
            $content,
            'Endpoint must gate the response on missing chassis, year, or model'
        );
        $this->assertStringContainsString(
            "ApiResponse::error('Missing required parameters: chassis, year, and model', 400)",
            $content,
            'Missing parameters must return HTTP 400'
        );
        $this->assertMatchesRegularExpression(
            '/Missing required parameters: chassis, year, and model\', 400\)\s*->withDataArray\(/',
            $content,
            'The missing-parameter response must keep the validation-result payload for the frontend'
        );
    }

    /**
     * chassis-validate.php forwards the validator's result verbatim on success.
     *
     * Source inspection: the two behavioural tests above prove what
     * ChassisValidator::validate() returns; only the endpoint source shows that
     * the array is passed straight through rather than reshaped or filtered, so
     * the keys those tests assert are the keys the frontend actually receives.
     */
    public function testValidateChassisSuccessForwardsValidatorResultVerbatim(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        $this->assertStringContainsString(
            "ApiResponse::success('Chassis validation completed')",
            $content,
            'A completed validation must return HTTP 200 via ApiResponse::success()'
        );
        $this->assertMatchesRegularExpression(
            '/ApiResponse::success\(\'Chassis validation completed\'\)\s*->withDataArray\(\$result\)\s*->send\(\)/',
            $content,
            'The success response must forward the validator result unmodified'
        );
    }

    /**
     * chassis-validate.php converts any throwable from the validator into a
     * logged 500.
     *
     * Source inspection: the catch is generic, so it also covers the
     * "validator class unavailable" scenario the removed
     * testValidateChassisMissingClassFileReturnsServerError() invented — the
     * endpoint has never had a dedicated missing-class branch.
     */
    public function testValidateChassiExceptionReturnsServerError(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        $this->assertStringContainsString(
            'catch (Throwable $e)',
            $content,
            'Endpoint must catch every throwable raised by the validator'
        );
        $this->assertStringContainsString(
            'ApiResponse::serverError(',
            $content,
            'Caught throwables must return HTTP 500 via ApiResponse::serverError()'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_VALIDATION_ERROR',
            $content,
            'Validator failures must be logged with the validation-error category'
        );
        $this->assertMatchesRegularExpression(
            '/catch \(Throwable \$e\).*?->withLogging\([^;]*LOG_CATEGORY_VALIDATION_ERROR/s',
            $content,
            'The throwable path must log via ->withLogging() with the validation-error category'
        );
    }

    /**
     * chassis-validate.php resolves ChassisValidator through a namespace import.
     *
     * Replaces the former "class file missing" test, whose premise matched no
     * branch in the endpoint: there is no file_exists()/require guard, only the
     * generic catch asserted above. The real contract is that the class is
     * imported and autoloaded — if the class were ever unresolvable, the
     * resulting Error would be caught by that generic catch rather than by a
     * dedicated branch.
     */
    public function testValidateChassisResolvesValidatorViaNamespaceImport(): void
    {
        $content = $this->readEndpointSource(self::CHASSIS_ENDPOINT);

        $this->assertStringContainsString(
            'use ElanRegistry\ChassisValidator;',
            $content,
            'Endpoint must import ChassisValidator from the ElanRegistry namespace'
        );
        $this->assertStringContainsString(
            'new ChassisValidator()',
            $content,
            'Endpoint must instantiate the imported validator'
        );
        $this->assertStringContainsString(
            '$validator->validate($chassis, $year, $model, $allowOverride)',
            $content,
            'Endpoint must delegate to ChassisValidator::validate() with all four parameters'
        );

        // The imported class must actually be autoloadable under that name.
        $this->assertTrue(
            class_exists(ChassisValidator::class),
            'ChassisValidator must be autoloadable, otherwise the endpoint hits its generic catch'
        );
    }

    // =========================================================================
    // Response format consistency (source inspection)
    // =========================================================================

    /**
     * Every ApiResponse factory method: a public static method that returns an
     * ApiResponse (i.e. `self`). Derived by reflection rather than hardcoded so
     * that adding a factory to ApiResponse cannot make the consistency test
     * below fail with a misleading message.
     *
     * @return list<string> Factory method names, e.g. success, error, notFound
     */
    private function apiResponseFactoryMethods(): array
    {
        $factories = array_filter(
            get_class_methods(ApiResponse::class),
            static function (string $method): bool {
                $reflection = new \ReflectionMethod(ApiResponse::class, $method);
                $returnType = $reflection->getReturnType();

                if (!$reflection->isStatic() || !$returnType instanceof \ReflectionNamedType) {
                    return false;
                }

                // getName() on a `self`/`static` return type is not guaranteed to resolve
                // to the declaring class name across PHP versions/builds — resolve it
                // explicitly instead of relying on that behavior.
                $typeName = $returnType->getName();
                if ($typeName === 'self' || $typeName === 'static') {
                    $typeName = $reflection->getDeclaringClass()->getName();
                }

                return $typeName === ApiResponse::class;
            }
        );

        return array_values($factories);
    }

    /**
     * Both endpoints emit every response through ApiResponse (Pattern A) and
     * never hand-roll a response of their own.
     *
     * Source inspection: the check is structural — each ->send() call must be
     * fed by an ApiResponse static factory, and the endpoint must never do the
     * JSON encoding or status-code setting itself, since those are exactly what
     * a hand-rolled Pattern B {status, info} response would require.
     *
     * Deliberately *not* asserted: the absence of a literal 'status' / 'info'
     * array key. Either endpoint may legitimately grow a car/job "status" field
     * inside a ->withDataArray([...]) payload, which is still Pattern A at the
     * response's top level; a bare key check would fail on it. A hand-rolled
     * Pattern B response is only observable to the frontend if the endpoint
     * encodes and emits it itself, so the encode/status-code checks catch the
     * real regression without that false positive.
     */
    public function testResponseFormatConsistencyAcrossHistoryAndValidation(): void
    {
        $factoryMethods = $this->apiResponseFactoryMethods();
        $this->assertNotEmpty($factoryMethods, 'ApiResponse must expose at least one factory method');

        $factoryPattern = '/ApiResponse::(' . implode('|', array_map('preg_quote', $factoryMethods)) . ')\(/';

        foreach ([self::HISTORY_ENDPOINT, self::CHASSIS_ENDPOINT] as $endpoint) {
            $content = $this->readEndpointSource($endpoint);

            $factoryCount = preg_match_all($factoryPattern, $content);
            $sendCount = substr_count($content, '->send()');

            $this->assertGreaterThan(0, $factoryCount, "$endpoint must use ApiResponse factories");
            $this->assertSame(
                $sendCount,
                $factoryCount,
                "$endpoint must emit every response through an ApiResponse factory"
            );

            // Encoding and status codes belong to ApiResponse::send(), nowhere else.
            $this->assertStringNotContainsString(
                'json_encode',
                $content,
                "$endpoint must not build its own JSON payload — ApiResponse::send() encodes"
            );
            $this->assertStringNotContainsString(
                'http_response_code(',
                $content,
                "$endpoint must not set its own status code — ApiResponse::send() does"
            );
            $this->assertStringNotContainsString(
                "header('Content-Type",
                $content,
                "$endpoint must not set its own Content-Type — ApiResponse::send() does"
            );
        }
    }
}
