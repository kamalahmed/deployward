<?php

namespace Deployward\Deploy;

use Deployward\Container;
use Deployward\Support\Result;

final class DeployScheduler implements DeploySchedulerInterface
{
    const HOOK = 'deployward_run_deploy';

    /** @var Container */
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function schedule(string $deploymentId, string $trigger, bool $force = false): Result
    {
        $queued = $this->container->queue()->enqueue($deploymentId, $trigger, $force);
        if (! $queued->isOk()) {
            return $queued;
        }
        // Cron is only a wakeup. Each job is already durable in its own row.
        wp_schedule_single_event(time(), self::HOOK);
        spawn_cron();
        return $queued;
    }

    public function run(string $deploymentId = '', string $trigger = 'webhook', bool $force = false): void
    {
        // Preserve pending events created by versions before the durable queue.
        if ($deploymentId !== '') {
            $queued = $this->container->queue()->enqueue($deploymentId, $trigger, $force);
            if (! $queued->isOk()) {
                error_log('Deployward: ' . $queued->message());
                return;
            }
        }
        $this->drain();
    }

    public function drain(): void
    {
        $queue = $this->container->queue();
        if (! $queue->acquire()) {
            return;
        }
        $started = microtime(true);
        try {
            foreach ($queue->pending(20) as $job) {
                if (microtime(true) - $started >= 45) {
                    break;
                }
                try {
                    $deployment = $this->container->repository()->find($job['deployment_id']);
                    if ($deployment === null) {
                        $queue->remove($job['key']);
                        continue;
                    }
                    $this->container->deployer()->deploy($deployment, $job['trigger'], (bool) $job['force']);
                    // A returned failure is a completed, logged attempt. Only
                    // interrupted attempts are retried; a fresh push gets a new job.
                    $queue->remove($job['key']);
                } catch (\Throwable $e) {
                    // Preserve the work behind other jobs so a persistent
                    // interruption cannot starve the rest of the queue.
                    $queue->retryLater($job);
                    error_log('Deployward queued deployment interrupted: ' . $e->getMessage());
                }
            }
        } finally {
            // Connection-bound locks also disappear if the worker dies, without
            // a timeout expiring underneath a deployment that is still running.
            $queue->release();
        }
    }
}
