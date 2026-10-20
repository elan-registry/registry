<?php

declare(strict_types=1);

use ElanRegistry\AppConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2460: owner contact mail carries a Brevo tag, so the soft-bounce
 * count can skip it.
 *
 * The count reads the tag from `plg_sendinblue_messages.tags`. The sendinblue
 * plugin's `caller` tag mode writes `send-owner-email` for the endpoint, so
 * the constant must keep that value. The endpoint must pass the tag to
 * `email()`, or the tag is lost when `tag_mode` is `off`.
 *
 * @issue 2460
 * @link https://github.com/elan-registry/registry/issues/2460
 */
#[Group('regression')]
#[Group('fast')]
final class Issue2460OwnerContactTagSourceRegressionTest extends TestCase
{
    private const ENDPOINT = __DIR__ . '/../../../app/api/contact/send-owner-email.php';

    /** Fails without the change: the endpoint passes no tags to email(). */
    public function test_ownerEndpoint_passesOwnerContactTagToEmail(): void
    {
        $source = file_get_contents(self::ENDPOINT);
        $this->assertIsString($source);

        $tokens = token_get_all($source);
        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $this->assertSame(
            1,
            preg_match_all('/\bemail\(/', $code, $m),
            'The endpoint must call email() once'
        );
        $this->assertMatchesRegularExpression(
            "/\\bemail\\(\\\$toEmail,\\\$subject,\\\$body,\\['tags'=>AppConstants::OWNER_CONTACT_EMAIL_TAG\\]\\+\\\$replyOpts\\)/",
            $code,
            'email() must get the owner contact tag merged with the reply-to options'
        );
    }

    /** Fails without the change: the merge with the reply-to options is part of the new call. It guards that the tag does not replace the reply-to keys. */
    public function test_ownerEndpoint_keepsReplyToOptions(): void
    {
        $source = file_get_contents(self::ENDPOINT);
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/\$replyOpts\s*=/', $source);
        $this->assertStringContainsString('+ $replyOpts', $source, 'The tag is merged with $replyOpts, not in place of it');
    }

    /** Fails without the change: the constant does not exist. */
    public function test_ownerContactTag_matchesCallerModeTag(): void
    {
        // Reflection keeps PHPStan from folding the constant into an always-true compare.
        $value = (new \ReflectionClassConstant(AppConstants::class, 'OWNER_CONTACT_EMAIL_TAG'))->getValue();
        $this->assertSame('send-owner-email', $value);
    }
}
