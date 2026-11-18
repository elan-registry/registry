<?php

declare(strict_types=1);

require_once __DIR__ . '/_curl_namespace_overrides.php';
require_once __DIR__ . '/_apcu_namespace_overrides.php';

use ElanRegistry\Exceptions\LocationServiceException;
use ElanRegistry\LocationService;
use ElanRegistry\RateLimiterInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for LocationService's rate-limiter integration (issue #1582).
 *
 * LocationService::searchLocation() and ::reverseGeocode() now:
 *   1. Check the cache FIRST — a cache hit returns immediately, never
 *      touching the rate limiter at all.
 *   2. On a cache miss, check the rate limiter (shared 'location_search'
 *      action key for both methods) via the private rateLimiterAllows()/
 *      recordRateLimiterAttempt() wrapper methods.
 *   3. A blocked check throws LocationServiceException and records a failed
 *      attempt; a genuine successful upstream call caches the result and
 *      records a successful attempt.
 *
 * FakeRateLimiter (below) is a small in-memory RateLimiterInterface double
 * that records every allow()/record() call, following the same
 * dependency-injection + fake idiom already established in this codebase for
 * DatabaseInterface (see usersc/classes/Database/DbAdapter.php and its test
 * fakes) — LocationService itself is never mocked, only its rate-limiter
 * collaborator.
 *
 * The upstream HTTP call inside the private makeHttpRequest() method is
 * stubbed via _curl_namespace_overrides.php (a namespace-scoped override of
 * the curl_*() functions LocationService calls unqualified), following the
 * same technique _apcu_namespace_overrides.php already established in this
 * directory for APCu. This lets Test 3 exercise the real success path
 * (cache write + record(success: true)) without a live network call.
 *
 * searchLocation() gets the primary blocked/cache-hit/success coverage
 * (Tests 1-3). reverseGeocode() shares the exact same rate-limiter wrapper
 * methods and the same 'location_search' action key, so it does not repeat
 * all three — instead Test 5 targets reverseGeocode()'s one structurally
 * distinct path (its single Nominatim call, vs. searchLocation()'s
 * Photon-then-Nominatim fallback) to confirm a failed upstream call is
 * recorded as success=false rather than miscounted as success=true.
 *
 * Test 4 covers the fail-open contract itself: rateLimiterAllows()/
 * recordRateLimiterAttempt() must swallow a throwing RateLimiterInterface
 * and keep the request working, matching the documented fail-open behavior
 * on LocationService's constructor/method docblocks.
 *
 * @issue 1582
 * @see usersc/classes/LocationService.php
 * @see usersc/classes/RateLimiterInterface.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('location-service')]
final class LocationServiceRateLimitTest extends TestCase
{
    private string $tempRoot = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Isolated cache directory, mirroring LocationServiceCacheTest's setup,
        // so this suite never touches (or is polluted by) the real cache dir.
        $this->tempRoot = sys_get_temp_dir() . '/location_service_ratelimit_test_' . uniqid('', true);
        $this->cacheDir = $this->tempRoot . '/usersc/cache/';
        mkdir($this->cacheDir, 0755, true);

