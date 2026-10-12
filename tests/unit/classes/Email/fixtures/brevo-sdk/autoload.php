<?php

declare(strict_types=1);

// Stand-in for usersc/plugins/sendinblue/vendor/autoload.php in
// BrevoDevOverrideTest. It declares a minimal Brevo\Client\Configuration
// with the real SDK's shared-default behavior: getDefaultConfiguration()
// returns one static instance, and setApiKey() leaves the host unchanged.
//
// Load it ONLY inside a #[RunInSeparateProcess] test. In a shared process
// it would declare Brevo\Client\Configuration for every later test, and on
// a machine with the real SDK loaded it would fatal on redeclaration.

namespace Brevo\Client;

class Configuration
{
    private static ?self $defaultConfiguration = null;

    private string $host = 'https://api.brevo.com/v3';

    /** @var array<string, string> */
    private array $apiKeys = [];

    public static function getDefaultConfiguration(): self
    {
        if (self::$defaultConfiguration === null) {
            self::$defaultConfiguration = new self();
        }

        return self::$defaultConfiguration;
    }

    public function setHost(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setApiKey(string $apiKeyIdentifier, string $key): self
    {
        $this->apiKeys[$apiKeyIdentifier] = $key;
        return $this;
    }

    public function getApiKey(string $apiKeyIdentifier): ?string
    {
        return $this->apiKeys[$apiKeyIdentifier] ?? null;
    }
}
