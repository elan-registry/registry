<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\BrevoSuppressionSyncClient;

/**
 * FakeBrevoSuppressionSyncClient - Scripted stand-in for the Brevo suppression
 * list poll, for BrevoSuppressionSyncJobTest
 *
 * Extends the real client so it satisfies the job's constructor type, but
 * overrides fetchBlockedContacts() to return canned pages and record the exact
 * arguments it was called with — which is how the tests assert the window,
 * page size, offset, and the paging loop's stop conditions.
 *
 * The real class's constructor is bypassed entirely (no parent::__construct()
 * call): it takes a DatabaseInterface only to read the Brevo API key, and
 * nothing in the overridden method path touches it.
 *
 * Unlike {@see FakeBrevoEventReconciliationClient}, whose real counterpart
 * returns a bare `array`, this one's real return type is the SDK model
 * `?\Brevo\Client\Model\GetTransacBlockedContacts`. PHP checks return types
 * covariantly at *call* time, so an override cannot hand back an unrelated
 * "page-shaped" class — doing so is a TypeError, not a passing test. The page
 * wrapper below is therefore declared as that very class name (guarded, see
 * its own note), which is the only way a fake can satisfy the inherited
 * signature while the vendored SDK is off the unit suite's autoloader.
 *
 * Deliberately a *named* class rather than an anonymous one, per the
 * `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
class FakeBrevoSuppressionSyncClient extends BrevoSuppressionSyncClient
{
    /** Number of times fetchBlockedContacts() has been called. */
    public int $fetchCalls = 0;

    /** @var list<array{start: \DateTimeImmutable|null, end: \DateTimeImmutable|null, limit: int, offset: int}> */
    public array $fetchArgs = [];

    /**
     * @param list<\Brevo\Client\Model\GetTransacBlockedContacts|null> $pages
     *        One entry per expected call, returned in order. A null entry
     *        simulates a failed poll — the distinction the real client draws
     *        between "Brevo did not answer" and "Brevo answered with nothing".
     *        Once the list is exhausted every further call returns null, so a
     *        job that pages further than the test scripted fails loudly on the
     *        poll-failure path rather than looping on a repeated last page.
     */
    public function __construct(private array $pages = [])
    {
    }

    /**
     * Build a page from contacts, without naming the wrapper class.
     *
     * `$count` defaults to the number of contacts given, which is the honest
     * single-page case; pass it explicitly to script a multi-page run, where
     * Brevo reports a total larger than the page returned.
     *
     * Constructed via the associative `$data` array the real generated SDK's
     * constructor actually takes (confirmed against
     * usersc/plugins/sendinblue/vendor/getbrevo/brevo-php/lib/Model/GetTransacBlockedContacts.php)
     * — not positional args — so this call satisfies both the real class (if
     * it happens to already be loaded) and the guarded stand-in below, and so
     * PHPStan (which resolves the class via stubs/brevo-sdk.php, itself
     * matched to the real constructor) has nothing to flag.
     *
     * @param list<FakeBrevoBlockedContact> $contacts
     */
    public static function page(array $contacts, ?int $count = null): \Brevo\Client\Model\GetTransacBlockedContacts
    {
        return new \Brevo\Client\Model\GetTransacBlockedContacts([
            'count' => $count ?? count($contacts),
            'contacts' => $contacts,
        ]);
    }

    public function fetchBlockedContacts(
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $limit,
        int $offset
    ): ?\Brevo\Client\Model\GetTransacBlockedContacts {
        $this->fetchArgs[] = [
            'start' => $startDate,
            'end' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->pages[$this->fetchCalls++] ?? null;
    }
}

namespace Brevo\Client\Model;

/**
 * Minimal runtime stand-in for the SDK's GetTransacBlockedContacts page model.
 *
 * Declared here, under the SDK's own namespace, because
 * FakeBrevoSuppressionSyncClient::fetchBlockedContacts() inherits that return
 * type and PHP enforces it on every call — no separately-named fake can
 * satisfy it. stubs/brevo-sdk.php cannot serve: PHPStan reads it for types and
 * never loads it, and the vendored SDK that would define the real class is not
 * on the unit suite's autoloader.
 *
 * Guarded by class_exists() because the sendinblue plugin *is* installed on
 * developer machines with email configured. If anything in the same process
 * has already loaded the real SDK, that definition wins and this file adds
 * nothing — without the guard it would be a duplicate-declaration fatal, and
 * the suite would pass in CI while dying locally.
 *
 * The constructor takes the same associative `$data` array shape the real
 * generated SDK's constructor does (`?array $data = null`, confirmed against
 * usersc/plugins/sendinblue/vendor/getbrevo/brevo-php/lib/Model/GetTransacBlockedContacts.php
 * and mirrored in stubs/brevo-sdk.php) rather than positional args, so this
 * stand-in and the real class present an identical constructor call shape to
 * both PHPStan and any caller. Use
 * {@see \Tests\Support\FakeBrevoSuppressionSyncClient::page()} rather than
 * constructing one directly.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
if (!class_exists(\Brevo\Client\Model\GetTransacBlockedContacts::class, false)) {
    class GetTransacBlockedContacts
    {
        private readonly int $count;

        /** @var list<\Tests\Support\FakeBrevoBlockedContact> */
        private readonly array $contacts;

        /**
         * @param array{count?: int, contacts?: list<\Tests\Support\FakeBrevoBlockedContact>}|null $data
         */
        public function __construct(?array $data = null)
        {
            $this->count = $data['count'] ?? 0;
            $this->contacts = $data['contacts'] ?? [];
        }

        public function getCount(): int
        {
            return $this->count;
        }

        /**
         * @return list<\Tests\Support\FakeBrevoBlockedContact>
         */
        public function getContacts(): array
        {
            return $this->contacts;
        }
    }
}
