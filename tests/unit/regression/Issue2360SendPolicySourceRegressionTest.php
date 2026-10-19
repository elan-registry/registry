<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2360: contact mail must pass the send policy, and no log line may hold an address.
 *
 * This test reads the source of the two contact endpoints as PHP tokens.
 * It fails when old code comes back:
 *
 * - An `email()` call that no `SendPolicy` call (`forUser` or `forAddress`)
 *   comes before. A comment or a string that names the policy does not count,
 *   because the checker compares token positions, not text positions.
 * - A `logger(` or `->withLogging(` call that names an address variable
 *   or an `->email` property.
 * - Two different success texts in send-owner-email.php. A blocked send must
 *   return the same text as a real send, or the sender can learn the
 *   recipient's email state.
 *
 * Every checker has a negative fixture: a source string that holds the
 * defect. The test asserts that the checker flags it.
 *
 * To run the checkers against other copies of the endpoints, set the
 * environment variable ISSUE2360_SOURCE_DIR to a directory that holds the same
 * relative paths. This proves that the test fails on the pre-change files.
 *
 * - A reference to the send policy or to an owner e-mail flag in the files that
 *   render the contact control and the contact form. Acceptance criterion: the
 *   control and the form show for every owner, whatever the recipient's state.
 *
 * Variables that the log check allows: none. A variable name that holds
 * "email" (any case) fails, and so do the names in ADDRESS_VARIABLES. No log
 * call in either file names an address variable or an email property, so no
 * variable needs an exception.
 *
 * @issue 2360
 * @link https://github.com/elan-registry/registry/issues/2360
 */
#[Group('regression')]
final class Issue2360SendPolicySourceRegressionTest extends TestCase
{
    private const OWNER_FILE = 'app/api/contact/send-owner-email.php';
    private const ADMIN_FILE = 'app/admin/includes/process-admin-contact.php';

    /**
     * Variable names that hold an address or an object with an address,
     * but have no "email" in the name. Lower case, without the `$`.
     */
    private const ADDRESS_VARIABLES = [
        'recipient', 'replyto', 'reply_to', 'to', 'from',
        'todata', 'fromdata', 'ownerdata', 'admindata', 'template', 'opts', 'replyopts',
    ];

    /** Files that render the contact control (two forms) and the contact form. */
    private const CONTACT_UI_FILES = [
        'app/views/cars/_car_hero_actions.php',
        'app/owner/contact/owner.php',
    ];

    /**
     * Names that must not appear in a contact UI file: the policy classes, the
     * flag reader, and the profile flag columns. Lower case.
     */
    private const FLAG_NAMES = [
        'effectiveemailflags', 'sendpolicy', 'senddecision', 'mailkind',
        'email_opted_out', 'email_blocked', 'email_bounced', 'email_bounce_cleared_at',
        'email_address_since', 'email_address_seen',
    ];

    /** Fixture: a contact UI file that gates the control on a recipient flag. */
    private const BAD_UI_SOURCE = <<<'PHP'
<?php
// A comment may name SendPolicy without failing the check.
if (!$flags->email_opted_out) { echo '<form></form>'; }
PHP;

    /** Fixture: an address in a log text, and an email() call before the policy call. */
    private const BAD_SOURCE = <<<'PHP'
<?php
// forUser(MailKind::OwnerContact, 1) in a comment must not count.
$note = 'forAddress(';
$result = email($toEmail, $subject, $body);
logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'sent to ' . $toEmail);
$decision = $policy->forUser(MailKind::OwnerContact, 1);
PHP;

    /** Fixture: the policy call comes first and the logs name IDs only. */
    private const GOOD_SOURCE = <<<'PHP'
