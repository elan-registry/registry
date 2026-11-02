<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * BrevoUnblockReason - Why one {@see BrevoBlocklistClient::unblock()} call failed
 *
 * The value is the text that the Clear Block log line holds.
 *
 * @package ElanRegistry\Email
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2357
 */
enum BrevoUnblockReason: string
{
    /** No Brevo API key could be read. */
    case NoApiKey = 'no_api_key';

    /** The vendored Brevo SDK is not installed: no autoloader file. */
    case NoSdk = 'no_sdk';

    /**
     * The SDK autoloader is there, but the SDK did not load: the autoloader
     * threw, or the API class is still undefined. A fault, not a missing setup.
     */
    case SdkLoadFailed = 'sdk_load_failed';

    /** Brevo answered with a non-2xx status other than 404. */
    case Http = 'http';

    /** The request did not get an HTTP answer (connection, timeout, SDK error). */
    case Transport = 'transport';

    /** The query that reads the API key failed. A retry can work. No call was made. */
    case DbError = 'db_error';

    /** The address was empty or whitespace only, so no call was made. */
    case NoAddress = 'no_address';
}
