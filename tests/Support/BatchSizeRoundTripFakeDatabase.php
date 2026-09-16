<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * BatchSizeRoundTripFakeDatabase - FakeDatabase double for
 * VerificationSettingsTest's setBatchSize()/batchSize() round-trip test
 *
 * A single instance backs both the write (`setBatchSize()`'s `UPDATE
 * er_verification_settings SET batch_size ...`) and the read-back
 * (`batchSize()`'s `SELECT batch_size FROM er_verification_settings ...`),
 * so the read always answers with whatever was actually written — proving
 * the clamped value genuinely persisted, rather than asserting against a
 * second, independently-configured double that could silently drift from
 * the implementation's own clamped value.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods (`query()`, `first()` here)
 * with a body that doesn't depend on mutable state, because an anonymous
 * class can never be extended later to add one. A named class with real
 * constructor-driven state is exempt from that check. See
 * CronJobGuardFakeDatabase's docblock for the same rationale, established
 * first for a different test.
 *
 * @package Tests\Support
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1885
 */
class BatchSizeRoundTripFakeDatabase extends FakeDatabase
{
    private ?int $written = null;

    public function query(string $sql, array $params = []): self
    {
        if (stripos($sql, 'UPDATE er_verification_settings SET batch_size') !== false) {
            $this->written = (int) $params[0];
        }

        return $this;
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->written !== null) {
            $row = ['id' => 1, 'batch_size' => $this->written];
            return $assoc ? $row : (object) $row;
        }

        return [];
    }
}
