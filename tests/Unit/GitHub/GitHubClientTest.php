<?php

namespace Deployward\Tests\Unit\GitHub;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\GitHub\GitHubClient;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_resolve_sha_returns_commit_sha(): void
    {
        Functions\when('wp_remote_get')->justReturn(array('body' => '{"sha":"abc123"}'));
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertTrue($result->isOk());
        $this->assertSame('abc123', $result->data());
    }

    public function test_resolve_sha_fails_on_non_200(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/missing', 'main', null);

        $this->assertFalse($result->isOk());
    }
}
