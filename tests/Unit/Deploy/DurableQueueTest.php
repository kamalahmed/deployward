<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Deploy\DeploymentQueue;
use Deployward\Deploy\DeployScheduler;
use Deployward\Support\Result;
use PHPUnit\Framework\TestCase;

final class DurableQueueTest extends TestCase
{
    private $db;
    private $queue;
    private $container;
    private $previousErrorLog;
    private $errorLog;

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->previousErrorLog = ini_get('error_log');
        $this->errorLog = tempnam(sys_get_temp_dir(), 'dw-queue-log-');
        ini_set('error_log', $this->errorLog);
        $this->db = new QueueDatabase();
        Functions\when('add_option')->alias(function ($name, $value) {
            try {
                $stmt = $this->db->pdo->prepare('INSERT INTO wp_options(option_name, option_value) VALUES (?, ?)');
                return $stmt->execute(array($name, $value));
            } catch (\PDOException $e) { return false; }
        });
        Functions\when('delete_option')->alias(function ($name) {
            $stmt = $this->db->pdo->prepare('DELETE FROM wp_options WHERE option_name = ?');
            $stmt->execute(array($name));
            return $stmt->rowCount() === 1;
        });
        Functions\when('wp_schedule_single_event')->justReturn(false); // Simulate lost cron wakeups.
        Functions\when('spawn_cron')->justReturn(false);
        $this->queue = new DeploymentQueue($this->db);
        $this->container = new QueueContainer($this->queue);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        unlink($this->errorLog);
        Monkey\tearDown();
    }

    public function test_two_components_survive_lost_wakeups_and_are_drained_together(): void
    {
        $first = new DeployScheduler($this->container);
        $second = new DeployScheduler($this->container);
        $this->assertTrue($first->schedule('theme', 'webhook')->isOk());
        $this->assertTrue($second->schedule('plugin', 'webhook')->isOk());
        $this->assertCount(2, $this->queue->pending());
        $first->drain();
        $this->assertSame(array('theme', 'plugin'), $this->container->ran);
        $this->assertSame(array(), $this->queue->pending());
    }

    public function test_exception_preserves_job_and_releases_lock_for_recovery(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $this->container->throw = true;
        $scheduler->drain();
        $this->assertCount(1, $this->queue->pending());
        $this->assertFalse($this->db->locked);
        $this->container->throw = false;
        $scheduler->drain();
        $this->assertSame(array(), $this->queue->pending());
        $this->assertSame(array('theme'), $this->container->ran);
    }

    public function test_logged_failure_completes_attempt_and_other_component_runs(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $scheduler->schedule('plugin', 'webhook');
        $this->container->failed = 'theme';
        $scheduler->drain();
        $this->assertSame(array(), $this->queue->pending());
        $this->assertSame(array('theme', 'plugin'), $this->container->ran);
    }

    public function test_busy_worker_leaves_jobs_for_later(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $this->db->locked = true;
        $scheduler->drain();
        $this->assertSame(array(), $this->container->ran);
        $this->assertCount(1, $this->queue->pending());
        $this->db->locked = false; // Server releases an advisory lock when its connection dies.
        $scheduler->drain();
        $this->assertSame(array('theme'), $this->container->ran);
    }

    public function test_job_added_while_processing_is_not_removed_with_completed_job(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $this->container->duringDeploy = function () use ($scheduler) {
            $scheduler->schedule('plugin', 'webhook');
        };
        $scheduler->drain();
        $jobs = $this->queue->pending();
        $this->assertCount(1, $jobs);
        $this->assertSame('plugin', $jobs[0]['deployment_id']);
        $this->container->duringDeploy = null;
        $scheduler->drain();
        $this->assertSame(array('theme', 'plugin'), $this->container->ran);
    }

    public function test_legacy_cron_arguments_are_persisted_and_run(): void
    {
        (new DeployScheduler($this->container))->run('theme', 'webhook', true);
        $this->assertSame(array('theme'), $this->container->ran);
        $this->assertSame(array(), $this->queue->pending());
    }

    public function test_skipped_duplicate_job_is_removed(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $this->container->skip = true;
        $scheduler->drain();
        $this->assertSame(array(), $this->queue->pending());
        $this->assertSame(array('theme'), $this->container->ran);
    }

    public function test_drain_is_bounded_and_later_wakeup_processes_remaining_jobs(): void
    {
        $scheduler = new DeployScheduler($this->container);
        for ($i = 0; $i < 21; $i++) { $scheduler->schedule('theme', 'webhook'); }
        $scheduler->drain();
        $this->assertCount(20, $this->container->ran);
        $this->assertCount(1, $this->queue->pending());
        $scheduler->drain();
        $this->assertCount(21, $this->container->ran);
        $this->assertSame(array(), $this->queue->pending());
    }

    public function test_interrupted_batch_does_not_starve_later_healthy_job(): void
    {
        $scheduler = new DeployScheduler($this->container);
        for ($i = 0; $i < 20; $i++) {
            $id = 'broken' . $i;
            $this->container->interrupted[] = $id;
            $scheduler->schedule($id, 'webhook');
        }
        $scheduler->schedule('healthy', 'webhook');
        $scheduler->drain();
        $this->assertSame(array(), $this->container->ran);
        $scheduler->drain();
        $this->assertSame(array('healthy'), $this->container->ran);
        $this->assertCount(20, $this->queue->pending());
    }

    public function test_failed_retry_persistence_keeps_original_job(): void
    {
        $scheduler = new DeployScheduler($this->container);
        $scheduler->schedule('theme', 'webhook');
        $original = $this->queue->pending()[0]['key'];
        $this->container->throw = true;
        Functions\when('add_option')->justReturn(false);
        $scheduler->drain();
        $this->assertSame($original, $this->queue->pending()[0]['key']);
    }

    public function test_storage_failure_is_reported_instead_of_accepted(): void
    {
        Functions\when('add_option')->justReturn(false);
        $result = (new DeployScheduler($this->container))->schedule('theme', 'webhook');
        $this->assertFalse($result->isOk());
    }
}

