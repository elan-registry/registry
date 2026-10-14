<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2359: the test webhook subscribes to the delivery events of all
 * registry mail, and to no open or click event.
 *
 * `CREATE_EVENTS` in `scripts/spike-1888/brevo-register-webhook.php` is the
 * list that `--create` sends to Brevo. The script cannot be loaded in a test,
 * because it runs its CLI entry point on include. So this test reads the
 * source. The spelling is the one that Brevo's webhook API uses
 * (`softBounce`, `hardBounce`), not the event names in the webhook body.
 *
 * @issue 2359
 * @link https://github.com/elan-registry/registry/issues/2359
 */
#[Group('regression')]
final class Issue2359RegisterWebhookEventsRegressionTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/spike-1888/brevo-register-webhook.php';

    /** @var list<string> */
    private const EXPECTED_EVENTS = [
        'delivered',
        'softBounce',
        'hardBounce',
        'blocked',
        'invalid',
        'spam',
        'unsubscribed',
    ];

    private static function source(): string
    {
        $source = file_get_contents(self::SCRIPT);
        self::assertIsString($source, 'The registration script must be readable');

        return $source;
    }

    /**
     * Read the string items of the `CREATE_EVENTS` constant
     *
     * @return list<string>
     */
    public static function listedEvents(string $source): array
    {
        $found = preg_match('/const\s+CREATE_EVENTS\s*=\s*\[(.*?)\]\s*;/s', $source, $match);
        self::assertSame(1, $found, 'The script must define CREATE_EVENTS as an array literal');

        preg_match_all("/'([^']*)'/", $match[1], $items);

        return $items[1];
    }

    public function testCreateEventsListsExactlyTheSevenDeliveryEventsInOrder(): void
    {
        $this->assertSame(self::EXPECTED_EVENTS, self::listedEvents(self::source()));
    }

    public function testCreateEventsHasNoOpenOrClickEvent(): void
    {
        $events = self::listedEvents(self::source());

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertDoesNotMatchRegularExpression(
                '/open|click/i',
                $event,
                'An open or click event writes a row for every mail view'
            );
        }
    }

    public function testCreateRequestSendsTheListedEvents(): void
    {
        $this->assertMatchesRegularExpression(
            "/'events'\s*=>\s*CREATE_EVENTS\b/",
            self::source(),
            'The --create body must send CREATE_EVENTS, not a second list'
        );
    }

    /** The reader must reject a list that has a changed spelling or an extra event. */
    public function testListReaderSeesAChangedList(): void
    {
        $changed = "const CREATE_EVENTS = ['delivered', 'opened', 'hard_bounce'];";

        $this->assertSame(['delivered', 'opened', 'hard_bounce'], self::listedEvents($changed));
        $this->assertNotSame(self::EXPECTED_EVENTS, self::listedEvents($changed));
    }
}
