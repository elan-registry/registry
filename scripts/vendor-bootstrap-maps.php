<?php

declare(strict_types=1);

/**
 * Vendors Bootstrap's official .map files to match whatever Bootstrap
 * version is currently vendored under users/css|js/ (Issue #1414).
 *
 * users/css/ and users/js/ are gitignored — provisioned by UserSpice's own
 * admin-triggered updater (users/admin.php?view=updates), independent of
 * this repo's git history — so the .map files can't simply be committed;
 * they'd silently go stale the next time UserSpice's Bootstrap build is
 * updated. This script re-derives them from whatever version is actually on
 * disk every time it runs (invoked by .githooks/post-merge locally on every
 * `git pull`/`git merge`, and by scripts/server-hooks/post-receive on every
 * test/prod deploy), so they can't drift out of sync for long.
 *
 * Before trusting a fetched .map, the corresponding official .min.css/.min.js
 * for that exact version is fetched and byte-hash-compared against the local
 * vendored file — this is skipped (not overwritten) if they don't match,
 * since a mismatch means the local file isn't the unmodified official build.
 *
 * Idempotency: after a successful vendor, the local minified file's hash is
 * recorded in a sidecar `<map>.source-hash` file. On the next run, if that
 * hash still matches and the .map already exists, the asset is skipped
 * entirely with zero network calls — this keeps routine `git pull`s (which
 * run this on every invocation via .githooks/post-merge) fast in the common
 * case where the vendored Bootstrap version hasn't changed.
 *
 * Usage: php scripts/vendor-bootstrap-maps.php [projectRoot]
 *   projectRoot - optional; defaults to the repository root (one level above
 *                 this script). Overridable for tests, which point it at a
 *                 fixture directory instead of the real project.
 *
 * Never allowed to fail the caller: every failure path is caught and exits 0.
 * The hook callers additionally guard the call with `||` to handle a
 * non-zero PHP process exit (e.g. a parse error or OOM fatal) that occurs
 * before this try/catch can even run — everything this script itself can
 * catch already exits 0, so that `||` fallback exists purely for failures
 * outside this script's own control.
 */

function warn(string $message): void
{
    fwrite(STDERR, "vendor-bootstrap-maps: $message\n");
}

function fetchUrl(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($body === false) {
        warn("curl error fetching $url: $curlError");
        return null;
    }
    if ($httpCode !== 200) {
        warn("unexpected HTTP $httpCode fetching $url");
        return null;
    }

    return $body;
}

/**
 * Extracted as a pure function so the version-parsing logic is unit
 * testable without touching the filesystem or network.
 */
function parseBootstrapVersion(string $header): ?string
{
    if (!preg_match('/Bootstrap v(\d+\.\d+\.\d+)/', $header, $matches)) {
        return null;
    }
    return $matches[1];
}

try {
    $projectRoot = $argv[1] ?? dirname(__DIR__);

    $versionFile = $projectRoot . '/users/js/bootstrap.bundle.min.js';
    if (!file_exists($versionFile)) {
        warn("$versionFile not found, skipping.");
        exit(0);
    }

    $header = file_get_contents($versionFile, false, null, 0, 200);
    $version = $header === false ? null : parseBootstrapVersion($header);
    if ($version === null) {
        warn("could not parse Bootstrap version from $versionFile, skipping.");
        exit(0);
    }

    // Both assets share the single version parsed from the JS bundle above — if
    // UserSpice's updater ever vendors mismatched CSS/JS versions, the CSS
    // byte-hash check below fails closed (skips, doesn't corrupt) rather than
    // silently vendoring a mismatched map.
    $assets = [
        [
            'minified' => 'users/css/bootstrap.min.css',
            'map' => 'users/css/bootstrap.min.css.map',
            'cdnPath' => 'dist/css/bootstrap.min.css',
        ],
        [
            'minified' => 'users/js/bootstrap.bundle.min.js',
            'map' => 'users/js/bootstrap.bundle.min.js.map',
            'cdnPath' => 'dist/js/bootstrap.bundle.min.js',
        ],
    ];

    foreach ($assets as $asset) {
        $localPath = $projectRoot . '/' . $asset['minified'];
        $mapPath = $projectRoot . '/' . $asset['map'];
        $sourceHashPath = $mapPath . '.source-hash';
        $cdnBase = "https://cdn.jsdelivr.net/npm/bootstrap@{$version}/{$asset['cdnPath']}";

        $localHash = @hash_file('sha256', $localPath);
        if ($localHash === false) {
            $err = error_get_last();
            warn("could not hash $localPath, skipping." . ($err !== null ? ' (' . $err['message'] . ')' : ''));
            continue;
        }

        // Already up to date for this exact local file — skip the network entirely.
        if (
            file_exists($mapPath)
            && @file_get_contents($sourceHashPath) === $localHash
        ) {
            continue;
        }

        // fetchUrl() already warns with the specific curl/HTTP failure reason on null.
        $remoteMinified = fetchUrl($cdnBase);
        if ($remoteMinified === null) {
            continue;
        }
        if (hash('sha256', $remoteMinified) !== $localHash) {
            warn(
                "{$asset['minified']} does not byte-match the official v$version build " .
                    '(customized locally?) — skipping its map to avoid vendoring a mismatched one.'
            );
            continue;
        }

        $remoteMap = fetchUrl($cdnBase . '.map');
        if ($remoteMap === null) {
            continue;
        }
        json_decode($remoteMap);
        if (json_last_error() !== JSON_ERROR_NONE) {
            warn("fetched map for {$asset['map']} is not valid JSON, skipping.");
            continue;
        }

        // Write via a temp file + rename (atomic on the same filesystem, unique per
        // invocation) so a killed process or two overlapping runs can't interleave
        // writes or leave a truncated .map on disk.
        $tmpPath = $mapPath . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmpPath, $remoteMap) === false || !@rename($tmpPath, $mapPath)) {
            $err = error_get_last();
            warn("could not write {$asset['map']}, skipping." . ($err !== null ? ' (' . $err['message'] . ')' : ''));
            @unlink($tmpPath);
            continue;
        }
        // Record what local hash this map was derived from, so the next run can
        // short-circuit without any network calls if nothing has changed.
        @file_put_contents($sourceHashPath, $localHash);

        echo "vendor-bootstrap-maps: {$asset['map']} updated (Bootstrap v$version).\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "WARNING: vendor-bootstrap-maps failed (non-fatal): %s: %s in %s:%d\n",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

exit(0);
