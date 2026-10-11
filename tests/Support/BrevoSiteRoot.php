<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Car\VerificationSettings;

/**
 * BrevoSiteRoot - temp site root that holds the sendinblue plugin files
 *
 * `VerificationSettings::brevoNotReadyReason()` reads these files under the
 * site root:
 *
 * - `usersc/plugins/plugins.ini.php` (must give `sendinblue = 1`)
 * - `usersc/plugins/sendinblue/override.php` (must exist)
 * - `usersc/plugins/sendinblue/info.xml` (its `<version>` must be 1.8.0 or later)
 * - `usersc/plugins/sendinblue/override.RENAME.php` (must match override.php)
 *
 * This class makes a throwaway root with those files in the ready state and
 * points the `$abs_us_root` / `$us_url_root` globals at it. A unit test then
 * does not depend on the checkout's gitignored plugin directory.
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

    private const PLUGINS_INI = '/usersc/plugins/plugins.ini.php';

    /** Plugin state file content that marks the Brevo plugin active, in the upstream file's shape. */
    private const ACTIVE_PLUGINS_INI = ";<?php die();?>\nhooker = 1\nsendinblue = 1\n";

    /** Plugin files that {@see remove()} deletes. */
    private const PLUGIN_FILES = ['override.php', 'override.RENAME.php', 'info.xml'];

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
     * The bundled override.RENAME.php and an active plugins.ini.php are always
     * written. Use the helper methods to break them.
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

        file_put_contents($root . self::PLUGIN_DIR . '/override.RENAME.php', BrevoOverrideStub::CONTENT);
        $siteRoot->writePluginsIni(self::ACTIVE_PLUGINS_INI);
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
     * Write plugins.ini.php with exactly this content
     *
     * @param string $contents Full file content
     */
    public function writePluginsIni(string $contents): void
    {
        file_put_contents($this->root . self::PLUGINS_INI, $contents);
    }

    /**
     * Give the Brevo plugin the value 2 in plugins.ini.php, as the Plugins page does on deactivate
     */
    public function deactivatePlugin(): void
    {
        $this->writePluginsIni(";<?php die();?>\nhooker = 1\nsendinblue = 2\n");
    }

    /**
     * Delete plugins.ini.php
     */
    public function removePluginsIni(): void
    {
        if (is_file($this->root . self::PLUGINS_INI)) {
            unlink($this->root . self::PLUGINS_INI);
        }
    }

    /**
     * Write an override.php that differs from the bundled override.RENAME.php
     *
     * This is the state after a plugin update when nobody replaces override.php with the bundled version.
     */
    public function writeStaleOverride(): void
    {
        file_put_contents($this->pluginFile('override.php'), "<?php\n// override from an older plugin version\n");
    }

    /**
     * Delete the bundled override.RENAME.php
     */
    public function removeBundledOverride(): void
    {
        if (is_file($this->pluginFile('override.RENAME.php'))) {
            unlink($this->pluginFile('override.RENAME.php'));
        }
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

        foreach (self::PLUGIN_FILES as $file) {
            if (is_file($this->pluginFile($file))) {
                unlink($this->pluginFile($file));
            }
        }
        $this->removePluginsIni();
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
