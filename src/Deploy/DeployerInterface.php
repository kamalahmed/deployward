<?php

namespace Deployward\Deploy;

use Deployward\Config\Deployment;
use Deployward\Support\Result;

interface DeployerInterface
{
    public function deploy(Deployment $deployment, string $trigger, bool $force = false): Result;

    public function rollback(Deployment $deployment, string $trigger = 'manual-rollback'): Result;
}