        $GLOBALS['abs_us_root'] = $this->tempRoot . '/';
        $GLOBALS['us_url_root'] = '';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['abs_us_root'],
            $GLOBALS['us_url_root'],
            $GLOBALS['mockCurlResponse'],
            $GLOBALS['mockCurlFailure'],
            $GLOBALS['mockCurlHttpCode'],
            $GLOBALS['mockCurlInitMissing'],
            $GLOBALS['mockFileGetContentsFailure'],
            $GLOBALS['mockCurlFailureForUrlSubstring'],
            $GLOBALS['mockCurlResponseByUrlSubstring']
        );

        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) && !is_link($entry)
                ? $this->removeDirectory($entry)
                : unlink($entry);
        }
        rmdir($path);
    }

    /**
     * A valid, minimal Photon API JSON response for a single result, used to
     * drive the mocked cURL success path in Test 3.
     */
    private function photonSuccessBody(): string
    {
        return json_encode([
            'features' => [
                [
                    'properties' => [
                        'city' => 'Portland',
                        'state' => 'Oregon',
                        'country' => 'United States',
                        'name' => 'Portland',
                    ],
                    'geometry' => [
                        'coordinates' => [-122.6765, 45.5231],
                    ],
                ],
            ],
        ]) ?: '{}';
    }

    /**
     * A valid, minimal Nominatim API JSON response for a single result, used
     * to drive the mocked cURL success path for the asymmetric-fallback test
     * (issue #1621) where Photon fails and Nominatim must still succeed.
     */
    private function nominatimSuccessBody(): string
    {
        return json_encode([
            [
                'address' => [
                    'city' => 'Portland',
                    'state' => 'Oregon',
                    'country' => 'United States',
                ],
                'lat' => '45.5231',
                'lon' => '-122.6765',
                'display_name' => 'Portland, Oregon, United States',
            ],
        ]) ?: '[]';
    }

    // =========================================================================
    // Test 1: blocked — rate limiter denies, no upstream call, exception thrown
    // =========================================================================

    #[Group('fast')]
    public function test_searchLocation_throwsAndRecordsFailure_whenRateLimiterBlocks(): void
    {
        $fake = new FakeRateLimiter(allow: false);
        $service = new LocationService($fake);

        // Guaranteed-unique query so this is always a cache miss.
        $query = 'ratelimit-block-test-' . uniqid('', true);

        // If the blocked path fell through to the upstream call, a real
        // network request would be attempted (and fail in a sandboxed test
        // run) or the curl mock would need to be armed; leaving both curl
        // mock globals unset means any accidental upstream call is very
        // unlikely to silently "succeed" its way to a passing test.
        $this->expectException(LocationServiceException::class);

        try {
            $service->searchLocation($query, 42);
        } finally {
            $this->assertSame(
                [['action' => 'location_search', 'success' => false, 'userId' => 42]],
                $fake->recordCalls,
                'record() must be called exactly once, with success=false, when the rate limiter blocks.'
            );
            $this->assertSame(
                [['action' => 'location_search', 'userId' => 42]],
                $fake->allowCalls,
                'allow() must be called exactly once for a cache-miss query.'
            );
            // No cache entry should exist for a successful result — proves the
            // blocked path never reached the upstream call / setCache().
            $cacheFile = $this->cacheDir . md5('location_search_' . md5($query . '_8')) . '.cache';
            $this->assertFileDoesNotExist(
                $cacheFile,
                'No cache entry should be written when the rate limiter blocks the request.'
            );
        }
    }

    /**
     * Same as above but for an anonymous caller ($userId = null) — the actual
     * subject of #1582 (anonymous callers were the ones sharing a single
     * global bucket). Confirms the blocked path, the record() call, and the
     * internal logger() calls (which now coalesce null to 0 per
     * CODING_STANDARDS.md) all work correctly when $userId is null, not just
     * when it's a concrete int.
     */
    #[Group('fast')]
    public function test_searchLocation_throwsAndRecordsFailure_whenRateLimiterBlocks_forAnonymousCaller(): void
    {
        $fake = new FakeRateLimiter(allow: false);
        $service = new LocationService($fake);

        $query = 'ratelimit-block-anon-test-' . uniqid('', true);

        $this->expectException(LocationServiceException::class);

        try {
            $service->searchLocation($query, null);
        } finally {
            $this->assertSame(
                [['action' => 'location_search', 'success' => false, 'userId' => null]],
                $fake->recordCalls,
                'record() must be called exactly once, with success=false and userId=null, for an anonymous caller.'
            );
            $this->assertSame(
                [['action' => 'location_search', 'userId' => null]],
                $fake->allowCalls,
                'allow() must be called exactly once with userId=null for an anonymous caller.'
            );
        }
    }

    // =========================================================================
    // Test 2: cache hit — rate limiter never consulted, even if it would block
    // =========================================================================

    #[Group('fast')]
    public function test_searchLocation_returnsCachedResult_andNeverCallsRateLimiter_onCacheHit(): void
    {
        // Seed the cache via a real successful call with allow() = true and a
        // mocked successful upstream response.
        $query = 'ratelimit-cachehit-test-' . uniqid('', true);
        $GLOBALS['mockCurlResponse'] = $this->photonSuccessBody();
        $GLOBALS['mockCurlHttpCode'] = 200;

        $seedFake = new FakeRateLimiter(allow: true);
        $seedService = new LocationService($seedFake);
        $seeded = $seedService->searchLocation($query, 7);

        $this->assertNotEmpty($seeded, 'Precondition: seeding call must return a non-empty result to populate the cache.');
        $this->assertSame(
            [['action' => 'location_search', 'success' => true, 'userId' => 7]],
            $seedFake->recordCalls,
            'Precondition: the seeding call must have recorded a successful attempt.'
        );

        // Second call: fresh fake whose allow() always returns false. If the
        // cache hit path incorrectly consulted the rate limiter, this call
        // would throw LocationServiceException instead of returning the
        // cached result.
        $blockingFake = new FakeRateLimiter(allow: false);
        $service = new LocationService($blockingFake);
        $result = $service->searchLocation($query, 7);

        $this->assertSame($seeded, $result, 'A cache hit must return the exact cached result.');
        $this->assertSame(
            [],
            $blockingFake->allowCalls,
            'allow() must never be called on a cache hit — the cache check happens before rate limiting.'
        );
        $this->assertSame(
            [],
            $blockingFake->recordCalls,
            'record() must never be called on a cache hit.'
        );
    }

    // =========================================================================
    // Test 3: success — rate limiter allows, upstream succeeds, records once
    // =========================================================================

    #[Group('fast')]
    public function test_searchLocation_recordsSuccessOnce_whenAllowedAndUpstreamSucceeds(): void
    {
        $query = 'ratelimit-success-test-' . uniqid('', true);
        $GLOBALS['mockCurlResponse'] = $this->photonSuccessBody();
        $GLOBALS['mockCurlHttpCode'] = 200;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        $result = $service->searchLocation($query, 99);

        $this->assertNotEmpty($result, 'A successful upstream call must return non-empty results.');
        $this->assertSame(
            [['action' => 'location_search', 'userId' => 99]],
            $fake->allowCalls,
            'allow() must be called exactly once.'
        );
        $this->assertSame(
            [['action' => 'location_search', 'success' => true, 'userId' => 99]],
            $fake->recordCalls,
            'record() must be called exactly once, with success=true and the correct userId, on a genuine successful upstream call.'
        );
    }

    // =========================================================================
    // Test 4: fail-open — a throwing rate limiter must not break the request
    // =========================================================================

    #[Group('fast')]
    public function test_searchLocation_failsOpen_whenRateLimiterAllowThrows(): void
    {
        $query = 'ratelimit-failopen-allow-test-' . uniqid('', true);
        $GLOBALS['mockCurlResponse'] = $this->photonSuccessBody();
        $GLOBALS['mockCurlHttpCode'] = 200;

        $fake = new FakeRateLimiter(allow: true, throwOnAllow: true);
        $service = new LocationService($fake);

        // Must not throw, and must not propagate the rate limiter's failure —
        // rateLimiterAllows() is documented to catch \Throwable and return
        // true, matching this class's existing fail-open behavior on cache
        // errors.
        $result = $service->searchLocation($query, 5);

        $this->assertNotEmpty($result, 'A throwing allow() must fail open — the request should still succeed.');
    }

    #[Group('fast')]
    public function test_searchLocation_failsOpen_whenRateLimiterRecordThrows(): void
    {
        $query = 'ratelimit-failopen-record-test-' . uniqid('', true);
        $GLOBALS['mockCurlResponse'] = $this->photonSuccessBody();
        $GLOBALS['mockCurlHttpCode'] = 200;

        $fake = new FakeRateLimiter(allow: true, throwOnRecord: true);
        $service = new LocationService($fake);

        // record() throwing must not prevent the already-successful result
        // from being returned to the caller — recordRateLimiterAttempt()
        // swallows the failure after logging it.
        $result = $service->searchLocation($query, 5);

        $this->assertNotEmpty($result, 'A throwing record() must not break an otherwise-successful request.');
    }

    // =========================================================================
    // Test 5: reverseGeocode() — a failed upstream call is not miscounted as
    // a success (the one path structurally different from searchLocation())
    // =========================================================================

    #[Group('fast')]
    public function test_reverseGeocode_recordsFailure_whenNominatimCallFails(): void
    {
        // Distinct coordinates per test run so this is always a cache miss.
        $lat = 45.0 + (random_int(1, 1000) / 100000);
        $lon = -122.0 + (random_int(1, 1000) / 100000);
        $GLOBALS['mockCurlFailure'] = true;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        $this->expectException(LocationServiceException::class);

        try {
            $service->reverseGeocode($lat, $lon, 11);
        } finally {
            $this->assertSame(
                [['action' => 'location_search', 'userId' => 11]],
                $fake->allowCalls,
                'allow() must be called exactly once.'
            );
            $this->assertSame(
                [['action' => 'location_search', 'success' => false, 'userId' => 11]],
                $fake->recordCalls,
                'A failed Nominatim call must record success=false, never success=true — '
                    . 'confirms reverseGeocode()\'s single-provider failure path does not '
                    . 'share searchLocation()\'s Photon-then-Nominatim fallback shape in a '
                    . 'way that could miscount a failure as a success.'
            );
        }
    }

    // =========================================================================
    // Tests 6-9 (issue #1621): makeHttpRequest() fallback branches and
    // searchLocation()'s "all services failed" throw, including the
    // asymmetric-fallback case where only one upstream service fails. These
    // branches were flagged as uncovered by a test-coverage audit tracked in
    // #1621; #1582's rate-limiter refactor (which this file already covers
    // above) was a prerequisite and has since merged.
    // =========================================================================

    /**
     * makeHttpRequest()'s cURL-failure branch (curl_exec() returns false) is
     * already indirectly exercised by Test 5 above via reverseGeocode(). This
     * test drives the same branch through searchLocation() instead, which is
     * the specific call path issue #1621's acceptance criteria target, and
     * pins down the exact "all services failed" exception message thrown once
     * both Photon and Nominatim fail identically.
     */
    #[Group('fast')]
    public function test_searchLocation_throwsAllServicesFailed_whenCurlExecFails(): void
    {
        $query = 'httpfallback-curlfail-test-' . uniqid('', true);
        $GLOBALS['mockCurlFailure'] = true;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        try {
            $service->searchLocation($query, 21);
            $this->fail('Expected LocationServiceException was not thrown.');
        } catch (LocationServiceException $e) {
            $this->assertSame(
                'Location search temporarily unavailable. Please try again later.',
                $e->getMessage(),
                'A cURL failure on every upstream service must surface the "all services failed" message.'
            );
        }

        $this->assertSame(
            [['action' => 'location_search', 'success' => false, 'userId' => 21]],
            $fake->recordCalls,
            'record() must be called exactly once, with success=false, once all upstream services fail.'
        );
    }

    /**
     * makeHttpRequest()'s non-200 branch: curl_exec() succeeds (returns a
     * response body) but curl_getinfo(CURLINFO_HTTP_CODE) reports a non-200
     * status. No existing test sets mockCurlHttpCode without also implying a
     * 200 success, so this branch was genuinely uncovered before #1621.
     */
    #[Group('fast')]
    public function test_searchLocation_throwsAllServicesFailed_whenUpstreamReturnsNon200(): void
    {
        $query = 'httpfallback-non200-test-' . uniqid('', true);
        // mockCurlResponse must be set for the curl_getinfo() override to
        // read mockCurlHttpCode at all — otherwise it assumes 200.
        $GLOBALS['mockCurlResponse'] = 'irrelevant body for a non-200 response';
        $GLOBALS['mockCurlHttpCode'] = 500;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        try {
            $service->searchLocation($query, 22);
            $this->fail('Expected LocationServiceException was not thrown.');
        } catch (LocationServiceException $e) {
            $this->assertSame(
                'Location search temporarily unavailable. Please try again later.',
                $e->getMessage(),
                'A non-200 HTTP status from every upstream service must surface the "all services failed" message.'
            );
        }

        $this->assertSame(
            [['action' => 'location_search', 'success' => false, 'userId' => 22]],
            $fake->recordCalls,
            'record() must be called exactly once, with success=false, when every upstream service returns non-200.'
        );
    }

    /**
     * makeHttpRequest()'s file_get_contents() fallback branch, taken only
     * when function_exists('curl_init') is false. Simulated via
     * $GLOBALS['mockCurlInitMissing'] (see _apcu_namespace_overrides.php,
     * the single ElanRegistry\function_exists() override shared across this
     * suite — PHP forbids redeclaring the same namespaced function from a
     * second file) together with $GLOBALS['mockFileGetContentsFailure'] (see
     * _curl_namespace_overrides.php), which makes the file_get_contents()
     * override return false deterministically.
     *
     * Deliberately does NOT rely on the sandboxed test environment lacking
     * outbound network access: CI runners are not guaranteed to be
     * network-isolated, and a real HTTP call that happened to succeed but
     * resolve to zero results would still reach the same "all services
     * failed" throw via a completely different path (empty results, not a
     * failed request) — passing the test for the wrong reason. The explicit
     * mockFileGetContentsFailure flag removes that ambiguity.
     */
    #[Group('fast')]
    public function test_searchLocation_throwsAllServicesFailed_whenCurlUnavailable_fileGetContentsFallback(): void
    {
        $query = 'httpfallback-nocurl-test-' . uniqid('', true);
        $GLOBALS['mockCurlInitMissing'] = true;
        $GLOBALS['mockFileGetContentsFailure'] = true;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        try {
            $service->searchLocation($query, 23);
            $this->fail('Expected LocationServiceException was not thrown.');
        } catch (LocationServiceException $e) {
            $this->assertSame(
                'Location search temporarily unavailable. Please try again later.',
                $e->getMessage(),
                'file_get_contents() failing for every upstream service must surface the '
                    . '"all services failed" message, proving the fallback branch was reached '
                    . 'and its failure path handled cleanly.'
            );
        }

        $this->assertSame(
            [['action' => 'location_search', 'success' => false, 'userId' => 23]],
            $fake->recordCalls,
            'record() must be called exactly once, with success=false, when the file_get_contents() '
                . 'fallback fails for every upstream service.'
        );
    }

    /**
     * A query that both providers answer with an empty list is a no-match,
     * not an outage. searchLocation() must return [] so the picker shows
     * "No locations found" (found in /flow:finish-milestone v2.34.0).
     */
    #[Group('fast')]
    public function test_searchLocation_returnsEmptyAndRecordsSuccess_whenBothProvidersFindNoMatch(): void
    {
        $query = 'nomatch-test-' . uniqid('', true);
        $GLOBALS['mockCurlResponseByUrlSubstring'] = [
            'photon.komoot.io' => '{"features":[]}',
            'nominatim.openstreetmap.org' => '[]',
        ];
        $GLOBALS['mockCurlHttpCode'] = 200;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        $result = $service->searchLocation($query, 31);

        $this->assertSame(
            [],
            $result,
            'A query that both providers answer with no match must return an empty list, '
                . 'not throw the "temporarily unavailable" exception that the picker shows as a refusal.'
        );
        $this->assertSame(
            [['action' => 'location_search', 'success' => true, 'userId' => 31]],
            $fake->recordCalls,
            'A no-match answer is a successful upstream call, not an outage.'
        );
    }

    /**
     * The asymmetric-fallback case: Photon fails via one of the branches
     * covered above (cURL failure, chosen arbitrarily as representative),
     * but Nominatim then succeeds. Tests 6-8 only ever fail both providers
     * identically, which would still pass even if a regression broke the
     * catch-and-fallthrough in searchLocation() (LocationService.php:207-209)
     * so it stopped trying Nominatim at all — this test specifically proves
     * the fallback still delivers a successful result when only the primary
     * provider is down, using the per-URL cURL mock
     * (_curl_namespace_overrides.php's mockCurlFailureForUrlSubstring /
     * mockCurlResponseByUrlSubstring) to give Photon and Nominatim distinct
     * mocked behavior in the same test.
     */
    #[Group('fast')]
    public function test_searchLocation_succeedsViaNominatim_whenPhotonFails(): void
    {
        $query = 'httpfallback-asymmetric-test-' . uniqid('', true);
        $GLOBALS['mockCurlFailureForUrlSubstring'] = 'photon.komoot.io';
        $GLOBALS['mockCurlResponseByUrlSubstring'] = [
            'nominatim.openstreetmap.org' => $this->nominatimSuccessBody(),
        ];
        $GLOBALS['mockCurlHttpCode'] = 200;

        $fake = new FakeRateLimiter(allow: true);
        $service = new LocationService($fake);

        $result = $service->searchLocation($query, 24);

        $this->assertNotEmpty(
            $result,
            'searchLocation() must still return results via the Nominatim fallback when only Photon fails.'
        );
        $this->assertSame(
            'nominatim',
            $result[0]['source'] ?? null,
            'The successful result must come from Nominatim (formatNominatimResults() tags source), '
                . 'confirming the fallback — not a lucky Photon retry — produced it.'
        );
        $this->assertSame(
            [['action' => 'location_search', 'success' => true, 'userId' => 24]],
            $fake->recordCalls,
            'A successful fallback to Nominatim must record success=true, exactly once, '
                . 'even though the primary provider failed first.'
        );
    }
}

