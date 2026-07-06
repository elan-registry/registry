<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for regression tests.
 *
 * Saves and restores $_POST and $_GET around each test so superglobal
 * state cannot leak between tests.
 */
abstract class RegressionTestCase extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalPost = [];

    /** @var array<string, mixed> */
    private array $originalGet = [];

    /**
     * Subclasses that override setUp() MUST call parent::setUp() first;
     * otherwise tearDown() restores [] instead of the real pre-test state.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPost = $_POST;
        $this->originalGet  = $_GET;
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        $_GET  = $this->originalGet;
        parent::tearDown();
    }
}
