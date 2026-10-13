<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailNoticeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PhpBuiltinServer;

/**
 * #2101: every reader takes the deliverability state from the owner's profile.
 *
 * The readers are the admin verification queue and its counts, the admin user
 * form, the Email on file row, the verification-email opt-out page and the
 * account notice. Account mail (password reset) is the one path that must NOT
 * read the profile. Each flag has its own owner, and one unflagged owner is the
 * control, so a reader that ignores the profile cannot pass by chance.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryProfileFlagReadersTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private static ?PhpBuiltinServer $server = null;

    private CarRepository $repo;

    /** @var list<int> Owners whose flag-change log rows tearDown deletes */
    private array $loggedOwnerIds = [];

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->loggedOwnerIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->loggedOwnerIds as $ownerId) {
                $this->db->query(
                    "DELETE FROM logs
                      WHERE logtype = 'EmailBounced'
                        AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                        AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
                    [(string) $ownerId]
                );
            }
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'verification_code_attempt'");
        }
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
        parent::tearDown();
    }

    // ---- fixtures -----------------------------------------------------------------------------

    /**
     * An owner with a profile and one stale, unsold car with an email, so that
     * the car is eligible until a flag excludes it.
     *
     * @return array{owner: int, car: int, email: string}
     */
    private function createOwner(string $label): array
    {
        $email = $label . '-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $email], true);
        $this->loggedOwnerIds[] = $ownerId;
        $stale = date('Y-m-d H:i:s', strtotime('-3 years'));
        $carId = $this->createTestCar($ownerId, [
            'email' => $email,
            'last_verified' => null,
            'owner_last_updated' => $stale,
            'mtime' => $stale,
            'solddate' => null,
        ]);

        return ['owner' => $ownerId, 'car' => $carId, 'email' => $email];
    }

    /**
     * One owner per flag, and one without a flag.
     *
     * @return array{opted: array{owner: int, car: int, email: string}, blocked: array{owner: int, car: int, email: string}, bounced: array{owner: int, car: int, email: string}, none: array{owner: int, car: int, email: string}}
     */
    private function createOwnerPerFlag(): array
    {
        $opted = $this->createOwner('opted');
        $blocked = $this->createOwner('blocked');
        $bounced = $this->createOwner('bounced');
        $none = $this->createOwner('none');
        $this->setOwnerOptedOut($opted['owner'], true, '2026-03-04 05:06:07');
        $this->setOwnerBlocked($blocked['owner'], $blocked['email'], '2026-04-05 06:07:08', 'spam');
        $this->setOwnerBounced($bounced['owner'], $bounced['email'], '2026-05-06 07:08:09');

        return ['opted' => $opted, 'blocked' => $blocked, 'bounced' => $bounced, 'none' => $none];
    }

    /**
     * @param list<int> $carIds
     * @return array<int, object> Queue row by car id, for the cars asked for
     */
    private function queueRows(string $status, array $carIds): array
    {
        $rows = [];
        foreach ($this->repo->findVerificationQueue($status, null, PHP_INT_MAX) as $row) {
            if (in_array((int) $row->id, $carIds, true)) {
                $rows[(int) $row->id] = $row;
            }
        }

        return $rows;
    }

    // ---- profile readers ----------------------------------------------------------------------

    public function testProfileUserIdIsUniqueSoNoReaderNeedsMax(): void
    {
        $owner = $this->createOwner('unique');

        $result = $this->db->query(
            "INSERT INTO profiles (user_id, bio, city, state, country) VALUES (?, '', '', '', '')",
            [$owner['owner']]
        );

        $this->assertTrue($result->error(), 'A second profiles row for the same user must be rejected');
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$owner['owner']])->first()->cnt
        );
    }

    public function testRepositorySqlHasNoMaxOverTheProfileFlagColumns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/usersc/classes/Car/CarRepository.php');
        $this->assertNotSame('', $source);

        $this->assertSame(
            0,
            preg_match('/MAX\s*\(\s*(p\.|profiles\.)?email_(opted_out|blocked|bounced|suppressed)/i', $source),
            'With a unique profiles.user_id the flag readers read the one row. MAX() over the flag columns is the old duplicate-row workaround'
        );
    }

    public function testBlockedFlagWithUnknownCauseIsStillActive(): void
    {
        $owner = $this->createOwner('unknowncause');
        $this->setOwnerBlocked($owner['owner'], $owner['email'], null, null);
        $control = $this->createOwner('unknowncause-control');

        $state = $this->repo->findProfileEmailState($owner['owner']);
        $this->assertIsObject($state);
        $this->assertSame(1, (int) $state->email_blocked);
        $this->assertNull($state->email_blocked_cause, 'Test setup: the cause is unknown');

        $eligible = $this->allVerificationEligibleCarIds();
        $this->assertNotContains($owner['car'], $eligible, 'A block with no known cause still excludes the car');
        $this->assertContains($control['car'], $eligible, 'Control car stays eligible');
        $this->assertArrayHasKey($owner['car'], $this->queueRows('suppressed', [$owner['car']]), 'The block is shown in the Suppressed queue');
    }

    // ---- admin verification queue and counts ---------------------------------------------------

    public function testQueueRowsShowEachFlagFromTheProfile(): void
    {
        $owners = $this->createOwnerPerFlag();
        $carIds = array_column($owners, 'car');

        $rows = $this->queueRows('all', $carIds);

        $this->assertCount(4, $rows, 'Each of the 4 fixture cars must be in the all queue');
        $expected = [
            'opted'   => [1, 0, 0],
            'blocked' => [0, 1, 0],
            'bounced' => [0, 0, 1],
            'none'    => [0, 0, 0],
        ];
        foreach ($expected as $name => [$optedOut, $blocked, $bounced]) {
            $row = $rows[$owners[$name]['car']];
            $this->assertSame(1, (int) $row->has_profile, "{$name}: has a profile");
            $this->assertSame($optedOut, (int) $row->email_opted_out, "{$name}: email_opted_out");
            $this->assertSame($blocked, (int) $row->email_blocked_active, "{$name}: email_blocked_active");
            $this->assertSame($bounced, (int) $row->email_bounced_active, "{$name}: email_bounced_active");
        }
        $this->assertSame('spam', $rows[$owners['blocked']['car']]->email_blocked_cause);
    }

    public function testSuppressedAndBouncedQueuesFollowTheProfileFlags(): void
    {
        $owners = $this->createOwnerPerFlag();
        $carIds = array_column($owners, 'car');

        $suppressed = array_keys($this->queueRows('suppressed', $carIds));
        $bounced = array_keys($this->queueRows('bounced', $carIds));

        sort($suppressed);
        $expectedSuppressed = [$owners['opted']['car'], $owners['blocked']['car']];
        sort($expectedSuppressed);
        $this->assertSame($expectedSuppressed, $suppressed, 'Suppressed = opted out or blocked');
        $this->assertSame([$owners['bounced']['car']], $bounced, 'Bounced = bounced');
    }

    public function testSummaryCountsSuppressedAsOptedOutOrBlockedAndMatchesTheQueues(): void
    {
        $owners = $this->createOwnerPerFlag();

        $summary = $this->repo->countVerificationSummary(null);
        $suppressedQueue = $this->repo->findVerificationQueue('suppressed', null, PHP_INT_MAX);
        $bouncedQueue = $this->repo->findVerificationQueue('bounced', null, PHP_INT_MAX);

        $this->assertSame($summary['suppressed'], count($suppressedQueue), 'The Suppressed card and pill agree');
        $this->assertSame($summary['bounced'], count($bouncedQueue), 'The Bounced card and pill agree');
        $suppressedIds = array_map(static fn (object $r): int => (int) $r->id, $suppressedQueue);
        $this->assertContains($owners['opted']['car'], $suppressedIds);
        $this->assertContains($owners['blocked']['car'], $suppressedIds);
        $this->assertNotContains($owners['bounced']['car'], $suppressedIds, 'A bounce alone is not Suppressed');
        $this->assertNotContains($owners['none']['car'], $suppressedIds);
        $this->assertGreaterThanOrEqual(2, $summary['suppressed']);
    }

    // ---- admin user form -----------------------------------------------------------------------

    /**
     * Render the admin user form hook against the real database.
     */
    private function renderUserForm(int $ownerId): string
    {
        global $userId, $us_url_root;
        // $us_url_root also feeds getVericodeSecret(), which the opt-out page
        // tests use later. Put back the real value after the render.
        $savedUserId = $userId ?? null;
        $savedUrlRoot = $us_url_root ?? null;
        $userId = $ownerId;
        $us_url_root = '/';
        $db = DB::getInstance();
        $hook = dirname(__DIR__, 4) . '/usersc/plugins/hooker/hooks/user_form_hook.php';
        $this->assertFileExists($hook);

        ob_start();
        try {
            include $hook;
        } finally {
            $html = (string) ob_get_clean();
            $userId = $savedUserId;
            $us_url_root = $savedUrlRoot;
        }

        return $html;
    }

    public function testUserFormShowsEachFlagFromTheProfile(): void
    {
        $owners = $this->createOwnerPerFlag();

        $opted = $this->renderUserForm($owners['opted']['owner']);
        $this->assertStringContainsString('Owner opted out', $opted);
        $this->assertStringNotContainsString('No delivery problems recorded', $opted);
        $this->assertStringNotContainsString('>Bounced<', $opted);

        $blocked = $this->renderUserForm($owners['blocked']['owner']);
        $this->assertStringContainsString('Blocked (cause: spam)', $blocked);
        $this->assertStringContainsString($owners['blocked']['email'], $blocked);
        $this->assertStringNotContainsString('Owner opted out', $blocked);

        $bounced = $this->renderUserForm($owners['bounced']['owner']);
        $this->assertStringContainsString('>Bounced<', $bounced);
        $this->assertStringContainsString($owners['bounced']['email'], $bounced);
        $this->assertStringNotContainsString('Blocked (cause', $bounced);

        $none = $this->renderUserForm($owners['none']['owner']);
        $this->assertStringContainsString('No delivery problems recorded', $none);
    }

    public function testUserFormShowsAnUnknownBlockCause(): void
    {
        $owner = $this->createOwner('formunknown');
        $this->setOwnerBlocked($owner['owner'], $owner['email'], null, null);

        $this->assertStringContainsString('Blocked (cause: unknown)', $this->renderUserForm($owner['owner']));
    }

    public function testUserFormForAnOwnerWithNoProfileSaysSo(): void
    {
        $ownerId = $this->createTestUser();

        $html = $this->renderUserForm($ownerId);

        $this->assertStringContainsString('This owner has no profile, so no verification emails are sent.', $html);
        $this->assertStringNotContainsString('No delivery problems recorded', $html);
    }

    // ---- Email on file row ---------------------------------------------------------------------

    private function renderVehicleInfoCard(int $carId, ?EffectiveEmailFlags $ownerEmailState, bool $viewerIsRegistryAdmin): string
    {
        $carData = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($carData);
        $purchaseDate = null;
        $soldDate = null;
        $headingTag = 'h3';
        $card = dirname(__DIR__, 4) . '/app/views/cars/_vehicle_info_card.php';
        $this->assertFileExists($card);

        ob_start();
        try {
            include $card;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * @return string The text of the Email on file row, or '' when the row is absent
     */
    private function emailOnFileText(string $html): string
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $nodes = (new DOMXPath($doc))->query('//dt[starts-with(normalize-space(.),"Email on file")]/following-sibling::dd[1]');
        $this->assertNotFalse($nodes);

        return $nodes->length === 0 ? '' : trim((string) preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent));
    }

    public function testEmailOnFileRowShowsEachFlagFromTheProfile(): void
    {
        $owners = $this->createOwnerPerFlag();
        $text = [];
        foreach ($owners as $name => $owner) {
            $state = $this->repo->effectiveEmailFlags($owner['owner']);
            $text[$name] = $this->emailOnFileText($this->renderVehicleInfoCard($owner['car'], $state, true));
        }

        $this->assertStringContainsString('Suppressed', $text['opted']);
        $this->assertStringNotContainsString('Bounced', $text['opted']);
        $this->assertStringContainsString('Suppressed', $text['blocked']);
        $this->assertStringNotContainsString('Bounced', $text['blocked']);
        $this->assertStringContainsString('Bounced', $text['bounced']);
        $this->assertStringNotContainsString('Suppressed', $text['bounced']);
        $this->assertSame('', $text['none'], 'No flag, no Email on file row');
    }

    public function testEmailOnFileRowIsHiddenFromAViewerWhoIsNotARegistryAdmin(): void
    {
        $owner = $this->createOwner('cardviewer');
        $this->setOwnerBounced($owner['owner'], $owner['email']);
        $state = $this->repo->effectiveEmailFlags($owner['owner']);

        $this->assertSame('', $this->emailOnFileText($this->renderVehicleInfoCard($owner['car'], $state, false)));
        $this->assertNotSame('', $this->emailOnFileText($this->renderVehicleInfoCard($owner['car'], $state, true)), 'Control: the admin sees it');
    }

    public function testCarDetailsPageLoadsTheOwnerProfileStateForTheCard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/app/owner/cars/details.php');
        $this->assertNotSame('', $source);

        $loadAt = strpos($source, 'effectiveEmailFlags((int) $carData->user_id)');
        $includeAt = strpos($source, "_vehicle_info_card.php'");
        $this->assertNotFalse($loadAt, 'details.php must read the owner profile state');
        $this->assertNotFalse($includeAt);
        $this->assertLessThan($includeAt, $loadAt, 'The state must be loaded before the card is included');
        $this->assertStringContainsString('$ownerEmailState = (new CarRepository(dbi()))', $source);
    }

    // ---- account notice ------------------------------------------------------------------------

    public function testAccountNoticeReadsTheProfileFlags(): void
    {
        $owners = $this->createOwnerPerFlag();
        $builder = new EmailNoticeBuilder($this->repo);

        $opted = $builder->buildForOwner($owners['opted']['owner']);
        $this->assertNotNull($opted);
        $this->assertSame('opted_out', $opted['kind']);
        $this->assertSame('2026-03-04', $opted['date']);

        $blocked = $builder->buildForOwner($owners['blocked']['owner']);
        $this->assertNotNull($blocked);
        $this->assertSame('blocked', $blocked['kind']);
        $this->assertSame($owners['blocked']['email'], $blocked['address']);
        $this->assertSame('spam', $blocked['cause']);
        $this->assertSame('2026-04-05', $blocked['date']);

        $bounced = $builder->buildForOwner($owners['bounced']['owner']);
        $this->assertNotNull($bounced);
        $this->assertSame('bounced', $bounced['kind']);
        $this->assertSame($owners['bounced']['email'], $bounced['address']);
        $this->assertSame('2026-05-06', $bounced['date']);

        $this->assertNull($builder->buildForOwner($owners['none']['owner']), 'No flag, no notice');
    }

    // ---- readers ignore a flag stored for an old address (#2356) -------------------------

    public function testUserFormAndEmailOnFileRowIgnoreABlockAndABounceForAnOldAddress(): void
    {
        $owner = $this->createOwner('oldaddress');
        $this->setOwnerBlocked($owner['owner'], $owner['email'], '2026-04-05 06:07:08', 'spam');
        $this->setOwnerBounced($owner['owner'], $owner['email'], '2026-05-06 07:08:09');
        $this->assertStringContainsString('Blocked (cause: spam)', $this->renderUserForm($owner['owner']), 'Test setup: the flags apply');

        $this->setUserEmailRaw($owner['owner'], 'moved-' . uniqid() . '@example.com');

        $form = $this->renderUserForm($owner['owner']);
        $this->assertStringContainsString('No delivery problems recorded', $form);
        $this->assertStringNotContainsString('Blocked (cause', $form);
        $this->assertStringNotContainsString('>Bounced<', $form);
        $this->assertStringNotContainsString($owner['email'], $form, 'The old address is not shown');
        $this->assertSame(
            '',
            $this->emailOnFileText($this->renderVehicleInfoCard(
                $owner['car'],
                $this->repo->effectiveEmailFlags($owner['owner']),
                true
            )),
            'The Email on file row is absent when no flag applies'
        );
    }

    public function testUserFormShowsTheOptOutAfterTheAddressChangesButNotTheOldBlock(): void
    {
        $owner = $this->createOwner('optoldaddress');
        $this->setOwnerOptedOut($owner['owner'], true, '2026-03-04 05:06:07');
        $this->setOwnerBlocked($owner['owner'], $owner['email'], '2026-04-05 06:07:08', 'spam');
        $this->setUserEmailRaw($owner['owner'], 'moved-' . uniqid() . '@example.com');

        $form = $this->renderUserForm($owner['owner']);

        $this->assertStringContainsString('Owner opted out', $form);
        $this->assertStringNotContainsString('Blocked (cause', $form);
    }

    public function testUserFormAndEmailOnFileRowShowAFlagWithANullAddress(): void
    {
        $owner = $this->createOwner('nulladdress');
        $this->setOwnerBounced($owner['owner'], 'placeholder@example.com');
        $this->db->query('UPDATE profiles SET email_bounced_address = NULL WHERE user_id = ?', [$owner['owner']]);
        $this->setUserEmailRaw($owner['owner'], 'moved-' . uniqid() . '@example.com');

        $this->assertStringContainsString('>Bounced<', $this->renderUserForm($owner['owner']));
        $this->assertStringContainsString(
            'Bounced',
            $this->emailOnFileText($this->renderVehicleInfoCard(
                $owner['car'],
                $this->repo->effectiveEmailFlags($owner['owner']),
                true
            ))
        );
    }

    public function testAccountNoticeIgnoresAFlagForAnOldAddressAndShowsAnOptOut(): void
    {
        $owner = $this->createOwner('noticeold');
        $this->setOwnerBounced($owner['owner'], $owner['email']);
        $this->setUserEmailRaw($owner['owner'], 'moved-' . uniqid() . '@example.com');
        $builder = new EmailNoticeBuilder($this->repo);

        $beforeOptOut = $builder->buildForOwner($owner['owner']);
        $this->assertNull($beforeOptOut, 'A bounce for the old address gives no notice');

        $this->setOwnerOptedOut($owner['owner']);
        $notice = (new EmailNoticeBuilder($this->repo))->buildForOwner($owner['owner']);
        $this->assertNotNull($notice);
        $this->assertSame('opted_out', $notice['kind'], 'The opt-out shows after an address change');
    }

    // ---- verification-email opt-out page --------------------------------------------------------

    private function exposeTestDatabaseToEnvironment(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    private function verifyServer(): PhpBuiltinServer
    {
        $this->exposeTestDatabaseToEnvironment();
        $projectRoot = dirname(__DIR__, 4);
        self::$server ??= PhpBuiltinServer::start($projectRoot, <<<PHP
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
        chdir('{$projectRoot}/app/verify');
        require '{$projectRoot}/app/verify/verify_car.php';
        PHP);

        return self::$server;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function verifyRequest(string $method, string $vericode): array
    {
        $url = $this->verifyServer()->url('/app/verify/verify_car.php') . '?vericode=' . $vericode . '&action=optout';
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query(['vericode' => $vericode]);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $this->assertNotFalse($body, 'curl to verify_car.php failed: ' . curl_error($ch));
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    private function issueVericode(int $carId): string
    {
        $plaintext = bin2hex(random_bytes(16));
        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            [hashVericode($plaintext), date('Y-m-d H:i:s', strtotime('-10 days')), $carId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: vericode fixture');

        return $plaintext;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function optOutPageOwnerProvider(): array
    {
        return [
            'only blocked' => ['blocked'],
            'only bounced' => ['bounced'],
            'no flag'      => ['none'],
        ];
    }

    #[DataProvider('optOutPageOwnerProvider')]
    public function testOptOutPageIsTheSameWhateverTheBlockedOrBouncedState(string $which): void
    {
        $owners = $this->createOwnerPerFlag();
        $owner = $owners[$which];
        $code = $this->issueVericode($owner['car']);

        $before = $this->verifyRequest('GET', $code);
        $this->assertSame(200, $before['status']);
        $this->assertStringContainsString('Stop verification emails', $before['body'], 'Before the opt-out the page asks for it');
        $this->assertStringNotContainsString('unsubscribed from verification emails', $before['body']);
        $this->assertDoesNotMatchRegularExpression('/bounc|blocked|complaint/i', $before['body'], 'The page does not reveal the delivery state');

        $post = $this->verifyRequest('POST', $code);
        $this->assertSame(303, $post['status']);

        $after = $this->verifyRequest('GET', $code);
        $this->assertSame(200, $after['status']);
        $this->assertStringContainsString('unsubscribed from verification emails', $after['body']);
        $this->assertDoesNotMatchRegularExpression('/bounc|blocked|complaint/i', $after['body']);

        $flags = $this->getOwnerEmailFlags($owner['owner']);
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The opt-out is stored whatever the other flags');
        $this->assertSame($which === 'blocked' ? '1' : '0', (string) $flags['email_blocked'], 'The opt-out leaves the block as it was');
        $this->assertSame($which === 'bounced' ? '1' : '0', (string) $flags['email_bounced'], 'The opt-out leaves the bounce as it was');
    }

    public function testOptOutPageShowsTheFormToAnOwnerWhoIsOnlyBlockedAndNotOptedOut(): void
    {
        // Parity with the none-owner is above. This pins the cause: email_blocked = 1 must not
        // render the "already unsubscribed" card, as a reader of email_blocked would.
        $owner = $this->createOwner('optoutblocked');
        $this->setOwnerBlocked($owner['owner'], $owner['email']);
        $code = $this->issueVericode($owner['car']);

        $page = $this->verifyRequest('GET', $code);

        $this->assertStringContainsString('Stop verification emails', $page['body']);
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($owner['owner'])['email_opted_out'], 'A GET writes nothing');
    }

    // ---- account mail is never blocked by a profile flag ----------------------------------------

    /**
     * The password reset send path: the page, its Turnstile hook, the core
     * email() helper, and the Brevo override that email() resolves to.
     *
     * @return list<string>
     */
    private function passwordResetSendPathFiles(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            $root . '/users/forgot_password.php',
            $root . '/usersc/plugins/hooker/hooks/post_turnstile.php',
            $root . '/users/helpers/helpers.php',
            $root . '/usersc/plugins/sendinblue/override.php',
            $root . '/usersc/plugins/sendinblue/functions.php',
        ];
    }

    public function testPasswordResetSendPathReadsNoProfileFlag(): void
    {
        $files = $this->passwordResetSendPathFiles();
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);
            $this->assertNotSame('', $source, $file);
            $this->assertDoesNotMatchRegularExpression(
                '/email_opted_out|email_blocked|email_bounced|findProfileEmailState|effectiveEmailFlags|findProfileOptedOut|CarRepository|FROM\s+profiles/i',
                $source,
                basename($file) . ' is on the password reset send path and must not read a profile flag'
            );
        }
    }

    public function testPasswordResetStillFindsAnOwnerWithEveryFlagSet(): void
    {
        $owner = $this->createOwner('resetflags');
        $this->setOwnerBounced($owner['owner'], $owner['email']);
        $this->setOwnerBlocked($owner['owner'], $owner['email']);
        $this->setOwnerOptedOut($owner['owner']);

        // forgot_password.php sends the reset only when `new User($email)` exists().
        $resetUser = new User($owner['email']);

        $this->assertTrue($resetUser->exists(), 'A bounced, blocked and opted-out owner is still found, so the reset mail is sent');
        $this->assertSame($owner['owner'], (int) $resetUser->data()->id);
    }
}