// A real SQLite options table exercises independent durable rows; only the
// database-specific connection lock is simulated here, not queue behavior.
final class QueueDatabase
{
    public $options = 'wp_options';
    public $pdo;
    public $locked = false;
    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE wp_options(option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT)');
    }
    public function prepare($sql, ...$args)
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : $this->pdo->quote($arg), $sql, 1);
        }
        return $sql;
    }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function get_results($sql, $format)
    {
        // SQLite needs its LIKE escape explicitly declared, unlike MySQL.
        $sql = preg_replace("/(LIKE '[^']*')/", "$1 ESCAPE '\\\\'", $sql);
        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function get_var($sql)
    {
        if (strpos($sql, 'GET_LOCK') !== false) {
            if ($this->locked) { return '0'; }
            $this->locked = true;
            return '1';
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->locked = false; return '1'; }
        return null;
    }
}

final class QueueContainer
{
    public $ran = array();
    public $throw = false;
    public $failed = '';
    public $skip = false;
    public $interrupted = array();
    public $duringDeploy;
    private $queue;
    public function __construct($queue) { $this->queue = $queue; }
    public function queue() { return $this->queue; }
    public function repository() { return $this; }
    public function deployer() { return $this; }
    public function find($id)
    {
        return Deployment::fromArray(array('id' => $id, 'repo' => 'owner/repo', 'branch' => 'main', 'visibility' => 'public', 'target_type' => 'plugin'));
    }
    public function deploy($deployment, $trigger, $force)
    {
        if ($this->throw || in_array($deployment->id(), $this->interrupted, true)) { throw new \RuntimeException('Worker interrupted'); }
        $this->ran[] = $deployment->id();
        if ($this->duringDeploy) { ($this->duringDeploy)(); }
        if ($this->skip) { return Result::skip('Already deployed'); }
        return $this->failed === $deployment->id() ? Result::fail('Fixture failed') : Result::ok('sha');
    }
}
