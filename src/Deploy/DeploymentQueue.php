<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class DeploymentQueue
{
    const PREFIX = 'deployward_job_';

    private $wpdb;
    private $lockName;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
        $this->lockName = 'deployward_' . substr(hash('sha256',
            (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->options
        ), 0, 40);
    }

    public function enqueue(string $deploymentId, string $trigger, bool $force): Result
    {
        $key = self::PREFIX . bin2hex(random_bytes(16));
        $value = json_encode(array('deployment_id' => $deploymentId, 'trigger' => $trigger, 'force' => $force));
        if (! add_option($key, $value, '', false)) {
            return Result::fail('Could not persist deployment job; retry the webhook delivery');
        }
        return Result::ok($key);
    }

    public function pending(int $limit = 20): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT option_name, option_value FROM {$this->wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
            $this->wpdb->esc_like(self::PREFIX) . '%', $limit
        ), ARRAY_A);
        $jobs = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $job = json_decode($row['option_value'], true);
            if (is_array($job) && isset($job['deployment_id'], $job['trigger'], $job['force'])) {
                $job['key'] = $row['option_name'];
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    public function remove(string $key): void
    {
        delete_option($key);
    }

    public function retryLater(array $job): void
    {
        // Append before removing: interruption during rotation can duplicate an
        // attempt, but cannot lose it. Later jobs can pass a persistently failing job.
        $retry = $this->enqueue($job['deployment_id'], $job['trigger'], (bool) $job['force']);
        if ($retry->isOk()) {
            $this->remove($job['key']);
        }
    }

    public function acquire(): bool
    {
        return (string) $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName)) === '1';
    }

    public function release(): void
    {
        $this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName));
    }
}
