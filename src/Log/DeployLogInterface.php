<?php

namespace Deployward\Log;

interface DeployLogInterface
{
    public function record(array $row): void;

    public function recent(string $deploymentId, int $limit = 20, int $offset = 0): array;
}
