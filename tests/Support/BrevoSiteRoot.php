<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Car\VerificationSettings;

/**
 * BrevoSiteRoot - temp site root that holds the sendinblue plugin files
 *
 * `VerificationSettings::brevoNotReadyReason()` reads two files under the site
 * root: `usersc/plugins/sendinblue/override.php` (must exist) and
 * `usersc/plugins/sendinblue/info.xml` (its `<version>` must be 1.8.0 or
 * later). This class makes a throwaway root with those files and points the
 * `$abs_us_root` / `$us_url_root` globals at it, so a unit test does not depend
 * on the checkout's gitignored plugin directory.
 *
 * Call {@see remove()} in tearDown(). It restores the two globals and deletes
 * only the files and directories this class made.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2184
 */
final class BrevoSiteRoot
{
    /** Plugin version that makes Brevo ready, written by default. */
    public const READY_VERSION = '1.8.0';

    private const PLUGIN_DIR = '/usersc/plugins/sendinblue';

    private ?string $root;

    private function __construct(
        string $root,
        private readonly ?string $originalAbsUsRoot,
        private readonly ?string $originalUsUrlRoot,
    ) {
        $this->root = $root;
    }

    /**
     * Make the temp root and point the site-root globals at it
     *
     * @param string $prefix Directory name prefix, for a readable temp path
     * @param bool $withOverrideFile True to write override.php
     * @param string|null $pluginVersion Version for info.xml; null writes no info.xml
     * @return self The new root; call remove() when done
     */
    public static function create(
        string $prefix,
        bool $withOverrideFile = true,
        ?string $pluginVersion = self::READY_VERSION,
    ): self {
        global $abs_us_root, $us_url_root;

        $root = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
        mkdir($root . self::PLUGIN_DIR, 0777, true);

        $siteRoot = new self($root, $abs_us_root ?? null, $us_url_root ?? null);

        if ($withOverrideFile) {
            file_put_contents($root . self::PLUGIN_DIR . '/override.php', BrevoOverrideStub::CONTENT);
        }
        if ($pluginVersion !== null) {
            $siteRoot->writePluginVersion($pluginVersion);
        }

        $abs_us_root = $root . '/';
        $us_url_root = '';

        return $siteRoot;
    }

    /**
     * A real VerificationSettings whose brevoReady() returns true while this root is in place
     *
     * VerificationSettings is final, so a test cannot double it. The fake
     * database gives the plg_sendinblue key row, and this root gives the files.
     *
     * @return VerificationSettings Settings that report Brevo ready
     */
    public function readySettings(): VerificationSettings
    {
        return new VerificationSettings(new VerificationSettingsFakeDatabase(
            firstRowValue: (object) ['key' => 'test-api-key'],
        ));
    }

    /**
     * Write info.xml with the given plugin version, in the upstream file's shape
     *
     * @param string $version Value for the `<version>` element
     */
    public function writePluginVersion(string $version): void
    {
        $this->writeInfoXml(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<plugin>\n"
            . "  <name>Brevo Sendinblue Plugin</name>\n"
            . "  <version>{$version}</version>\n"
            . "</plugin>\n"
        );
    }

    /**
     * Write info.xml with exactly this content
     *
     * @param string $contents Full file content
     */
    public function writeInfoXml(string $contents): void
    {
        file_put_contents($this->pluginFile('info.xml'), $contents);
    }

    /**
     * Restore the site-root globals and delete the temp root. Safe to call twice.
     */
    public function remove(): void
    {
        if ($this->root === null) {
            return;
        }

        global $abs_us_root, $us_url_root;
        $abs_us_root = $this->originalAbsUsRoot;
        $us_url_root = $this->originalUsUrlRoot;

        foreach (['override.php', 'info.xml'] as $file) {
            if (is_file($this->pluginFile($file))) {
                unlink($this->pluginFile($file));
            }
        }
        rmdir($this->root . self::PLUGIN_DIR);
        rmdir($this->root . '/usersc/plugins');
        rmdir($this->root . '/usersc');
        rmdir($this->root);
        $this->root = null;
    }

    private function pluginFile(string $name): string
    {
        return $this->root . self::PLUGIN_DIR . '/' . $name;
    }
}
