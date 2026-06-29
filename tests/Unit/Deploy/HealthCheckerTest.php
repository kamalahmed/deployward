<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\HealthChecker;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_healthy_on_200_clean_body(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>ok</html>');

        $this->assertTrue((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_500(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_fatal_signature(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('There has been a critical error on this website.');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }
}
