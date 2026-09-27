<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Container;
use Deployward\Deploy\DeployScheduler;
use Deployward\Deploy\DeployerInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DeploySchedulerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    private $queued = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! defined('AUTH_KEY')) {
            define('AUTH_KEY', str_repeat('a', 64));
        }
        if (! defined('AUTH_SALT')) {
            define('AUTH_SALT', str_repeat('b', 64));
        }
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugins');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir());
        }
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (! defined('DEPLOYWARD_SLUG')) {
            define('DEPLOYWARD_SLUG', 'deployward');
        }
        Functions\when('wp_upload_dir')->justReturn(array('basedir' => sys_get_temp_dir() . '/uploads'));
        Functions\when('add_option')->alias(function ($key, $value) {
            $this->queued[] = array('option_name' => $key, 'option_value' => $value);
            return true;
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir() . '/');
        Functions\when('get_theme_root')->justReturn(sys_get_temp_dir() . '/themes');
        Functions\when('home_url')->justReturn('https://nara.local/');
        Functions\when('get_option')->justReturn('admin@example.com');
        Functions\when('trailingslashit')->alias(function ($p) {
            return rtrim($p, '/') . '/';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function database()
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql) { return $sql; });
        $wpdb->shouldReceive('esc_like')->andReturn('deployward_job_');
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturnUsing(function () { return $this->queued; });
        return $wpdb;
    }

    private function deployment(): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 's',
        ));
    }

    public function test_schedule_enqueues_a_single_event_and_spawns_cron(): void
    {
        $wpdb = $this->database();
        $container = new Container($wpdb);
        Functions\expect('wp_schedule_single_event')->once()->with(
            Mockery::type('int'),
            DeployScheduler::HOOK
        );
        Functions\expect('time')->andReturn(1000000000);
        Functions\expect('spawn_cron')->once();

        (new DeployScheduler($container))->schedule('dw_abc', 'webhook');
    }

    public function test_run_deploys_a_found_deployment(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_abc')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->once()
            ->with(Mockery::type(Deployment::class), 'webhook', false)
            ->andReturn(Result::ok('sha'));
        $wpdb = $this->database();
        $mock = Mockery::mock(new Container($wpdb))
            ->makePartial();
        $mock->shouldReceive('repository')->andReturn($repo);
        $mock->shouldReceive('deployer')->andReturn($deployer);

        (new DeployScheduler($mock))->run('dw_abc', 'webhook', false);
    }

    public function test_run_ignores_a_missing_deployment(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('gone')->andReturn(null);
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldNotReceive('deploy');
        $wpdb = $this->database();
        $mock = Mockery::mock(new Container($wpdb))
            ->makePartial();
        $mock->shouldReceive('repository')->andReturn($repo);
        $mock->shouldReceive('deployer')->andReturn($deployer);

        (new DeployScheduler($mock))->run('gone', 'webhook', false);
    }
}
