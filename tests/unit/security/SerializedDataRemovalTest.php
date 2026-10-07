<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The contact-owner form and endpoint replaced serialized data with
 * individual fields. PHPStan's NoSerializeCallRule bans serialize() and
 * unserialize(). RouteLookupRawSqlRule keeps the user lookup in Owner.
 */
class SerializedDataRemovalTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
    }

    /** #971: the sender comes from the session, so from_user_id is not a form field. */
    public function testContactOwnerUsesSecureFields(): void
    {
        $content = $this->read('app/owner/contact/owner.php');

        $this->assertStringContainsString('to_user_id', $content);
        $this->assertDoesNotMatchRegularExpression('/name=[\'"]from_user_id[\'"]/', $content);
    }

    /** #971: the endpoint never reads the sender from POST. */
    public function testContactOwnerEmailDerivesSenderFromSession(): void
    {
        $content = $this->read('app/api/contact/send-owner-email.php');

        $this->assertStringNotContainsString("Input::get('from_user_id')", $content);
        $this->assertStringContainsString('$user->data()->id', $content);
    }

    public function testUserIdFieldsAreHTMLEncoded(): void
    {
        $this->assertStringContainsString(
            'htmlspecialchars((string)$to[\'id\'], ENT_QUOTES, \'UTF-8\')',
            $this->read('app/owner/contact/owner.php')
        );
    }

    public function testCSRFProtectionMaintained(): void
    {
        $content = $this->read('app/owner/contact/owner.php');

        $this->assertStringContainsString('Token::generate()', $content);
        $this->assertStringContainsString('name=\'csrf\'', $content);
    }

    private function read(string $relativePath): string
    {
        $path = $this->projectRoot . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
