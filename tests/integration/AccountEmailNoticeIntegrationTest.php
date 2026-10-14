<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailNoticeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The account email notice end to end (#2356): real rows, the real
 * EmailNoticeBuilder, and the real partial app/views/_email_paused_notice.php
 * rendered with ob_start. The unit tests mock the repository and never run its
 * queries or the template.
 *
 * This file also covers the partial's own escaping and date-omitted
 * branches.
 *
 * Expected text is a literal from the approved copy in the issue.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 * @see https://github.com/elan-registry/registry/issues/2318
 * @see https://github.com/elan-registry/registry/issues/2319
 * @see https://github.com/elan-registry/registry/issues/2320
 */
#[Group('integration')]
#[Group('car-verification')]
final class AccountEmailNoticeIntegrationTest extends IntegrationTestCase
{
    private const URL_ROOT = '/';

    private CarRepository $repo;
    private CarVerificationManager $manager;
    private EmailEventApplier $applier;

    /** @var list<int> Owners whose EmailBounced log rows tearDown deletes */
    private array $loggedOwnerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
        $this->applier = new EmailEventApplier($this->repo, $this->manager);
        $this->loggedOwnerIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // The base class deletes the er_email_events rows of the test users (#2358).
            foreach ($this->loggedOwnerIds as $ownerId) {
                $this->db->query("DELETE FROM logs WHERE logtype = 'EmailBounced' AND user_id = ?", [$ownerId]);
            }
        }
        parent::tearDown();
    }

    // ---- fixtures and rendering -----------------------------------------------------------------

    /**
     * An owner with a profile. users.email is the given address.
     */
    private function createOwner(?string $email = null): int
    {
        $ownerId = $this->createTestUser(['email' => $email ?? 'notice-' . uniqid() . '@example.com'], true);
        $this->loggedOwnerIds[] = $ownerId;
        // A soft bounce before email_address_since does not count (#2358), and these tests use
        // fixed past times. A new profile has since = now.
        $this->db->query('UPDATE profiles SET email_address_since = ? WHERE user_id = ?', ['2020-01-01 00:00:00', $ownerId]);

        return $ownerId;
    }

    private function ownerEmail(int $ownerId): string
    {
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$ownerId])->first();
        $this->assertIsObject($row);

        return (string) $row->email;
    }

    /**
     * Render the partial the way usersc/account.php does: build the state for
     * the owner, then include the template.
     */
    private function renderNotice(int $ownerId): string
    {
        $emailNotice = (new EmailNoticeBuilder($this->repo))->buildForOwner($ownerId);
        $us_url_root = self::URL_ROOT;
        $userspice_nonce = 'test-nonce';
        $partial = dirname(__DIR__, 2) . '/app/views/_email_paused_notice.php';
        $this->assertFileExists($partial);

        ob_start();
        try {
            (static function () use ($partial, $emailNotice, $ownerId, $us_url_root, $userspice_nonce): void {
                include $partial;
            })();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * @return array{message: string, text: string, hrefs: array<string, string>, dismissKey: string}|null
     *         Null when the partial renders nothing.
     */
    private function parseNotice(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $message = $xpath->query('//*[@id="email-notice-message"]');
        $this->assertNotFalse($message);
        $this->assertSame(1, $message->length, 'The notice has one message paragraph');

        $hrefs = [];
        $links = $xpath->query('//a[@href]');
        $this->assertNotFalse($links);
        foreach ($links as $link) {
            $this->assertInstanceOf(DOMElement::class, $link);
            $hrefs[trim($link->textContent)] = $link->getAttribute('href');
        }
        $notice = $xpath->query('//*[@id="email-paused-notice"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $notice);

        // The visible text only: drop the style and script elements.
        foreach (iterator_to_array($xpath->query('//style | //script')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        return [
            'message'    => self::flatten($message->item(0)->textContent ?? ''),
            'text'       => self::flatten($notice->textContent),
            'hrefs'      => $hrefs,
            'dismissKey' => $notice->getAttribute('data-dismiss-key'),
        ];
    }

    /**
     * @return list<string> The labels of the links with the `btn btn-primary` class
     */
    private function primaryButtonLabels(string $html): array
    {
        preg_match_all('/<a class="btn btn-primary[^"]*"[^>]*>([^<]+)</', $html, $m);

        return $m[1];
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** @return array{message: string, text: string, hrefs: array<string, string>, dismissKey: string} */
    private function noticeFor(int $ownerId): array
    {
        $notice = $this->parseNotice($this->renderNotice($ownerId));
        $this->assertNotNull($notice, 'A notice must render for this owner');

        return $notice;
    }

    // ---- blocked --------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockCauseProvider(): array
    {
        return [
            'spam'         => ['spam', 'a message to this address was reported as spam'],
            'unsubscribed' => ['unsubscribed', 'this address unsubscribed from registry email'],
            'blocklist'    => ['blocklist', 'an administrator blocked this address'],
        ];
    }

    #[DataProvider('blockCauseProvider')]
    #[Group('fast')]
    public function testBlockedNoticeNamesTheAddressAndTheCause(string $cause, string $causeText): void
    {
        $email = 'blocked-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, $email, '2026-03-10 09:30:00', $cause);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} is blocked because {$causeText}.", $notice['message']);
        $this->assertStringContainsString('Change your email address or contact an admin.', $notice['text']);
        $this->assertStringNotContainsString('also opted out', $notice['text']);
    }

    /** #2318: an owner blocked with no known cause is told the address is blocked and no cause. */
    #[Group('fast')]
    public function testBlockedWithNoKnownCauseStatesNoCause(): void
    {
        $email = 'nocause-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, $email, '2026-03-10 09:30:00', null);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} is blocked.", $notice['message']);
        $this->assertStringNotContainsString('because', $notice['text']);
        $this->assertStringNotContainsString('spam', $notice['text']);
    }

    /** #2318: a cause value outside the three known ones counts as no cause and shows no text for it. */
    #[Group('fast')]
    public function testBlockedWithAnUnrecognizedCauseValueStatesNoCause(): void
    {
        $email = 'oddcause-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, $email, '2026-03-10 09:30:00', 'spam');
        $write = $this->db->query('UPDATE profiles SET email_blocked_cause = ? WHERE user_id = ?', ['not-a-known-cause', $ownerId]);
        if ($write->error()) {
            $this->markTestSkipped('The column rejects an unrecognized cause value, so the state cannot exist: ' . $write->errorString());
        }

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} is blocked.", $notice['message']);
    }

    #[Group('fast')]
    public function testBlockedNoticeHasChangeEmailAndContactLinks(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, $this->ownerEmail($ownerId), '2026-03-10 09:30:00', 'spam');

        $notice = $this->noticeFor($ownerId);

        $this->assertSame('/usersc/user_settings.php#account-email', $notice['hrefs']['Change email'] ?? null);
        $this->assertSame('/app/owner/contact/index.php', $notice['hrefs']['Contact an admin'] ?? null);
        $this->assertArrayNotHasKey('Resume', $notice['hrefs'], 'Resume shows only with an opt-out');
    }

    /** The notice never shows the word Brevo, for any state, in the visible text or the markup. */
    #[Group('fast')]
    public function testNoStateMentionsBrevo(): void
    {
        $states = [];
        foreach (['spam', 'unsubscribed', 'blocklist', null] as $cause) {
            $ownerId = $this->createOwner();
            $this->setOwnerBlocked($ownerId, $this->ownerEmail($ownerId), '2026-03-10 09:30:00', $cause);
            $states["blocked {$cause}"] = $ownerId;
        }
        $ownerId = $this->createOwner();
        $this->setOwnerBounced($ownerId, $this->ownerEmail($ownerId), '2026-05-06 07:08:09');
        $states['bounced'] = $ownerId;
        $ownerId = $this->createOwner();
        $this->setOwnerOptedOut($ownerId);
        $states['opted out'] = $ownerId;
        $ownerId = $this->createOwner();
        $this->setOwnerOptedOut($ownerId);
        $this->setOwnerBlocked($ownerId, $this->ownerEmail($ownerId));
        $states['blocked and opted out'] = $ownerId;

        foreach ($states as $name => $ownerId) {
            $html = $this->renderNotice($ownerId);
            $this->assertNotSame('', trim($html), "The {$name} state must render, or this check proves nothing");
            $this->assertStringNotContainsStringIgnoringCase('brevo', $html, "The {$name} notice");
            $this->assertStringNotContainsStringIgnoringCase('sendinblue', $html, "The {$name} notice");
        }
    }

    // ---- combinations ---------------------------------------------------------------------------

    #[Group('fast')]
    public function testBlockedAndOptedOutShowOneNoticeWithTheBlockFirstThenTheOptOutLineAndResume(): void
    {
        $email = 'both-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, $email, '2026-03-10 09:30:00', 'spam');
        $this->setOwnerOptedOut($ownerId, true, '2026-03-01 12:00:00');

        $html = $this->renderNotice($ownerId);
        $notice = $this->noticeFor($ownerId);

        $this->assertSame(1, substr_count($html, 'id="email-paused-notice"'), 'One notice');
        $this->assertSame(
            "Email to {$email} is blocked because a message to this address was reported as spam.",
            $notice['message']
        );
        $blockedAt = strpos($notice['text'], 'is blocked because');
        $optOutAt = strpos($notice['text'], 'You also opted out of registry email.');
        $this->assertNotFalse($blockedAt);
        $this->assertNotFalse($optOutAt, 'The opt-out line shows');
        $this->assertLessThan($optOutAt, $blockedAt, 'The blocked text comes first');
        $this->assertSame('/usersc/user_settings.php#resume-emails', $notice['hrefs']['Resume'] ?? null);
        $this->assertSame('/usersc/user_settings.php#account-email', $notice['hrefs']['Change email'] ?? null);
        $this->assertStringNotContainsString('1 March 2026', $notice['text'], 'The also-opted-out line holds no date');
        $this->assertSame(
            ['Change email'],
            $this->primaryButtonLabels($html),
            'Change email is the only primary action. Resume is secondary.'
        );
        $this->assertSame(1, preg_match('/class="btn btn-outline-primary"[^>]*>Resume</', $html));
    }

    #[Group('fast')]
    public function testBlockedAndBouncedShowOneNoticeWithTheBlockedText(): void
    {
        $email = 'blockbounce-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, $email, '2026-03-10 09:30:00', 'blocklist');
        $this->setOwnerBounced($ownerId, $email, '2026-05-06 07:08:09');

        $html = $this->renderNotice($ownerId);
        $notice = $this->noticeFor($ownerId);

        $this->assertSame(1, substr_count($html, 'id="email-paused-notice"'));
        $this->assertSame("Email to {$email} is blocked because an administrator blocked this address.", $notice['message']);
        $this->assertStringNotContainsString('bounced', $notice['text']);
        $this->assertStringNotContainsString('6 May 2026', $notice['text']);
    }

    #[Group('fast')]
    public function testBouncedAndOptedOutShowTheBounceWithTheOptOutLine(): void
    {
        $email = 'bounceopt-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBounced($ownerId, $email, '2026-05-06 07:08:09');
        $this->setOwnerOptedOut($ownerId);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} bounced on 6 May 2026.", $notice['message']);
        $this->assertStringContainsString('You also opted out of registry email.', $notice['text']);
        $this->assertSame('/usersc/user_settings.php#resume-emails', $notice['hrefs']['Resume'] ?? null);
    }

    // ---- bounced and opted out, dates -----------------------------------------------------------

    #[Group('fast')]
    public function testBouncedNoticeShowsTheDateOfTheBounce(): void
    {
        $email = 'bounced-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBounced($ownerId, $email, '2026-05-06 07:08:09');

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} bounced on 6 May 2026.", $notice['message']);
        $this->assertStringContainsString('Change your email address or contact an admin.', $notice['text']);
        $this->assertSame('/usersc/user_settings.php#account-email', $notice['hrefs']['Change email'] ?? null);
        $this->assertSame('/app/owner/contact/index.php', $notice['hrefs']['Contact an admin'] ?? null);
    }

    /** Legacy data: a bounce with no time shows no date. */
    #[Group('fast')]
    public function testBouncedNoticeWithNoDateOmitsTheDate(): void
    {
        $email = 'bounced-nodate-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBounced($ownerId, $email, '2026-05-06 07:08:09');
        $this->db->query('UPDATE profiles SET email_bounced_at = NULL WHERE user_id = ?', [$ownerId]);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} bounced.", $notice['message']);
        $this->assertStringNotContainsString(' on ', $notice['message']);
    }

    #[Group('fast')]
    public function testOptedOutNoticeShowsTheDateAndTheResumeLinkOnly(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');

        $notice = $this->noticeFor($ownerId);

        $this->assertSame('You opted out of registry email on 4 March 2026.', $notice['message']);
        $this->assertSame('/usersc/user_settings.php#resume-emails', $notice['hrefs']['Resume'] ?? null);
        $this->assertSame(['Resume'], $this->primaryButtonLabels($this->renderNotice($ownerId)), 'Resume is the primary action');
        $this->assertArrayNotHasKey('Change email', $notice['hrefs']);
        $this->assertArrayNotHasKey('Contact an admin', $notice['hrefs']);
        $this->assertStringNotContainsString('also opted out', $notice['text']);
    }

    #[Group('fast')]
    public function testOptedOutNoticeWithNoDateOmitsTheDate(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');
        $this->db->query('UPDATE profiles SET email_opted_out_at = NULL WHERE user_id = ?', [$ownerId]);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame('You opted out of registry email.', $notice['message']);
    }

    // ---- address match --------------------------------------------------------------------------

    /** A flag for an old address is hidden. The owner has no notice. */
    #[Group('fast')]
    public function testFlagsForAnOldAddressShowNoNotice(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, 'old-address@example.com', '2026-03-10 09:30:00', 'spam');
        $this->setOwnerBounced($ownerId, 'old-address@example.com', '2026-05-06 07:08:09');

        $this->assertNull($this->parseNotice($this->renderNotice($ownerId)));
        $this->assertSame('', trim($this->renderNotice($ownerId)));
    }

    /** An opt-out shows after an address change, and the old-address block does not. */
    #[Group('fast')]
    public function testOptOutShowsAfterAnAddressChangeAndTheOldBlockDoesNot(): void
    {
        $ownerId = $this->createOwner('first-' . uniqid() . '@example.com');
        $this->setOwnerBlocked($ownerId, $this->ownerEmail($ownerId), '2026-03-10 09:30:00', 'spam');
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');
        $this->setUserEmailRaw($ownerId, 'second-' . uniqid() . '@example.com');

        $notice = $this->noticeFor($ownerId);

        $this->assertSame('You opted out of registry email on 4 March 2026.', $notice['message']);
    }

    /** A flag with no stored address applies, and the notice names the current address. */
    #[Group('fast')]
    public function testFlagWithANullAddressShowsTheCurrentEmail(): void
    {
        $email = 'current-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBlocked($ownerId, 'placeholder@example.com', '2026-03-10 09:30:00', 'spam');
        $this->db->query('UPDATE profiles SET email_blocked_address = NULL WHERE user_id = ?', [$ownerId]);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} is blocked because a message to this address was reported as spam.", $notice['message']);
    }

    #[Group('fast')]
    public function testBounceWithAnEmptyAddressShowsTheCurrentEmail(): void
    {
        $email = 'current-empty-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBounced($ownerId, 'placeholder@example.com', '2026-05-06 07:08:09');
        $this->db->query("UPDATE profiles SET email_bounced_address = '' WHERE user_id = ?", [$ownerId]);

        $notice = $this->noticeFor($ownerId);

        $this->assertSame("Email to {$email} bounced on 6 May 2026.", $notice['message']);
    }

    #[Group('fast')]
    public function testOwnerWithNoFlagGetsNoNoticeAndAnotherOwnersFlagDoesNotShow(): void
    {
        $ownerId = $this->createOwner();
        $otherId = $this->createOwner();
        $this->setOwnerBlocked($otherId, $this->ownerEmail($otherId), '2026-03-10 09:30:00', 'spam');
        $this->setOwnerOptedOut($otherId);

        $this->assertSame('', trim($this->renderNotice($ownerId)));
    }

    // ---- #2319 ----------------------------------------------------------------------------------

    /** #2319: an owner who opted out before the first car sees the notice, and still sees it after registering a car. */
    #[Group('fast')]
    public function testOptOutSetBeforeTheFirstCarShowsTheNoticeAndAfterRegisteringACar(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');
        $this->assertSame(
            [],
            $this->db->query('SELECT id FROM cars WHERE user_id = ?', [$ownerId])->results(),
            'Test setup: the owner has no car'
        );

        $before = $this->noticeFor($ownerId);
        $this->assertSame('You opted out of registry email on 4 March 2026.', $before['message']);

        $this->createTestCar($ownerId, ['email' => $this->ownerEmail($ownerId)]);

        $after = $this->noticeFor($ownerId);
        $this->assertSame('You opted out of registry email on 4 March 2026.', $after['message']);
        $this->assertSame($before['dismissKey'], $after['dismissKey'], 'A new car does not change the state, so a dismissed notice stays dismissed');
    }

    // ---- #2320 ----------------------------------------------------------------------------------

    /**
     * An owner with a car whose email is the owner's email, so that the soft
     * bounces of the car's address escalate to the owner's profile.
     *
     * @return array{owner: int, car: int, email: string}
     */
    private function createOwnerWithCar(string $emailPrefix): array
    {
        $email = $emailPrefix . '-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $carId = $this->createTestCar($ownerId, [
            'email'              => $email,
            'last_verified'      => null,
            'owner_last_updated' => '2020-01-01 00:00:00',
        ]);

        return ['owner' => $ownerId, 'car' => $carId, 'email' => $email];
    }

    /**
     * Three soft bounces, each later than the fixed clear time, set the bounce
     * again with the time of the third event.
     */
    private function applyThreeSoftBouncesAfter(array $fixture): void
    {
        foreach (
            [
                ['soft-1', '2026-07-02 00:00:00'],
                ['soft-2', '2026-07-03 00:00:00'],
                ['soft-3', '2026-07-04 12:13:14'],
            ] as [$messageId, $occurredAt]
        ) {
            $this->applier->apply($fixture['owner'], $fixture['email'], 'soft_bounce', null, $messageId, $occurredAt, 'webhook');
        }
    }

    /** #2320: Clear Bounced, then three soft bounces set the flag again. The notice shows the new date. */
    #[Group('fast')]
    public function testNoticeShowsTheNewBounceDateAfterClearBouncedAndThreeSoftBounces(): void
    {
        $fixture = $this->createOwnerWithCar('clearbounced');
        $this->setOwnerBounced($fixture['owner'], $fixture['email'], '2026-01-15 10:00:00');
        $this->assertSame(
            "Email to {$fixture['email']} bounced on 15 January 2026.",
            $this->noticeFor($fixture['owner'])['message'],
            'Test setup: the old date shows'
        );

        $this->manager->clearBouncedForOwner($fixture['owner'], 777001);
        // A fixed clear time earlier than the soft bounces, so the test does not depend on the clock.
        $this->db->query('UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?', ['2026-07-01 00:00:00', $fixture['owner']]);
        $this->assertSame('', trim($this->renderNotice($fixture['owner'])), 'Test setup: no notice after the clear');

        $this->applyThreeSoftBouncesAfter($fixture);

        $notice = $this->noticeFor($fixture['owner']);
        $this->assertSame("Email to {$fixture['email']} bounced on 4 July 2026.", $notice['message']);
        $this->assertStringNotContainsString('15 January 2026', $notice['text'], 'The old date does not show');
    }

    /** #2320, second variant: a confirmed email change in place of Clear Bounced. */
    #[Group('fast')]
    public function testNoticeShowsTheNewBounceDateAfterAConfirmedChangeAndThreeSoftBounces(): void
    {
        $oldEmail = 'before-change-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($oldEmail);
        $this->setOwnerBounced($ownerId, $oldEmail, '2026-01-15 10:00:00');

        // users/verify.php writes the confirmed address, then the hook clears.
        $newEmail = 'after-change-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $newEmail);
        $this->assertTrue($this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId), 'Test setup: the change clears the bounce');
        // The confirmed change sets email_address_since to now. Use a fixed time before the soft bounces.
        $this->db->query(
            'UPDATE profiles SET email_bounce_cleared_at = ?, email_address_since = ? WHERE user_id = ?',
            ['2026-07-01 00:00:00', '2026-07-01 00:00:00', $ownerId]
        );
        $carId = $this->createTestCar($ownerId, [
            'email'              => $newEmail,
            'last_verified'      => null,
            'owner_last_updated' => '2020-01-01 00:00:00',
        ]);
        $this->assertSame('', trim($this->renderNotice($ownerId)), 'Test setup: no notice after the change');

        $this->applyThreeSoftBouncesAfter(['owner' => $ownerId, 'email' => $newEmail]);

        $notice = $this->noticeFor($ownerId);
        $this->assertSame("Email to {$newEmail} bounced on 4 July 2026.", $notice['message']);
        $this->assertStringNotContainsString('15 January 2026', $notice['text']);
        $this->assertStringNotContainsString($oldEmail, $notice['text']);
    }

    // ---- escaping -------------------------------------------------------------------------------

    /** The apostrophe and the ampersand in the local part are escaped at render, for both addresses. */
    #[DataProvider('hostileKindProvider')]
    #[Group('fast')]
    public function testHostileAddressIsEscapedAtRender(string $kind): void
    {
        $hostile = "o'brien&co@example.com";
        $ownerId = $this->createOwner($hostile);
        if ($kind === 'blocked') {
            $this->setOwnerBlocked($ownerId, $hostile, '2026-03-10 09:30:00', 'spam');
        } else {
            $this->setOwnerBounced($ownerId, $hostile, '2026-05-06 07:08:09');
        }
        $this->assertSame($hostile, $this->ownerEmail($ownerId), 'Test setup: the address is stored raw');

        $html = $this->renderNotice($ownerId);

        $this->assertStringContainsString('<strong>o&#039;brien&amp;co@example.com</strong>', $html);
        $this->assertStringNotContainsString($hostile, $html, 'The raw address does not appear in the markup');
        $this->assertSame(
            $kind === 'blocked'
                ? "Email to {$hostile} is blocked because a message to this address was reported as spam."
                : "Email to {$hostile} bounced on 6 May 2026.",
            $this->noticeFor($ownerId)['message'],
            'The browser shows the address as stored'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function hostileKindProvider(): array
    {
        return ['blocked' => ['blocked'], 'bounced' => ['bounced']];
    }

    #[Group('fast')]
    public function testAddressThatLooksLikeMarkupIsNotRenderedAsMarkup(): void
    {
        $hostile = '"><script>alert(1)</script>@example.com';
        $ownerId = $this->createOwner($hostile);
        $this->setOwnerBounced($ownerId, $hostile, '2026-05-06 07:08:09');

        $html = $this->renderNotice($ownerId);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ---- dismiss key ----------------------------------------------------------------------------

    /** A dismissed notice shows again when the state changes: the dismiss key follows the content. */
    #[Group('fast')]
    public function testDismissKeyChangesWhenTheStateChanges(): void
    {
        $email = 'dismiss-' . uniqid() . '@example.com';
        $ownerId = $this->createOwner($email);
        $this->setOwnerBounced($ownerId, $email, '2026-05-06 07:08:09');
        $first = $this->noticeFor($ownerId)['dismissKey'];
        $same = $this->noticeFor($ownerId)['dismissKey'];

        $this->setOwnerOptedOut($ownerId);
        $second = $this->noticeFor($ownerId)['dismissKey'];

        $this->assertSame($first, $same, 'The same state gives the same key');
        $this->assertNotSame($first, $second, 'A changed state gives a new key');
        $this->assertStringStartsWith("er.emailNotice.dismissed.{$ownerId}.", $first);
    }
}
