<?php

namespace Deployward\Config;

interface DeploymentRepositoryInterface
{
    public function save(Deployment $deployment): void;
}
