<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface DeploySchedulerInterface
{
    public function schedule(string $deploymentId, string $trigger, bool $force = false): Result;

    public function run(string $deploymentId, string $trigger, bool $force = false): void;
}
