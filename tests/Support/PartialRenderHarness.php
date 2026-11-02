<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMXPath;
use ErrorException;

/**
 * Include a view partial, capture its HTML, and read the HTML as a DOM
 *
 * Shared by the partial render tests. The partial sees only the variables
 * in `$vars`, so a test that leaves a key out sees what an including scope
 * that forgot the variable would see.
 */
trait PartialRenderHarness
{
    /**
     * Include a partial the way its page does and return its HTML
     *
     * An environment variable can name another file, so a reviewer can
     * point the test at a copy of an older partial and prove that the test
     * can fail. A PHP warning or deprecation inside the partial is a defect,
     * not noise, so it is thrown as an ErrorException.
     *
     * @param string               $partial     Path of the partial
     * @param string               $overrideEnv Environment variable that can replace `$partial`
     * @param array<string, mixed> $vars        Variables for the partial scope
     * @return string The captured HTML
     */
    private static function renderPartial(string $partial, string $overrideEnv, array $vars): string
    {
        $override = getenv($overrideEnv);
        $file     = is_string($override) && $override !== '' ? $override : $partial;

        set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
            throw new ErrorException($message, 0, $severity, $errFile, $line);
        });
        ob_start();
        try {
            // A static closure keeps $this and the locals of this method out of the partial scope.
            (static function (string $partialFile, array $partialVars): void {
                extract($partialVars);
                include $partialFile;
            })($file, $vars);
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        return $html;
    }

    /**
     * Parse an HTML fragment as UTF-8 and return an XPath over it
     *
     * @param string $html The fragment, without `<html>` or `<body>`
     */
    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }
}
