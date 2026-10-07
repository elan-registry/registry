<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\BrevoSuppressionSyncClient;

/**
 * FakeBrevoSuppressionSyncClient - Scripted stand-in for the Brevo suppression
 * list poll, for BrevoSuppressionSyncJobTest
 *
 * Returns canned pages and records each call's arguments, so tests can assert
 * the window, page size, offset, and the paging loop's stop conditions. The
 * parent constructor is skipped: it needs a database only for the API key.
 *
 * The real return type is the SDK model
 * `?\Brevo\Client\Model\GetTransacBlockedContacts`, and PHP enforces it on
 * every call, so a "page-shaped" class of another name is a TypeError. The
 * stand-in at the end of this file therefore uses that exact class name.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
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
     * Uses the associative `$data` array the real SDK constructor takes, so the
     * call works with the real class, the stand-in below, and
     * stubs/brevo-sdk.php.
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

    /**
     * Build a page whose `getContacts()` returns null, not an empty array: the
     * real SDK's shape for an empty or exhausted list. `page([])` stores an
     * empty array, so it cannot exercise that null-safety.
     */
    public static function pageWithNullContacts(int $count = 0): \Brevo\Client\Model\GetTransacBlockedContacts
    {
        return new \Brevo\Client\Model\GetTransacBlockedContacts(['count' => $count]);
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
 * Declared under the SDK's namespace because the inherited return type of
 * fetchBlockedContacts() requires this class name. stubs/brevo-sdk.php is
 * read by PHPStan only and never loaded, and the vendored SDK is not on the
 * unit suite's autoloader.
 *
 * Guarded by class_exists(): developer machines with email configured can
 * load the real SDK in the same process. Without the guard that is a
 * duplicate-declaration fatal that passes in CI but fails locally.
 *
 * The constructor takes the real SDK's `?array $data` shape. Use
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

        /** @var list<\Tests\Support\FakeBrevoBlockedContact>|null */
        private readonly ?array $contacts;

        /**
         * `contacts` is null when `$data` has no `contacts` key, as in the
         * real SDK for an empty or exhausted result.
         *
         * @param array{count?: int, contacts?: list<\Tests\Support\FakeBrevoBlockedContact>}|null $data
         */
        public function __construct(?array $data = null)
        {
            $this->count = $data['count'] ?? 0;
            $this->contacts = $data['contacts'] ?? null;
        }

        public function getCount(): int
        {
            return $this->count;
        }

        /**
         * @return list<\Tests\Support\FakeBrevoBlockedContact>|null
         */
        public function getContacts(): ?array
        {
            return $this->contacts;
        }
    }
}
