<?php

// Type-only stubs for the vendored Brevo SDK and its Guzzle transport.
//
// WHY THIS FILE EXISTS
// --------------------
// usersc/classes/Cron/BrevoEventReconciliationClient.php and
// usersc/classes/Cron/BrevoSuppressionSyncClient.php call into the Brevo
// PHP SDK, which ships vendored inside the sendinblue plugin at
// usersc/plugins/sendinblue/vendor/. That plugin is a manually installed,
// licensed UserSpice plugin and is gitignored in its entirety (.gitignore:
// `usersc/plugins/*`), so it is present on machines where email has been
// configured and absent everywhere else — including CI and any fresh clone.
//
// Pointing PHPStan's scanDirectories at the vendored SDK therefore made
// analysis environment-dependent: PHPStan hard-errors (exit 1) when a scanned
// directory does not exist, so CI failed on every run with "Scanned directory
// ... does not exist". There is no reproducible way to install the plugin in
// CI, so the symbols have to come from inside the repo.
//
// These stubs supply exactly the surface BrevoEventReconciliationClient
// touches, and nothing more — this is not a reimplementation of the SDK and
// must not grow into one. Signatures and PHPDoc return types are copied from
// the generated SDK (getbrevo/brevo-php) so the caller is analysed identically
// whether or not the plugin is installed. If the caller starts using a new SDK
// method, add it here with the signature the real SDK declares.
//
// Referenced from phpstan.neon as `scanFiles`, NOT `stubFiles`. The two are
// not interchangeable here: stubFiles only overrides the types of classes
// PHPStan has already discovered elsewhere, so with the plugin absent every
// symbol still resolved as class.notFound. scanFiles is the parameter that
// makes a class *exist* for analysis without analysing the file itself, which
// is what a missing runtime dependency needs. Nothing here is analysed, so
// nothing enters the baseline, and the `excludePaths.analyse: */vendor/*`
// rule keeping third-party code out of analysis is unaffected.
//
// NEVER LOAD THIS FILE AT RUNTIME, and never add `stubs/` to a Composer
// autoload root. It sits outside every autoload root (see composer.json) and
// outside the web-served page tree, so nothing reaches it today. Including it
// on a host where the sendinblue plugin *is* installed would redeclare the
// real SDK classes and fatal. It is declared unconditionally rather than
// behind class_exists() guards deliberately: a guard would let an accidental
// include pass silently and leave these empty method bodies standing in for
// the real SDK, which is a far worse failure than a loud fatal.
//
// Braced namespaces because this file declares several namespaces, and the
// leading comment above must not be a docblock: a docblock ahead of an
// unbraced `namespace` is a statement, which is a parse error.
//
// @see https://phpstan.org/user-guide/discovering-symbols
// @see https://github.com/elan-registry/registry/issues/1889
// @see https://github.com/elan-registry/registry/issues/1923

namespace GuzzleHttp {
    interface ClientInterface
    {
    }

    class Client implements ClientInterface
    {
        /**
         * @param array<string, mixed> $config
         */
        public function __construct(array $config = [])
        {
        }
    }
}

namespace Brevo\Client {
    class Configuration
    {
        /**
         * @return self
         */
        public static function getDefaultConfiguration()
        {
        }

        /**
         * @param string $apiKeyIdentifier
         * @param string $key
         * @return $this
         */
        public function setApiKey($apiKeyIdentifier, $key)
        {
        }
    }
}

namespace Brevo\Client\Api {
    class TransactionalEmailsApi
    {
        public function __construct(
            ?\GuzzleHttp\ClientInterface $client = null,
            ?\Brevo\Client\Configuration $config = null
        ) {
        }

        /**
         * @param int $limit
         * @param int $offset
         * @param string $startDate
         * @param string $endDate
         * @param int $days
         * @param string $email
         * @param string $event
         * @param string $tags
         * @param string $messageId
         * @param int $templateId
         * @param string $sort
         * @return \Brevo\Client\Model\GetEmailEventReport
         */
        public function getEmailEventReport(
            $limit = '2500',
            $offset = '0',
            $startDate = null,
            $endDate = null,
            $days = null,
            $email = null,
            $event = null,
            $tags = null,
            $messageId = null,
            $templateId = null,
            $sort = 'desc'
        ) {
        }

