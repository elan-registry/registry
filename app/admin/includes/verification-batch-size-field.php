<?php
declare(strict_types=1);

/*
 * The value of the "Batch size" row in the Automatic Sending card of the
 * Verification tab.
 *
 * tab-verification.php includes it inside the Batch size <dd>.
 *
 * Expects from the including scope:
 *   $vsBatchSize ?int, the configured batch size; null when the read threw
 *   $vsCanToggle bool, true when the viewer may change the batch size (admin)
 *   $csrfToken   string, the CSRF token for the Save form
 *
 * When the read threw, no viewer gets the form: a Save would post the
 * placeholder value and overwrite the real setting (#2457).
 */

/** @var ?int $vsBatchSize */
/** @var string $csrfToken */
// Fail closed: without the flag the viewer gets the read-only text.
$vsCanToggle    = $vsCanToggle ?? false;
$vsBatchSizeEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<?php if ($vsBatchSize === null) { ?>
                <span class="badge text-bg-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Unavailable
                </span>
                <small class="text-muted ms-1">The batch size could not be read. Check the system log.</small>
<?php } elseif ($vsCanToggle) { ?>
                <form action="index.php?tab=verification" method="POST" class="row g-2 align-items-center">
                    <input type="hidden" name="csrf" value="<?= $vsBatchSizeEsc($csrfToken) ?>">
                    <input type="hidden" name="command" value="verification_set_batch_size">
                    <div class="col-auto">
                        <label class="visually-hidden" for="verificationBatchSize">Batch size</label>
                        <input type="number" class="form-control form-control-sm"
                               id="verificationBatchSize" name="batch_size"
                               min="1" max="25" style="width: 6rem;"
                               value="<?= $vsBatchSizeEsc((string) $vsBatchSize) ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
                <small class="form-text text-muted d-block mt-1">
                    <i class="fas fa-info-circle"></i>
                    Cars per run, for both the automatic job and the manual send below.
                    Values outside 1&ndash;25 are clamped when saved.
                </small>
<?php } else { ?>
                    <?= $vsBatchSizeEsc((string) $vsBatchSize) ?> cars per run
<?php } ?>
