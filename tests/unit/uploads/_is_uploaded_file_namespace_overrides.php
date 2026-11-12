<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * Namespace-scoped override for is_uploaded_file(), used only by
 * FileUploadSecurityTest and CarImageProcessorBatchTest to exercise the real
 * CarImageProcessor::validateFileUpload() (#1444) and validateUploadBatch()
 * (#1844) outside of an actual HTTP file upload request.
 *
 * is_uploaded_file() only ever returns true for files PHP itself moved into
 * a temp location during multipart/form-data processing — it always returns
 * false for a plain file created with file_put_contents(), which is how
 * these tests build their fixtures. Without this override, every call into
 * validateFileUpload() would fail at that check regardless of the size/error
 * conditions the test is actually trying to exercise.
 *
 * PHP resolves unqualified function calls against the CURRENT namespace
 * first, falling back to the global namespace only if nothing is defined
 * here. CarImageProcessor.php is in the ElanRegistry\Car namespace and
 * calls is_uploaded_file() unqualified, so this override takes precedence
 * there — without affecting any other file, since PHP namespace resolution
 * is per-call-site, not global.
 *
 * IMPORTANT: PHP has no per-file function scoping — once this file is
 * require_once'd, this ElanRegistry\Car\is_uploaded_file symbol is declared
 * for the rest of the PHPUnit process, not just this test file.
 */
if (!\function_exists(__NAMESPACE__ . '\\is_uploaded_file')) {
    function is_uploaded_file(string $filename): bool
    {
        return \is_uploaded_file($filename) || \file_exists($filename);
    }
}
