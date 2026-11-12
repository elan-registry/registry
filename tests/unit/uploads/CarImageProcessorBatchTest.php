<?php

declare(strict_types=1);

require_once __DIR__ . '/_is_uploaded_file_namespace_overrides.php';

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests CarImageProcessor::validateUploadBatch() (#1844).
 *
 * The method checks every file of a multi-file upload before save.php moves
 * one. A bad file must give one owner message that names the file and the
 * reason, and must not get a MIME type entry.
 *
 * Requires _is_uploaded_file_namespace_overrides.php (see its docblock): a
 * file made with file_put_contents() is never an upload for the real
 * is_uploaded_file().
 */
#[Group('fast')]
class CarImageProcessorBatchTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/elan_batch_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    /**
     * Test that a batch with one good and one bad file names only the bad file
     */
    public function testBatchOfOneValidAndOneInvalidFileNamesOnlyTheBadFile(): void
    {
        $good = $this->makeFile('good.jpg', $this->jpegData());
        $bad  = $this->makeFile('tiny.png', substr($this->pngData(), 0, 50));

        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['good-photo.jpg', $good, UPLOAD_ERR_OK, (int) filesize($good)],
            ['tiny-photo.png', $bad, UPLOAD_ERR_OK, 50],
        ]));

        // Not-empty first, so that the test cannot pass on an empty list.
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('tiny-photo.png', $result['errors'][0]);
        $this->assertStringContainsString('The photo file is empty or damaged.', $result['errors'][0]);
        $this->assertStringNotContainsString('good-photo.jpg', $result['errors'][0]);
        $this->assertSame([0 => 'image/jpeg'], $result['mimeTypes']);
        $this->assertArrayNotHasKey(1, $result['mimeTypes']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('tiny-photo.png', $result['rejected'][0]['name']);
        $this->assertStringContainsString('File too small', $result['rejected'][0]['reason']);
    }

    /**
     * Test that a batch with two bad files gives one error for each file
     */
    public function testBatchOfTwoInvalidFilesReturnsOneErrorPerFile(): void
    {
        $a = $this->makeFile('a.png', substr($this->pngData(), 0, 40));
        $b = $this->makeFile('b.jpg', 'plain text content, more than one hundred bytes long. ' . str_repeat('t', 60));

        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['first.png', $a, UPLOAD_ERR_OK, 40],
            ['second.jpg', $b, UPLOAD_ERR_OK, (int) filesize($b)],
        ]));

        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('first.png', $result['errors'][0]);
        $this->assertStringNotContainsString('second.jpg', $result['errors'][0]);
        $this->assertStringContainsString('second.jpg', $result['errors'][1]);
        $this->assertStringNotContainsString('first.png', $result['errors'][1]);
        $this->assertSame([], $result['mimeTypes']);
        $this->assertCount(2, $result['rejected']);
    }

    /**
     * Test that the batch check continues after the first bad file
     */
    public function testBatchChecksEveryFileAfterTheFirstBadOne(): void
    {
        $bad  = $this->makeFile('bad.png', substr($this->pngData(), 0, 40));
        $good = $this->makeFile('good.png', $this->pngData() . str_repeat("\0", 100));

        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['bad.png', $bad, UPLOAD_ERR_OK, 40],
            ['good.png', $good, UPLOAD_ERR_OK, (int) filesize($good)],
        ]));

        $this->assertCount(1, $result['errors']);
        $this->assertSame([1 => 'image/png'], $result['mimeTypes']);
    }

    /**
     * Test that an empty file input (UPLOAD_ERR_NO_FILE) is skipped
     */
    public function testBatchSkipsNoFileEntries(): void
    {
        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['', '', UPLOAD_ERR_NO_FILE, 0],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['mimeTypes']);
        $this->assertSame([], $result['rejected']);
    }

    /**
     * Test that an entry with a PHP upload error gives its owner message
     */
    public function testBatchReportsAnUploadErrorEntryWithItsOwnerMessage(): void
    {
        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['partial.jpg', '', UPLOAD_ERR_PARTIAL, 0],
        ]));

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('partial.jpg', $result['errors'][0]);
        $this->assertStringContainsString('Photo upload did not complete.', $result['errors'][0]);
        $this->assertSame([], $result['mimeTypes']);
    }

    /**
     * Test that a file of a wrong type gives the type owner message
     */
    public function testBatchRejectsWrongTypeWithOwnerMessage(): void
    {
        $text = $this->makeFile('notes.jpg', 'plain text content, more than one hundred bytes long. ' . str_repeat('t', 60));

        $result = CarImageProcessor::validateUploadBatch($this->batch([
            ['notes.jpg', $text, UPLOAD_ERR_OK, (int) filesize($text)],
        ]));

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('notes.jpg', $result['errors'][0]);
        $this->assertStringContainsString('not a JPEG, PNG or GIF', $result['errors'][0]);
        $this->assertStringNotContainsString('text/plain', $result['errors'][0]);
        $this->assertSame([], $result['mimeTypes']);
    }

    /**
     * cleanClientText() removes control characters, replaces invalid UTF-8
     * and caps the length. It does not HTML-encode: car-edit.js escapes the
     * error text. The expected string is a literal, not a cleanClientText() call.
     */
    public function testBatchCleansClientNameInMessage(): void
    {
        $bad  = $this->makeFile('tiny.png', substr($this->pngData(), 0, 50));
        $name = "bad\x00\n<b>\xFF" . str_repeat('a', 200) . '.png';

        $result = CarImageProcessor::validateUploadBatch($this->batch([
            [$name, $bad, UPLOAD_ERR_OK, 50],
        ]));

        $this->assertCount(1, $result['errors']);
        $expectedName = 'bad<b>?' . str_repeat('a', 93);
        $this->assertStringStartsWith('File upload rejected for ' . $expectedName . ': ', $result['errors'][0]);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $result['errors'][0]);
        // The log gets the raw name.
        $this->assertSame($name, $result['rejected'][0]['name']);
    }

    /**
     * A malformed batch gives ImageProcessingException with an owner message,
     * or an empty result for an empty batch. It never gives a TypeError.
     *
     * @param array<mixed> $files
     */
    #[DataProvider('malformedBatchProvider')]
    public function testMalformedBatchThrowsOwnerMessageException(array $files): void
    {
        try {
            CarImageProcessor::validateUploadBatch($files);
            $this->fail('A malformed batch must throw ImageProcessingException');
        } catch (ImageProcessingException $e) {
            $this->assertNotSame('', $e->getUserMessage());
            $this->assertStringContainsString('Malformed upload batch', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function malformedBatchProvider(): array
    {
        $ok = [
            'name' => ['a.jpg'],
            'tmp_name' => ['/tmp/phpabc'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [1024],
        ];
        $with = static fn (string $field, mixed $value): array => [array_merge($ok, [$field => $value])];
        $without = static function (string $field) use ($ok): array {
            unset($ok[$field]);
            return [$ok];
        };

        return [
            'name is a string' => $with('name', 'a.jpg'),
            'tmp_name is a string' => $with('tmp_name', '/tmp/phpabc'),
            'error is a string' => $with('error', '0'),
            'size is a string' => $with('size', '1024'),
            'name is null' => $with('name', null),
            'name key missing' => $without('name'),
            'tmp_name key missing' => $without('tmp_name'),
            'error key missing' => $without('error'),
            'size key missing' => $without('size'),
            'name list entry is an int' => $with('name', [123]),
            'tmp_name list entry is an int' => $with('tmp_name', [123]),
            'error list entry is a string' => $with('error', ['0']),
            'error list entry is null' => $with('error', [null]),
            'size list entry is a string' => $with('size', ['1024']),
            'size list entry is a float' => $with('size', [1024.5]),
            'name list entry is a nested list' => $with('name', [['a.jpg']]),
            'size list is shorter' => $with('size', []),
            'name list is longer' => $with('name', ['a.jpg', 'b.jpg']),
            'same length, different keys' => $with('size', [5 => 1024]),
            'size list is longer' => $with('size', [1024, 2048]),
            'error list has an extra key' => $with('error', [UPLOAD_ERR_OK, 'x' => UPLOAD_ERR_OK]),
            'tmp_name has a NUL byte' => $with('tmp_name', ["/tmp/php\0abc"]),
        ];
    }

    /**
     * Test that an empty batch gives an empty result
     */
    public function testEmptyBatchGivesEmptyResult(): void
    {
        $this->assertSame(
            ['mimeTypes' => [], 'errors' => [], 'rejected' => []],
            CarImageProcessor::validateUploadBatch([])
        );
    }

    /**
     * Test that a batch with empty lists gives an empty result
     */
    public function testBatchWithEmptyListsGivesEmptyResult(): void
    {
        $this->assertSame(
            ['mimeTypes' => [], 'errors' => [], 'rejected' => []],
            CarImageProcessor::validateUploadBatch(['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []])
        );
    }

    /**
     * Build the $_FILES['file'] shape from one row for each file.
     *
     * @param list<array{string, string, int, int}> $rows name, tmp_name, error, size
     * @return array<string, list<int|string>>
     */
    private function batch(array $rows): array
    {
        return [
            'name' => array_column($rows, 0),
            'tmp_name' => array_column($rows, 1),
            'error' => array_column($rows, 2),
            'size' => array_column($rows, 3),
        ];
    }

    private function makeFile(string $filename, string $content): string
    {
        $path = $this->tempDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    private function jpegData(): string
    {
        $data = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/wAAAAAAA');
        return (string) $data;
    }

    private function pngData(): string
    {
        return "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\tpHYs\x00\x00\x0b\x13\x00\x00\x0b\x13\x01\x00\x9a\x9c\x18\x00\x00\x00\fIDATx\x9cc\xf8\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00IEND\xaeB`\x82";
    }
}
