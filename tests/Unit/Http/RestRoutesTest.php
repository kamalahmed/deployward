<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Http\ApiResponse;
use Deployward\Http\RestController;
use Deployward\Http\RestRoutes;
use Deployward\Log\DeployLogInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RestRoutesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! class_exists('WP_REST_Response')) {
            eval('class WP_REST_Response { public $data; public $status; public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_can_manage_reflects_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(RestRoutes::canManage());

        Functions\when('current_user_can')->justReturn(true);
        $this->assertTrue(RestRoutes::canManage());
    }

    public function test_respond_maps_apiresponse_to_wp_rest_response(): void
    {
        $controller = new RestController(
            Mockery::mock(DeploymentRepositoryInterface::class),
            Mockery::mock(DeployerInterface::class),
            Mockery::mock(DeployLogInterface::class),
            Mockery::mock(GitHubClientInterface::class)
        );
        $routes = new RestRoutes($controller);
        $wp = $routes->respond(ApiResponse::error('nope', 404));

        $this->assertSame(404, $wp->status);
        $this->assertSame('nope', $wp->data['error']);
    }
}