        /**
         * @param string $startDate
         * @param string $endDate
         * @param int $limit
         * @param int $offset
         * @param string[] $senders
         * @param string $sort
         * @return \Brevo\Client\Model\GetTransacBlockedContacts
         */
        public function getTransacBlockedContacts(
            $startDate = null,
            $endDate = null,
            $limit = '50',
            $offset = '0',
            $senders = null,
            $sort = 'desc'
        ) {
        }
    }
}

namespace Brevo\Client\Model {
    class GetEmailEventReport
    {
        /**
         * @return \Brevo\Client\Model\GetEmailEventReportEvents[]
         */
        public function getEvents()
        {
        }
    }

    class GetEmailEventReportEvents
    {
        /**
         * Untyped in the real generated SDK — its base model deserializes
         * every field from a JSON response with no type enforcement, so any
         * getter can return a non-string at runtime (int, array, null) if
         * Brevo's payload doesn't match the SDK's own assumptions. Declaring
         * `string` here would tell PHPStan these are guaranteed strings,
         * which would make the is_string() guards in
         * BrevoEventReconciliationJob::applyEvent() and resolveOccurredAt()
         * look like dead code instead of the load-bearing defensive checks
         * they are (see #1889's round-2 review fix). Keep `mixed` — it must
         * stay in sync with tests/Support/FakeBrevoEvent.php's declared type.
         *
         * @return mixed
         */
        public function getEmail()
        {
        }

        /**
         * @return mixed
         */
        public function getDate()
        {
        }

        /**
         * @return mixed
         */
        public function getMessageId()
        {
        }

        /**
         * @return mixed
         */
        public function getEvent()
        {
        }

        /**
         * @return mixed
         */
        public function getReason()
        {
        }

        /**
         * @return mixed
         */
        public function getTag()
        {
        }
    }

    class GetTransacBlockedContacts
    {
        /**
         * Matches the real generated SDK's constructor exactly (an
         * associative-array constructor, not positional args) — confirmed at
         * usersc/plugins/sendinblue/vendor/getbrevo/brevo-php/lib/Model/GetTransacBlockedContacts.php.
         *
         * @param array<string, mixed>|null $data
         */
        public function __construct(?array $data = null)
        {
        }

        /**
         * @return int
         */
        public function getCount()
        {
        }

        /**
         * The real generated SDK leaves this null whenever the response
         * carries no `contacts` key (an empty/exhausted result) — confirmed
         * against the vendored SDK's deserializer, which does
         * `isset($data['contacts']) ? $data['contacts'] : null`. Declared
         * nullable here (unlike the SDK's own inaccurate non-nullable
         * PHPDoc) so a caller's `?? []` guard is not flagged as dead code.
         *
         * @return \Brevo\Client\Model\GetTransacBlockedContactsContacts[]|null
         */
        public function getContacts()
        {
        }
    }

    class GetTransacBlockedContactsContacts
    {
        /**
         * Untyped for the same reason as GetEmailEventReportEvents above: the
         * real generated SDK deserializes these fields from the JSON response
         * with no type enforcement, so any getter can return a non-string at
         * runtime. Declaring `string` here would make the defensive type
         * guards in the suppression-import caller look like dead code.
         *
         * @return mixed
         */
        public function getEmail()
        {
        }

        /**
         * @return mixed
         */
        public function getSenderEmail()
        {
        }

        /**
         * @return \Brevo\Client\Model\GetTransacBlockedContactsReason
         */
        public function getReason()
        {
        }

        /**
         * @return mixed
         */
        public function getBlockedAt()
        {
        }
    }

    class GetTransacBlockedContactsReason
    {
        public const CODE_UNSUBSCRIBED_VIA_MA = 'unsubscribedViaMA';
        public const CODE_UNSUBSCRIBED_VIA_EMAIL = 'unsubscribedViaEmail';
        public const CODE_ADMIN_BLOCKED = 'adminBlocked';
        public const CODE_UNSUBSCRIBED_VIA_API = 'unsubscribedViaApi';
        public const CODE_HARD_BOUNCE = 'hardBounce';
        public const CODE_CONTACT_FLAGGED_AS_SPAM = 'contactFlaggedAsSpam';

        /**
         * @return mixed
         */
        public function getCode()
        {
        }

        /**
         * @return mixed
         */
        public function getMessage()
        {
        }
    }
}
