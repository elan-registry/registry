<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for the car save endpoint (app/api/cars/save.php).
 *
 * Covers:
 * - app/api/cars/save.php (the switch statement's default: branch)
 * - app/api/cars/save.php buildCarDetails() not-found branch (#1975): it
 *   appends to $errors and logs under the car-error category
 * - app/api/cars/save.php `case "updateCar"` branch: the $errors guard runs between
 *   buildCarDetails() and uploadImages() (#1975)
 *
 * The endpoint file cannot be require()'d from PHPUnit: every response path
 * ends in ApiResponse::send(), which calls exit and would terminate the test
 * runner. The action router dispatches on Input::get('action'), which only
 * exists during a real HTTP request, so the unroutable-action fallback is
 * asserted against the endpoint file's source text.
 *
 * Deliberately narrow: this is not a wiring audit of save.php's auth, CSRF and
 * method guards. The database-backed behaviour of addCar / updateCar is
 * exercised for real against the Car class in
 * tests/integration/database/CarDatabaseOperationsTest.php, and removeImages'
 * underlying CarImageProcessor::removeImage() in
 * tests/integration/CarImageLifecycleTest.php. fetchImages (Car::exists() /
 * Car::images()) has no real coverage yet — a pre-existing gap, not
 * introduced here.
 *
 * Also out of scope: per-action ApiResponse format-consistency across all of
 * save.php's branches (the sibling wiring test asserts this for history.php /
 * chassis-validate.php). save.php can't share that loop unchanged: its
 * substr_count('->send()') would count two occurrences sitting inside
 * comments (lines 150, 239), and it legitimately calls json_encode() 9 times
 * — 6 to serialize $errors into log messages, 3 to build the image column —
 * which the invariant doesn't account for. Stripping comments via
 * token_get_all() before counting would make the factory/send half of the
 * invariant hold (33 === 33); left as a follow-up if it proves needed.
 * Generic Pattern A/B format compliance is covered by
 * tests/unit/api/ApiResponseTest.php.
 *
 * @author Elan Registry Development Team
 */
#[Group('fast')]
#[Group('unit')]
#[Group('car-actions')]
final class CarActionsSaveWiringTest extends TestCase
{
    /** Endpoint path, relative to the repository root. */
    private const SAVE_ENDPOINT = 'app/api/cars/save.php';

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

    /**
     * Remove comments and docblocks from PHP source, so that a commented-out
     * line cannot satisfy a source assertion.
     *
     * @param string $source PHP source text, with or without the open tag
     * @return string The source without T_COMMENT and T_DOC_COMMENT tokens
     */
    private function withoutComments(string $source): string
    {
        $hasOpenTag = str_starts_with($source, '<?php');
        $tokens = token_get_all($hasOpenTag ? $source : '<?php ' . $source);

        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $hasOpenTag ? $code : substr($code, strlen('<?php '));
    }

    /**
     * Return the source of save.php's buildCarDetails(), from its signature to
     * the next top-level function.
     *
     * @return string The function source text
     */
    private function readBuildCarDetailsBody(): string
    {
        $content = $this->readEndpointSource(self::SAVE_ENDPOINT);

        $functionStart = strpos($content, 'function buildCarDetails(');
        $this->assertIsInt($functionStart, 'Could not locate the buildCarDetails() function');

        $nextFunctionStart = strpos($content, "\nfunction ", $functionStart + 1);
        $this->assertIsInt($nextFunctionStart, 'Could not locate the end of the buildCarDetails() function body');

        return substr($content, $functionStart, $nextFunctionStart - $functionStart);
    }

    // =========================================================================
    // save.php — action routing (source inspection)
    // =========================================================================

    /**
     * save.php answers an action it cannot route with a 400 rather than falling
     * through silently.
     *
     * Source inspection: reaching the branch needs a real request carrying an
     * unrecognised `action` parameter, and the response ends in send()/exit.
     * `default:` is part of the asserted sequence on purpose — a bare check for
     * the error string would still pass if the fallback were moved into a
     * routable case, leaving an unknown action to drop out of the switch with
     * no response at all.
     */
    public function testInvalidActionReturnsNoValidActionError(): void
    {
        $content = $this->readEndpointSource(self::SAVE_ENDPOINT);

        $this->assertStringContainsString(
            'switch ($action) {',
            $content,
            'Endpoint must route on the action parameter with a switch statement'
        );
        $this->assertMatchesRegularExpression(
            '/\bdefault:\s*ApiResponse::error\(\'No valid action\', 400\)/',
            $content,
            'An unroutable action must fall to default: and return HTTP 400 via ApiResponse::error()'
        );
        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\(\'No valid action\', 400\)\s*->withLogging\([^;]*LOG_CATEGORY_VALIDATION_ERROR/s',
            $content,
            'The unroutable-action response must be logged under the validation-error category'
        );
        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\(\'No valid action\', 400\)[^;]*->send\(\);/s',
            $content,
            'The unroutable-action response must actually be emitted via ->send()'
        );
    }

    // =========================================================================
    // save.php — updateCar() ownership-derived update flag (source inspection)
    // =========================================================================

    /**
     * updateCar() must call Car::update() with an owner-initiated flag derived
     * by comparing the car row's owner (loaded server-side by
     * buildCarDetails(), not client-supplied) against the authenticated user —
     * never a client-controlled value, and never omitted, since that flag
     * gates whether the owner_last_updated freshness clock resets (see the
     * comment above the assignment in save.php).
     *
     * Source inspection: updateCar() cannot be invoked directly from PHPUnit
     * (see class docblock — every response path ends in exit via
     * ApiResponse::send()), so this pins the exact call and the exact
     * derivation expression against a regression rather than exercising the
     * function.
     */
    public function testUpdateCarDerivesOwnerInitiatedFlagFromCarOwnerComparison(): void
    {
        $content = $this->readEndpointSource(self::SAVE_ENDPOINT);

        $functionStart = strpos($content, 'function updateCar(');
        $this->assertIsInt($functionStart, 'Could not locate the updateCar() function');

        $nextFunctionStart = strpos($content, "\nfunction ", $functionStart + 1);
        $this->assertIsInt($nextFunctionStart, 'Could not locate the end of the updateCar() function body');
        $functionBody = substr($content, $functionStart, $nextFunctionStart - $functionStart);

        $this->assertStringContainsString(
            "\$isOwnerInitiated = (int) (\$cardetails['user_id'] ?? 0) === (int) \$user->data()->id;",
            $functionBody,
            'updateCar() must derive $isOwnerInitiated by comparing the car row\'s user_id to the authenticated user id'
        );
        $this->assertStringContainsString(
            '$car->update($cardetails, $isOwnerInitiated);',
            $functionBody,
            'updateCar() must pass the derived $isOwnerInitiated flag to Car::update()'
        );
    }

    /**
     * removeImage() must pass an owner-initiated flag to Car::removeImage(),
     * derived from the car row's owner against the authenticated user
     * (issue #1929). An admin or editor on another owner's car must get
     * false, so the owner_last_updated freshness clock is not reset.
     *
     * Source inspection, for the same reason as the updateCar() test above.
     */
    public function testRemoveImageDerivesOwnerInitiatedFlagFromCarOwnerComparison(): void
    {
        $content = $this->readEndpointSource(self::SAVE_ENDPOINT);

        $functionStart = strpos($content, 'function removeImage(');
        $this->assertIsInt($functionStart, 'Could not locate the removeImage() function');

        $nextFunctionStart = strpos($content, "\nfunction ", $functionStart + 1);
        $this->assertIsInt($nextFunctionStart, 'Could not locate the end of the removeImage() function body');
        $functionBody = substr($content, $functionStart, $nextFunctionStart - $functionStart);

        $this->assertStringContainsString(
            '$isOwnerInitiated = (int) $car->data()->user_id === (int) $user->data()->id;',
            $functionBody,
            'removeImage() must derive $isOwnerInitiated by comparing the car row\'s user_id to the authenticated user id'
        );
        $this->assertStringContainsString(
            '$car->removeImage($file, $isOwnerInitiated);',
            $functionBody,
            'removeImage() must pass the derived $isOwnerInitiated flag to Car::removeImage()'
        );
    }

    // =========================================================================
    // save.php — buildCarDetails() owner-refresh security ordering (source inspection)
    // =========================================================================

    /**
     * Pins the #1962 security invariant: on the edit branch, the owner used
     * to refresh a car's contact columns must be derived from the CAR's own
     * `user_id` (just loaded from the database row a few lines above), never
     * from the logged-in session user, and never from any value a client
     * could have influenced.
     *
     * Why this matters: an admin or editor can open ANY member's car for
     * editing. If `new Owner(...)` here were ever constructed from
     * `$user->data()->id` (the person performing the edit) instead of
     * `$cardetails['user_id']` (the car's actual owner, loaded server-side),
     * every admin edit would silently overwrite that member's public contact
     * details — name, email, city, state, country, lat/lon — with the
     * admin's own. That is a PII-leak regression, not a style nit: it would
     * ship quietly (the save still "succeeds"), be visible only by noticing
     * a car's owner information card showing the wrong person, and would
     * affect every car an admin ever touches until caught.
     *
     * The security reviewer of #1962 flagged that no test pinned this
     * invariant and that the plan's own evidence for it was circular
     * (asserting the test helper's behavior rather than save.php's actual
     * source). This test reads save.php's real source text instead.
     *
     * buildCarDetails() cannot be invoked directly under PHPUnit (see class
     * docblock: every response path in this endpoint file ends in exit via
     * ApiResponse::send(), and buildCarDetails() itself is reached only from
     * inside that request-scoped flow) — hence source inspection, matching
     * this class's established pattern.
     *
     * Three source-level facts are asserted together because the invariant
     * depends on all three holding simultaneously — breaking any one of them
     * reopens the leak even if the others still look correct:
     *
     *   (a) `new Owner(` is constructed from `$cardetails['user_id']`
     *       (the car row's owner), not from `$user->data()->id` (the
     *       session/admin performing the edit).
     *   (b) That construction happens BEFORE the first Input::raw() consumer
     *       call in the function — the invariant is ordering-dependent: it
     *       only holds because updateYear/updateModel/updateChassis/
     *       updateColor/updateEngine/updatePurchasedate/updateSolddate/
     *       updateComments (all of which read client input)
     *       run AFTER the owner refresh, at the very end of the function.
     *       If the refresh were moved after those calls — or a future
     *       change let one of them write into `$cardetails['user_id']`
     *       before the refresh — client input could reach the Owner
     *       construction.
     *   (c) No assignment to `$cardetails['user_id']` occurs between the
     *       `foreach` that copies the loaded car row onto $cardetails and
     *       the `new Owner(` line — i.e. nothing quietly substitutes a
     *       different user_id into the gap before the refresh reads it.
     *
     * @see https://github.com/elan-registry/registry/issues/1962
     */
    public function testBuildCarDetailsConstructsOwnerFromCarRowNotSessionUserBeforeAnyInputIsProcessed(): void
    {
        $functionBody = $this->readBuildCarDetailsBody();

        // --- (a) Owner must be constructed from the car row's user_id ---
        //
        // This pins an exact source string, so a semantically identical
        // refactor — e.g. hoisting the cast into
        // `$carOwnerId = (int) $cardetails['user_id'];` and passing that —
        // fails here even though it is correct. That false positive is
        // deliberate. Accepting an extracted local would require this test to
        // track what that local holds, which a source-text grep cannot do; the
        // check would then pass for a local assigned from the session user,
        // which is precisely the leak it exists to stop. A red build on a
        // cosmetic refactor is the cheaper mistake. If you hit this, either
        // keep the inline form or update this assertion deliberately, having
        // re-verified by mutation that the leak is still caught.
        $this->assertStringContainsString(
            "new Owner((int) \$cardetails['user_id']);",
            $functionBody,
            "SECURITY (#1962): the owner-contact refresh must construct Owner from " .
            "\$cardetails['user_id'] (the CAR's owner, loaded from the database row), " .
            "never from the logged-in \$user. Constructing it from the session user " .
            "would cause an admin editing a member's car to overwrite that member's " .
            "public contact details (name, email, location) with the admin's own — " .
            "a PII-leak regression, not a style violation."
        );

        $ownerConstructionPos = strpos($functionBody, "new Owner((int) \$cardetails['user_id']);");
        $this->assertIsInt($ownerConstructionPos, 'Could not locate the owner-refresh Owner construction');

        // Asserting the correct construction is PRESENT is not enough: adding a
        // second assignment after it silently wins, and every behavioral test
        // stays green because they construct their own Owner. Verified by
        // mutation — appending
        //
        //     $carOwner = new Owner((int) $user->data()->id);
        //
        // reintroduces the full PII leak with the unit and integration suites
        // both passing. Require exactly one assignment to $carOwner so the
        // pinned construction is not just present but EFFECTIVE.
        $carOwnerAssignments = preg_match_all('/\$carOwner\s*=(?!=)/', $functionBody);
        $this->assertSame(
            1,
            $carOwnerAssignments,
            "SECURITY (#1962): \$carOwner must be assigned exactly once in buildCarDetails(), " .
            "found {$carOwnerAssignments}. A second assignment overwrites the car-row owner " .
            "that the assertion above pins, reintroducing the admin-overwrites-member PII leak " .
            "while leaving the pinned string in place. If you are legitimately restructuring " .
            "this code, keep it to a single assignment rather than relaxing this check."
        );

        // The add-car (`else`) branch legitimately constructs
        // `new Owner($ownerId)` from the session user further down in this
        // same function body — that is a different, non-security-sensitive
        // code path (there is no "car row" yet to be confused with). Confirm
        // we located the EDIT-branch construction specifically, by requiring
        // it to appear strictly before that other construction.
        $sessionOwnerConstructionPos = strpos($functionBody, 'new Owner($ownerId);');
        if ($sessionOwnerConstructionPos !== false) {
            $this->assertLessThan(
                $sessionOwnerConstructionPos,
                $ownerConstructionPos,
                "The car-row owner construction (new Owner((int) \$cardetails['user_id'])) " .
                "must appear before the unrelated add-car-branch session-owner construction " .
                "(new Owner(\$ownerId)) — if this ordering ever inverts, re-verify by function " .
                "name/branch rather than assuming position alone still disambiguates them."
            );
        }

        // --- (b) Must run before any Input::raw() consumer call ---
        $inputConsumers = [
            'updateYear', 'updateModel', 'updateChassis', 'updateColor', 'updateEngine',
            'updatePurchasedate', 'updateSolddate', 'updateComments',
        ];
        foreach ($inputConsumers as $consumer) {
            $callPos = strpos($functionBody, $consumer . '($cardetails');
            $this->assertIsInt($callPos, "Could not locate the {$consumer}() call in buildCarDetails()");
            $this->assertGreaterThan(
                $ownerConstructionPos,
                $callPos,
                "SECURITY (#1962): {$consumer}() processes client input (Input::raw()) and must " .
                "run AFTER the owner-contact refresh's Owner construction, not before. The " .
                "no-client-input-reaches-user_id invariant is ordering-dependent: if any of these " .
                "input-processing calls moved ahead of the refresh, a client-supplied value could " .
                "reach \$cardetails['user_id'] before it is read into new Owner(...), letting a " .
                "malicious request substitute another owner's contact details onto this car."
            );
        }

        // --- (c) No user_id assignment between the car-row foreach and the Owner construction ---
        $foreachPos = strpos($functionBody, 'foreach ($carData as $key => $value)');
        $this->assertIsInt($foreachPos, 'Could not locate the car-row copy foreach in buildCarDetails()');
        $this->assertLessThan(
            $ownerConstructionPos,
            $foreachPos,
            'The car-row copy foreach must precede the owner-refresh Owner construction'
        );

        $gap = substr($functionBody, $foreachPos, $ownerConstructionPos - $foreachPos);
        $this->assertDoesNotMatchRegularExpression(
            '/\$cardetails\[\'user_id\'\]\s*=/',
            $gap,
            "SECURITY (#1962): nothing may assign to \$cardetails['user_id'] between the point " .
            "the car row is copied onto \$cardetails and the owner-refresh Owner construction. " .
            "Such an assignment would let a client- or session-derived value silently replace " .
            "the car's real owner before the refresh reads it, defeating invariant (a) above " .
            "even though the Owner(...) call itself still reads \$cardetails['user_id']."
        );
    }

    /**
     * The endpoint must actually CALL the refresher, and assign its result.
     *
     * The merge logic itself lives in {@see \ElanRegistry\OwnerContactRefresher}
     * and is properly unit- and integration-tested there. But no test that
     * exercises the refresher can tell whether `save.php` still invokes it —
     * deleting the call here leaves every one of those tests green, because they
     * construct the refresher themselves. This source-level assertion is the
     * only thing standing between "#1962 is fixed" and "#1962's code exists in
     * the repo but never runs".
     *
     * Asserting on source text is a poor substitute for executing the code, and
     * it is used here only because `save.php` cannot be require()'d — every
     * branch ends in ApiResponse::send() -> exit. Keep the scope of these
     * source assertions minimal: pin that the call happens and that its result
     * is assigned back, and leave what the call DOES to the refresher's own
     * tests, which are behavioral.
     */
    public function testBuildCarDetailsActuallyInvokesTheOwnerContactRefresher(): void
    {
        $functionBody = $this->readBuildCarDetailsBody();

        $this->assertStringContainsString(
            'new OwnerContactRefresher()',
            $functionBody,
            '#1962: buildCarDetails() must construct an OwnerContactRefresher — without it ' .
            'the owner-contact columns are never refreshed and the bug is unfixed, however ' .
            'thoroughly the refresher class itself is tested.'
        );

        // The result must be assigned back. `refresh()` is pure — it returns a
        // new array rather than mutating by reference — so a bare call
        // statement would type-check, pass PHPStan, and silently do nothing.
        $this->assertMatchesRegularExpression(
            '/\$cardetails\s*=\s*\$\w+->refresh\(\s*\$cardetails\s*,/',
            $functionBody,
            '#1962: the refresher\'s return value must be assigned back to $cardetails. ' .
            'OwnerContactRefresher::refresh() does not mutate its argument, so calling it ' .
            'without assigning the result discards the refreshed values entirely.'
        );

        // The refresh must still happen before any client input is processed —
        // the same ordering invariant the security test above pins for the
        // Owner construction, re-checked for the call that consumes it.
        $refreshCallPos = strpos($functionBody, '->refresh($cardetails');
        $this->assertIsInt($refreshCallPos, 'Could not locate the refresh() call');

        foreach (['updateYear', 'updateComments'] as $consumer) {
            $callPos = strpos($functionBody, $consumer . '($cardetails');
            $this->assertIsInt($callPos, "Could not locate the {$consumer}() call");
            $this->assertGreaterThan(
                $refreshCallPos,
                $callPos,
                "SECURITY (#1962): {$consumer}() processes client input and must run AFTER " .
                'the owner-contact refresh, for the same reason the Owner construction must.'
            );
        }
    }

    // =========================================================================
    // save.php — car load failure in updateCar (source inspection)
    // =========================================================================

    /**
     * When the car fails to load in buildCarDetails(), the not-found branch
     * must add an entry to $errors and log under the car-error category.
     *
     * Without the $errors entry, the caller's `!empty($errors)` guard passes,
     * uploadImages() runs for a car that does not exist, and Car::update()
     * then reports "No data or ID provided for car update" (#1975).
     *
     * Source inspection: in practice the branch is reachable only when the
     * car is deleted between the auth guard's load and this load, which a
     * request cannot drive, and save.php cannot be require()'d (every path
     * exits). Comments are removed first, so a commented-out line cannot
     * satisfy the assertions.
     *
     * @see https://github.com/elan-registry/registry/issues/1975
     */
    public function testBuildCarDetailsNotFoundBranchAppendsErrorAndLogsAsError(): void
    {
        $functionBody = $this->withoutComments($this->readBuildCarDetailsBody());

        $logMessagePos = strpos($functionBody, "' not found (deleted after the auth check) for user_id='");
        $this->assertIsInt($logMessagePos, 'Could not locate the not-found log message in buildCarDetails()');

        // The branch runs from the `} else {` before the log message to the
        // first `return;` after it.
        $branchStart = strrpos(substr($functionBody, 0, $logMessagePos), '} else {');
        $this->assertIsInt($branchStart, 'Could not locate the start of the not-found branch');
        $branchEnd = strpos($functionBody, 'return;', $logMessagePos);
        $this->assertIsInt($branchEnd, 'The not-found branch must end in return;');
        $branch = substr($functionBody, $branchStart, $branchEnd - $branchStart);

        $this->assertMatchesRegularExpression(
            '/\$errors\[\]\s*=\s*\'The car you are trying to edit could not be found\.\';/',
            $branch,
            '#1975: the not-found branch must append its message to $errors before return; ' .
            'otherwise the caller\'s !empty($errors) guard passes and uploadImages() runs.'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_CAR_ERRORS',
            $branch,
            '#1975: a car that fails to load is an error and must be logged under CarErrors.'
        );
        $this->assertStringNotContainsString(
            'LogCategories::LOG_CATEGORY_CAR_ACTIONS',
            $branch,
            '#1975: the not-found branch must not log under CarActions, which records normal activity.'
        );
    }

    /**
     * In the `case "updateCar"` branch, the `!empty($errors)` guard must run
     * after buildCarDetails() and must end the request with ->send() before
     * uploadImages(), so that an error from buildCarDetails() stops the
     * request before any image upload (#1975).
     *
     * @see https://github.com/elan-registry/registry/issues/1975
     */
    public function testUpdateCarChecksErrorsBeforeUploadingImages(): void
    {
        $content = $this->withoutComments($this->readEndpointSource(self::SAVE_ENDPOINT));

        $caseStart = strpos($content, 'case "updateCar":');
        $this->assertIsInt($caseStart, 'Could not locate the updateCar case');

        $buildPos = strpos($content, 'buildCarDetails(', $caseStart);
        $this->assertIsInt($buildPos, 'updateCar must call buildCarDetails()');

        $guardPos = strpos($content, 'if (!empty($errors))', $buildPos);
        $this->assertIsInt($guardPos, 'updateCar must check $errors after buildCarDetails()');

        $uploadPos = strpos($content, 'uploadImages(', $caseStart);
        $this->assertIsInt($uploadPos, 'updateCar must call uploadImages()');

        $this->assertLessThan(
            $uploadPos,
            $guardPos,
            '#1975: the $errors guard must run before uploadImages(), so a car that ' .
            'failed to load in buildCarDetails() gets no image upload.'
        );

        // Order alone is not enough: a guard that only logs lets the request
        // continue into uploadImages().
        $this->assertStringContainsString(
            '->send();',
            substr($content, $guardPos, $uploadPos - $guardPos),
            '#1975: the $errors guard must end the request with ->send() before uploadImages().'
        );
    }
}
