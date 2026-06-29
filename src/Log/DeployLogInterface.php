<?php

namespace Deployward\Log;

interface DeployLogInterface
{
    public function record(array $row): void;
}