/**
 * In-memory fake RateLimiterInterface for tests. Records every allow()/
 * record() call (action, userId — and success, for record()) and lets the
 * test control what allow() returns via the constructor.
 *
 * Kept in the same file as its only consumer, mirroring how small,
 * single-purpose test doubles are typically colocated with the test that
 * uses them elsewhere in this suite (e.g. LocationServiceCacheTest's private
 * helper methods).
 */
final class FakeRateLimiter implements RateLimiterInterface
{
    /** @var list<array{action: string, userId: int|null}> */
    public array $allowCalls = [];

    /** @var list<array{action: string, success: bool, userId: int|null}> */
    public array $recordCalls = [];

    public function __construct(
        private readonly bool $allow,
        private readonly bool $throwOnAllow = false,
        private readonly bool $throwOnRecord = false,
    ) {
    }

    public function allow(string $action, ?int $userId): bool
    {
        if ($this->throwOnAllow) {
            throw new \RuntimeException('Simulated rate limiter failure (allow)');
        }
        $this->allowCalls[] = ['action' => $action, 'userId' => $userId];
        return $this->allow;
    }

    public function record(string $action, bool $success, ?int $userId): void
    {
        if ($this->throwOnRecord) {
            throw new \RuntimeException('Simulated rate limiter failure (record)');
        }
        $this->recordCalls[] = ['action' => $action, 'success' => $success, 'userId' => $userId];
    }
}