<?php
include 'template.php';
$decision = $policy->forUser(MailKind::OwnerContact, $toUserId);
logger(0, X, 'blocked: sender ' . $fromUserId . ' recipient ' . $toUserId);
$result = email($toEmail, $subject, $body);
ApiResponse::success('ok')->withLogging(0, X, "sent: {$fromUserId} {$toUserId}")->send();
PHP;

    // ------------------------------------------------------------------
    // Checkers
    // ------------------------------------------------------------------

    /**
     * @return list<array{0: int|null, 1: string}> Tokens without whitespace and comments.
     */
    private static function tokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out[] = [$token[0], $token[1]];
            } else {
                $out[] = [null, $token];
            }
        }
        return $out;
    }

    /**
     * Token index of each call to the named function or method.
     *
     * @param list<array{0: int|null, 1: string}> $tokens
     * @return list<int>
     */
    private static function callIndexes(array $tokens, string $name, bool $isMethod): array
    {
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (strtolower(ltrim($tokens[$i][1], '\\')) !== strtolower($name)) {
                continue;
            }
            if (!in_array($tokens[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (($tokens[$i + 1][1] ?? '') !== '(') {
                continue;
            }
            $prev = $i > 0 ? $tokens[$i - 1] : [null, ''];
            $isMemberAccess = in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            if ($isMethod) {
                if (!$isMemberAccess) {
                    continue;
                }
            } elseif ($isMemberAccess || in_array($prev[0], [T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $found[] = $i;
        }
        return $found;
    }

    /**
     * Token index of the first `include` or `require` of a path that holds the needle.
     *
     * @param list<array{0: int|null, 1: string}> $tokens
     */
    private static function includeIndex(array $tokens, string $needle): ?int
    {
        foreach ($tokens as $i => $token) {
            if ($token[0] === T_INCLUDE && self::statementText($tokens, $i) !== '' && str_contains(self::statementText($tokens, $i), $needle)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<array{0: int|null, 1: string}> $tokens
     */
    private static function statementText(array $tokens, int $start): string
    {
        $text = '';
        for ($i = $start; $i < count($tokens) && $tokens[$i][1] !== ';'; $i++) {
            $text .= $tokens[$i][1];
        }
        return $text;
    }

    /**
     * Tokens inside the balanced parentheses that start at $open.
     *
     * @param list<array{0: int|null, 1: string}> $tokens
     * @return list<array{0: int|null, 1: string}>
     */
    private static function argumentTokens(array $tokens, int $open): array
    {
        $depth = 0;
        $args = [];
        for ($i = $open; $i < count($tokens); $i++) {
            $text = $tokens[$i][1];
            if ($tokens[$i][0] === null && $text === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($tokens[$i][0] === null && $text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $args;
                }
            }
            $args[] = $tokens[$i];
        }
        return $args;
    }

    /**
     * Policy-before-email() violations.
     *
     * @return list<string>
     */
    private static function orderViolations(string $source, bool $requireBothPolicyCalls): array
    {
        $tokens = self::tokens($source);
        $emails = self::callIndexes($tokens, 'email', false);
        $forUser = self::callIndexes($tokens, 'forUser', true);
        $forAddress = self::callIndexes($tokens, 'forAddress', true);
        $violations = [];

        if ($emails === []) {
            $violations[] = 'no email() call found';
        }
        foreach ($emails as $emailIndex) {
            $kinds = $requireBothPolicyCalls ? ['forUser' => $forUser, 'forAddress' => $forAddress] : ['forUser' => $forUser];
            foreach ($kinds as $kind => $indexes) {
                $before = array_filter($indexes, static fn (int $i): bool => $i < $emailIndex);
                if ($before === []) {
                    $violations[] = "email() at token {$emailIndex} has no {$kind}() call before it";
                }
            }
        }
        return $violations;
    }

    /**
     * Address-in-log violations.
     *
     * @return list<string>
     */
    private static function logViolations(string $source): array
    {
        $tokens = self::tokens($source);
        $calls = array_merge(
            self::callIndexes($tokens, 'logger', false),
            self::callIndexes($tokens, 'withLogging', true)
        );
        $violations = [];
        foreach ($calls as $callIndex) {
            $args = self::argumentTokens($tokens, $callIndex + 1);
            foreach ($args as $k => $arg) {
                if ($arg[0] === T_VARIABLE) {
                    $name = strtolower(ltrim($arg[1], '$'));
                    if (str_contains($name, 'email') || in_array($name, self::ADDRESS_VARIABLES, true)) {
                        $violations[] = "{$arg[1]} in a log call at token {$callIndex}";
                    }
                } elseif ($arg[0] === T_STRING && in_array($args[$k - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    if (str_contains(strtolower($arg[1]), 'email')) {
                        $violations[] = "->{$arg[1]} in a log call at token {$callIndex}";
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * The checked log calls, so that a test cannot pass over zero calls.
     */
    private static function logCallCount(string $source): int
    {
        $tokens = self::tokens($source);
        return count(self::callIndexes($tokens, 'logger', false))
            + count(self::callIndexes($tokens, 'withLogging', true));
    }

    /**
     * Policy and flag names in the code of a file. Comments do not count.
     *
     * @return list<string>
     */
    private static function flagReferences(string $source): array
    {
        $found = [];
        foreach (self::tokens($source) as [$type, $text]) {
            if ($type === null) {
                continue;
            }
            $lower = strtolower($text);
            foreach (self::FLAG_NAMES as $name) {
                if (str_contains($lower, $name)) {
                    $found[] = $name;
                }
            }
        }
        return array_values(array_unique($found));
    }

    private function source(string $relativePath): string
    {
        $override = getenv('ISSUE2360_SOURCE_DIR');
        $base = ($override !== false && $override !== '') ? $override : dirname(__DIR__, 3);
        $content = file_get_contents($base . '/' . $relativePath);
        $this->assertNotFalse($content, "Cannot read {$relativePath}");
        return $content;
    }

    // ------------------------------------------------------------------
    // Order: policy before email()
    // ------------------------------------------------------------------

    public function test_ownerEndpoint_policyCallComesBeforeEmailCall(): void
    {
        $this->assertSame([], self::orderViolations($this->source(self::OWNER_FILE), false));
    }

    public function test_adminEndpoint_bothPolicyCallsComeBeforeEmailCall(): void
    {
        $this->assertSame([], self::orderViolations($this->source(self::ADMIN_FILE), true));
    }

    #[DataProvider('endpointProvider')]
    public function test_endpoint_policyCallComesAfterTemplateRender(string $file, string $template): void
    {
        $tokens = self::tokens($this->source($file));
        $include = self::includeIndex($tokens, $template);
        $policy = array_merge(
            self::callIndexes($tokens, 'forUser', true),
            self::callIndexes($tokens, 'forAddress', true)
        );

        $this->assertNotNull($include, "{$file} must include {$template}");
        $this->assertNotEmpty($policy, "{$file} must call the send policy");
        $this->assertGreaterThan($include, min($policy), 'The policy check runs after the template render.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'owner' => [self::OWNER_FILE, '_member_to_owner.php'],
            'admin' => [self::ADMIN_FILE, '_admin_to_owner.php'],
        ];
    }

    public function test_ownerEndpoint_blockedAndSentBranchesUseTheSameSuccessLiteral(): void
    {
        $tokens = self::tokens($this->source(self::OWNER_FILE));
        $emailCalls = self::callIndexes($tokens, 'email', false);
        $this->assertCount(1, $emailCalls);

        $literals = [];
        foreach ($tokens as $i => $token) {
            if (
                $token[0] === T_STRING && $token[1] === 'success'
                && ($tokens[$i - 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i - 2][1] ?? '') === 'ApiResponse'
                && ($tokens[$i + 1][1] ?? '') === '('
            ) {
                $literals[] = ['index' => $i, 'args' => self::argumentTokens($tokens, $i + 1)];
            }
        }

        $this->assertCount(2, $literals, 'One success response for the blocked branch and one for the sent branch.');
        $this->assertLessThan($emailCalls[0], $literals[0]['index'], 'The blocked branch comes before email().');
        $this->assertGreaterThan($emailCalls[0], $literals[1]['index'], 'The sent branch comes after email().');
        $this->assertSame($literals[0]['args'], $literals[1]['args']);
        $this->assertSame(T_CONSTANT_ENCAPSED_STRING, $literals[0]['args'][0][0], 'The text is one string literal.');
        $this->assertCount(1, $literals[0]['args']);
    }

    // ------------------------------------------------------------------
    // No address in logs
    // ------------------------------------------------------------------

    #[DataProvider('endpointFileProvider')]
    public function test_endpoint_noLogCallNamesAnAddress(string $file): void
    {
        $source = $this->source($file);

        $this->assertGreaterThan(0, self::logCallCount($source), 'The checker must see log calls.');
        $this->assertSame([], self::logViolations($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointFileProvider(): array
    {
        return ['owner' => [self::OWNER_FILE], 'admin' => [self::ADMIN_FILE]];
    }

    // ------------------------------------------------------------------
    // The contact control and form show for every owner
    // ------------------------------------------------------------------

    #[DataProvider('contactUiFileProvider')]
    public function test_contactUiFile_namesNoPolicyOrOwnerFlag(string $file): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString('contact', $source, 'The checker must read the contact UI source.');
        $this->assertSame([], self::flagReferences($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function contactUiFileProvider(): array
    {
        $cases = [];
        foreach (self::CONTACT_UI_FILES as $file) {
            $cases[$file] = [$file];
        }
        return $cases;
    }

    public function test_flagReferenceChecker_flagsAFlagColumnInCode(): void
    {
        $this->assertSame(['email_opted_out'], self::flagReferences(self::BAD_UI_SOURCE));
    }

    public function test_flagReferenceChecker_flagsPolicyClassNames(): void
    {
        $source = '<?php $d = (new \\ElanRegistry\\Car\\SendPolicy($r))->forUser(MailKind::OwnerContact, 1); $f = effectiveEmailFlags($u);';

        $found = self::flagReferences($source);
        sort($found);
        $this->assertSame(['effectiveemailflags', 'mailkind', 'sendpolicy'], $found);
    }

    public function test_flagReferenceChecker_acceptsContactMarkupWithoutFlags(): void
    {
        $source = "<?php \$x = 1; ?>\n<form action=\"owner.php\"><input name=\"action\" value=\"contact_owner\"></form>";

        $this->assertSame([], self::flagReferences($source));
    }

    // ------------------------------------------------------------------
    // Negative fixtures: the checkers must be able to fail
    // ------------------------------------------------------------------

    public function test_orderChecker_flagsEmailCallBeforePolicyCall(): void
    {
        $violations = self::orderViolations(self::BAD_SOURCE, false);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('no forUser() call before it', $violations[0]);
    }

    public function test_orderChecker_flagsMissingForAddressCallWhenBothAreRequired(): void
    {
        $violations = self::orderViolations(self::GOOD_SOURCE, true);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('forAddress()', $violations[0]);
    }

    public function test_orderChecker_flagsSourceWithNoEmailCall(): void
    {
        $this->assertSame(['no email() call found'], self::orderViolations('<?php $a = 1;', false));
    }

    public function test_orderChecker_ignoresMemberAndDefinitionNamedEmail(): void
    {
        $source = '<?php function email($a) {} $m->email($x); Mailer::email($x); $r = $p->forUser(1);';

        $this->assertSame(['no email() call found'], self::orderViolations($source, false));
    }

    public function test_orderChecker_acceptsPolicyCallBeforeEmailCall(): void
    {
        $this->assertSame([], self::orderViolations(self::GOOD_SOURCE, false));
    }

    public function test_logChecker_flagsAddressVariableInLoggerCall(): void
    {
        $violations = self::logViolations(self::BAD_SOURCE);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('$toEmail', $violations[0]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badLogSourceProvider(): array
    {
        return [
            'logger, interpolated' => ['<?php logger(1, X, "to {$toEmail}");', '$toEmail'],
            'logger, plain interpolation' => ['<?php logger(1, X, "to $targetEmail");', '$targetEmail'],
            'withLogging, concatenated' => ['<?php $r->withLogging(1, X, "a " . $recipient)->send();', '$recipient'],
            'reply-to variable' => ['<?php logger(1, X, "a " . $replyTo);', '$replyTo'],
            'property read' => ['<?php logger(1, X, "a " . $owner->email);', '->email'],
            'property read, nested call' => ['<?php logger(1, X, strtolower($d->email));', '->email'],
            'name with email in other case' => ['<?php logger(1, X, "a " . $OwnerEMAIL);', '$OwnerEMAIL'],
            'second line of a split call' => ["<?php logger(1, X,\n 'a' .\n \$fromEmail);", '$fromEmail'],
        ];
    }

    #[DataProvider('badLogSourceProvider')]
    public function test_logChecker_flagsAddressReference(string $source, string $needle): void
    {
        $violations = self::logViolations($source);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString($needle, $violations[0]);
    }

    public function test_logChecker_ignoresAddressVariableOutsideLogCalls(): void
    {
        $source = '<?php $x = email($toEmail, $s, $b); $r = foo($toEmail); logger(1, X, "id " . $userId);';

        $this->assertSame([], self::logViolations($source));
    }

    public function test_logChecker_acceptsIdOnlyLogCalls(): void
    {
        $this->assertSame([], self::logViolations(self::GOOD_SOURCE));
    }
}
