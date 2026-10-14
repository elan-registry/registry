<?php

declare(strict_types=1);

/**
 * Worker process of EmailEventApplierConcurrencyTest (#2359).
 *
 * Runs EmailEventMatcher and EmailEventApplier in its own PHP process, so the
 * test can run two event steps at the same time, each on its own database
 * connection. It is not a test. Usage: `php email-event-apply-worker.php <json>`.
 *
 * Job keys: user_id, address, event, message_id, message_time, occurred_at,
 * source, pause (bool), match (bool). With `match`, the worker first matches
 * by message ID (step (a)). With `pause`, it prints PAUSED after the match
 * step and waits for one line on stdin before it applies the event.
 *
 * Protocol on stdout, one line each: READY, MATCHED, PAUSED, then
 * `RESULT:<json>`. Failures print `ERROR:<class>` and exit with 1.
 */

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Database\DbAdapter;

$root = dirname(__DIR__, 2);
$job = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($job)) {
    fwrite(STDERR, "worker: the first argument must be a JSON object\n");
    exit(2);
}

require $root . '/vendor/autoload.php';
\Dotenv\Dotenv::createMutable($root, '.env.test.local')->load();
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['SCRIPT_FILENAME'] = $root . '/usersc/scripts/after_user_deletion.php';
chdir($root);
ob_start();
require_once $root . '/users/init.php';
ob_end_clean();

try {
    global $db;
    $db = DB::getInstance();
    $repo = new CarRepository(new DbAdapter($db));
    echo "READY\n";
    flush();

    if (!empty($job['match'])) {
        $match = (new EmailEventMatcher($repo))->matchByMessageId((string) $job['message_id'], (string) $job['address']);
    } else {
        $match = EmailEventMatch::matched((int) $job['user_id'], null, (string) $job['message_time']);
    }
    echo "MATCHED\n";
    flush();

    if (!$match->isMatched()) {
        echo 'RESULT:' . json_encode(['matched' => false]) . "\n";
        exit(0);
    }

    if (!empty($job['pause'])) {
        echo "PAUSED\n";
        flush();
        fgets(STDIN);
    }

    $applier = new EmailEventApplier($repo, new CarVerificationManager($repo));
    $isNew = $applier->apply(
        $match,
        (string) $job['address'],
        (string) $job['event'],
        null,
        (string) $job['message_id'],
        (string) $job['occurred_at'],
        (string) ($job['source'] ?? 'webhook'),
        EmailEventApplier::MODE_EVENT
    );
    echo 'RESULT:' . json_encode(['matched' => true, 'is_new' => $isNew]) . "\n";
} catch (Throwable $e) {
    echo 'ERROR:' . get_class($e) . "\n";
    fwrite(STDERR, get_class($e) . " " . $e->getMessage() . "\n");
    exit(1);
}
