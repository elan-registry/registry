<?php

declare(strict_types=1);

/**
 * Fixture for SessionTimeZoneIntegrationTest (#2589). invokeEndpoint() runs it
 * after users/init.php, as a fresh PHP process. It prints the MySQL clock and
 * the PHP clock as JSON. It writes nothing. It is not a web page and has no
 * securePage() call, so it must stay out of the web routes (the .htaccess
 * blocks tests/, and the CLI check below stops any other route).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$row = DB::getInstance()->query('SELECT NOW() AS db_now')->first();

echo json_encode([
    'db_now' => (string) $row->db_now,
    'php_now' => date('Y-m-d H:i:s'),
], JSON_THROW_ON_ERROR);
