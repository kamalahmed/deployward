<?php

namespace Deployward\Deploy;

interface DeploySchedulerInterface
{
    public function schedule(string $deploymentId, string $trigger, bool $force = false): void;

    public function run(string $deploymentId, string $trigger, bool $force = false): void;
}
