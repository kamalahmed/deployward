<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\BackupManagerInterface;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\ExtractorInterface;
use Deployward\Deploy\HealthCheckerInterface;
use Deployward\Deploy\MaintenanceModeInterface;
use Deployward\Deploy\PayloadValidatorInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;
use Deployward\Notify\NotifierInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DeployerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var string */
    private $tempPluginRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->tempPluginRoot = sys_get_temp_dir() . '/dw-test-plugins-' . uniqid('', true);
        mkdir($this->tempPluginRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        $this->removeDir($this->tempPluginRoot);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function deployment(string $slug = 'nara-core'): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => $slug,
            'token' => 'ghp_x',
            'webhook_secret' => 'whsec',
            'last_deployed_sha' => 'oldsha',
        ));
    }

    private function deployer(array $mocks, ?array $targetRoots = null): Deployer
    {
        return new Deployer(
            $mocks['github'],
            $mocks['extractor'],
            $mocks['validator'],
            $mocks['backups'],
            $mocks['health'],
            $mocks['maintenance'],
            $mocks['log'],
            $mocks['repository'],
            $mocks['notifier'],
            $targetRoots ?? array(
                'plugin' => $this->tempPluginRoot,
                'theme' => sys_get_temp_dir(),
                'mu-plugin' => sys_get_temp_dir(),
            ),
            'https://nara.local/',
            sys_get_temp_dir()
        );
    }

    public function test_refuses_to_deploy_itself(): void
    {
        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('record')->once();
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once();
        $mocks = $this->baseMocks(array('log' => $log, 'notifier' => $notifier));

        $result = $this->deployer($mocks)->deploy($this->deployment('deployward'), 'manual');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('itself', $result->message());
    }

    public function test_skips_when_already_at_latest_sha(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->once()->andReturn(Result::ok('oldsha'));
        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('record')->once();
        $mocks = $this->baseMocks(array('github' => $github, 'log' => $log));

        $result = $this->deployer($mocks)->deploy($this->deployment(), 'cron');

        $this->assertTrue($result->isSkipped());
    }

    public function test_rolls_back_when_health_check_fails(): void
    {
        // Real payload dir and temp target root so rename() succeeds.
        $payloadDir = sys_get_temp_dir() . '/dw-payload-' . uniqid('', true);
        mkdir($payloadDir, 0755, true);

        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::ok('newsha'));
        $github->shouldReceive('downloadZipball')->andReturn(Result::ok('/tmp/x.zip'));

        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('extract')->andReturn(Result::ok($payloadDir));

        $validator = Mockery::mock(PayloadValidatorInterface::class);
        $validator->shouldReceive('validate')->andReturn(Result::ok('nara-core'));

        $backups = Mockery::mock(BackupManagerInterface::class);
        $backups->shouldReceive('backup')->andReturn(
            Result::skip('Nothing to back up; target does not exist yet')
        );
        $backups->shouldReceive('restoreLatest')->once()->andReturn(
            Result::ok($this->tempPluginRoot . '/nara-core')
        );

        $health = Mockery::mock(HealthCheckerInterface::class);
        $health->shouldReceive('check')->andReturn(Result::fail('fatal'));

        $maintenance = Mockery::mock(MaintenanceModeInterface::class);
        $maintenance->shouldReceive('enable')->once();
        $maintenance->shouldReceive('disable')->once();

        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('record')->once();

        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once();

        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldNotReceive('save');

        $mocks = array(
            'github' => $github, 'extractor' => $extractor, 'validator' => $validator,
            'backups' => $backups, 'health' => $health, 'maintenance' => $maintenance,
            'log' => $log, 'repository' => $repository, 'notifier' => $notifier,
        );

        $result = $this->deployer($mocks)->deploy($this->deployment(), 'webhook');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Health check failed', $result->message());
        $this->assertStringContainsString('rolled back', $result->message());
    }

    public function test_happy_path_deploy_saves_sha_and_logs_success(): void
    {
        // Real payload dir so rename() into tempPluginRoot/nara-core succeeds.
        $payloadDir = sys_get_temp_dir() . '/dw-payload-happy-' . uniqid('', true);
        mkdir($payloadDir, 0755, true);

        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->once()->andReturn(Result::ok('newsha'));
        $github->shouldReceive('downloadZipball')->once()->andReturn(Result::ok('/tmp/x.zip'));

        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('extract')->once()->andReturn(Result::ok($payloadDir));

        $validator = Mockery::mock(PayloadValidatorInterface::class);
        $validator->shouldReceive('validate')->once()->andReturn(Result::ok('nara-core'));

        $backups = Mockery::mock(BackupManagerInterface::class);
        $backups->shouldReceive('backup')->once()->andReturn(
            Result::skip('Nothing to back up; target does not exist yet')
        );
        $backups->shouldReceive('prune')->once();

        $health = Mockery::mock(HealthCheckerInterface::class);
        $health->shouldReceive('check')->once()->andReturn(Result::ok(200));

        $maintenance = Mockery::mock(MaintenanceModeInterface::class);
        $maintenance->shouldReceive('enable')->once();
        $maintenance->shouldReceive('disable')->once();

        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('record')->once()->with(
            Mockery::on(function ($row) {
                return isset($row['status']) && $row['status'] === 'success';
            })
        );

        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once();

        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('save')->once()->with(
            Mockery::on(function ($dep) {
                return $dep instanceof Deployment && $dep->lastDeployedSha() === 'newsha';
            })
        );

        $mocks = array(
            'github' => $github, 'extractor' => $extractor, 'validator' => $validator,
            'backups' => $backups, 'health' => $health, 'maintenance' => $maintenance,
            'log' => $log, 'repository' => $repository, 'notifier' => $notifier,
        );

        $result = $this->deployer($mocks)->deploy($this->deployment(), 'webhook');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isSkipped());
    }

    private function baseMocks(array $overrides): array
    {
        $defaults = array(
            'github' => Mockery::mock(GitHubClientInterface::class),
            'extractor' => Mockery::mock(ExtractorInterface::class),
            'validator' => Mockery::mock(PayloadValidatorInterface::class),
            'backups' => Mockery::mock(BackupManagerInterface::class),
            'health' => Mockery::mock(HealthCheckerInterface::class),
            'maintenance' => Mockery::mock(MaintenanceModeInterface::class),
            'log' => Mockery::mock(DeployLogInterface::class),
            'repository' => Mockery::mock(DeploymentRepositoryInterface::class),
            'notifier' => Mockery::mock(NotifierInterface::class),
        );

        return array_merge($defaults, $overrides);
    }
}
