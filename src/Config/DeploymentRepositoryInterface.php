<?php

namespace Deployward\Config;

interface DeploymentRepositoryInterface
{
    public function all(): array;

    public function find(string $id): ?Deployment;

    public function save(Deployment $deployment): void;

    public function delete(string $id): void;
}
