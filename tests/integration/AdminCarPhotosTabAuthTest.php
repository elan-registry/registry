<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/CarImageFixtureTrait.php';

use ElanRegistry\Car\CarPhotoRecovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TestJpeg;

/**
 * #2644: the Car Photos tab of app/admin/index.php.
 *
 * Each request runs the real page through invokeEndpoint(). That method
 * always sends a POST. A "GET" here is a request with an empty $post and
 * csrfMode 'omit': $_POST is empty, so ElanInput::existsPost() is false and
 * the page takes its render path, as for a real GET. $get carries
 * `tab=car-photos`.
 *
 * The page reads photo files from the real `userimages/{carId}/`, so the test
 * writes there. tearDown removes each directory that the test made. newCar()
 * fails when a directory for a new car ID exists already, so the test never
 * writes into a real car's folder.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminCarPhotosTabAuthTest extends IntegrationTestCase
{
    use CarImageFixtureTrait;

    private const PAGE = 'app/admin/index.php';

    private const PERMISSION_ADMIN = 2;

    private const PERMISSION_EDITOR = 3;

    private int $adminId = 0;

    private int $editorId = 0;

    private int $plainUserId = 0;

    private int $ownerId = 0;

    /** @var list<int> permission_page_matches rows that a test added */
    private array $pageMatchIds = [];

    /** @var list<string> Absolute paths of the car directories that this test made */
    private array $madeDirectories = [];

    /** @var list<string> Paths that a test made unreadable. tearDown() restores them. */
    private array $locked = [];

    /** @var list<array{link: string, target: string}> Car folder symlinks and their temp directories */
    private array $linkedDirectories = [];

    /** @var string|null Where the real `userimages/` is while a test hides it */
    private ?string $hiddenImageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $hidden = glob(dirname(__DIR__, 2) . '/userimages.hidden-*');
        $this->assertSame(
            [],
            $hidden === false ? [] : $hidden,
            'A previous run left the photo folder hidden. Restore it by hand: move it back to userimages/.'
        );
        $this->requireDatabase();
        $this->initThumbnailSizes();
        $this->madeDirectories = [];
        $this->locked = [];
        $this->linkedDirectories = [];
        $this->pageMatchIds = [];

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);
        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->editorId, self::PERMISSION_EDITOR);
        $this->plainUserId = $this->createTestUser(['permissions' => 1]);
        $this->ownerId = $this->createTestUser(['permissions' => 1]);
    }

    protected function tearDown(): void
    {
        // Restore the folder first, but assert only after the rest of the
        // cleanup: a failed assertion here would skip parent::tearDown().
        $rootRestored = $this->restoreImageRoot();
        foreach ($this->locked as $path) {
            chmod($path, 0664);
        }
        foreach ($this->linkedDirectories as $linked) {
            unlink($linked['link']);
            $this->recursiveRemoveDirectory($linked['target']);
        }
        foreach ($this->madeDirectories as $directory) {
            $this->recursiveRemoveDirectory($directory);
        }
        if ($this->databaseConnected) {
            foreach ($this->pageMatchIds as $matchId) {
                $this->db->query('DELETE FROM permission_page_matches WHERE id = ?', [$matchId]);
            }
            foreach ([$this->adminId, $this->editorId, $this->plainUserId, $this->ownerId] as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
            }
        }
        parent::tearDown();
        $this->assertTrue($rootRestored, 'Test cleanup: move the hidden photo folder back to userimages/. Restore it by hand.');
    }

    // ---- helpers ----------------------------------------------------------

    private function carDirectory(int $carId): string
    {
        return dirname(__DIR__, 2) . '/userimages/' . $carId . '/';
    }

    private function photo(int $carId, string $name, int $seed): void
    {
        $directory = $this->carDirectory($carId);
        if (!is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0775, true), 'Test setup: car directory');
            $this->madeDirectories[] = rtrim($directory, '/');
        }
        TestJpeg::write($directory . $name, 40, 30, ($seed * 37) % 256, (255 - $seed * 29) % 256, ($seed * 71) % 256);
    }

    /** @param list<string>|null $names */
    private function setImage(int $carId, ?array $names): void
    {
        $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$names === null ? null : json_encode($names), $carId]);
        $this->assertFalse($this->db->error(), $this->db->errorString());
    }

    private function newCar(): int
    {
        $carId = $this->createTestCar($this->ownerId);
        $this->assertDirectoryDoesNotExist(
            $this->carDirectory($carId),
            "Test setup: userimages/{$carId}/ exists. The test car ID collides with a real car folder."
        );
        return $carId;
    }

    /** A car with a replaced photo: current b.jpg, detached a.jpg. */
    private function carWithDetachedPhoto(): int
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);
        return $carId;
    }

    /**
     * A car with a detached photo that the server cannot read: current
     * [b.jpg, a.jpg], detached c.jpg with mode 000. The history also holds
     * [a.jpg, b.jpg], the exact reverse of the current list. So the car would
     * offer a restore if the server could read c.jpg. tearDown() restores the
     * mode.
     *
     * The Docker bind mount of the project does not enforce mode 000. So the
     * car folder is a symlink to a temp directory in the container, where the
     * mode applies.
     */
    private function carWithUnreadablePhoto(): int
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Root can read a file with mode 000, so the read failure cannot happen.');
        }
        $carId = $this->newCar();
        $target = sys_get_temp_dir() . '/elan_car_photos_' . bin2hex(random_bytes(8));
        $link = rtrim($this->carDirectory($carId), '/');
        $this->assertTrue(mkdir($target, 0775), 'Test setup: temp car directory');
        $this->assertTrue(symlink($target, $link), 'Test setup: car directory symlink');
        $this->linkedDirectories[] = ['link' => $link, 'target' => $target];
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->photo($carId, 'c.jpg', 3);
        $this->setImage($carId, ['a.jpg', 'b.jpg', 'c.jpg']);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $this->lockPhoto($carId, 'c.jpg');
        return $carId;
    }

    /** Remove read access to a photo. tearDown() restores it. */
    private function lockPhoto(int $carId, string $name): void
    {
        $path = $this->carDirectory($carId) . $name;
        $this->assertTrue(chmod($path, 0000), 'Test setup: remove read access');
        $this->locked[] = $path;
        clearstatcache();
        $this->assertFalse(is_readable($path), 'Test setup: the file system enforces mode 000');
    }

    /**
     * The `seen` field for each car, with the token of its `cars.image` now.
     *
     * @return array{seen: array<int, string>}
     */
    private function seen(int ...$carIds): array
    {
        $seen = [];
        foreach ($carIds as $carId) {
            $seen[$carId] = CarPhotoRecovery::versionToken($this->currentImage($carId));
        }
        return ['seen' => $seen];
    }

    /** The value of the hidden `seen[<carId>]` field in a rendered page. */
    private function seenFromPage(string $html, int $carId): string
    {
        $field = $this->xpath($html)->query("//input[@type='hidden'][@name='seen[{$carId}]']");
        $this->assertSame(1, $field->length, "The page has one seen field for car {$carId}");
        $item = $field->item(0);
        $this->assertInstanceOf(DOMElement::class, $item);
        return $item->getAttribute('value');
    }

    private function currentImage(int $carId): ?string
    {
        $row = $this->db->query('SELECT image FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($row);
        return $row->image === null ? null : (string) $row->image;
    }

    /**
     * Rows of the CarActions log type. A render also writes unrelated rows
     * (for example an EmailSettings row from getBaseUrl()), so the count is
     * for the type that the Car Photos apply writes.
     */
    private function logCount(int $userId): int
    {
        return $this->countMatchingLogs('CarActions', '%', $userId);
    }

    /**
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function render(?int $userId): array
    {
        return $this->invokeEndpoint(self::PAGE, [], $userId, 'omit', ['tab' => 'car-photos']);
    }

    /**
     * @param array<string, scalar|null|array<mixed>> $fields
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function post(?int $userId, array $fields, string $csrfMode = 'valid'): array
    {
        return $this->invokeEndpoint(
            self::PAGE,
            array_merge(['command' => 'car_photos_apply'], $fields),
            $userId,
            $csrfMode,
            ['tab' => 'car-photos']
        );
    }

    /**
     * Fail on PHP error text. assertNoPhpErrorText() also matches "TypeError",
     * which a JavaScript comment in the page footer contains.
     */
    private function assertNoPhpError(string $raw): void
    {
        foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:'] as $text) {
            $this->assertStringNotContainsString($text, $raw);
        }
    }

    /**
     * The page prints each UserSpice message as userSpiceMessage("text",'type').
     * The text is a JSON string. Two or more messages of one type come as one
     * `<li>` list, so the method splits it.
     *
     * @return list<string>
     */
    private function messages(string $raw, string $type): array
    {
        preg_match_all('/userSpiceMessage\("(.*?)",\'' . $type . '\'\)/', $raw, $matches);
        $messages = [];
        foreach ($matches[1] as $encoded) {
            $text = json_decode('"' . $encoded . '"');
            $text = is_string($text) ? $text : $encoded;
            $items = preg_match_all('#<li>(.*?)</li>#s', $text, $listItems) > 0 ? $listItems[1] : [$text];
            foreach ($items as $item) {
                $messages[] = html_entity_decode($item);
            }
        }
        return $messages;
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    // ---- GET ---------------------------------------------------------------

    public function testRenderAsAdminShowsRowWithUncheckedBoxesAndWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);
        $histBefore = $this->countCarsHistRows($carId);
        $logsBefore = $this->logCount($this->adminId);

        $result = $this->render($this->adminId);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $xpath = $this->xpath($result['raw']);
        $this->assertSame(1, $xpath->query("//tr[@data-testid='car-photos-row'][@data-car-id='{$carId}']")->length, 'The car has a row');
        $boxes = $xpath->query("//input[@type='checkbox'][starts-with(@name,'reattach[') or @name='restore[]']");
        $this->assertGreaterThan(0, $boxes->length, 'The grid has checkboxes');
        $this->assertSame(0, $xpath->query("//input[@type='checkbox'][@checked]")->length, 'No checkbox is checked');
        $this->assertSame(1, $xpath->query("//input[@name='reattach[{$carId}][]'][@value='a.jpg']")->length);
        $this->assertSame(0, $xpath->query("//input[@name='restore[]'][@value='{$carId}']")->length, 'No reversed order: no restore box');
        $this->assertSame(
            CarPhotoRecovery::versionToken('["b.jpg"]'),
            $this->seenFromPage($result['raw'], $carId),
            'The seen field holds the token of cars.image'
        );
        $this->assertSame('Apply selected', trim($xpath->query("//button[@data-testid='car-photos-apply']")->item(0)->textContent));
        $this->assertSame(1, $xpath->query("//a[contains(@class,'nav-link')][@href='?tab=car-photos']")->length, 'The nav link exists');

        $this->assertSame($imageBefore, $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame($logsBefore, $this->logCount($this->adminId));
    }

    public function testRenderUnreadablePhotoShowsNoteAndBannerWithNoCheckboxAndWritesNothing(): void
    {
        $carId = $this->carWithUnreadablePhoto();
        $readableCar = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);
        $histBefore = $this->countCarsHistRows($carId);
        $logsBefore = $this->logCount($this->adminId);

        $result = $this->render($this->adminId);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $xpath = $this->xpath($result['raw']);
        $note = $xpath->query("//tr[@data-car-id='{$carId}']//*[@data-testid='car-photos-unreadable']");
        $this->assertSame(1, $note->length, 'The row shows the note');
        $this->assertSame('Could not read: c.jpg', trim((string) preg_replace('/\s+/', ' ', $note->item(0)->textContent)));
        $this->assertSame(0, $xpath->query("//tr[@data-car-id='{$carId}']//input[@type='checkbox']")->length, 'No checkbox for the car');
        $this->assertSame(0, $xpath->query("//input[@name='restore[]'][@value='{$carId}']")->length, 'No restore box, though the history holds the reverse');
        $this->assertSame(0, $xpath->query("//input[@name='seen[{$carId}]']")->length, 'No seen field for a row without a checkbox');
        $this->assertSame(1, $xpath->query("//tr[@data-car-id='{$carId}']//span[normalize-space()='No action available']")->length);
        $this->assertSame(1, $xpath->query("//input[@name='reattach[{$readableCar}][]'][@value='a.jpg']")->length, 'Another car keeps its checkbox');
        $banner = $xpath->query("//*[@data-testid='car-photos-unreadable-banner']");
        $this->assertSame(1, $banner->length, 'The banner is shown');
        $this->assertSame(1, preg_match('/could not read the photos of (\d+) car\(s\)/', $banner->item(0)->textContent, $match));
        $this->assertSame(
            $xpath->query("//*[@data-testid='car-photos-unreadable']")->length,
            (int) $match[1],
            'The banner count equals the number of unreadable notes on the page'
        );

        $this->assertSame($imageBefore, $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame($logsBefore, $this->logCount($this->adminId));
    }

    public function testPostUnreadablePhotoWritesNothingAndNamesTheCar(): void
    {
        $carId = $this->carWithUnreadablePhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['c.jpg']], 'restore' => [(string) $carId]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%"));
        $this->assertContains(
            "1 car(s) not updated because the server could not read the photos: car(s) {$carId}. Check the file permissions.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame([], $this->messages($result['raw'], 'success'), 'No car was updated, so no summary');
    }

    /**
     * The POST carries a current seen token for both cars. One car has a
     * detached photo with mode 000, so the server cannot read it when the POST
     * finds the row again. The POST writes the other car and names the
     * unreadable one. The test does not load the page first, so it does not
     * cover a file that becomes unreadable between the GET and the POST.
     */
    public function testPostOneUnreadableAndOneReadableCarWritesOnlyTheReadableCar(): void
    {
        $readableCar = $this->carWithDetachedPhoto();
        $unreadableCar = $this->carWithUnreadablePhoto();
        $unreadableHistBefore = $this->countCarsHistRows($unreadableCar);

        $result = $this->post($this->adminId, [
            'reattach' => [$readableCar => ['a.jpg'], $unreadableCar => ['c.jpg']],
        ] + $this->seen($readableCar, $unreadableCar));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($readableCar), 'The readable car is written');
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($unreadableCar), 'The unreadable car is unchanged');
        $this->assertSame($unreadableHistBefore, $this->countCarsHistRows($unreadableCar));
        $this->assertSame(['1 car(s) updated.'], $this->messages($result['raw'], 'success'));
        $this->assertContains(
            "1 car(s) not updated because the server could not read the photos: car(s) {$unreadableCar}. Check the file permissions.",
            $this->messages($result['raw'], 'danger')
        );
    }

    public function testRenderReversedOrderShowsRestoreBoxEarlierOrderAndHistoryTime(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $time = $this->db->query(
            'SELECT timestamp FROM cars_hist WHERE car_id = ? AND image = ? ORDER BY id DESC LIMIT 1',
            [$carId, '["a.jpg","b.jpg"]']
        )->first();
        $this->assertIsObject($time, 'Test setup: the trigger wrote the earlier order');

        $result = $this->render($this->adminId);

        $this->assertNoPhpError($result['raw']);
        $xpath = $this->xpath($result['raw']);
        $restore = $xpath->query("//tr[@data-car-id='{$carId}']//input[@type='checkbox'][@name='restore[]'][@value='{$carId}']");
        $this->assertSame(1, $restore->length, 'One restore box');
        $this->assertSame(0, $xpath->query("//tr[@data-car-id='{$carId}']//input[@type='checkbox'][@checked]")->length, 'Not checked');
        $this->assertSame(0, $xpath->query("//input[@name='reattach[{$carId}][]']")->length, 'No reattach box');
        $titles = [];
        foreach ($xpath->query("//tr[@data-car-id='{$carId}']//td[@data-testid='car-photos-earlier']//img") as $img) {
            $this->assertInstanceOf(DOMElement::class, $img);
            $titles[] = $img->getAttribute('title');
        }
        $this->assertSame(['a.jpg', 'b.jpg'], $titles, 'The earlier order, a then b');
        $this->assertSame(
            'Saved ' . $time->timestamp,
            trim($xpath->query("//tr[@data-car-id='{$carId}']//*[@data-testid='car-photos-earlier-at']")->item(0)->textContent)
        );
    }

    public function testRenderAsEditorShowsTheRow(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->render($this->editorId);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame(
            1,
            $this->xpath($result['raw'])->query("//tr[@data-testid='car-photos-row'][@data-car-id='{$carId}']")->length
        );
    }

    public function testRenderShowsHowTheDetachedPhotoLeft(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $dropped = $this->db->query(
            'SELECT timestamp FROM cars_hist WHERE car_id = ? AND image = ? ORDER BY id DESC LIMIT 1',
            [$carId, '["a.jpg"]']
        )->first();
        $this->assertIsObject($dropped, 'Test setup: the trigger wrote the list that held a.jpg');

        $xpath = $this->xpath($this->render($this->adminId)['raw']);

        $this->assertSame(1, $xpath->query("//tr[@data-car-id='{$carId}']//*[@data-testid='car-photos-main']")->length, 'The current photo has the main photo badge');
        $cell = $xpath->query("//tr[@data-car-id='{$carId}']//span[contains(@class,'badge')][not(@data-testid='car-photos-main')]");
        $this->assertSame(1, $cell->length);
        $this->assertSame('replaced', trim($cell->item(0)->textContent));
        $this->assertSame(
            1,
            $xpath->query("//tr[@data-car-id='{$carId}']//div[contains(@class,'text-nowrap')][normalize-space()='{$dropped->timestamp}']")->length,
            'The detached photo shows the time of the save that dropped it'
        );
        $legend = $xpath->query("//*[@data-testid='car-photos-legend']");
        $this->assertSame(1, $legend->length, 'The legend is shown');
        $this->assertStringContainsString('The date under each label is the save that took the photo off the car.', (string) $legend->item(0)->textContent);
        $this->assertSame(
            ['removed', 'replaced', 'partly removed'],
            array_map(static fn(DOMNode $badge): string => trim((string) $badge->textContent), iterator_to_array($xpath->query("//*[@data-testid='car-photos-legend']//span[contains(@class,'badge')]"))),
            'The legend shows each term as the badge of the table'
        );
        $link = $xpath->query("//tr[@data-car-id='{$carId}']/td[1]/a");
        $this->assertSame(1, $link->length);
        $this->assertInstanceOf(DOMElement::class, $link->item(0));
        $this->assertStringEndsWith("app/owner/cars/details.php?car_id={$carId}", $link->item(0)->getAttribute('href'));
        $this->assertSame("Car {$carId} (opens in a new tab)", trim((string) $link->item(0)->textContent));
        $this->assertSame(
            1,
            $xpath->query("//tr[@data-car-id='{$carId}']//img[@alt='Car {$carId}, detached photo 1 of 1'][@title='a.jpg']")->length,
            'Thumbnail of the detached photo'
        );
        $this->assertSame(
            1,
            $xpath->query("//tr[@data-car-id='{$carId}']//img[@alt='Car {$carId}, current photo 1 of 1'][@title='b.jpg']")->length,
            'Thumbnail of the current photo'
        );
    }

    public function testRenderUnsafeNameShowsNoteAndNoReattachBox(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'my photo.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['my photo.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $xpath = $this->xpath($this->render($this->adminId)['raw']);

        $this->assertSame(1, $xpath->query("//tr[@data-car-id='{$carId}']//*[@data-testid='car-photos-unsafe']")->length);
        $this->assertSame(0, $xpath->query("//input[@name='reattach[{$carId}][]']")->length);
    }

    public function testRenderEscapesAnAwkwardFileName(): void
    {
        $carId = $this->newCar();
        $name = 'x"><i id=pwn>.jpg';
        $this->photo($carId, $name, 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, [$name]);
        $this->setImage($carId, ['b.jpg']);

        $result = $this->render($this->adminId);

        $this->assertNoPhpError($result['raw']);
        $xpath = $this->xpath($result['raw']);
        $this->assertSame(1, $xpath->query("//tr[@data-car-id='{$carId}']")->length, 'The car has a row');
        $this->assertSame(0, $xpath->query('//*[@id="pwn"]')->length, 'The name did not become markup');
    }

    public function testRenderUnknownTabFallsBackToTheDefaultTab(): void
    {
        $this->carWithDetachedPhoto();

        $result = $this->invokeEndpoint(self::PAGE, [], $this->adminId, 'omit', ['tab' => 'no-such-tab']);

        $this->assertSame(0, $result['exitCode']);
        $xpath = $this->xpath($result['raw']);
        $this->assertSame(0, $xpath->query("//*[@data-testid='car-photos-card']")->length, 'The Car Photos card is not shown');
        $this->assertSame(1, $xpath->query("//a[contains(@class,'nav-link') and contains(@class,'active')][@href='?tab=car-mgmt']")->length);
    }

    // ---- POST: roles -------------------------------------------------------

    public function testPostAsAdminReattachesAndReportsTheCount(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore + 1, $this->countCarsHistRows($carId));
        $this->assertSame(1, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%", $this->adminId));
        $this->assertSame(['1 car(s) updated.'], $this->messages($result['raw'], 'success'));
        $this->assertSame([], $this->messages($result['raw'], 'danger'));
    }

    public function testPostAfterOutOfBandChangeSinceThePageLoadedWritesNothingAndNamesTheCar(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->photo($carId, 'c.jpg', 3);
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        // Another save lands between the GET and the POST. The car still has
        // detached photos, so apply() finds a row and compares the token.
        $this->setImage($carId, ['c.jpg']);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']], 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["c.jpg"]', $this->currentImage($carId), 'The other save stays');
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%"));
        $danger = $this->messages($result['raw'], 'danger');
        $this->assertContains(
            "1 car(s) not updated because the photos changed since the page loaded: car(s) {$carId}. Check them again.",
            $danger
        );
        $this->assertCount(1, $danger, 'One line names the reason and the car, with no count summary');
        $this->assertSame([], $this->messages($result['raw'], 'success'));
    }

    /**
     * @return array<string, array{array<int|string, mixed>|null}>
     */
    public static function badSeenProvider(): array
    {
        return [
            'missing'   => [null],
            'too short' => [['abc']],
            'upper case' => [[str_repeat('A', 64)]],
        ];
    }

    /**
     * @param array<int|string, mixed>|null $seen The seen value for the car, or null for no field
     */
    #[DataProvider('badSeenProvider')]
    public function testPostWithMissingOrMalformedSeenTokenWritesNothing(?array $seen): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);
        $fields = ['reattach' => [$carId => ['a.jpg']]];
        if ($seen !== null) {
            $fields['seen'] = [$carId => $seen[0]];
        }

        $result = $this->post($this->adminId, $fields);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertContains(
            "1 car(s) not updated because the photos changed since the page loaded: car(s) {$carId}. Check them again.",
            $this->messages($result['raw'], 'danger')
        );
    }

    public function testPostAsEditorReattaches(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->editorId, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
    }

    public function testPostAsAdminRestoresOrderAndReattachesInOneWrite(): void
    {
        $carId = $this->newCar();
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['a.jpg', 'b.jpg', 'c.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['c.jpg']], 'restore' => [(string) $carId]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["a.jpg","b.jpg","c.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore + 1, $this->countCarsHistRows($carId), 'One write');
        $this->assertSame(1, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%", $this->adminId), 'One log row');
    }

    public function testPostAsPlainUserIsRefusedAndWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->plainUserId, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId));

        $this->assertSame($imageBefore, $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%"));
        $this->assertStringNotContainsString('car(s) updated', implode(' ', $this->messages($result['raw'], 'success')) . $result['raw']);
    }

    /**
     * securePage() decides who opens the page. The role check in the new case
     * guards the write for a user that the page admits but who has neither the
     * admin nor the editor role. The test admits the User role to the page for
     * this one request, and tearDown removes the row.
     */
    public function testPostAsUserThatThePageAdmitsWithoutAdminOrEditorRoleIsRefused(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->grantTestPermission($this->plainUserId, 1);
        $page = $this->db->query('SELECT id FROM pages WHERE page = ?', [self::PAGE])->first();
        $this->assertIsObject($page, 'Test setup: the page is registered');
        $this->db->insert('permission_page_matches', ['permission_id' => 1, 'page_id' => (int) $page->id]);
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $this->pageMatchIds[] = (int) $this->db->lastId();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->plainUserId, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertContains(
            'Administrator or editor access is required for this action.',
            $this->messages($result['raw'], 'danger'),
            'The page let the user in, so the refusal comes from the role check'
        );
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(1, $this->countMatchingLogs('Security', "Car Photos: user without the admin or editor role%", $this->plainUserId));
    }

    public function testPostLoggedOutIsRefusedAndWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);

        $this->post(null, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId));

        $this->assertSame($imageBefore, $this->currentImage($carId));
    }

    // ---- POST: CSRF ---------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function badCsrfProvider(): array
    {
        return ['missing token' => ['omit'], 'wrong token' => ['invalid']];
    }

    #[DataProvider('badCsrfProvider')]
    public function testPostWithBadCsrfIsRefusedAndWritesNothing(string $csrfMode): void
    {
        $carId = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']]] + $this->seen($carId), $csrfMode);

        $this->assertSame($imageBefore, $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%"));
        $this->assertStringNotContainsString('car(s) updated', implode(' ', $this->messages($result['raw'], 'success')) . $result['raw']);
    }

    // ---- POST: input ---------------------------------------------------------

    public function testPostForgedFileNameInBodyAddsNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, [
            'reattach'  => [$carId => ['a.jpg']],
            'image'     => '["evil.jpg"]',
            'filenames' => ['evil.jpg', '../../etc/passwd'],
            'names'     => ['evil.jpg'],
        ] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $image = $this->currentImage($carId);
        $this->assertSame('["b.jpg","a.jpg"]', $image, 'Only the file that the server found is added');
        $this->assertStringNotContainsString('evil', (string) $image);
    }

    public function testPostForgedCarIdWithNothingDetachedWritesNothing(): void
    {
        $detachedCar = $this->carWithDetachedPhoto();
        $plainCar = $this->newCar();
        $this->photo($plainCar, 'p.jpg', 3);
        $this->setImage($plainCar, ['p.jpg']);
        $plainBefore = $this->countCarsHistRows($plainCar);

        $result = $this->post($this->adminId, [
            'reattach' => [$plainCar => ['p.jpg'], 'abc' => ['a.jpg'], '-5' => ['a.jpg'], '0' => ['a.jpg']],
        ] + $this->seen($plainCar));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["p.jpg"]', $this->currentImage($plainCar));
        $this->assertSame($plainBefore, $this->countCarsHistRows($plainCar));
        $this->assertSame('["b.jpg"]', $this->currentImage($detachedCar), 'A car that was not selected stays as it was');
        $danger = $this->messages($result['raw'], 'danger');
        $this->assertContains(
            "1 car(s) not updated because nothing is left to change: car(s) {$plainCar}. Reload the tab.",
            $danger
        );
        $this->assertContains('3 value(s) in the form were not valid. The server did not use them.', $danger);
        $this->assertSame([], $this->messages($result['raw'], 'success'), 'No car was updated, so no success message');
        $this->assertSame(3, $this->countMatchingLogs('Security', 'Car Photos: invalid car id in reattach[]: %', $this->adminId));
        $this->assertSame(1, $this->countMatchingLogs('Security', "Car Photos: invalid car id in reattach[]: 'abc'", $this->adminId));
    }

    public function testPostWithNoCheckboxSelectedWritesNothingAndSaysSo(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, []);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $danger = $this->messages($result['raw'], 'danger');
        $this->assertContains('Select a photo to reattach or Restore order for at least one car, then click Apply selected.', $danger);
        $this->assertNotContains('1 value(s) in the form were not valid. The server did not use them.', $danger, 'An absent field is not an invalid reference');
        $this->assertSame(0, $this->countMatchingLogs('Security', 'Car Photos: invalid car id%', $this->adminId));
    }

    /**
     * @return array<string, array{Closure(int): array<string, mixed>, int, list<string>}>
     *         [form fields for a car ID, invalid value count, Security log notes (`%` is the car ID)]
     */
    public static function malformedFormShapeProvider(): array
    {
        return [
            'reattach as a scalar' => [
                static fn(int $carId): array => ['reattach' => 'a.jpg'],
                1,
                ["Car Photos: invalid reattach field: 'a.jpg'"],
            ],
            'reattach[<id>] as a scalar' => [
                static fn(int $carId): array => ['reattach' => [$carId => 'a.jpg']],
                1,
                ["Car Photos: invalid photo list in reattach[%]: 'a.jpg'"],
            ],
            'a non-string inside reattach[<id>][]' => [
                static fn(int $carId): array => ['reattach' => [$carId => [['a.jpg']]]],
                1,
                ['Car Photos: non-scalar photo name in reattach[%]: array'],
            ],
            'invalid restore[] IDs' => [static fn(int $carId): array => ['restore' => ['abc', '0', '-1', '7x']], 4, [
                "Car Photos: invalid car id in restore[]: 'abc'",
                "Car Photos: invalid car id in restore[]: '0'",
                "Car Photos: invalid car id in restore[]: '-1'",
                "Car Photos: invalid car id in restore[]: '7x'",
            ]],
        ];
    }

    /**
     * @param Closure(int): array<string, mixed> $fields
     * @param list<string> $logNotes
     */
    #[DataProvider('malformedFormShapeProvider')]
    public function testPostMalformedFormShapeWritesNothingAndLogsEachValue(Closure $fields, int $invalid, array $logNotes): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, $fields($carId) + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame([], $this->photoLogMetadata($carId));
        $this->assertContains(
            "{$invalid} value(s) in the form were not valid. The server did not use them.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame($invalid, $this->countMatchingLogs('Security', 'Car Photos: %', $this->adminId), 'One Security row for each invalid value');
        foreach ($logNotes as $note) {
            $this->assertSame(1, $this->countMatchingLogs('Security', str_replace('%', (string) $carId, $note), $this->adminId), $note);
        }
    }

    // ---- helpers: per-row actions, Ignore, order restore ---------------------

    /** A car with three detached photos x1, x2, x3 (in that stored order) and the current photo keep.jpg. */
    private function carWithThreeDetachedPhotos(): int
    {
        $carId = $this->newCar();
        foreach (['x1.jpg', 'x2.jpg', 'x3.jpg', 'keep.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['x1.jpg', 'x2.jpg', 'x3.jpg']);
        $this->setImage($carId, ['keep.jpg']);
        return $carId;
    }

    /** A car whose current list [b, a] is the reverse of an earlier list [a, b]. */
    private function carWithReversedOrder(): int
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        return $carId;
    }

    /** @return list<string> Rows of the car in the tab's main table, as car IDs in page order */
    private function mainRowIds(string $html): array
    {
        $ids = [];
        foreach ($this->xpath($html)->query("//tr[@data-testid='car-photos-row']") as $row) {
            $this->assertInstanceOf(DOMElement::class, $row);
            $ids[] = $row->getAttribute('data-car-id');
        }
        return $ids;
    }

    /**
     * @param array<string, scalar|null|array<mixed>> $get Extra GET fields, for example ['ignored' => '1']
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function renderWith(int $userId, array $get): array
    {
        return $this->invokeEndpoint(self::PAGE, [], $userId, 'omit', ['tab' => 'car-photos'] + $get);
    }

    /**
     * The metadata of the "Car Photos: car N" log rows of the CarActions type, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function photoLogMetadata(int $carId): array
    {
        $this->db->query(
            "SELECT metadata FROM logs WHERE logtype = 'CarActions' AND (lognote LIKE ? OR lognote LIKE ?) ORDER BY id",
            ["Car Photos: car {$carId},%", "Car Photos: car {$carId} ignored with%"]
        );
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $out = [];
        foreach ($this->db->results() as $row) {
            $decoded = json_decode((string) $row->metadata, true);
            $this->assertIsArray($decoded, 'The log row holds JSON metadata');
            $out[] = $decoded;
        }
        return $out;
    }

    /** Rows with the note prefix `Car Photos:` for a user, in every log type. */
    private function photoLogRows(int $userId): int
    {
        $row = $this->db->query("SELECT COUNT(*) AS cnt FROM logs WHERE user_id = ? AND lognote LIKE 'Car Photos:%'", [$userId])->first();
        $this->assertIsObject($row);
        return (int) $row->cnt;
    }

    /** Move `userimages/` away so that the image root is not a directory. restoreImageRoot() undoes it. */
    private function hideImageRoot(): void
    {
        $root = dirname(__DIR__, 2) . '/userimages';
        $hidden = $root . '.hidden-' . bin2hex(random_bytes(4));
        $this->assertTrue(rename($root, $hidden), 'Test setup: move userimages/ away');
        $this->hiddenImageRoot = $hidden;
    }

    /** @return bool False only when a hidden folder could not be moved back. */
    private function restoreImageRoot(): bool
    {
        if ($this->hiddenImageRoot === null) {
            return true;
        }
        $hidden = $this->hiddenImageRoot;
        $this->hiddenImageRoot = null;
        if (rename($hidden, dirname(__DIR__, 2) . '/userimages')) {
            return true;
        }
        fwrite(STDERR, "Test cleanup: move {$hidden} back to userimages/ by hand.\n");
        return false;
    }

    // ---- POST: per-row Apply, bulk Apply, per-photo choice --------------------

    public function testPostPerRowApplyWritesOnlyThatRowAlthoughOtherRowsAreTicked(): void
    {
        $carA = $this->carWithDetachedPhoto();
        $carB = $this->carWithDetachedPhoto();
        $histB = $this->countCarsHistRows($carB);

        $result = $this->post($this->adminId, [
            'apply_car' => (string) $carA,
            'reattach'  => [$carA => ['a.jpg'], $carB => ['a.jpg']],
        ] + $this->seen($carA, $carB));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carA), 'The clicked row is written');
        $this->assertSame('["b.jpg"]', $this->currentImage($carB), 'The other ticked row is not written');
        $this->assertSame($histB, $this->countCarsHistRows($carB));
        $this->assertSame(1, $this->countMatchingLogs('CarActions', "Car Photos: car {$carA},%", $this->adminId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carB},%"));
        $this->assertSame(["Car {$carA} updated."], $this->messages($result['raw'], 'success'));
        $this->assertSame(
            ["Only car {$carA} was changed. Your selections for car(s) {$carB} were not applied. Select them again."],
            $this->messages($result['raw'], 'dark'),
            'The admin learns that the other selections were lost'
        );
    }

    public function testPostPerRowApplyWithNothingTickedInThatRowSaysSoAndWritesNothing(): void
    {
        $carA = $this->carWithDetachedPhoto();
        $carB = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, [
            'apply_car' => (string) $carA,
            'reattach'  => [$carB => ['a.jpg']],
        ] + $this->seen($carA, $carB));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carA));
        $this->assertSame('["b.jpg"]', $this->currentImage($carB), 'A tick in another row does not count');
        $this->assertContains(
            "Select a photo to reattach or Restore order for car {$carA}, then click Apply.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame(
            ["Your selections for car(s) {$carB} were not applied. Select them again."],
            $this->messages($result['raw'], 'dark')
        );
    }

    public function testPostBulkApplyWritesEveryTickedRow(): void
    {
        $carA = $this->carWithDetachedPhoto();
        $carB = $this->carWithDetachedPhoto();
        $carC = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, [
            'reattach' => [$carA => ['a.jpg'], $carB => ['a.jpg']],
        ] + $this->seen($carA, $carB, $carC));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carA));
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carB));
        $this->assertSame('["b.jpg"]', $this->currentImage($carC), 'A row with no tick is not written');
        $this->assertSame(['2 car(s) updated.'], $this->messages($result['raw'], 'success'));
        $this->assertSame([], $this->messages($result['raw'], 'dark'), 'A bulk Apply leaves no selection out');
    }

    public function testPostTickedSubsetAttachesOnlyThoseInStoredOrderNotTickOrder(): void
    {
        $carId = $this->carWithThreeDetachedPhotos();

        // The form lists x3 before x1. The stored order is x1, x2, x3.
        $result = $this->post($this->adminId, ['reattach' => [$carId => ['x3.jpg', 'x1.jpg']]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["keep.jpg","x1.jpg","x3.jpg"]', $this->currentImage($carId));
        $this->assertSame([], $this->messages($result['raw'], 'danger'));
        // x2 stays detached: the next render still lists it with a box.
        $xpath = $this->xpath($this->render($this->adminId)['raw']);
        $this->assertSame(1, $xpath->query("//input[@name='reattach[{$carId}][]'][@value='x2.jpg']")->length);
        $this->assertSame(0, $xpath->query("//input[@name='reattach[{$carId}][]'][@value='x1.jpg']")->length);
    }

    public function testPostPerPhotoForgedNameBesideARealOneAttachesOnlyTheRealOneAndLogsTheForgery(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg', 'evil.jpg', "../b.jpg\r\nFORGED"]]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId), 'Only the detached photo is attached');
        $this->assertContains(
            '2 photo name(s) in the form were not detached photos of the car and were not attached.',
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame(
            1,
            $this->countMatchingLogs('Security', "Car Photos: invalid photo name for car {$carId}, not a detached photo: 'evil.jpg'", $this->adminId)
        );
        $this->assertSame(
            2,
            $this->countMatchingLogs('Security', "Car Photos: invalid photo name for car {$carId}, not a detached photo: %", $this->adminId)
        );
        $this->assertSame(
            0,
            $this->countMatchingLogs('Security', "%\r%", $this->adminId) + $this->countMatchingLogs('Security', "%\n%", $this->adminId),
            'A control character in a name does not reach the log'
        );
    }

    public function testPostOnlyForgedNamesWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['evil.jpg']]] + $this->seen($carId));

        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(0, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%"));
        $this->assertSame([], $this->messages($result['raw'], 'success'));
    }

    // ---- POST: order restore --------------------------------------------------

    public function testPostRestoreOrderWritesTriggerHistRowAndTheNextRenderOffersNoRestoreForThatCar(): void
    {
        $carId = $this->carWithReversedOrder();
        $before = $this->render($this->adminId)['raw'];
        $this->assertSame(1, $this->xpath($before)->query("//input[@name='restore[]'][@value='{$carId}']")->length, 'Test setup: the car offers a restore');
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['restore' => [(string) $carId]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["a.jpg","b.jpg"]', $this->currentImage($carId));
        // The real cars_update trigger wrote the list from before the restore.
        $this->assertSame($histBefore + 1, $this->countCarsHistRows($carId), 'The trigger wrote one cars_hist row');
        $newest = $this->db->query('SELECT image FROM cars_hist WHERE car_id = ? ORDER BY id DESC LIMIT 1', [$carId])->first();
        $this->assertIsObject($newest);
        $this->assertSame('["b.jpg","a.jpg"]', (string) $newest->image);
        // The POST response renders the tab again, and a fresh GET agrees.
        foreach ([$result['raw'], $this->render($this->adminId)['raw']] as $html) {
            $this->assertSame(0, $this->xpath($html)->query("//input[@name='restore[]'][@value='{$carId}']")->length, 'No restore order is offered again');
            $this->assertNotContains((string) $carId, $this->mainRowIds($html), 'The car has no row');
        }
    }

    public function testPostRestoreOrderLogRowHoldsActionAndTokenAfterOfTheListItWrote(): void
    {
        $carId = $this->carWithReversedOrder();

        $this->post($this->adminId, ['restore' => [(string) $carId]] + $this->seen($carId));

        $metadata = $this->photoLogMetadata($carId);
        $this->assertCount(1, $metadata);
        $this->assertSame('car_photos_apply', $metadata[0]['action']);
        $this->assertSame($carId, $metadata[0]['car_id']);
        $this->assertTrue($metadata[0]['order_restored']);
        $this->assertSame([], $metadata[0]['reattached']);
        $this->assertSame(
            hash('sha256', 'string:["a.jpg","b.jpg"]'),
            $metadata[0]['token_after'],
            'token_after identifies the stored list that the restore wrote'
        );
        $this->assertSame(
            CarPhotoRecovery::versionToken($this->currentImage($carId)),
            $metadata[0]['token_after'],
            'token_after equals the token of cars.image now'
        );
    }

    public function testPostStaleRestoreWritesNothing(): void
    {
        $carId = $this->carWithReversedOrder();
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        // Another save lands between the GET and the POST.
        $this->setImage($carId, ['b.jpg']);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['restore' => [(string) $carId], 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId), 'The other save stays');
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame([], $this->photoLogMetadata($carId));
        $this->assertContains(
            "1 car(s) not updated because the photos changed since the page loaded: car(s) {$carId}. Check them again.",
            $this->messages($result['raw'], 'danger')
        );
    }

    public function testPostRestoreWithNoEarlierOrderSaysSoLogsItAndCountsNoUpdate(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['restore' => [(string) $carId]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame([], $this->photoLogMetadata($carId));
        $this->assertSame(
            ["Restore order for car {$carId} was not available. Reload the tab."],
            $this->messages($result['raw'], 'danger'),
            'One message names the car, and no nothing-to-change message repeats it'
        );
        $this->assertSame([], $this->messages($result['raw'], 'success'), 'The car does not count as updated');
        $this->assertSame(
            1,
            $this->countMatchingLogs('Security', "Car Photos: invalid restore[] car id, no earlier order: {$carId}", $this->adminId)
        );
    }

    public function testPostRestoreWithNoEarlierOrderBesideATickedPhotoAttachesItAndSaysTheRestoreWasNotAvailable(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']], 'restore' => [(string) $carId]] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame(['1 car(s) updated.'], $this->messages($result['raw'], 'success'));
        $this->assertSame(
            ["Restore order for car {$carId} was not available. Reload the tab."],
            $this->messages($result['raw'], 'danger')
        );
        $this->assertFalse($this->photoLogMetadata($carId)[0]['order_restored']);
    }

    // ---- POST and GET: Ignore and Undo ignore ----------------------------------

    public function testPostIgnoreHidesTheRowWritesOneLogRowAndChangesNoCar(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $imageBefore = $this->currentImage($carId);
        $histBefore = $this->countCarsHistRows($carId);
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);

        $result = $this->post($this->adminId, ['ignore_car' => (string) $carId, 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame($imageBefore, $this->currentImage($carId), 'cars.image is unchanged');
        $this->assertSame($histBefore, $this->countCarsHistRows($carId), 'No cars_hist row');
        $this->assertContains(
            "Car {$carId} ignored. The tab shows it again when its photos change.",
            $this->messages($result['raw'], 'success')
        );
        $this->assertNotContains((string) $carId, $this->mainRowIds($result['raw']), 'The POST response hides the row');
        $get = $this->render($this->adminId)['raw'];
        $this->assertNotContains((string) $carId, $this->mainRowIds($get), 'A fresh GET hides the row');
        $this->assertSame(
            1,
            $this->xpath($get)->query("//a[@data-testid='car-photos-show-ignored'][@href='?tab=car-photos&ignored=1#cp-ignored-heading']")->length,
            'The Show ignored cars link is offered and jumps to the ignored table'
        );

        $metadata = $this->photoLogMetadata($carId);
        $this->assertCount(1, $metadata, 'One log row for the ignore');
        $this->assertSame('car_photos_ignore', $metadata[0]['action']);
        $this->assertSame($carId, $metadata[0]['car_id']);
        $this->assertSame($token, $metadata[0]['token']);
        $this->assertSame(['a.jpg'], $metadata[0]['detached']);
        $this->assertSame(1, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId} ignored with 1 detached photo(s)", $this->adminId));
    }

    public function testPostIgnoreWithStaleTokenWritesNoLogRowAndKeepsTheRow(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->photo($carId, 'c.jpg', 3);
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        $this->setImage($carId, ['c.jpg']);

        $result = $this->post($this->adminId, ['ignore_car' => (string) $carId, 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame([], $this->photoLogMetadata($carId), 'No log row');
        $this->assertSame(0, $this->photoLogRows($this->adminId));
        $this->assertContains(
            "Car {$carId} not ignored because the photos changed since the page loaded. Check it again.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertContains((string) $carId, $this->mainRowIds($result['raw']), 'The row stays visible');
    }

    public function testPostIgnoreWithMalformedButtonValueWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, ['ignore_car' => 'abc'] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertContains(
            'The car reference of that button was not valid. Nothing was changed.',
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame([], $this->photoLogMetadata($carId));
    }

    public function testShowIgnoredListsTheRowWithIgnoredByAndUndoIgnoreShowsItAgain(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        $this->post($this->adminId, ['ignore_car' => (string) $carId, 'seen' => [$carId => $token]]);

        // The plain GET lists the car in neither table. The flag lists it in the ignored table.
        $plain = $this->xpath($this->render($this->adminId)['raw']);
        $this->assertSame(0, $plain->query("//*[@data-testid='car-photos-ignored-row']")->length);
        $shown = $this->renderWith($this->adminId, ['ignored' => '1']);
        $this->assertNoPhpError($shown['raw']);
        $xpath = $this->xpath($shown['raw']);
        $this->assertSame(1, $xpath->query("//tr[@data-testid='car-photos-ignored-row'][@data-car-id='{$carId}']")->length);
        $this->assertSame(1, $xpath->query("//table[@data-testid='car-photos-ignored-table'][@aria-labelledby='cp-ignored-heading']")->length);
        $this->assertSame(1, $xpath->query("//*[@id='cp-ignored-heading']")->length, 'The label of the ignored table exists');
        $by = $xpath->query("//tr[@data-car-id='{$carId}']//*[@data-testid='car-photos-ignored-by']");
        $this->assertSame(1, $by->length);
        $this->assertMatchesRegularExpression(
            '/^Ignored by Test User on \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            trim((string) preg_replace('/\s+/', ' ', $by->item(0)->textContent))
        );
        $this->assertSame(0, $xpath->query("//tr[@data-car-id='{$carId}']//input[@type='checkbox']")->length, 'An ignored row has no checkbox');
        $this->assertSame(1, $xpath->query("//tr[@data-car-id='{$carId}']//button[@name='unignore_car']")->length, 'The Undo ignore button exists');
        $this->assertSame(1, $xpath->query("//a[@data-testid='car-photos-hide-ignored']")->length);
        $undoToken = $this->seenFromPage($shown['raw'], $carId);

        $undone = $this->post($this->adminId, ['unignore_car' => (string) $carId, 'seen' => [$carId => $undoToken]]);

        $this->assertSame(0, $undone['exitCode']);
        $this->assertContains("Car {$carId} shown again.", $this->messages($undone['raw'], 'success'));
        $this->assertContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']), 'The row is back');
        $this->assertSame('["b.jpg"]', $this->currentImage($carId), 'Undo ignore changes no car');
        $metadata = $this->photoLogMetadata($carId);
        $this->assertSame(['car_photos_ignore', 'car_photos_unignore'], array_column($metadata, 'action'), 'A newer log row, no deletion');
    }

    public function testPostUndoIgnoreOfARowThatIsNotIgnoredWritesNothing(): void
    {
        $carId = $this->carWithDetachedPhoto();

        $result = $this->post($this->adminId, ['unignore_car' => (string) $carId] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertContains("Car {$carId} not shown again: it has nothing to show, or it is not ignored.", $this->messages($result['raw'], 'danger'));
        $this->assertSame([], $this->photoLogMetadata($carId));
    }

    public function testIgnoredRowReturnsAfterAnOutOfBandChangeOfTheDetachedPhotos(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->post($this->adminId, ['ignore_car' => (string) $carId] + $this->seen($carId));
        $this->assertNotContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']), 'Test setup: the row is hidden');

        // Another save drops b.jpg and keeps a.jpg out: the token and the detached names both change.
        $this->photo($carId, 'c.jpg', 3);
        $this->setImage($carId, ['c.jpg']);

        $this->assertContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']), 'The row shows again');
    }

    public function testIgnoredRowReturnsWhenOnlyTheStoredValueChanges(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->post($this->adminId, ['ignore_car' => (string) $carId] + $this->seen($carId));
        $this->assertNotContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']), 'Test setup: the row is hidden');

        // Same photo, same detached names, other stored text: a changed token alone shows the row.
        $this->db->query('UPDATE cars SET image = ? WHERE id = ?', ['[ "b.jpg" ]', $carId]);

        $this->assertContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']));
    }

    public function testApplyAfterIgnoreChangesTheListSoTheRowShowsAgain(): void
    {
        $carId = $this->carWithThreeDetachedPhotos();
        $this->post($this->adminId, ['ignore_car' => (string) $carId] + $this->seen($carId));

        $this->post($this->adminId, ['apply_car' => (string) $carId, 'reattach' => [$carId => ['x1.jpg']]] + $this->seen($carId));

        $this->assertSame('["keep.jpg","x1.jpg"]', $this->currentImage($carId));
        $this->assertContains((string) $carId, $this->mainRowIds($this->render($this->adminId)['raw']), 'The apply changed the list, so the row shows again');
    }

    // ---- ImageProcessingException ------------------------------------------------

    public function testRenderWithUnreadableImageRootShowsAlertAndWritesNoLogRow(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->hideImageRoot();
        $logsBefore = $this->photoLogRows($this->adminId);
        $errorsBefore = $this->countMatchingLogs('CarErrors', '%', $this->adminId);

        $result = $this->render($this->adminId);

        $this->assertTrue($this->restoreImageRoot(), 'Test: move the hidden photo folder back to userimages/.');
        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $xpath = $this->xpath($result['raw']);
        $alert = $xpath->query("//*[@data-testid='car-photos-error']");
        $this->assertSame(1, $alert->length, 'The card shows an alert');
        $this->assertStringContainsString('The server could not read the car photo folder', (string) $alert->item(0)->textContent);
        $this->assertSame(1, $xpath->query("//*[@data-testid='car-photos-card']")->length, 'The page and the card still render');
        $this->assertSame(0, $xpath->query("//*[@data-testid='car-photos-form']")->length, 'No form');
        $this->assertSame($logsBefore, $this->photoLogRows($this->adminId), 'A GET writes no log row');
        $this->assertSame($errorsBefore, $this->countMatchingLogs('CarErrors', '%', $this->adminId));
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
    }

    public function testPostApplyWithUnreadableImageRootLogsTheCarShowsMessageAndAlertAndWritesNoCar(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $tokens = $this->seen($carId);
        $this->hideImageRoot();
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']]] + $tokens);

        $this->assertTrue($this->restoreImageRoot(), 'Test: move the hidden photo folder back to userimages/.');
        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertContains(
            "1 car(s) not updated because the server could not check the photos, from car {$carId} on. Check the logs.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame([], $this->messages($result['raw'], 'success'));
        $this->assertSame(1, $this->countMatchingLogs('CarErrors', "Car Photos: apply for car {$carId} failed:%", $this->adminId));
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertSame(1, $this->xpath($result['raw'])->query("//*[@data-testid='car-photos-error']")->length, 'The tab under the message shows the alert');
    }

    public function testPostIgnoreWithUnreadableImageRootLogsTheCarAndWritesNoDecision(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $tokens = $this->seen($carId);
        $this->hideImageRoot();

        $result = $this->post($this->adminId, ['ignore_car' => (string) $carId] + $tokens);

        $this->assertTrue($this->restoreImageRoot(), 'Test: move the hidden photo folder back to userimages/.');
        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertContains(
            "Car {$carId} not ignored because the server could not check its photos. Check the logs.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertSame(1, $this->countMatchingLogs('CarErrors', "Car Photos: ignore of car {$carId} failed:%", $this->adminId));
        $this->assertSame([], $this->photoLogMetadata($carId), 'No decision row');
    }

    public function testPostPhotoOfACarThatNoLongerHasARowSaysChangedSinceThePageLoaded(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        // Another session reattaches a.jpg: the car has no detached photo, so no row.
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']], 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame($histBefore, $this->countCarsHistRows($carId));
        $this->assertContains(
            "1 car(s) not updated because the photos changed since the page loaded: car(s) {$carId}. Check them again.",
            $this->messages($result['raw'], 'danger')
        );
    }

    public function testPostDetachedFileRemovedAfterPageLoadSaysNothingToChangeAndLogsNoForgery(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $token = $this->seenFromPage($this->render($this->adminId)['raw'], $carId);
        // Out of band, the detached file goes from disk. `cars.image` stays,
        // so the token still matches, but the car has no row now.
        $this->assertTrue(unlink($this->carDirectory($carId) . 'a.jpg'), 'Test setup: remove the detached file');
        $histBefore = $this->countCarsHistRows($carId);

        $result = $this->post($this->adminId, ['reattach' => [$carId => ['a.jpg']], 'seen' => [$carId => $token]]);

        $this->assertSame(0, $result['exitCode']);
        $this->assertNoPhpError($result['raw']);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId), 'cars.image is unchanged');
        $this->assertSame($histBefore, $this->countCarsHistRows($carId), 'No cars_hist row');
        $this->assertSame(0, $this->photoLogRows($this->adminId), 'No Car Photos log row of any type');
        $this->assertSame(0, $this->countMatchingLogs('Security', 'Car Photos: invalid photo name%', $this->adminId));
        $danger = $this->messages($result['raw'], 'danger');
        $this->assertContains(
            "1 car(s) not updated because nothing is left to change: car(s) {$carId}. Reload the tab.",
            $danger
        );
        $this->assertSame([], preg_grep('/were not detached photos/', $danger), 'The ticked name is not called a forgery');
        $this->assertSame([], $this->messages($result['raw'], 'success'));
    }

    /**
     * Ignore every readable row in the main table, so the tab shows its empty
     * state. Returns the number of ignored rows.
     */
    private function ignoreEveryRow(): int
    {
        $recovery = new CarPhotoRecovery($this->db);
        foreach ($recovery->findRows() as $row) {
            if (!$recovery->isIgnored($row)) {
                $recovery->ignore($row->carId, $this->adminId, CarPhotoRecovery::versionToken($row->rawImage));
            }
        }
        $ignored = 0;
        foreach ($recovery->findRows() as $row) {
            $this->assertTrue($recovery->isIgnored($row), "Test setup: car {$row->carId} cannot be ignored, so the main table is not empty");
            $ignored++;
        }
        return $ignored;
    }

    /** The text of the empty-state paragraph, with its white space collapsed. */
    private function emptyStateText(string $html): string
    {
        $empty = $this->xpath($html)->query("//*[@data-testid='car-photos-empty']");
        $this->assertSame(1, $empty->length, 'The tab shows the empty state');
        return trim((string) preg_replace('/\s+/', ' ', (string) $empty->item(0)?->textContent));
    }

    public function testRenderEmptyMainListWithIgnoredRowsHiddenSaysHowManyAreHidden(): void
    {
        $this->carWithDetachedPhoto();
        $ignored = $this->ignoreEveryRow();

        $page = $this->render($this->adminId);

        $this->assertNoPhpError($page['raw']);
        $this->assertSame(
            "No cars need a photo check. {$ignored} ignored car(s) are hidden.",
            $this->emptyStateText($page['raw'])
        );
    }

    public function testRenderEmptyMainListWithIgnoredRowsShownDoesNotSayTheyAreHidden(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->ignoreEveryRow();

        $page = $this->renderWith($this->adminId, ['ignored' => '1']);

        $this->assertNoPhpError($page['raw']);
        $this->assertSame('No cars need a photo check.', $this->emptyStateText($page['raw']));
        $this->assertSame(
            1,
            $this->xpath($page['raw'])->query("//tr[@data-testid='car-photos-ignored-row'][@data-car-id='{$carId}']")->length,
            'The ignored table lists the car'
        );
    }

    public function testPostIgnoreOfAnIgnoredRowWritesNoSecondLogRow(): void
    {
        $carId = $this->carWithDetachedPhoto();
        $this->post($this->adminId, ['ignore_car' => (string) $carId] + $this->seen($carId));

        $result = $this->post($this->adminId, ['ignore_car' => (string) $carId] + $this->seen($carId));

        $this->assertSame(0, $result['exitCode']);
        $this->assertContains(
            "Car {$carId} not ignored: it has nothing to show, or it is already ignored.",
            $this->messages($result['raw'], 'danger')
        );
        $this->assertCount(1, $this->photoLogMetadata($carId));
    }

    public function testRenderListsRowsWithAnEarlierOrderBeforeRowsWithOnlyDetachedPhotos(): void
    {
        $detached = $this->carWithDetachedPhoto();
        $reversed = $this->carWithReversedOrder();
        $this->assertLessThan($reversed, $detached, 'Test setup: the detached car has the lower ID');

        $ids = $this->mainRowIds($this->render($this->adminId)['raw']);

        $this->assertContains((string) $detached, $ids);
        $this->assertContains((string) $reversed, $ids);
        $this->assertLessThan(
            array_search((string) $detached, $ids, true),
            array_search((string) $reversed, $ids, true),
            'The restore row comes first although its car ID is higher'
        );
    }

    public function testRenderShowsEachRowWithItsOwnApplyAndIgnoreButtonsAndNoCheckedBox(): void
    {
        $carId = $this->carWithReversedOrder();

        $xpath = $this->xpath($this->render($this->adminId)['raw']);

        $apply = $xpath->query("//tr[@data-car-id='{$carId}']//button[@name='apply_car'][@value='{$carId}']");
        $ignore = $xpath->query("//tr[@data-car-id='{$carId}']//button[@name='ignore_car'][@value='{$carId}']");
        $this->assertSame(1, $apply->length, 'Per-row Apply');
        $this->assertSame(1, $ignore->length, 'Per-row Ignore');
        $this->assertSame(0, $xpath->query("//input[@type='checkbox'][@checked]")->length);
    }
}
