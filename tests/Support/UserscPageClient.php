<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Serves one usersc/ page from the built-in web server and requests it with a
 * curl cookie jar. A page such as login.php or join.php needs a real session
 * and a session-bound CSRF token, so an in-process require cannot test it.
 *
 * Trait static properties belong to each using class, so each test class
 * gets its own server.
 */
trait UserscPageClient
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private static ?PhpBuiltinServer $pageServer = null;
    private static string $pageName = '';

    private string $cookieJar = '';

    /**
     * Call from setUp(). Starts the server on the first call in the class.
     *
     * @param string $page File name in usersc/, e.g. 'login.php'
     * @param string $remoteAddr REMOTE_ADDR that the page sees
     */
    private function startPageClient(string $page, string $remoteAddr): void
    {
        // The server process reads the test database settings from its environment.
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }

        if (self::$pageServer === null) {
            $projectRoot = dirname(__DIR__, 2);
            self::$pageName = $page;
            self::$pageServer = PhpBuiltinServer::start($projectRoot, <<<PHP
            require '{$projectRoot}/vendor/autoload.php';
            \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
            \$_SERVER['REMOTE_ADDR'] = '{$remoteAddr}';
            chdir('{$projectRoot}/usersc');
            require '{$projectRoot}/usersc/{$page}';
            PHP);
        }

        $jar = tempnam(sys_get_temp_dir(), 'usersc_page_cookies_');
        if ($jar === false) {
            throw new RuntimeException('Could not create a temp file for the curl cookie jar');
        }
        $this->cookieJar = $jar;
    }

    /** Call from tearDown(). */
    private function stopPageClient(): void
    {
        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
    }

    /** Call from tearDownAfterClass(). */
    private static function stopPageServer(): void
    {
        self::$pageServer?->stop();
        self::$pageServer = null;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function request(string $method, ?string $body): array
    {
        if (self::$pageServer === null) {
            throw new RuntimeException('startPageClient() must run before request()');
        }

        $ch = curl_init(self::$pageServer->url('/' . self::$pageName));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('curl request to ' . self::$pageName . " failed: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }
}
